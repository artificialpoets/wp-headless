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

    /** Minimal wpdb double for table-backed storage. */
    private function mockWpdb( ?string $stored = null ) {
        $wpdb         = \Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( function ( $query ) {
            return $query;
        } );
        if ( null !== $stored ) {
            $wpdb->shouldReceive( 'get_var' )->andReturn( $stored );
        }
        $GLOBALS['wpdb'] = $wpdb;
        return $wpdb;
    }

    // --- get() ---

    public function test_get_returns_stored_markup(): void {
        $this->mockWpdb( '<div class="page">Hello</div>' );

        $this->assertSame( '<div class="page">Hello</div>', Prerender::get( 7 ) );
    }

    public function test_get_returns_null_for_empty_row(): void {
        $this->mockWpdb( '' );

        $this->assertNull( Prerender::get( 7 ) );
    }

    public function test_get_refuses_script_bearing_rows(): void {
        $this->mockWpdb( '<div><script>alert(1)</script></div>' );

        $this->assertNull( Prerender::get( 7 ) );
    }

    public function test_get_returns_null_for_invalid_post_id(): void {
        $this->assertNull( Prerender::get( 0 ) );
    }

    // --- store() ---

    public function test_store_strips_scripts_and_writes_row(): void {
        $wpdb    = $this->mockWpdb();
        $written = array();
        $wpdb->shouldReceive( 'replace' )->once()->andReturnUsing(
            function ( $table, $data ) use ( &$written ) {
                $written = $data;
                return 1;
            }
        );

        $stored = Prerender::store( 7, '<div>Keep</div><script src="x.js"></script>' );

        $this->assertTrue( $stored );
        $this->assertSame( '<div>Keep</div>', $written['html'] );
        $this->assertSame( 7, $written['post_id'] );
    }

    public function test_store_refuses_empty_and_oversized(): void {
        $wpdb = $this->mockWpdb();
        $wpdb->shouldReceive( 'replace' )->never();

        $this->assertFalse( Prerender::store( 7, '   ' ) );
        $this->assertFalse( Prerender::store( 7, str_repeat( 'a', Prerender::MAX_BYTES + 1 ) ) );
        $this->assertFalse( Prerender::store( 0, '<div>x</div>' ) );
    }

    // --- inject() ---

    public function test_inject_places_container_before_root(): void {
        Functions\when( 'is_singular' )->justReturn( true );
        Functions\when( 'get_queried_object_id' )->justReturn( 7 );
        $this->mockWpdb( '<main>Page</main>' );

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
        $this->mockWpdb( '<main>Blog</main>' );

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
        $this->mockWpdb( '' );
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
        $this->mockWpdb( '<main>Page</main>' );

        $html = '<body><div id="root"><p>occupied</p></div></body>';
        $this->assertSame( $html, $this->makeModule()->inject( $html ) );
    }

    // --- reusable-block cascade ---

    /**
     * wpdb double for the embedder cascade: arg-substituting prepare (the
     * LIKE needles must reach get_results), needle-routed embedder rows,
     * and delete capture.
     *
     * @param array $embedders_by_needle Map of '"ref":N}' needle → rows
     *                                   (each array{ID: int, post_type: string}).
     * @param array $deleted             Receives deleted post IDs in order.
     */
    private function mockCascadeWpdb( array $embedders_by_needle, array &$deleted ) {
        $wpdb         = \Mockery::mock( 'wpdb' );
        $wpdb->prefix = 'wp_';
        $wpdb->posts  = 'wp_posts';
        $wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static function ( $text ) {
            return $text;
        } );
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( static function ( $query, ...$args ) {
            foreach ( $args as $arg ) {
                $pos = strpos( $query, '%s' );
                if ( false === $pos ) {
                    break;
                }
                $query = substr_replace( $query, (string) $arg, $pos, 2 );
            }
            return $query;
        } );
        $wpdb->shouldReceive( 'delete' )->andReturnUsing( static function ( $table, $where ) use ( &$deleted ) {
            $deleted[] = (int) $where['post_id'];
            return 1;
        } );
        $GLOBALS['wpdb'] = $wpdb;
        return $wpdb;
    }

    /** Routes get_results by which block's needle the prepared query carries. */
    private function routeEmbedderQueries( $wpdb, array $embedders_by_needle, int $times ): void {
        $wpdb->shouldReceive( 'get_results' )->times( $times )->andReturnUsing(
            static function ( $query ) use ( $embedders_by_needle ) {
                foreach ( $embedders_by_needle as $needle => $rows ) {
                    if ( false !== strpos( $query, $needle ) ) {
                        return array_map( static function ( $row ) {
                            return (object) $row;
                        }, $rows );
                    }
                }
                return array();
            }
        );
    }

    private function makePost( string $type, string $status = 'publish' ) {
        $post              = \Mockery::mock( 'WP_Post' );
        $post->post_type   = $type;
        $post->post_status = $status;
        return $post;
    }

    public function test_reusable_block_save_invalidates_embedders(): void {
        Functions\when( 'wp_is_post_revision' )->justReturn( false );
        Functions\when( 'wp_is_post_autosave' )->justReturn( false );
        Monkey\Actions\expectDone( 'wp_headless_prerender_invalidated' )->times( 3 );

        $deleted = array();
        $map     = array(
            '"ref":9}' => array(
                array( 'ID' => 21, 'post_type' => 'page' ),
                array( 'ID' => 22, 'post_type' => 'page' ),
            ),
        );
        $wpdb    = $this->mockCascadeWpdb( $map, $deleted );
        $this->routeEmbedderQueries( $wpdb, $map, 1 );

        $this->makeModule()->invalidate_on_save( 9, $this->makePost( 'wp_block' ) );

        $this->assertSame( array( 9, 21, 22 ), $deleted );
    }

    public function test_embedder_walk_recurses_through_nested_blocks_once(): void {
        Functions\when( 'wp_is_post_revision' )->justReturn( false );
        Functions\when( 'wp_is_post_autosave' )->justReturn( false );

        // Block 9 is embedded by block 31; block 31 is embedded by page 41
        // and (cyclically) by block 9 again — the visited set must stop
        // the loop, and the nested block itself gets no invalidation: it
        // has no route of its own.
        $deleted = array();
        $map     = array(
            '"ref":9}'  => array( array( 'ID' => 31, 'post_type' => 'wp_block' ) ),
            '"ref":31}' => array(
                array( 'ID' => 9, 'post_type' => 'wp_block' ),
                array( 'ID' => 41, 'post_type' => 'page' ),
            ),
        );
        $wpdb    = $this->mockCascadeWpdb( $map, $deleted );
        $this->routeEmbedderQueries( $wpdb, $map, 2 );

        $this->makeModule()->invalidate_on_save( 9, $this->makePost( 'wp_block' ) );

        $this->assertSame( array( 9, 41 ), $deleted );
    }

    public function test_regular_save_skips_embedder_scan(): void {
        Functions\when( 'wp_is_post_revision' )->justReturn( false );
        Functions\when( 'wp_is_post_autosave' )->justReturn( false );

        $deleted = array();
        $wpdb    = $this->mockCascadeWpdb( array(), $deleted );
        $wpdb->shouldReceive( 'get_results' )->never();

        $this->makeModule()->invalidate_on_save( 7, $this->makePost( 'page' ) );

        $this->assertSame( array( 7 ), $deleted );
    }

    public function test_revision_save_invalidates_nothing(): void {
        Functions\when( 'wp_is_post_revision' )->justReturn( true );

        $deleted = array();
        $wpdb    = $this->mockCascadeWpdb( array(), $deleted );
        $wpdb->shouldReceive( 'delete' )->never();
        $wpdb->shouldReceive( 'get_results' )->never();

        $this->makeModule()->invalidate_on_save( 12, $this->makePost( 'wp_block' ) );

        $this->assertSame( array(), $deleted );
    }

    public function test_reusable_block_delete_cascades_to_embedders(): void {
        $deleted = array();
        $map     = array(
            '"ref":9}' => array( array( 'ID' => 21, 'post_type' => 'page' ) ),
        );
        $wpdb    = $this->mockCascadeWpdb( $map, $deleted );
        $this->routeEmbedderQueries( $wpdb, $map, 1 );

        $this->makeModule()->invalidate_on_delete( 9, $this->makePost( 'wp_block' ) );

        $this->assertSame( array( 9, 21 ), $deleted );
    }

    public function test_delete_without_post_object_skips_scan(): void {
        $deleted = array();
        $wpdb    = $this->mockCascadeWpdb( array(), $deleted );
        $wpdb->shouldReceive( 'get_results' )->never();

        $this->makeModule()->invalidate_on_delete( 7, null );

        $this->assertSame( array( 7 ), $deleted );
    }
}
