<?php
/**
 * Editor activity stamp.
 *
 * @package WPHeadless
 */

namespace WPHeadless\StaticPublish;

/**
 * Tells a host that someone is editing, so it keeps WordPress awake.
 *
 * A host that puts WordPress to sleep while the static copy serves visitors
 * needs a signal that an editor is at work. A request count is the wrong
 * one: scanners probing `/wp-login.php` or missing URLs would keep
 * WordPress awake forever. So every signed-in request from a user who can
 * edit posts writes `__data/__activity.json` (`{"lastEditor": ISO}`) to the
 * storage target: at most once a minute per site, after the response has
 * gone out. The host sleeps WordPress once the stamp is older than its idle
 * window.
 *
 * A failed write drops the once-a-minute guard, so the next editor request
 * tries again instead of leaving the stamp stale for a minute.
 */
final class Activity {
	/** Transient that holds the once-a-minute guard. */
	public const TRANSIENT = 'wp_headless_static_activity';

	/** Seconds between two stamps; a host's idle window is far longer. */
	public const EVERY = 60;

	/** @var callable(): ?Storage The target, or null when the publisher is off. */
	private $target;

	private ?Storage $storage = null;

	/**
	 * @param callable(): ?Storage $target Resolves the storage target, or null when the publisher is off.
	 */
	public function __construct( callable $target ) {
		$this->target = $target;
	}

	/**
	 * On `init`: queue a stamp for the end of this request when an editor
	 * is signed in and no stamp was written in the last minute.
	 */
	public function note(): void {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) || get_transient( self::TRANSIENT ) ) {
			return;
		}
		$this->storage = ( $this->target )();
		if ( null === $this->storage ) {
			return;
		}
		set_transient( self::TRANSIENT, 1, self::EVERY );
		add_action( 'shutdown', array( $this, 'write' ), 110 );
	}

	/**
	 * On `shutdown`: finish the response, then store the stamp.
	 *
	 * @return bool Whether the stamp was stored.
	 */
	public function write(): bool {
		if ( null === $this->storage ) {
			return false;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		try {
			$this->storage->put(
				Keys::ACTIVITY,
				(string) wp_json_encode( array( 'lastEditor' => gmdate( 'c' ) ) ),
				'application/json; charset=utf-8',
				'no-cache, max-age=0'
			);
		} catch ( StorageError $e ) {
			error_log( '[wp-headless] static activity stamp failed: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			delete_transient( self::TRANSIENT );
			return false;
		}
		return true;
	}
}
