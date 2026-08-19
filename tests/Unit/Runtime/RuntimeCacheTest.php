<?php
/**
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\Runtime;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WPHeadless\Runtime\RuntimeCache;

final class RuntimeCacheTest extends TestCase {
	use MockeryPHPUnitIntegration;

	/** @var array<string, mixed> Fake transient store. */
	private array $transients = array();

	/** @var array<string, mixed> Fake options store. */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		RuntimeCache::flush_memo();
		$this->transients = array();
		$this->options    = array();

		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return $this->transients[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl = 0 ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				return $this->options[ $key ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_stylesheet' )->justReturn( 'a13s' );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
	}

	protected function tearDown(): void {
		RuntimeCache::flush_memo();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_remember_builds_and_stores_on_miss(): void {
		$calls = 0;
		$value = RuntimeCache::remember(
			'k1',
			900,
			static function () use ( &$calls ) {
				$calls++;
				return array( 'site' => array( 'name' => 'AP' ) );
			}
		);
		$this->assertSame( 1, $calls );
		$this->assertSame( 'AP', $value['site']['name'] );
		$this->assertArrayHasKey( RuntimeCache::TRANSIENT_PREFIX . 'k1', $this->transients );
	}

	public function test_memo_prevents_a_second_store_read_in_request(): void {
		RuntimeCache::remember(
			'k2',
			900,
			static function () {
				return array( 'a' => 1 );
			}
		);
		// Poison the store: a second read would surface the poison.
		$this->transients[ RuntimeCache::TRANSIENT_PREFIX . 'k2' ] = array( 'a' => 'poisoned' );
		$value = RuntimeCache::remember(
			'k2',
			900,
			static function () {
				return array( 'a' => 'rebuilt' );
			}
		);
		$this->assertSame( 1, $value['a'] );
	}

	public function test_stored_value_is_served_without_rebuilding(): void {
		$this->transients[ RuntimeCache::TRANSIENT_PREFIX . 'k3' ] = array( 'stored' => true );
		$value = RuntimeCache::remember(
			'k3',
			900,
			static function () {
				return array( 'stored' => false );
			}
		);
		$this->assertTrue( $value['stored'] );
	}

	public function test_invalidate_bumps_generation_and_fires_action(): void {
		Actions\expectDone( 'wp_headless_runtime_cache_invalidated' )
			->once()
			->with( 'switch_theme' );
		$before = RuntimeCache::generation();
		RuntimeCache::invalidate( 'switch_theme' );
		$this->assertSame( $before + 1, RuntimeCache::generation() );
	}

	public function test_key_changes_when_generation_changes(): void {
		Actions\expectDone( 'wp_headless_runtime_cache_invalidated' )->once();
		$a = RuntimeCache::key();
		RuntimeCache::invalidate( 'test' );
		$this->assertNotSame( $a, RuntimeCache::key() );
	}

	public function test_watched_option_invalidates(): void {
		Actions\expectDone( 'wp_headless_runtime_cache_invalidated' )
			->twice();
		RuntimeCache::maybe_invalidate_on_option( 'blogname' );
		RuntimeCache::maybe_invalidate_on_option( 'thread_comments' );
	}

	public function test_unwatched_option_does_not_invalidate(): void {
		Actions\expectDone( 'wp_headless_runtime_cache_invalidated' )->never();
		RuntimeCache::maybe_invalidate_on_option( 'siteurl' );
		RuntimeCache::maybe_invalidate_on_option( 42 );
	}
}
