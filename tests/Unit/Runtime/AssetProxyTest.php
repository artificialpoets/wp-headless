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
 * Expose protected cache_control_header() and resolve_file_path() for testing.
 */
class ExposedAssetProxy extends AssetProxy {
    public function exposeCacheControlHeader( string $filename ): string {
        return $this->cache_control_header( $filename );
    }

    public function exposeResolveFilePath( string $relative_path ): string {
        return $this->resolve_file_path( $relative_path );
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

    // --- Path traversal / arbitrary file read guard (security regression) ---

    /**
     * Build a proxy whose dist root is a real temp fixture:
     *   {tmp}/dist/assets/app.js   (legitimate asset)
     *   {tmp}/secret.txt           (sibling OUTSIDE dist — traversal target)
     *
     * @return array{0: ExposedAssetProxy, 1: string} proxy + tmp base dir
     */
    private function makeProxyWithFixture(): array {
        $base = sys_get_temp_dir() . '/wph-assetproxy-' . uniqid( '', true );
        $dist = $base . '/dist';
        mkdir( $dist . '/assets', 0777, true );
        file_put_contents( $dist . '/assets/app.js', 'console.log(1)' );
        file_put_contents( $base . '/secret.txt', 'DB_PASSWORD=hunter2' );

        // Pass-through stubs for the WP path helpers used by resolve_file_path().
        Monkey\Functions\when( 'wp_normalize_path' )->alias(
            static fn( $p ) => str_replace( '\\', '/', (string) $p )
        );
        Monkey\Functions\when( 'trailingslashit' )->alias(
            static fn( $p ) => rtrim( (string) $p, '/\\' ) . '/'
        );
        Monkey\Functions\when( 'untrailingslashit' )->alias(
            static fn( $p ) => rtrim( (string) $p, '/\\' )
        );

        $config = $this->getMockBuilder( Config::class )
            ->disableOriginalConstructor()
            ->getMock();
        $theme_manager = $this->getMockBuilder( \WPHeadless\Theme\ThemeManager::class )
            ->disableOriginalConstructor()
            ->getMock();
        $theme_manager->method( 'resolve_dist_path' )->willReturn( $dist );

        return array( new ExposedAssetProxy( $config, $theme_manager ), $base );
    }

    private function rmrf( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ( $items as $item ) {
            $item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
        }
        rmdir( $dir );
    }

    public function test_legitimate_nested_asset_resolves(): void {
        [ $proxy, $base ] = $this->makeProxyWithFixture();
        try {
            $resolved = $proxy->exposeResolveFilePath( 'assets/app.js' );
            $this->assertStringEndsWith( 'dist/assets/app.js', str_replace( '\\', '/', $resolved ) );
        } finally {
            $this->rmrf( $base );
        }
    }

    public function test_dot_dot_traversal_is_rejected(): void {
        [ $proxy, $base ] = $this->makeProxyWithFixture();
        try {
            // Would escape to {tmp}/secret.txt without the guard.
            $this->assertSame( '', $proxy->exposeResolveFilePath( '../secret.txt' ) );
        } finally {
            $this->rmrf( $base );
        }
    }

    public function test_deep_dot_dot_traversal_to_wp_config_is_rejected(): void {
        [ $proxy, $base ] = $this->makeProxyWithFixture();
        try {
            $this->assertSame( '', $proxy->exposeResolveFilePath( '../../../../wp-config.php' ) );
        } finally {
            $this->rmrf( $base );
        }
    }

    public function test_url_encoded_traversal_is_rejected(): void {
        [ $proxy, $base ] = $this->makeProxyWithFixture();
        try {
            // %2e%2e%2f decodes to ../ — proves rawurldecode happens before the guard.
            $this->assertSame( '', $proxy->exposeResolveFilePath( '%2e%2e%2fsecret.txt' ) );
        } finally {
            $this->rmrf( $base );
        }
    }

    public function test_traversal_within_a_deeper_segment_is_rejected(): void {
        [ $proxy, $base ] = $this->makeProxyWithFixture();
        try {
            $this->assertSame( '', $proxy->exposeResolveFilePath( 'assets/../../secret.txt' ) );
        } finally {
            $this->rmrf( $base );
        }
    }

    public function test_missing_file_returns_empty(): void {
        [ $proxy, $base ] = $this->makeProxyWithFixture();
        try {
            $this->assertSame( '', $proxy->exposeResolveFilePath( 'assets/nope.js' ) );
        } finally {
            $this->rmrf( $base );
        }
    }
}
