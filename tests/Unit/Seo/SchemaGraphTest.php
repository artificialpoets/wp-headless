<?php
/**
 * Tests for the schema.org @graph builder.
 *
 * SchemaGraph is exercised through its only public method, build(), with the
 * WordPress conditionals and lookups stubbed per view: envelope shape, config
 * and search/404 gating, stable @id anchors, kind-precedence (front page beats
 * singular), the piece filters, and breadcrumb trail ordering.
 *
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\Seo;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPHeadless\Config\Config;
use WPHeadless\Seo\SchemaGraph;

class SchemaGraphTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => trim( strip_tags( (string) $s ) ) );
		Functions\when( 'strip_shortcodes' )->alias( static fn( $s ) => (string) $s );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make( array $config_values = array() ): SchemaGraph {
		$values = array_merge(
			array(
				'seo.schema.enabled'              => true,
				'seo.schema.article_post_types'   => array( 'post' ),
				'seo.schema.search_action'        => true,
				'seo.schema.organization.name'    => '',
				'seo.schema.organization.logo'    => '',
				'seo.schema.organization.same_as' => array(),
			),
			$config_values
		);

		$config = $this->getMockBuilder( Config::class )->disableOriginalConstructor()->getMock();
		$config->method( 'get' )->willReturnCallback(
			static function ( $key, $default = null ) use ( $values ) {
				return array_key_exists( $key, $values ) ? $values[ $key ] : $default;
			}
		);

		return new SchemaGraph( $config );
	}

	private function make_post( array $overrides = array() ): \stdClass {
		$post                    = new \stdClass();
		$post->ID                = 10;
		$post->post_type         = 'post';
		$post->post_author       = 7;
		$post->post_title        = 'Hello World';
		$post->post_content      = 'Full content here.';
		$post->post_date_gmt     = '2026-01-02 03:04:05';
		$post->post_modified_gmt = '2026-01-03 04:05:06';

		foreach ( $overrides as $key => $value ) {
			$post->{$key} = $value;
		}

		return $post;
	}

	/**
	 * Stub the query conditionals; anything not overridden is a singular view.
	 */
	private function stub_query( $queried, array $flags = array() ): void {
		$flags = array_merge(
			array(
				'is_search'     => false,
				'is_404'        => false,
				'is_front_page' => false,
				'is_home'       => false,
				'is_singular'   => true,
			),
			$flags
		);

		foreach ( $flags as $conditional => $value ) {
			Functions\when( $conditional )->justReturn( $value );
		}

		Functions\when( 'get_queried_object' )->justReturn( $queried );
	}

	/**
	 * Stub the site-level lookups shared by every build.
	 */
	private function stub_site(): void {
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://x.test' . $path );
		Functions\when( 'get_bloginfo' )->alias(
			static fn( $key ) => 'name' === $key ? 'X Test' : ( 'description' === $key ? 'Just a test site.' : '' )
		);
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'get_theme_mod' )->justReturn( 0 );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'get_the_excerpt' )->justReturn( 'A hello excerpt.' );
	}

	/**
	 * Stub the post-level lookups for the default singular post view.
	 */
	private function stub_post_lookups(): void {
		Functions\when( 'wp_get_canonical_url' )->justReturn( 'https://x.test/hello-world/' );
		Functions\when( 'get_permalink' )->alias(
			static fn( $p = 0 ) => is_object( $p ) ? 'https://x.test/hello-world/' : 'https://x.test/page-' . $p . '/'
		);
		Functions\when( 'get_the_title' )->alias(
			static fn( $p = 0 ) => is_object( $p ) ? (string) $p->post_title : 'Page ' . $p
		);
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 33 );
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( array( 'https://x.test/uploads/hero.jpg', 1200, 630, false ) );
		Functions\when( 'wp_get_attachment_caption' )->justReturn( '' );
		Functions\when( 'get_the_author_meta' )->alias(
			static fn( $field, $id = 0 ) => 'display_name' === $field ? 'Jane Doe' : 'Bio of Jane.'
		);
		Functions\when( 'get_author_posts_url' )->justReturn( 'https://x.test/author/jane/' );
		Functions\when( 'get_avatar_url' )->justReturn( 'https://x.test/avatar-7.jpg' );
	}

	private function find_node( array $graph, string $type ): ?array {
		foreach ( $graph['@graph'] as $node ) {
			if ( ( $node['@type'] ?? '' ) === $type ) {
				return $node;
			}
		}
		return null;
	}

	// --- gating ---

	public function test_disabled_config_returns_null(): void {
		$this->assertNull( $this->make( array( 'seo.schema.enabled' => false ) )->build() );
	}

	public function test_search_returns_null(): void {
		Functions\when( 'is_search' )->justReturn( true );
		Functions\when( 'is_404' )->justReturn( false );

		$this->assertNull( $this->make()->build() );
	}

	// --- envelope ---

	public function test_singular_post_builds_graph_envelope(): void {
		$this->stub_query( $this->make_post() );
		$this->stub_site();
		$this->stub_post_lookups();

		$graph = $this->make()->build();

		$this->assertIsArray( $graph );
		$this->assertSame( 'https://schema.org', $graph['@context'] );
		$this->assertIsArray( $graph['@graph'] );
		$this->assertSame( array_values( $graph['@graph'] ), $graph['@graph'] ); // A JSON list, not a map.
		$this->assertCount( 7, $graph['@graph'] ); // org, website, webpage, breadcrumb, image, article, person.
		$this->assertSame( 'Organization', $graph['@graph'][0]['@type'] );

		$website = $this->find_node( $graph, 'WebSite' );
		$this->assertSame( 'https://x.test/?s={search_term_string}', $website['potentialAction']['target']['urlTemplate'] );
	}

	// --- @id anchors ---

	public function test_singular_post_nodes_carry_stable_anchors(): void {
		$this->stub_query( $this->make_post() );
		$this->stub_site();
		$this->stub_post_lookups();

		$graph = $this->make()->build();

		$organization = $this->find_node( $graph, 'Organization' );
		$website      = $this->find_node( $graph, 'WebSite' );
		$webpage      = $this->find_node( $graph, 'WebPage' );
		$breadcrumb   = $this->find_node( $graph, 'BreadcrumbList' );
		$image        = $this->find_node( $graph, 'ImageObject' );
		$article      = $this->find_node( $graph, 'Article' );
		$person       = $this->find_node( $graph, 'Person' );

		$this->assertSame( 'https://x.test/#organization', $organization['@id'] );
		$this->assertSame( 'https://x.test/#website', $website['@id'] );
		$this->assertSame( array( '@id' => 'https://x.test/#organization' ), $website['publisher'] );

		$this->assertSame( 'https://x.test/hello-world/#webpage', $webpage['@id'] );
		$this->assertSame( array( '@id' => 'https://x.test/#website' ), $webpage['isPartOf'] );
		$this->assertSame( array( '@id' => 'https://x.test/hello-world/#breadcrumb' ), $webpage['breadcrumb'] );
		$this->assertSame( array( '@id' => 'https://x.test/hello-world/#primaryimage' ), $webpage['primaryImageOfPage'] );

		$this->assertSame( 'https://x.test/hello-world/#breadcrumb', $breadcrumb['@id'] );
		$this->assertSame( 'https://x.test/hello-world/#primaryimage', $image['@id'] );

		$this->assertSame( 'https://x.test/hello-world/#article', $article['@id'] );
		$this->assertSame( array( '@id' => 'https://x.test/hello-world/#webpage' ), $article['mainEntityOfPage'] );
		$this->assertSame( array( '@id' => 'https://x.test/#organization' ), $article['publisher'] );
		$this->assertSame( array( '@id' => 'https://x.test/#/schema/person/7' ), $article['author'] );
		$this->assertSame( array( '@id' => 'https://x.test/hello-world/#primaryimage' ), $article['image'] );

		$this->assertSame( 'https://x.test/#/schema/person/7', $person['@id'] );
	}

	// --- kind precedence ---

	public function test_front_page_wins_when_singular_is_also_true(): void {
		$front = $this->make_post(
			array(
				'ID'         => 9,
				'post_type'  => 'page',
				'post_title' => 'Welcome',
			)
		);
		$this->stub_query( $front, array( 'is_front_page' => true, 'is_singular' => true ) );
		$this->stub_site();
		$this->stub_post_lookups();

		$graph = $this->make()->build();

		$webpage = $this->find_node( $graph, 'WebPage' );
		$this->assertSame( 'https://x.test/#webpage', $webpage['@id'] ); // Home canonical, not the page permalink.
		$this->assertSame( 'https://x.test/', $webpage['url'] );
		$this->assertNull( $this->find_node( $graph, 'Article' ) );        // front_page kind, not singular.
		$this->assertNull( $this->find_node( $graph, 'BreadcrumbList' ) ); // No trail on the front page.
	}

	// --- filters ---

	public function test_pieces_filter_added_node_survives_to_output(): void {
		$this->stub_query( $this->make_post() );
		$this->stub_site();
		$this->stub_post_lookups();

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null, ...$rest ) {
				if ( 'wp_headless_schema_pieces' === $hook ) {
					$value['custom'] = array(
						'@type' => 'Thing',
						'@id'   => 'https://x.test/#thing',
					);
				}
				return $value;
			}
		);

		$graph = $this->make()->build();

		$thing = $this->find_node( $graph, 'Thing' );
		$this->assertNotNull( $thing );
		$this->assertSame( 'https://x.test/#thing', $thing['@id'] );
	}

	public function test_piece_filter_returning_null_drops_the_node(): void {
		$this->stub_query( $this->make_post() );
		$this->stub_site();
		$this->stub_post_lookups();

		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null, ...$rest ) {
				if ( 'wp_headless_schema_piece' === $hook && 'website' === ( $rest[0] ?? '' ) ) {
					return null;
				}
				return $value;
			}
		);

		$graph = $this->make()->build();

		$this->assertNull( $this->find_node( $graph, 'WebSite' ) );
		$this->assertNotNull( $this->find_node( $graph, 'Organization' ) ); // Neighbors survive.
	}

	// --- breadcrumb ---

	public function test_breadcrumb_lists_ancestors_root_first_and_unlinks_last_item(): void {
		$page = $this->make_post(
			array(
				'ID'          => 40,
				'post_type'   => 'page',
				'post_author' => 0,
				'post_title'  => 'Child',
			)
		);
		$this->stub_query( $page );
		$this->stub_site();
		$this->stub_post_lookups();

		Functions\when( 'wp_get_canonical_url' )->justReturn( 'https://x.test/root/middle/child/' );
		Functions\when( 'get_post_ancestors' )->justReturn( array( 22, 11 ) ); // Closest parent first, core order.
		Functions\when( 'get_the_title' )->alias(
			static function ( $p = 0 ) {
				if ( is_object( $p ) ) {
					return (string) $p->post_title;
				}
				return 11 === $p ? 'Root' : 'Middle';
			}
		);
		Functions\when( 'get_permalink' )->alias(
			static function ( $p = 0 ) {
				if ( is_object( $p ) ) {
					return 'https://x.test/root/middle/child/';
				}
				return 11 === $p ? 'https://x.test/root/' : 'https://x.test/root/middle/';
			}
		);
		Functions\when( 'get_post_thumbnail_id' )->justReturn( 0 );

		$graph = $this->make()->build();

		$breadcrumb = $this->find_node( $graph, 'BreadcrumbList' );
		$this->assertNotNull( $breadcrumb );

		$items = $breadcrumb['itemListElement'];
		$this->assertCount( 4, $items );

		$this->assertSame( array( 1, 2, 3, 4 ), array_column( $items, 'position' ) );
		$this->assertSame( array( 'Home', 'Root', 'Middle', 'Child' ), array_column( $items, 'name' ) );

		$this->assertSame( 'https://x.test/', $items[0]['item'] );
		$this->assertSame( 'https://x.test/root/', $items[1]['item'] );
		$this->assertSame( 'https://x.test/root/middle/', $items[2]['item'] );
		$this->assertArrayNotHasKey( 'item', $items[3] ); // The current view is never linked.
	}
}
