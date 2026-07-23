<?php
/**
 * Pre-rendered first paint — stores per-post prerender HTML and serves
 * it inside the SPA document.
 *
 * The served shell normally ships an empty #root, so nothing paints
 * until the JS bundle boots. This module injects a stored pre-render
 * as a SIBLING immediately before #root: the browser paints real
 * content on first paint, and the frontend removes the container when
 * it commits its own chrome (the container id is the contract).
 *
 * The plugin deliberately does NOT render anything itself — themes own
 * their markup. They generate it however they like (the reference
 * implementation runs the theme's own SSR bundle in plain Node via
 * `wp headless prerender`) and this module stores, serves, and
 * invalidates it:
 *
 * - Storage: postmeta on the rendered post (`_wp_headless_prerender`),
 *   script-stripped and size-capped at write time.
 * - Serving: `wp_headless_document_html` at priority 10 — before
 *   theme-side fallback shells, which conventionally hook at 20 and
 *   skip when the container is already present.
 * - Invalidation: saving or deleting a post drops its snapshot;
 *   switching themes drops all of them. Themes/hosts add their own
 *   triggers by calling Prerender::invalidate()/flush(), and can react
 *   to any invalidation via the `wp_headless_prerender_invalidated`
 *   action (e.g. queue a regeneration, purge a CDN path).
 *
 * @package WPHeadless
 */

namespace WPHeadless\Runtime;

use WPHeadless\Config\Config;
use WPHeadless\Contracts\Module;

class Prerender implements Module {

	public const META_KEY     = '_wp_headless_prerender';
	public const META_TIME    = '_wp_headless_prerender_time';
	public const CONTAINER_ID = 'wp-headless-prerender';

	/** Snapshots larger than this are refused at store time. */
	public const MAX_BYTES = 1000000;

	private Config $config;

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	public function register(): void {
		add_filter( 'wp_headless_document_html', array( $this, 'inject' ), 10 );
		add_action( 'save_post', array( $this, 'invalidate_on_save' ), 10, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'invalidate' ) );
		add_action( 'switch_theme', array( __CLASS__, 'flush' ) );

