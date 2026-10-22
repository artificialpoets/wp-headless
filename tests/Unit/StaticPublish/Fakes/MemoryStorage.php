<?php
/**
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\StaticPublish\Fakes;

use WPHeadless\StaticPublish\Storage;
use WPHeadless\StaticPublish\StorageError;

/** A storage target in memory that records every call and can fail on demand. */
final class MemoryStorage implements Storage {
	/** @var array<string, array{body:string, type:string, cache:string}> */
	public array $objects = array();

	/** @var array<int,string> Keys put, in order. */
	public array $puts = array();

	/** @var array<int, array{0:string, 1:string}> Every successful put as [key, body]. */
	public array $history = array();

	/** @var array<int,string> Keys deleted, in order. */
	public array $deletes = array();

	/** @var array<int,string> Keys whose put throws; '*' fails every put. */
	public array $fail_puts = array();

	public string $id = 'memory://site';

	public function id(): string {
		return $this->id;
	}

	public function put( string $key, string $body, string $content_type, string $cache_control ): void {
		if ( in_array( '*', $this->fail_puts, true ) || in_array( $key, $this->fail_puts, true ) ) {
			throw new StorageError( 'put refused for ' . $key );
		}
		$this->puts[]          = $key;
		$this->history[]       = array( $key, $body );
		$this->objects[ $key ] = array(
			'body'  => $body,
			'type'  => $content_type,
			'cache' => $cache_control,
		);
	}

	public function delete( string $key ): void {
		$this->deletes[] = $key;
		unset( $this->objects[ $key ] );
	}

	/** Decoded status file. */
	public function status(): array {
		return json_decode( $this->objects['__data/__status.json']['body'] ?? '{}', true );
	}
}
