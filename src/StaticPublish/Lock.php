<?php
/**
 * Run lock of the static publisher.
 *
 * @package WPHeadless
 */

namespace WPHeadless\StaticPublish;

/**
 * One publish at a time per site. The lock is an options row holding its
 * expiry time, written with `INSERT IGNORE` — the pattern of core's
 * WP_Upgrader::create_lock() — so exactly one concurrent run creates it.
 * (add_option() is not a mutex: its `ON DUPLICATE KEY UPDATE` lets a second
 * writer overwrite the row.) A lock past its expiry belongs to a run that
 * died; it is taken over with a compare-and-swap, so only one run wins that
 * too. A long run extends its lock as it goes.
 *
 * The row is read and written directly, never through the options cache:
 * another process holds it.
 */
final class Lock {
	public const OPTION = 'wp_headless_static_lock';

	/**
	 * Take the lock.
	 *
	 * @param int $ttl Seconds the lock holds without an extend().
	 */
	public static function acquire( int $ttl ): bool {
		global $wpdb;
		$expires = (string) ( time() + $ttl );
		$created = $wpdb->query(
			$wpdb->prepare( "INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')", self::OPTION, $expires )
		);
		if ( 1 === (int) $created ) {
			return true;
		}
		$current = self::read();
		if ( null === $current ) {
			return false; // Released between the two queries: the next trigger retries.
		}
		if ( (int) $current >= time() ) {
			return false;
		}
		$taken = $wpdb->query(
			$wpdb->prepare( "UPDATE `{$wpdb->options}` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s", $expires, self::OPTION, $current )
		);
		return 1 === (int) $taken;
	}

	/**
	 * Push the expiry out while a run is still working.
	 *
	 * @param int $ttl Seconds from now.
	 */
	public static function extend( int $ttl ): void {
		global $wpdb;
		$wpdb->update( $wpdb->options, array( 'option_value' => (string) ( time() + $ttl ) ), array( 'option_name' => self::OPTION ) );
	}

	/** Release the lock. */
	public static function release(): void {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::OPTION ) );
	}

	/** Expiry of the held lock, or null when nothing holds it. */
	public static function expires(): ?int {
		$current = self::read();
		return null !== $current && (int) $current > 0 ? (int) $current : null;
	}

	/** The row's raw value, or null when there is none. */
	private static function read(): ?string {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s LIMIT 1", self::OPTION ) );
		return null === $value ? null : (string) $value;
	}
}
