<?php
/**
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\StaticPublish\Fakes;

use WPHeadless\StaticPublish\Fetcher;

/** An origin that answers from a table; unknown paths answer 404. */
final class FakeFetcher extends Fetcher {
	/** @var array<string, array{status:int,headers:array<string,string>,body:string}|null> */
	public array $responses = array();

	/** @var array<int,string> */
	public array $requests = array();

	/** @var callable|null Called before each read (to simulate mid-run changes). */
	public $before = null;

	public function __construct() {
		// No parent::__construct(): get() never reaches the network.
	}

	public function get( string $path ): ?array {
		$this->requests[] = $path;
		if ( null !== $this->before ) {
			( $this->before )( $path );
		}
		if ( array_key_exists( $path, $this->responses ) ) {
			return $this->responses[ $path ];
		}
		return self::page( 'missing', 404, 'public, max-age=0, s-maxage=60' );
	}

	public static function page( string $body, int $status = 200, string $cache = 'public, max-age=60, s-maxage=300' ): array {
		return array(
			'status'  => $status,
			'headers' => array(
				'content-type'  => 'text/html; charset=UTF-8',
				'cache-control' => $cache,
			),
			'body'    => $body,
		);
	}
}
