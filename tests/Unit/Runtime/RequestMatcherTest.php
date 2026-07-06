<?php
/**
 * Tests for RequestMatcher::should_serve_frontend() — the single gate that
 * decides whether the headless SPA takes over a request or WordPress renders
 * it natively.
 *
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\Runtime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPHeadless\Config\Config;
use WPHeadless\Runtime\RequestMatcher;
use WPHeadless\Theme\ThemeManager;

class RequestMatcherTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Default every stand-down conditional to "not this kind of request".
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'wp_is_json_request' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_trackback' )->justReturn( false );
		Functions\when( 'is_robots' )->justReturn( false );
		Functions\when( 'is_customize_preview' )->justReturn( false );
		// apply_filters( $tag, $value, ... ) → $value passthrough.
		Functions\when( 'apply_filters' )->alias(
			static fn( $tag, $value = null ) => $value
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string,string> $query_vars keyed by query var name
	 */
	private function makeMatcher( array $query_vars = array() ): RequestMatcher {
		Functions\when( 'get_query_var' )->alias(
			static fn( $key, $default = '' ) => $query_vars[ $key ] ?? $default
		);

		$config = $this->getMockBuilder( Config::class )->disableOriginalConstructor()->getMock();
		$config->method( 'is_enabled' )->willReturn( true );
		$config->method( 'frontend_available' )->willReturn( false );

		$theme_manager = $this->getMockBuilder( ThemeManager::class )->disableOriginalConstructor()->getMock();
		$theme_manager->method( 'has_build' )->willReturn( true );

		return new RequestMatcher( $config, $theme_manager );
	}

	public function test_ordinary_request_is_served_by_the_frontend(): void {
		$matcher = $this->makeMatcher();
		$this->assertTrue( $matcher->should_serve_frontend() );
	}

	public function test_sitemap_request_stands_down(): void {
		$matcher = $this->makeMatcher( array( 'sitemap' => 'posts' ) );
		$this->assertFalse(
			$matcher->should_serve_frontend(),
			'/wp-sitemap.xml carries the "sitemap" query var and must be left to core.'
		);
	}

	public function test_sitemap_stylesheet_request_stands_down(): void {
		$matcher = $this->makeMatcher( array( 'sitemap-stylesheet' => 'sitemap' ) );
		$this->assertFalse(
			$matcher->should_serve_frontend(),
			'The sitemap XSL stylesheet request must also be left to core.'
		);
	}

	public function test_feed_request_stands_down(): void {
		Functions\when( 'is_feed' )->justReturn( true );
		$matcher = $this->makeMatcher();
		$this->assertFalse( $matcher->should_serve_frontend() );
	}

	public function test_disabled_config_never_serves(): void {
		Functions\when( 'get_query_var' )->alias( static fn( $key, $default = '' ) => $default );

		$config = $this->getMockBuilder( Config::class )->disableOriginalConstructor()->getMock();
		$config->method( 'is_enabled' )->willReturn( false );
		$config->method( 'frontend_available' )->willReturn( false );
		$theme_manager = $this->getMockBuilder( ThemeManager::class )->disableOriginalConstructor()->getMock();
		$theme_manager->method( 'has_build' )->willReturn( true );

		$matcher = new RequestMatcher( $config, $theme_manager );
		$this->assertFalse( $matcher->should_serve_frontend() );
	}
}
