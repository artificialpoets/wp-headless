<?php
/**
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPHeadless\Config\Config;
use WPHeadless\Http\CachePolicy;

class ExposedCachePolicy extends CachePolicy {
	public function exposeCacheHeaders( bool $is_404, bool $is_logged_in, bool $has_cookies, bool $nonce_is_per_visitor ): array {
		return $this->cache_headers( $is_404, $is_logged_in, $has_cookies, $nonce_is_per_visitor );
	}

	public function exposeHasWpCookies( array $names ): bool {
		return $this->has_wp_cookies( $names );
	}
}

final class CachePolicyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $values Config overrides keyed by path.
	 */
	private function policy( array $values = array() ): ExposedCachePolicy {
		$config = $this->getMockBuilder( Config::class )
			->disableOriginalConstructor()
			->getMock();
		$config->method( 'get' )->willReturnCallback(
			static function ( $key, $default = null ) use ( $values ) {
				return array_key_exists( $key, $values ) ? $values[ $key ] : $default;
			}
		);
		return new ExposedCachePolicy( $config );
	}

	// --- The decision table ---

	public function test_anonymous_cookie_free_shared_nonce_gets_public_policy(): void {
		$headers = $this->policy()->exposeCacheHeaders( false, false, false, false );
		$this->assertSame( 'public, max-age=60, s-maxage=300, stale-while-revalidate=3600', $headers['Cache-Control'] );
		$this->assertSame( 'Cookie', $headers['Vary'] );
	}

	public function test_logged_in_is_private(): void {
		$headers = $this->policy()->exposeCacheHeaders( false, true, false, false );
		$this->assertSame( 'private, no-store, max-age=0', $headers['Cache-Control'] );
		$this->assertArrayNotHasKey( 'Vary', $headers );
	}

	public function test_cookie_carrier_is_private(): void {
		$headers = $this->policy()->exposeCacheHeaders( false, false, true, false );
		$this->assertSame( 'private, no-store, max-age=0', $headers['Cache-Control'] );
	}

	public function test_per_visitor_nonce_stands_down_to_private(): void {
		$headers = $this->policy()->exposeCacheHeaders( false, false, false, true );
		$this->assertSame( 'private, no-store, max-age=0', $headers['Cache-Control'] );
	}

	public function test_404_gets_zero_max_age_with_edge_absorption(): void {
		$headers = $this->policy()->exposeCacheHeaders( true, false, false, false );
		$this->assertSame( 'public, max-age=0, s-maxage=60', $headers['Cache-Control'] );
		$this->assertSame( 'Cookie', $headers['Vary'] );
	}

	public function test_logged_in_404_is_private(): void {
		$headers = $this->policy()->exposeCacheHeaders( true, true, false, false );
		$this->assertSame( 'private, no-store, max-age=0', $headers['Cache-Control'] );
	}

	// --- Config tuning + clamps ---

	public function test_config_values_are_honored(): void {
		$headers = $this->policy(
			array(
				'modules.cache.max_age'                => 120,
				'modules.cache.s_maxage'               => 900,
				'modules.cache.stale_while_revalidate' => 7200,
			)
		)->exposeCacheHeaders( false, false, false, false );
		$this->assertSame( 'public, max-age=120, s-maxage=900, stale-while-revalidate=7200', $headers['Cache-Control'] );
	}

	public function test_max_age_and_s_maxage_are_clamped_below_the_nonce_tick(): void {
		$headers = $this->policy(
			array(
				'modules.cache.max_age'  => 86400,
				'modules.cache.s_maxage' => 86400,
			)
		)->exposeCacheHeaders( false, false, false, false );
		$this->assertSame( 'public, max-age=3600, s-maxage=21600, stale-while-revalidate=3600', $headers['Cache-Control'] );
	}

	public function test_not_found_s_maxage_is_configurable(): void {
		$headers = $this->policy( array( 'modules.cache.not_found_s_maxage' => 10 ) )
			->exposeCacheHeaders( true, false, false, false );
		$this->assertSame( 'public, max-age=0, s-maxage=10', $headers['Cache-Control'] );
	}

	// --- Cookie-name matcher ---

	public function test_wp_identity_cookie_names_match(): void {
		$policy = $this->policy();
		$this->assertTrue( $policy->exposeHasWpCookies( array( 'wordpress_logged_in_abc' ) ) );
		$this->assertTrue( $policy->exposeHasWpCookies( array( 'wordpress_sec_x' ) ) );
		$this->assertTrue( $policy->exposeHasWpCookies( array( 'wordpress_test_cookie' ) ) );
		$this->assertTrue( $policy->exposeHasWpCookies( array( 'wp-postpass_x' ) ) );
		$this->assertTrue( $policy->exposeHasWpCookies( array( 'wp-settings-1' ) ) );
		$this->assertTrue( $policy->exposeHasWpCookies( array( 'comment_author_x' ) ) );
	}

	public function test_unrelated_cookie_names_do_not_match(): void {
		$policy = $this->policy();
		$this->assertFalse( $policy->exposeHasWpCookies( array( '_ga', 'phpsessid', 'cf_clearance' ) ) );
		$this->assertFalse( $policy->exposeHasWpCookies( array() ) );
	}
}
