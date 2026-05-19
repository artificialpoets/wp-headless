<?php
/**
 * Tests for AssetProxy::cache_control_header().
 *
 * No WordPress stubs needed — the method is pure PHP regex.
 *
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\Runtime;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use WPHeadless\Config\Config;
use WPHeadless\Runtime\AssetProxy;

/**
 * Expose protected cache_control_header() for testing.
 */
class ExposedAssetProxy extends AssetProxy {
    public function exposeCacheControlHeader( string $filename ): string {
        return $this->cache_control_header( $filename );
    }
}

class AssetProxyTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeProxy(): ExposedAssetProxy {
        $config = $this->getMockBuilder( Config::class )
            ->disableOriginalConstructor()
            ->getMock();
        $theme_manager = $this->getMockBuilder( \WPHeadless\Theme\ThemeManager::class )
            ->disableOriginalConstructor()
            ->getMock();
        return new ExposedAssetProxy( $config, $theme_manager );
    }

    // --- Immutable (content-hash) filenames ---

    public function test_8_char_hex_hash_with_dash_is_immutable(): void {
        $proxy = $this->makeProxy();
        $this->assertSame(
            'public, max-age=31536000, immutable',
            $proxy->exposeCacheControlHeader( 'app-a1b2c3d4.js' )
        );
    }

    public function test_12_char_hex_hash_is_immutable(): void {
        $proxy = $this->makeProxy();
        $this->assertSame(
            'public, max-age=31536000, immutable',
            $proxy->exposeCacheControlHeader( 'app-a1b2c3d4ef56.js' )
        );
    }

    public function test_dot_separated_hash_is_immutable(): void {
        $proxy = $this->makeProxy();
        $this->assertSame(
            'public, max-age=31536000, immutable',
            $proxy->exposeCacheControlHeader( 'app.a1b2c3d4.js' )
        );
    }

    public function test_chunk_prefix_with_hash_is_immutable(): void {
        $proxy = $this->makeProxy();
        $this->assertSame(
            'public, max-age=31536000, immutable',
            $proxy->exposeCacheControlHeader( 'chunk-abc12345.css' )
        );
    }

    public function test_hash_is_case_insensitive(): void {
        $proxy = $this->makeProxy();
        $this->assertSame(
            'public, max-age=31536000, immutable',
            $proxy->exposeCacheControlHeader( 'app-A1B2C3D4.js' )
        );
    }

    public function test_runtime_bundle_with_hash_is_immutable(): void {
        $proxy = $this->makeProxy();
        $this->assertSame(
            'public, max-age=31536000, immutable',
            $proxy->exposeCacheControlHeader( 'runtime-abc12345.js' )
        );
    }

    // --- Short-lived (no hash) filenames ---

    public function test_index_html_is_short_lived(): void {
        $proxy = $this->makeProxy();
        $this->assertSame( 'public, max-age=3600', $proxy->exposeCacheControlHeader( 'index.html' ) );
    }

    public function test_plain_js_without_hash_is_short_lived(): void {
        $proxy = $this->makeProxy();
        $this->assertSame( 'public, max-age=3600', $proxy->exposeCacheControlHeader( 'main.js' ) );
    }

    public function test_7_char_hash_does_not_qualify_as_immutable(): void {
        // The regex requires 8+ hex chars; 7 chars should not match.
        $proxy = $this->makeProxy();
        $this->assertSame( 'public, max-age=3600', $proxy->exposeCacheControlHeader( 'app-a1b2c3d.js' ) );
    }

    public function test_empty_filename_is_short_lived(): void {
        $proxy = $this->makeProxy();
        $this->assertSame( 'public, max-age=3600', $proxy->exposeCacheControlHeader( '' ) );
    }

    public function test_css_without_hash_is_short_lived(): void {
        $proxy = $this->makeProxy();
        $this->assertSame( 'public, max-age=3600', $proxy->exposeCacheControlHeader( 'style.css' ) );
    }
}
