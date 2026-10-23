<?php
/**
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\StaticPublish\Fakes;

use Brain\Monkey\Functions;

/**
 * An in-memory options table behind add/get/update/delete_option and the
 * raw queries of FakeWpdb (installed as the global $wpdb).
 */
final class Options {
	/** @var array<string,mixed> */
	public static array $values = array();

	public static function install( array $initial = array() ): void {
		self::$values    = $initial;
		$GLOBALS['wpdb'] = new FakeWpdb();
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) {
				return array_key_exists( $name, self::$values ) ? self::$values[ $name ] : $default;
			}
		);
		Functions\when( 'add_option' )->alias(
			static function ( $name, $value = '' ) {
				if ( array_key_exists( $name, self::$values ) ) {
					return false;
				}
				self::$values[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value ) {
				self::$values[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			static function ( $name ) {
				unset( self::$values[ $name ] );
				return true;
			}
		);
	}
}