		// Self-healing: every invalidation queues a (debounced) cron
		// regeneration, so stale-until-someone-runs-the-CLI stops being
		// a state the site can be in. Hosts with their own workers
		// disable via `modules.prerender.auto_regenerate => false` and
		// consume `wp_headless_prerender_invalidated` instead.
		add_action( 'wp_headless_prerender_invalidated', array( $this, 'queue_regeneration' ) );
		add_action( 'wp_headless_prerender_regenerate', array( $this, 'run_regeneration' ) );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'headless prerender', array( $this, 'cli' ) );
		}
	}

	/**
	 * Queue an async regeneration for the invalidated scope.
	 *
	 * @param int|null $post_id Invalidated post, or null for a full flush.
	 */
	public function queue_regeneration( $post_id ): void {
		if ( false === $this->config->get( 'modules.prerender.auto_regenerate', true ) ) {
			return;
		}

		if ( ! self::can_shell() ) {
			return;
		}

		$scope = null === $post_id ? 'all' : (string) (int) $post_id;
		if ( ! wp_next_scheduled( 'wp_headless_prerender_regenerate', array( $scope ) ) ) {
			wp_schedule_single_event( time() + 10, 'wp_headless_prerender_regenerate', array( $scope ) );
		}
	}

	/**
	 * Cron callback: regenerate one post or everything.
	 *
	 * @param string $scope 'all' or a post ID.
	 */
	public function run_regeneration( $scope = 'all' ): void {
		$this->generate( 'all' === $scope ? null : array( (int) $scope ) );
	}

	/** Whether this environment can shell out to the renderer. */
	public static function can_shell(): bool {
		return function_exists( 'shell_exec' )
			&& false === strpos( (string) ini_get( 'disable_functions' ), 'shell_exec' );
	}

	/**
	 * The Node binary for the default renderer command. Web contexts
	 * (cron loopbacks) run with a minimal PATH, so prefer an absolute
	 * binary when one can be found.
	 */
	private function node_bin(): string {
		$configured = (string) $this->config->get( 'modules.prerender.node_bin', '' );
		if ( '' !== $configured ) {
			return $configured;
		}

		$candidates = array( '/opt/homebrew/bin/node', '/usr/local/bin/node', '/usr/bin/node' );

		$home = getenv( 'HOME' );
		if ( ! $home && ! empty( $_SERVER['HOME'] ) ) {
			$home = (string) $_SERVER['HOME']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}
		if ( $home ) {
			$nvm = glob( rtrim( $home, '/' ) . '/.nvm/versions/node/*/bin/node' );
			if ( $nvm ) {
				natsort( $nvm );
				array_unshift( $candidates, end( $nvm ) );
			}
		}

		foreach ( $candidates as $candidate ) {
			if ( is_executable( $candidate ) ) {
				return $candidate;
			}
		}

		return 'node';
	}

	/**
	 * Serve the stored pre-render for the resolved singular, when one
	 * exists, as a sibling before #root.
	 *
	 * @param string $html Full document HTML.
	 * @return string
	 */
	public function inject( string $html ): string {
		$post_id = 0;
		if ( is_singular() ) {
			$post_id = get_queried_object_id();
		} elseif ( is_home() ) {
			// The posts page resolves as is_home(), not is_singular(),
			// but it is a real page with its own stored pre-render.
			$post_id = (int) get_option( 'page_for_posts' );
		}

		if ( $post_id <= 0 ) {
			return $html;
		}

		$snapshot = self::get( $post_id );
		if ( null === $snapshot ) {
			return $html;
		}

		$needle = '<div id="root"></div>';
		if ( false === strpos( $html, $needle ) ) {
			return $html;
		}

		$shell = '<div id="' . self::CONTAINER_ID . '">' . $snapshot . '</div>';

		return str_replace( $needle, $shell . $needle, $html );
	}

	/**
	 * The stored pre-render for a post, or null when absent/unsafe.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null
	 */
	public static function get( int $post_id ): ?string {
		if ( $post_id <= 0 ) {
			return null;
		}

		$snapshot = (string) get_post_meta( $post_id, self::META_KEY, true );
		if ( '' === $snapshot ) {
			return null;
		}

		// Defense in depth: store() strips scripts, but meta can be
		// written by other paths — never serve executable markup.
		if ( false !== stripos( $snapshot, '<script' ) ) {
			return null;
		}

		return $snapshot;
	}

	/**
	 * Store a post's pre-render (script-stripped, size-capped).
	 *
	 * @param int    $post_id Post ID.
	 * @param string $html    Pre-rendered HTML (the #root innerHTML).
	 * @return bool Whether the snapshot was stored.
	 */
	public static function store( int $post_id, string $html ): bool {
		$html = trim( $html );
		if ( $post_id <= 0 || '' === $html ) {
			return false;
		}

		if ( false !== stripos( $html, '<script' ) ) {
			$html = (string) preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html );
		}

		if ( strlen( $html ) > self::MAX_BYTES ) {
			return false;
		}

		update_post_meta( $post_id, self::META_KEY, wp_slash( $html ) );
		update_post_meta( $post_id, self::META_TIME, time() );

		return true;
	}

	/**
	 * Drop one post's pre-render.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function invalidate( $post_id ): void {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return;
		}

		delete_post_meta( $post_id, self::META_KEY );
		delete_post_meta( $post_id, self::META_TIME );

		/**
		 * Fires when pre-rendered HTML is invalidated.
		 *
		 * @param int|null $post_id The invalidated post, or null when ALL
		 *                          pre-renders were flushed.
		 */
		do_action( 'wp_headless_prerender_invalidated', $post_id );
	}

	/**
	 * Drop every stored pre-render (site-wide chrome changed, theme
	 * switched, deploy).
	 *
	 * @return int Rows deleted.
	 */
	public static function flush(): int {
		global $wpdb;

		$count = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)",
				self::META_KEY,
				self::META_TIME
			)
		);

		/** This action is documented in inject()'s invalidate(). */
		do_action( 'wp_headless_prerender_invalidated', null );

		return $count;
	}

	/**
	 * Editing content invalidates that post's pre-render (revisions and
	 * autosaves excluded — they never change the served page).
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function invalidate_on_save( $post_id, $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! $post instanceof \WP_Post || 'auto-draft' === $post->post_status ) {
			return;
		}

		self::invalidate( (int) $post_id );
	}

	/**
	 * Pre-render published content through the theme's renderer.
	 *
	 * Collects the routes (published posts of the configured types),
	 * shells out to the renderer command, and stores each result. The
	 * command is configurable (`modules.prerender.command`); the default
	 * expects the active theme to ship `tools/render-pages.mjs` and its
	 * SSR bundle (`dist-server/`), rendering in plain Node.
	 *
	 * ## OPTIONS
	 *
	 * [--post=<id>]
	 * : Only pre-render one post.
	 *
	 * [--flush]
	 * : Delete every stored pre-render and exit.
	 *
	 * ## EXAMPLES
	 *
	 *     wp headless prerender
	 *     wp headless prerender --post=709
	 *     wp headless prerender --flush
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public function cli( $args, $assoc_args ): void {
		if ( isset( $assoc_args['flush'] ) ) {
			$flushed = self::flush();
			\WP_CLI::success( "Flushed {$flushed} pre-render rows." );
			return;
		}

		$only   = ! empty( $assoc_args['post'] ) ? array( absint( $assoc_args['post'] ) ) : null;
		$result = $this->generate( $only );

		if ( 0 === $result['total'] ) {
			\WP_CLI::error( 'No published content found for the configured post types.' );
		}

		\WP_CLI::log( (string) $result['output'] );
		\WP_CLI::success( sprintf( 'Stored %d/%d pre-renders.', $result['stored'], $result['total'] ) );
	}

	/**
	 * The generation engine shared by the CLI and cron regeneration:
	 * collect routes, shell the renderer, store the results.
	 *
	 * @param int[]|null $only Restrict to these post IDs (null = all).
	 * @return array{stored: int, total: int, output: string}
	 */
	private function generate( ?array $only = null ): array {
		$post_types = (array) $this->config->get( 'modules.prerender.post_types', array( 'page' ) );

		$query_args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);
		if ( null !== $only ) {
			$query_args['post__in'] = array_map( 'absint', $only );
		}

		$post_ids = get_posts( $query_args );
		if ( ! $post_ids ) {
			return array(
				'stored' => 0,
				'total'  => 0,
				'output' => '',
			);
		}

		$front_id = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;

		// The posts page's listing is typically client-fetched, so a
		// headless render paints it empty — a wrong-layout shell is
		// worse than a clean SPA boot. Excluded until the theme embeds
		// archive content; opt in via modules.prerender.include_posts_page.
		$posts_page_id = 0;
		if ( false === $this->config->get( 'modules.prerender.include_posts_page', false ) ) {
			$posts_page_id = (int) get_option( 'page_for_posts' );
		}

		$routes = array();
		foreach ( $post_ids as $post_id ) {
			if ( $posts_page_id && (int) $post_id === $posts_page_id ) {
				self::invalidate( (int) $post_id );
				continue;
			}
			$permalink = get_permalink( $post_id );
			$path      = $permalink ? wp_parse_url( $permalink, PHP_URL_PATH ) : null;

			// The front page must render as '/' — its slug URL only
			// canonical-redirects there, and the renderer renders the
			// REQUESTED path (no redirect-following router).
			if ( $front_id && (int) $post_id === $front_id ) {
				$path = '/';
			}

			if ( $path ) {
				$routes[] = array(
					'path' => $path,
					'key'  => (string) $post_id,
				);
			}
		}

		$tmp_dir     = get_temp_dir() . 'wp-headless-prerender-' . wp_generate_password( 8, false );
		$routes_file = $tmp_dir . '/routes.json';
		wp_mkdir_p( $tmp_dir );
		file_put_contents( $routes_file, wp_json_encode( $routes ) );

		$command_template = (string) $this->config->get( 'modules.prerender.command', '' );
		if ( '' === $command_template ) {
			$command_template = $this->node_bin() . ' {renderer} --base={base} --routes={routes} --out={out}';
		}
		$command = strtr(
			$command_template,
			array(
				'{renderer}' => escapeshellarg( get_template_directory() . '/tools/render-pages.mjs' ),
				'{theme}'    => escapeshellarg( get_template_directory() ),
				'{base}'     => escapeshellarg( home_url() ),
				'{routes}'   => escapeshellarg( $routes_file ),
				'{out}'      => escapeshellarg( $tmp_dir ),
			)
		);

		$output = (string) shell_exec( $command . ' 2>&1' );

		$stored = 0;
		foreach ( $routes as $route ) {
			$file = $tmp_dir . '/' . $route['key'] . '.html';
			if ( ! file_exists( $file ) ) {
				continue;
			}
			if ( self::store( (int) $route['key'], (string) file_get_contents( $file ) ) ) {
				$stored++;
			}
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		@unlink( $routes_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		@rmdir( $tmp_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return array(
			'stored' => $stored,
			'total'  => count( $routes ),
			'output' => $output,
		);
	}
}
