<?php
/**
 * Tests for Runtime\Prerender — storage hygiene + document injection.
 *
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\Runtime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPHeadless\Config\Config;
use WPHeadless\Runtime\Prerender;

class PrerenderTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        // Mockery expectations (Functions\expect) verify in
        // Monkey\tearDown but do not count as PHPUnit assertions —
        // credit them so expectation-only tests are not "risky".
        $this->addToAssertionCount( \Mockery::getContainer()->mockery_getExpectationCount() );
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeModule( array $config_values = array() ): Prerender {
        $config = $this->getMockBuilder( Config::class )
            ->disableOriginalConstructor()
            ->getMock();
        $config->method( 'get' )->willReturnCallback(
            static function ( $key, $default = null ) use ( $config_values ) {
                return array_key_exists( $key, $config_values ) ? $config_values[ $key ] : $default;
            }
        );

        return new Prerender( $config );
    }

    // --- get() ---

    public function test_get_returns_stored_markup(): void {
        Functions\when( 'get_post_meta' )->justReturn( '<div class="page">Hello</div>' );

        $this->assertSame( '<div class="page">Hello</div>', Prerender::get( 7 ) );
    }

    public function test_get_returns_null_for_empty_meta(): void {
        Functions\when( 'get_post_meta' )->justReturn( '' );

        $this->assertNull( Prerender::get( 7 ) );
    }

    public function test_get_refuses_script_bearing_meta(): void {
        Functions\when( 'get_post_meta' )->justReturn( '<div><script>alert(1)</script></div>' );

        $this->assertNull( Prerender::get( 7 ) );
    }

    public function test_get_returns_null_for_invalid_post_id(): void {
        $this->assertNull( Prerender::get( 0 ) );
    }

    // --- store() ---

    public function test_store_strips_scripts_and_slashes(): void {
        $written = array();
        Functions\when( 'wp_slash' )->returnArg();
        Functions\when( 'update_post_meta' )->alias(
            function ( $post_id, $key, $value ) use ( &$written ) {
                $written[ $key ] = $value;
                return true;
            }
        );

        $stored = Prerender::store( 7, '<div>Keep</div><script src="x.js"></script>' );

        $this->assertTrue( $stored );
        $this->assertSame( '<div>Keep</div>', $written[ Prerender::META_KEY ] );
        $this->assertArrayHasKey( Prerender::META_TIME, $written );
    }

    public function test_store_refuses_empty_and_oversized(): void {
        Functions\when( 'wp_slash' )->returnArg();
        Functions\expect( 'update_post_meta' )->never();

        $this->assertFalse( Prerender::store( 7, '   ' ) );
        $this->assertFalse( Prerender::store( 7, str_repeat( 'a', Prerender::MAX_BYTES + 1 ) ) );
        $this->assertFalse( Prerender::store( 0, '<div>x</div>' ) );
    }

    // --- inject() ---

    public function test_inject_places_container_before_root(): void {
        Functions\when( 'is_singular' )->justReturn( true );
        Functions\when( 'get_queried_object_id' )->justReturn( 7 );
        Functions\when( 'get_post_meta' )->justReturn( '<main>Page</main>' );

        $html = '<body><div id="root"></div></body>';
        $out  = $this->makeModule()->inject( $html );

        $this->assertSame(
            '<body><div id="' . Prerender::CONTAINER_ID . '"><main>Page</main></div><div id="root"></div></body>',
            $out
        );
    }

    public function test_inject_serves_posts_page_via_is_home(): void {
        Functions\when( 'is_singular' )->justReturn( false );
        Functions\when( 'is_home' )->justReturn( true );
        Functions\when( 'get_option' )->justReturn( 9 );
        Functions\when( 'get_post_meta' )->justReturn( '<main>Blog</main>' );

        $out = $this->makeModule()->inject( '<body><div id="root"></div></body>' );

        $this->assertStringContainsString( 'id="' . Prerender::CONTAINER_ID . '"', $out );
        $this->assertStringContainsString( '<main>Blog</main>', $out );
    }

    public function test_inject_skips_non_singular_and_missing_snapshot(): void {
        Functions\when( 'is_singular' )->justReturn( false );
        Functions\when( 'is_home' )->justReturn( false );
        $html = '<body><div id="root"></div></body>';
        $this->assertSame( $html, $this->makeModule()->inject( $html ) );

        Functions\when( 'is_singular' )->justReturn( true );
        Functions\when( 'get_queried_object_id' )->justReturn( 7 );
        Functions\when( 'get_post_meta' )->justReturn( '' );
        $this->assertSame( $html, $this->makeModule()->inject( $html ) );
    }

    // --- queue_regeneration() ---

    public function test_queue_schedules_debounced_regeneration(): void {
        Functions\expect( 'wp_next_scheduled' )
            ->once()
            ->with( 'wp_headless_prerender_regenerate', array( '7' ) )
            ->andReturn( false );
        Functions\expect( 'wp_schedule_single_event' )
            ->once()
            ->with( \Mockery::type( 'int' ), 'wp_headless_prerender_regenerate', array( '7' ) );

        $this->makeModule()->queue_regeneration( 7 );
    }

    public function test_queue_dedupes_pending_events_and_maps_full_flush(): void {
        Functions\expect( 'wp_next_scheduled' )
            ->once()
            ->with( 'wp_headless_prerender_regenerate', array( 'all' ) )
            ->andReturn( time() + 5 );
        Functions\expect( 'wp_schedule_single_event' )->never();

        $this->makeModule()->queue_regeneration( null );
    }

    public function test_queue_respects_auto_regenerate_off(): void {
        Functions\expect( 'wp_next_scheduled' )->never();
        Functions\expect( 'wp_schedule_single_event' )->never();

        $this->makeModule( array( 'modules.prerender.auto_regenerate' => false ) )->queue_regeneration( 7 );
    }

    public function test_inject_requires_empty_root_needle(): void {
        Functions\when( 'is_singular' )->justReturn( true );
        Functions\when( 'get_queried_object_id' )->justReturn( 7 );
        Functions\when( 'get_post_meta' )->justReturn( '<main>Page</main>' );

        $html = '<body><div id="root"><p>occupied</p></div></body>';
        $this->assertSame( $html, $this->makeModule()->inject( $html ) );
    }
}
