<?php
/**
 * Tests for RequestDataBuilder::normalize_path().
 *
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\Runtime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPHeadless\Runtime\RequestDataBuilder;

/**
 * Expose protected normalize_path() for testing.
 */
class ExposedRequestDataBuilder extends RequestDataBuilder {
    public function exposeNormalizePath( string $path ): string {
        return $this->normalize_path( $path );
    }
    public function exposeRobotsForResponse( array $response ): ?string {
        return $this->robots_for_response( $response );
    }
    public function exposeCurrentKind(): string {
        return $this->current_kind();
    }
}

class RequestDataBuilderTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // untrailingslashit is used inside normalize_path.
        Functions\when( 'untrailingslashit' )->alias( fn( string $s ) => rtrim( $s, '/\\' ) );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make(): ExposedRequestDataBuilder {
        return new ExposedRequestDataBuilder();
    }

    public function test_path_without_trailing_slash_gets_one(): void {
        $this->assertSame( '/about/', $this->make()->exposeNormalizePath( '/about' ) );
    }

    public function test_path_with_trailing_slash_is_idempotent(): void {
        $this->assertSame( '/about/', $this->make()->exposeNormalizePath( '/about/' ) );
    }

    public function test_path_without_leading_slash_gets_one(): void {
        $this->assertSame( '/about/', $this->make()->exposeNormalizePath( 'about' ) );
    }

    public function test_root_path_stays_as_single_slash(): void {
        $this->assertSame( '/', $this->make()->exposeNormalizePath( '/' ) );
    }

    public function test_empty_string_becomes_root(): void {
        $this->assertSame( '/', $this->make()->exposeNormalizePath( '' ) );
    }

    public function test_multi_segment_path_gets_trailing_slash(): void {
        $this->assertSame( '/blog/hello-world/', $this->make()->exposeNormalizePath( '/blog/hello-world' ) );
    }

    public function test_multi_segment_path_is_idempotent(): void {
        $this->assertSame( '/blog/hello-world/', $this->make()->exposeNormalizePath( '/blog/hello-world/' ) );
    }

    public function test_deeply_nested_path(): void {
        $this->assertSame( '/a/b/c/', $this->make()->exposeNormalizePath( '/a/b/c' ) );
    }

    // --- robots_for_response() ---

    public function test_robots_non_public_site_blocks_everything(): void {
        Functions\when( 'get_option' )->justReturn( 0 ); // blog_public = 0
        $this->assertSame( 'noindex, nofollow', $this->make()->exposeRobotsForResponse( array() ) );
    }

    public function test_robots_noindexes_search_results(): void {
        Functions\when( 'get_option' )->justReturn( 1 );
        $this->assertSame(
            'noindex, follow',
            $this->make()->exposeRobotsForResponse( array( 'is_search' => true ) )
        );
    }

    public function test_robots_noindexes_404(): void {
        Functions\when( 'get_option' )->justReturn( 1 );
        $this->assertSame(
            'noindex, follow',
            $this->make()->exposeRobotsForResponse( array( 'is_404' => true ) )
        );
    }

    public function test_robots_indexable_page_gets_image_preview(): void {
        Functions\when( 'get_option' )->justReturn( 1 );
        $this->assertSame(
            'max-image-preview:large',
            $this->make()->exposeRobotsForResponse( array( 'is_singular' => true ) )
        );
    }

    // --- current_kind() ---

    /**
     * Stub every conditional to false, then let the caller flip specific ones.
     *
     * @param array<string,bool> $true names of is_* functions that return true
     */
    private function stubConditionals( array $true = array() ): void {
        $conds = array(
            'is_404', 'is_front_page', 'is_search', 'is_attachment', 'is_singular',
            'is_post_type_archive', 'is_author', 'is_date', 'is_category', 'is_tag',
            'is_tax', 'is_home', 'is_archive',
        );
        foreach ( $conds as $fn ) {
            Functions\when( $fn )->justReturn( in_array( $fn, $true, true ) );
        }
    }

    public function test_current_kind_404_is_unresolved(): void {
        $this->stubConditionals( array( 'is_404' ) );
        $this->assertSame( 'unresolved', $this->make()->exposeCurrentKind() );
    }

    public function test_current_kind_front_page(): void {
        $this->stubConditionals( array( 'is_front_page' ) );
        $this->assertSame( 'front_page', $this->make()->exposeCurrentKind() );
    }

    public function test_current_kind_singular_uses_post_type(): void {
        $this->stubConditionals( array( 'is_singular' ) );
        Functions\when( 'get_post_type' )->justReturn( 'product' );
        $this->assertSame( 'product', $this->make()->exposeCurrentKind() );
    }

    public function test_current_kind_term_archive(): void {
        $this->stubConditionals( array( 'is_archive', 'is_category' ) );
        $this->assertSame( 'term_archive', $this->make()->exposeCurrentKind() );
    }

    public function test_current_kind_search(): void {
        $this->stubConditionals( array( 'is_search' ) );
        $this->assertSame( 'search', $this->make()->exposeCurrentKind() );
    }

    public function test_current_kind_defaults_to_home(): void {
        $this->stubConditionals();
        $this->assertSame( 'home', $this->make()->exposeCurrentKind() );
    }
}
