<?php
/**
 * Tests for Cors::normalize_origin().
 *
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPHeadless\Config\Config;
use WPHeadless\Http\Cors;

/**
 * Expose protected normalize_origin() for testing.
 */
class ExposedCors extends Cors {
    public function exposeNormalizeOrigin( string $origin ): string {
        return $this->normalize_origin( $origin );
    }
}

class CorsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // wp_parse_url is a thin wrapper around parse_url — alias it.
        Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make(): ExposedCors {
        $config = $this->getMockBuilder( Config::class )
            ->disableOriginalConstructor()
            ->getMock();
        return new ExposedCors( $config );
    }

    public function test_basic_https_origin(): void {
        $this->assertSame( 'https://example.com', $this->make()->exposeNormalizeOrigin( 'https://example.com' ) );
    }

    public function test_trailing_slash_is_stripped(): void {
        $this->assertSame( 'https://example.com', $this->make()->exposeNormalizeOrigin( 'https://example.com/' ) );
    }

    public function test_port_is_preserved(): void {
        $this->assertSame( 'https://example.com:8080', $this->make()->exposeNormalizeOrigin( 'https://example.com:8080' ) );
    }

    public function test_http_scheme_is_preserved(): void {
        $this->assertSame( 'http://example.com', $this->make()->exposeNormalizeOrigin( 'http://example.com' ) );
    }

    public function test_subdomain_is_preserved(): void {
        $this->assertSame( 'https://app.example.com', $this->make()->exposeNormalizeOrigin( 'https://app.example.com' ) );
    }

    public function test_path_is_stripped(): void {
        $this->assertSame( 'https://example.com', $this->make()->exposeNormalizeOrigin( 'https://example.com/some/path' ) );
    }

    public function test_user_info_is_stripped(): void {
        $this->assertSame( 'https://example.com', $this->make()->exposeNormalizeOrigin( 'https://user:pass@example.com' ) );
    }

    public function test_invalid_url_returns_empty_string(): void {
        $this->assertSame( '', $this->make()->exposeNormalizeOrigin( 'not-a-url' ) );
    }

    public function test_empty_string_returns_empty_string(): void {
        $this->assertSame( '', $this->make()->exposeNormalizeOrigin( '' ) );
    }

    public function test_host_only_without_scheme_returns_empty_string(): void {
        // parse_url without a scheme returns host as path, not host component.
        $this->assertSame( '', $this->make()->exposeNormalizeOrigin( 'example.com' ) );
    }

    public function test_port_80_on_http_is_included(): void {
        // Port 80 is non-default for https but standard for http — parse_url returns it explicitly.
        $this->assertSame( 'http://example.com:80', $this->make()->exposeNormalizeOrigin( 'http://example.com:80' ) );
    }
}
