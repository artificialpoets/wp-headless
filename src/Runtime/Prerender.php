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

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'headless prerender', array( $this, 'cli' ) );
		}
	}

	/**
	 * Serve the stored pre-render for the resolved singular, when one
	 * exists, as a sibling before #root.
	 *
	 * @param string $html Full document HTML.
	 * @return string
	 */
	public function inject( string $html ): string {
		if ( ! is_singular() ) {
			return $html;
		}

		$snapshot = self::get( get_queried_object_id() );
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

		$post_types = (array) $this->config->get( 'modules.prerender.post_types', array( 'page' ) );

		$query_args = array(
			'post_type'      => $post_types,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		);
		if ( ! empty( $assoc_args['post'] ) ) {
			$query_args['post__in'] = array( absint( $assoc_args['post'] ) );
		}

		$post_ids = get_posts( $query_args );
		if ( ! $post_ids ) {
			\WP_CLI::error( 'No published content found for the configured post types.' );
		}

		$front_id = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;

		$routes = array();
		foreach ( $post_ids as $post_id ) {
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

		$command_template = (string) $this->config->get(
			'modules.prerender.command',
			'node {renderer} --base={base} --routes={routes} --out={out}'
		);
		$command          = strtr(
			$command_template,
			array(
				'{renderer}' => escapeshellarg( get_template_directory() . '/tools/render-pages.mjs' ),
				'{theme}'    => escapeshellarg( get_template_directory() ),
				'{base}'     => escapeshellarg( home_url() ),
				'{routes}'   => escapeshellarg( $routes_file ),
				'{out}'      => escapeshellarg( $tmp_dir ),
			)
		);

		\WP_CLI::log( sprintf( 'Rendering %d routes: %s', count( $routes ), $command ) );
		$output = shell_exec( $command . ' 2>&1' );
		\WP_CLI::log( (string) $output );

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

		\WP_CLI::success( sprintf( 'Stored %d/%d pre-renders.', $stored, count( $routes ) ) );
	}
}
