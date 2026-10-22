<?php
/**
 * Static publisher module.
 *
 * @package WPHeadless
 */

namespace WPHeadless\StaticPublish;

use WPHeadless\Config\Config;
use WPHeadless\Contracts\Module;
use WPHeadless\Export\DataExport;
use WPHeadless\Theme\ThemeManager;

/**
 * Writes a site's public pages, their data artifacts and the theme build to
 * a storage target, so a host can serve the public site from object storage
 * (or a plain directory) while WordPress sleeps.
 *
 * Opt-in per site, off by default. It runs only where the
 * `wp_headless_static_publish` option is `on` (or the
 * `WP_HEADLESS_STATIC_PUBLISH` constant is true) AND a storage target is
 * configured. Configuring a target alone never turns it on, and the option
 * turns it off again without a deploy.
 *
 * A visible change (a post published, updated or unpublished; a term, menu,
 * Customizer, theme or reading-setting change; a runtime-cache
 * invalidation) only marks the site dirty and schedules a single cron event. The publish runs from cron
 * or `wp headless static publish`, never inside the request that saved.
 */
class StaticPublish implements Module {
	/** Per-site opt-in option: `on` turns the publisher on. */
	public const OPTION = 'wp_headless_static_publish';

	/** Cron hook that runs a publish. */
	public const CRON = 'wp_headless_static_publish';

	/** Seconds between a change and its publish; later changes join it. */
	public const DEFAULT_DELAY = 60;

	/** Settings whose change alters public pages or their URLs. */
	public const WATCHED_OPTIONS = array( 'show_on_front', 'page_on_front', 'page_for_posts', 'posts_per_page', 'permalink_structure', 'category_base', 'tag_base', 'blogname', 'blogdescription', 'site_icon' );

	private Config $config;

	private ?ThemeManager $theme_manager;

	/**
	 * @param Config            $config        Plugin configuration.
	 * @param ThemeManager|null $theme_manager Resolves the active build for the asset mirror.
	 */
	public function __construct( Config $config, ?ThemeManager $theme_manager = null ) {
		$this->config        = $config;
		$this->theme_manager = $theme_manager;
	}

