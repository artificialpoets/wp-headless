<?php
/**
 * Cross-request cache for the static portion of the runtime payload.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Runtime;

/**
 * Static store, deliberately: two fresh RuntimeDataBuilder instances run per
 * request (the frontend bridge's and the REST /runtime endpoint's), so
 * instance state cannot be shared — and on stock installs without a
 * persistent object cache, request-scoped `wp_cache_*` alone would rebuild
 * on every request anyway. Transients are the storage (they ride the
 * persistent object cache when one exists, the options table otherwise),
 * with an in-process memo on top so one request never reads the store twice.
 *
 * Invalidation is a generation counter folded into the cache key rather than
 * a delete: locale and multisite variants make old keys non-enumerable, so
 * stale blobs simply age out via TTL while new reads miss immediately.
 */
final class RuntimeCache {

	const GENERATION_OPTION = 'wp_headless_runtime_cache_gen';
	const TRANSIENT_PREFIX  = 'wp_headless_runtime_';

	/**
	 * The updated_option allowlist: options whose value is embedded in the
	 * static payload. A single listener beats ten update_option_{name} hooks.
	 */
	const WATCHED_OPTIONS = array(
		'comment_registration',
		'require_name_email',
		'default_comment_status',
		'thread_comments',
		'thread_comments_depth',
		'page_comments',
		'comments_per_page',
		'default_comments_page',
		'comment_order',
		'show_avatars',
		'blogname',
		'blogdescription',
		'site_icon',
		'users_can_register',
	);

	/** @var array<string, array<string, mixed>> Per-request memo across builder instances. */
	private static $memo = array();

	/** @var bool Hook-registration guard. */
	private static $hooks_registered = false;

	/**
	 * Return the cached value for $key, building and storing it on a miss.
	 *
	 * @param string   $key     Cache key (already variant-qualified).
	 * @param int      $ttl     Transient TTL in seconds.
	 * @param callable $builder Builds the value on miss.
	 * @return array<string, mixed>
	 */
	public static function remember( string $key, int $ttl, callable $builder ): array {
		if ( isset( self::$memo[ $key ] ) ) {
			return self::$memo[ $key ];
		}
		$stored = get_transient( self::TRANSIENT_PREFIX . $key );
		if ( is_array( $stored ) ) {
			self::$memo[ $key ] = $stored;
			return $stored;
		}
		$value = (array) $builder();
		set_transient( self::TRANSIENT_PREFIX . $key, $value, $ttl );
		self::$memo[ $key ] = $value;
		return $value;
	}

	/**
	 * The variant-qualified cache key: plugin version (deploys invalidate
	 * implicitly), theme, locale, blog, and the invalidation generation.
	 */
	public static function key(): string {
		return md5(
			( defined( 'WP_HEADLESS_VERSION' ) ? WP_HEADLESS_VERSION : '0' )
			. '|' . get_stylesheet()
			. '|' . get_locale()
			. '|' . get_current_blog_id()
			. '|' . self::generation()
		);
	}

	/**
	 * The current invalidation generation.
	 */
	public static function generation(): int {
		return (int) get_option( self::GENERATION_OPTION, 0 );
	}

	/**
	 * Invalidate every cached payload variant by bumping the generation.
	 *
	 * @param string $reason Source hook, e.g. 'switch_theme', 'updated_option:blogname'.
	 */
	public static function invalidate( string $reason ): void {
		update_option( self::GENERATION_OPTION, self::generation() + 1, true );
		self::$memo = array();

		/**
		 * Fires after the cached runtime payload is invalidated.
		 *
		 * The payload is embedded in every served document, so hosts hook
		 * this to purge their CDN/edge caches of the document shell.
		 *
		 * @param string $reason The source of the invalidation.
		 */
		do_action( 'wp_headless_runtime_cache_invalidated', $reason );
	}

	/**
	 * Wire the invalidation triggers. Idempotent; called by the cache module.
	 */
	public static function register_invalidation_hooks(): void {
		if ( self::$hooks_registered ) {
			return;
		}
		self::$hooks_registered = true;

		foreach ( array( 'wp_update_nav_menu', 'wp_delete_nav_menu', 'wp_create_nav_menu' ) as $hook ) {
			add_action(
				$hook,
				static function () {
					RuntimeCache::invalidate( 'nav_menu' );
				}
			);
		}

		// Menu→location assignment lives in theme_mods_{stylesheet}, which
		// wp_update_nav_menu does NOT fire for.
		add_action(
			'update_option_theme_mods_' . get_option( 'stylesheet' ),
			static function () {
				RuntimeCache::invalidate( 'theme_mods' );
			}
		);

		foreach ( array( 'customize_save_after', 'switch_theme', 'activated_plugin', 'deactivated_plugin', 'upgrader_process_complete' ) as $hook ) {
			add_action(
				$hook,
				static function () use ( $hook ) {
					RuntimeCache::invalidate( $hook );
				}
			);
		}

		add_action( 'updated_option', array( self::class, 'maybe_invalidate_on_option' ) );

		// wp_get_custom_css() reads the custom_css CPT.
		add_action(
			'save_post_custom_css',
			static function () {
				RuntimeCache::invalidate( 'custom_css' );
			}
		);
	}

	/**
	 * updated_option listener — invalidate only for watched options.
	 *
	 * @param mixed $option Option name.
	 */
	public static function maybe_invalidate_on_option( $option ): void {
		if ( is_string( $option ) && in_array( $option, self::WATCHED_OPTIONS, true ) ) {
			self::invalidate( 'updated_option:' . $option );
		}
	}

	/**
	 * Drop the per-request memo (tests).
	 */
	public static function flush_memo(): void {
		self::$memo = array();
	}

	/**
	 * Reset the hook-registration guard (tests).
	 */
	public static function reset_hooks_flag(): void {
		self::$hooks_registered = false;
	}
}
