<?php
/**
 * WP-CLI: `wp headless static`.
 *
 * @package WPHeadless
 */

namespace WPHeadless\StaticPublish;

/**
 * Run and inspect the static publisher.
 *
 * ## EXAMPLES
 *
 *     wp option update wp_headless_static_publish on
 *     wp headless static publish
 *     wp headless static status
 */
class Cli {
	private StaticPublish $module;

	public function __construct( StaticPublish $module ) {
		$this->module = $module;
	}

	/**
	 * Publish every page, data artifact and build file to the storage target.
	 *
	 * Exits non-zero when the publisher is off, when another publish holds
	 * the lock (the change is then queued for that run), and when the
	 * publish fails (the site stays dirty).
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Rewrite every object, ignoring the content-hash manifest.
	 *
	 * @param array<int,string>    $args       Positional arguments (unused).
	 * @param array<string,string> $assoc_args Flags.
	 */
	public function publish( $args, $assoc_args ): void {
		unset( $args );
		$publisher = $this->module->publisher();
		if ( null === $publisher ) {
			\WP_CLI::error( $this->off_reason() );
			return;
		}
		try {
			$stats = $publisher->run(
				array(
					'force' => ! empty( $assoc_args['force'] ),
					'log'   => static function ( string $line ): void {
						\WP_CLI::log( $line );
					},
				)
			);
		} catch ( \Throwable $e ) {
			\WP_CLI::error( 'The publish failed and the site stays dirty: ' . $e->getMessage() );
			return;
		}
		if ( null === $stats ) {
			\WP_CLI::error( 'Another publish holds the lock. This change is queued, and that run publishes it when it ends.' );
			return;
		}
		\WP_CLI::success( 'Published: ' . wp_json_encode( $stats ) );
	}

	/**
	 * Show whether the publisher is on, its target, lock, dirty flag and
	 * last publish.
	 *
	 * @param array<int,string>    $args       Positional arguments (unused).
	 * @param array<string,string> $assoc_args Flags (unused).
	 */
	public function status( $args = array(), $assoc_args = array() ): void {
		unset( $args, $assoc_args );
		$storage  = $this->module->enabled() ? $this->module->storage() : null;
		$manifest = get_option( Publisher::MANIFEST, array() );
		$dirty    = get_option( Publisher::DIRTY, false );
		$next     = wp_next_scheduled( StaticPublish::CRON );
		\WP_CLI::log(
			(string) wp_json_encode(
				array(
					'enabled'   => $this->module->enabled(),
					'target'    => null !== $storage ? $storage->id() : null,
					'locked'    => null !== Lock::expires() ? gmdate( 'c', (int) Lock::expires() ) : null,
					'dirty'     => $dirty ? gmdate( 'c', (int) $dirty ) : null,
					'scheduled' => $next ? gmdate( 'c', (int) $next ) : null,
					'published' => get_option( Publisher::LAST, null ) ? get_option( Publisher::LAST ) : null,
					'objects'   => is_array( $manifest ) && is_array( $manifest['hashes'] ?? null ) ? count( $manifest['hashes'] ) : 0,
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			)
		);
	}

	/** Why publisher() returned null. */
	private function off_reason(): string {
		if ( ! $this->module->enabled() ) {
			return 'The static publisher is off on this site. It is opt-in: wp option update wp_headless_static_publish on';
		}
		return 'The static publisher has no storage target. Set modules.static_publish.target (local or s3) or supply one with the wp_headless_static_storage filter.';
	}
}