	public function register(): void {
		add_action( 'wp_after_insert_post', array( $this, 'on_post_change' ), 20, 4 );
		add_action( 'before_delete_post', array( $this, 'on_post_deleted' ) );
		foreach ( array( 'created_term', 'edited_term', 'delete_term', 'wp_update_nav_menu', 'customize_save_after', 'switch_theme', 'wp_headless_runtime_cache_invalidated' ) as $hook ) {
			add_action( $hook, array( $this, 'mark_dirty' ), 20, 0 );
		}
		add_action( 'updated_option', array( $this, 'on_option_updated' ), 20, 1 );
		add_action( self::CRON, array( $this, 'run_scheduled' ) );
		add_filter( 'wp_headless_runtime_data', array( $this, 'static_runtime' ), 1000 );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'headless static', new Cli( $this ) );
		}
	}

	/**
	 * Whether this site opted in. The constant, when defined, wins over the
	 * option (`false` keeps a site off whatever its option says).
	 */
	public function enabled(): bool {
		$constant = $this->constant();
		if ( null !== $constant ) {
			return $constant;
		}
		return 'on' === get_option( self::OPTION, 'off' );
	}

	/**
	 * The configured storage target, or null when there is none.
	 */
	public function storage(): ?Storage {
		$storage = null;
		$target  = (string) $this->setting( 'target', '' );
		try {
			if ( 'local' === $target && '' !== (string) $this->setting( 'path', '' ) ) {
				$storage = new LocalStorage( (string) $this->setting( 'path', '' ) );
			} elseif ( 's3' === $target ) {
				$s3      = $this->setting( 's3', array() );
				$storage = S3Storage::from_settings( is_array( $s3 ) ? $s3 : array() );
			}
		} catch ( StorageError $e ) {
			error_log( '[wp-headless] static storage: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			$storage = null;
		}

		/**
		 * Supplies the storage target the static publisher writes to.
		 *
		 * Return any Storage implementation to write somewhere the plugin
		 * does not cover, or to build the S3 target from host settings.
		 * Return null to keep the publisher off.
		 *
		 * @param Storage|null $storage The target from config, or null.
		 * @param Config       $config  Plugin config.
		 */
		$storage = apply_filters( 'wp_headless_static_storage', $storage, $this->config );
		return $storage instanceof Storage ? $storage : null;
	}

	/**
	 * A publisher for this site, or null when it is off or has no target.
	 */
	public function publisher(): ?Publisher {
		if ( ! $this->enabled() ) {
			return null;
		}
		$storage = $this->storage();
		if ( null === $storage ) {
			return null;
		}
		$fetcher = new Fetcher( home_url( '/' ), (string) $this->setting( 'origin', '' ), (int) $this->setting( 'timeout', 30 ) );
		$data    = false !== $this->config->get( 'modules.data_export.enabled', true ) ? new DataExport( $this->config ) : null;
		return new Publisher(
			$storage,
			$fetcher,
			array(
				'document_cache' => (string) $this->setting( 'document_cache', '' ),
				'dist'           => $this->asset_dist(),
				'mount'          => $this->config->asset_mount(),
			),
			null,
			null,
			$data,
			new AssetMirror()
		);
	}

	/**
	 * Mark the site changed and schedule the publish. Nothing is published
	 * in this request.
	 */
	public function mark_dirty(): void {
		if ( ! $this->enabled() ) {
			return;
		}
		update_option( Publisher::DIRTY, time(), false );
		$this->schedule();
	}

	/**
	 * A post change visitors could see: one that is, or was, published or
	 * scheduled (a scheduled post moves the status file's `nextScheduled`).
	 *
	 * @param int         $post_id     Post ID.
	 * @param object      $post        Post after the change.
	 * @param bool        $update      Whether this was an update.
	 * @param object|null $post_before Post before the change.
	 */
	public function on_post_change( $post_id, $post, $update = false, $post_before = null ): void {
		unset( $update );
		if ( ! is_object( $post ) || wp_is_post_revision( (int) $post_id ) || wp_is_post_autosave( (int) $post_id ) || ! $this->public_type( (string) ( $post->post_type ?? '' ) ) ) {
			return;
		}
		$visible = array( 'publish', 'future' );
		$was     = is_object( $post_before ) ? (string) ( $post_before->post_status ?? '' ) : '';
		if ( in_array( (string) ( $post->post_status ?? '' ), $visible, true ) || in_array( $was, $visible, true ) ) {
			$this->mark_dirty();
		}
	}

	/**
	 * A reading or permalink setting changed.
	 *
	 * @param mixed $option Option name.
	 */
	public function on_option_updated( $option ): void {
		if ( is_string( $option ) && in_array( $option, self::WATCHED_OPTIONS, true ) ) {
			$this->mark_dirty();
		}
	}

	/**
	 * A published post deleted outright (without passing through the trash).
	 *
	 * @param int $post_id Post ID.
	 */
	public function on_post_deleted( $post_id ): void {
		$post = get_post( (int) $post_id );
		if ( is_object( $post ) && in_array( (string) $post->post_status, array( 'publish', 'future' ), true ) && $this->public_type( (string) $post->post_type ) ) {
			$this->mark_dirty();
		}
	}

	/**
	 * Cron: publish when the site is dirty. Failures are already recorded
	 * (status file, error log, dirty flag); a run blocked by the lock is
	 * retried after the delay.
	 */
	public function run_scheduled(): void {
		if ( ! get_option( Publisher::DIRTY ) ) {
			return;
		}
		$publisher = $this->publisher();
		if ( null === $publisher ) {
			return;
		}
		try {
			if ( null === $publisher->run() ) {
				$this->schedule();
			}
		} catch ( \Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * The publisher's own reads get a runtime payload without the anonymous
	 * REST nonce and the logout nonce. Both rotate every 12 hours: a stored
	 * copy would outlive them (an expired nonce makes core reject anonymous
	 * REST writes with a 403) and change bytes on every tick, so every run
	 * would rewrite every document. Anonymous REST calls without a nonce run
	 * as user 0, which is what the nonce stood for.
	 *
	 * @param mixed $data Runtime payload.
	 * @return mixed
	 */
	public function static_runtime( $data ) {
		if ( ! is_array( $data ) || empty( $_SERVER['HTTP_X_WP_HEADLESS_STATIC'] ) || is_user_logged_in() || ! $this->enabled() ) {
			return $data;
		}
		if ( isset( $data['rest'] ) && is_array( $data['rest'] ) ) {
			$data['rest']['nonce'] = '';
		}
		if ( isset( $data['urls']['logout'] ) && is_string( $data['urls']['logout'] ) ) {
			$data['urls']['logout'] = remove_query_arg( '_wpnonce', $data['urls']['logout'] );
		}
		return $data;
	}

	/**
	 * Read a `modules.static_publish.*` setting.
	 *
	 * @param string $key     Setting under the module.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public function setting( string $key, $default = null ) {
		return $this->config->get( 'modules.static_publish.' . $key, $default );
	}

	/** Schedule the publish once; later changes join the pending event. */
	private function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + max( 0, (int) $this->setting( 'delay', self::DEFAULT_DELAY ) ), self::CRON );
		}
	}

	/**
	 * The build directory to mirror, or '' when assets are served elsewhere
	 * (`frontend.asset_base_url`), the mirror is off, or there is no build.
	 */
	private function asset_dist(): string {
		if ( false === $this->setting( 'assets', true ) || '' !== (string) $this->config->get( 'frontend.asset_base_url', '' ) || null === $this->theme_manager ) {
			return '';
		}
		return (string) $this->theme_manager->resolve_dist_path();
	}

	/**
	 * Whether a post type has public URLs.
	 *
	 * @param string $type Post type.
	 */
	private function public_type( string $type ): bool {
		$object = get_post_type_object( $type );
		return is_object( $object ) && ! empty( $object->public ) && 'attachment' !== $type;
	}

	/** The `WP_HEADLESS_STATIC_PUBLISH` constant, or null when undefined. */
	protected function constant(): ?bool {
		return defined( 'WP_HEADLESS_STATIC_PUBLISH' ) ? (bool) constant( 'WP_HEADLESS_STATIC_PUBLISH' ) : null;
	}
}
