<?php
/**
 * Tests for MenuEndpoint::build_tree().
 *
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPHeadless\Api\MenuEndpoint;
use WPHeadless\Config\Config;

/**
 * Expose protected build_tree() for testing.
 */
class ExposedMenuEndpoint extends MenuEndpoint {
    public function exposeBuildTree( array $items ): array {
        return $this->build_tree( $items );
    }
}

class MenuEndpointTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // apply_filters('wp_headless_menu_item', $item, $child_id) — return the item (arg 2).
        Functions\when( 'apply_filters' )->returnArg( 2 );
        // get_post_meta is called for item description — return empty string.
        Functions\when( 'get_post_meta' )->justReturn( '' );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make(): ExposedMenuEndpoint {
        $config = $this->getMockBuilder( Config::class )
            ->disableOriginalConstructor()
            ->getMock();
        return new ExposedMenuEndpoint( $config );
    }

    /**
     * Build a stdClass menu item with sensible defaults, allowing overrides.
     */
    private function makeItem( array $overrides = [] ): \stdClass {
        $defaults = [
            'ID'               => 1,
            'menu_item_parent' => '0',
            'title'            => 'Item',
            'url'              => 'https://example.com/',
            'target'           => '',
            'classes'          => [],
            'description'      => '',
            'type'             => 'custom',
            'object'           => 'custom',
            'object_id'        => 0,
        ];
        return (object) array_merge( $defaults, $overrides );
    }

    // -------------------------------------------------------------------------

    public function test_empty_items_returns_empty_array(): void {
        $tree = $this->make()->exposeBuildTree( [] );
        $this->assertSame( [], $tree );
    }

    public function test_single_top_level_item(): void {
        $items = [ $this->makeItem( [ 'ID' => 10, 'menu_item_parent' => '0', 'title' => 'Home', 'url' => 'https://example.com/' ] ) ];
        $tree  = $this->make()->exposeBuildTree( $items );

        $this->assertCount( 1, $tree );
        $this->assertSame( 10, $tree[0]['id'] );
        $this->assertSame( 'Home', $tree[0]['title'] );
        $this->assertSame( 'https://example.com/', $tree[0]['url'] );
        $this->assertSame( [], $tree[0]['children'] );
    }

    public function test_two_top_level_items(): void {
        $items = [
            $this->makeItem( [ 'ID' => 10, 'menu_item_parent' => '0', 'title' => 'Home' ] ),
            $this->makeItem( [ 'ID' => 11, 'menu_item_parent' => '0', 'title' => 'About' ] ),
        ];
        $tree = $this->make()->exposeBuildTree( $items );
        $this->assertCount( 2, $tree );
        $this->assertSame( 'Home', $tree[0]['title'] );
        $this->assertSame( 'About', $tree[1]['title'] );
    }

    public function test_parent_child_nesting(): void {
        $items = [
            $this->makeItem( [ 'ID' => 10, 'menu_item_parent' => '0', 'title' => 'About' ] ),
            $this->makeItem( [ 'ID' => 11, 'menu_item_parent' => '10', 'title' => 'Team' ] ),
        ];
        $tree = $this->make()->exposeBuildTree( $items );

        $this->assertCount( 1, $tree );
        $this->assertSame( 'About', $tree[0]['title'] );
        $this->assertCount( 1, $tree[0]['children'] );
        $this->assertSame( 'Team', $tree[0]['children'][0]['title'] );
        $this->assertSame( [], $tree[0]['children'][0]['children'] );
    }

    public function test_three_levels_deep(): void {
        $items = [
            $this->makeItem( [ 'ID' => 10, 'menu_item_parent' => '0',  'title' => 'About' ] ),
            $this->makeItem( [ 'ID' => 11, 'menu_item_parent' => '10', 'title' => 'Team' ] ),
            $this->makeItem( [ 'ID' => 12, 'menu_item_parent' => '11', 'title' => 'Leadership' ] ),
        ];
        $tree = $this->make()->exposeBuildTree( $items );

        $this->assertSame( 'Leadership', $tree[0]['children'][0]['children'][0]['title'] );
    }

    public function test_html_entities_in_title_are_decoded(): void {
        $items = [ $this->makeItem( [ 'ID' => 10, 'menu_item_parent' => '0', 'title' => 'Home &amp; Away' ] ) ];
        $tree  = $this->make()->exposeBuildTree( $items );
        $this->assertSame( 'Home & Away', $tree[0]['title'] );
    }

    public function test_empty_string_classes_are_filtered_out(): void {
        $items = [ $this->makeItem( [ 'ID' => 10, 'menu_item_parent' => '0', 'classes' => [ 'active', '', 'menu-item' ] ] ) ];
        $tree  = $this->make()->exposeBuildTree( $items );
        $this->assertSame( [ 'active', 'menu-item' ], $tree[0]['classes'] );
    }

    public function test_classes_array_is_reindexed(): void {
        $items = [ $this->makeItem( [ 'ID' => 10, 'menu_item_parent' => '0', 'classes' => [ '', 'active' ] ] ) ];
        $tree  = $this->make()->exposeBuildTree( $items );
        // After filtering empty, the array should start at index 0.
        $this->assertArrayHasKey( 0, $tree[0]['classes'] );
        $this->assertSame( 'active', $tree[0]['classes'][0] );
    }

    public function test_item_structure_contains_expected_keys(): void {
        $items = [ $this->makeItem( [ 'ID' => 10, 'menu_item_parent' => '0' ] ) ];
        $tree  = $this->make()->exposeBuildTree( $items );
        $keys  = array_keys( $tree[0] );
        foreach ( [ 'id', 'title', 'url', 'target', 'classes', 'description', 'type', 'object', 'object_id', 'children' ] as $key ) {
            $this->assertContains( $key, $keys );
        }
    }
}
