<?php
/**
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\StaticPublish\Fakes;

/**
 * Just enough of $wpdb for the raw options queries the static publisher
 * makes, backed by the same table as Options. prepare() returns the query
 * and its arguments so query() can act on them.
 */
final class FakeWpdb {
	public string $options = 'wp_options';

	/** @var array<int,string> SQL of every query, in order. */
	public array $queries = array();

	public function prepare( string $query, ...$args ): array {
		return array( $query, $args );
	}

	public function query( array $prepared ) {
		list( $sql, $args )  = $prepared;
		$this->queries[]     = $sql;
		if ( 0 === strpos( $sql, 'INSERT IGNORE' ) ) {
			if ( array_key_exists( $args[0], Options::$values ) ) {
				return 0;
			}
			Options::$values[ $args[0] ] = $args[1];
			return 1;
		}
		if ( 0 === strpos( $sql, 'UPDATE' ) ) {
			list( $value, $name, $old ) = $args;
			if ( array_key_exists( $name, Options::$values ) && (string) Options::$values[ $name ] === $old ) {
				Options::$values[ $name ] = $value;
				return 1;
			}
			return 0;
		}
		return false;
	}

	public function get_var( array $prepared ) {
		$this->queries[] = $prepared[0];
		$name            = $prepared[1][0];
		return array_key_exists( $name, Options::$values ) ? (string) Options::$values[ $name ] : null;
	}

	public function update( string $table, array $data, array $where ) {
		unset( $table );
		if ( ! array_key_exists( $where['option_name'], Options::$values ) ) {
			return 0;
		}
		Options::$values[ $where['option_name'] ] = $data['option_value'];
		return 1;
	}

	public function delete( string $table, array $where ) {
		unset( $table );
		unset( Options::$values[ $where['option_name'] ] );
		return 1;
	}
}
