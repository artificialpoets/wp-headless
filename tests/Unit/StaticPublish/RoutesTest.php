<?php
/**
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\StaticPublish;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WPHeadless\StaticPublish\RedirectMap;
use WPHeadless\StaticPublish\Routes;

final class RoutesTest extends TestCase {
	use MockeryPHPUnitIntegration;

	/** @var array<int, array<string,mixed>> get_posts() calls. */
	private array $queries = array();

	/** @var array<string,mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'https://ex.test' . $path;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) {
				return array_key_exists( $name, $this->options ) ? $this->options[ $name ] : $default;
			}
		);
		Functions\when( 'get_permalink' )->alias(
			static function ( $id ) {
				return 'https://ex.test/' . array( 1 => 'home', 2 => 'about', 3 => 'blog', 7 => 'news/hello' )[ $id ] . '/';
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function posts_by_type( array $by_type ): void {
		Functions\when( 'get_post_types' )->justReturn( array_combine( array_keys( $by_type ), array_keys( $by_type ) ) );
		Functions\when( 'get_posts' )->alias(
			function ( $args ) use ( $by_type ) {
				$this->queries[] = $args;
				return $by_type[ $args['post_type'] ] ?? array();
			}
		);
	}

	public function test_only_published_posts_without_a_password_are_listed(): void {
		$this->posts_by_type(
			array(
				'page' => array( 2 ),
				'post' => array( 7 ),
			)
		);
		( new Routes() )->singulars();

		foreach ( $this->queries as $query ) {
			$this->assertSame( 'publish', $query['post_status'] );
			$this->assertFalse( $query['has_password'] );
		}
		$this->assertCount( 2, $this->queries );
	}

	public function test_singulars_map_permalinks_to_paths_and_skip_attachments(): void {
		$this->posts_by_type(
			array(
				'page'       => array( 2 ),
				'post'       => array( 7 ),
				'attachment' => array( 99 ),
			)
		);
		$routes = ( new Routes() )->singulars();

		$this->assertSame(
			array(
				array(
					'path' => '/about',
					'id'   => 2,
					'type' => 'page',
				),
				array(
					'path' => '/news/hello',
					'id'   => 7,
					'type' => 'post',
				),
			),
			$routes
		);
	}

	public function test_the_static_front_page_maps_to_the_root(): void {
		$this->options = array(
			'show_on_front' => 'page',
			'page_on_front' => 1,
		);
		$this->posts_by_type( array( 'page' => array( 1, 2 ) ) );
		$routes = ( new Routes() )->singulars();

		$this->assertSame( '/', $routes[0]['path'] );
		$this->assertSame( '/about', $routes[1]['path'] );
	}

	public function test_archives_carry_their_pagination(): void {
		$this->options = array(
			'show_on_front'  => 'page',
			'page_for_posts' => 3,
			'posts_per_page' => 10,
		);
		Functions\when( 'wp_count_posts' )->justReturn( (object) array( 'publish' => 25 ) );
		Functions\when( 'get_taxonomies' )->justReturn( array( 'category' => 'category', 'post_format' => 'post_format' ) );
		Functions\when( 'get_terms' )->justReturn( array( (object) array( 'count' => 11 ) ) );
		Functions\when( 'get_term_link' )->justReturn( 'https://ex.test/category/news/' );
		Functions\when( 'get_users' )->justReturn( array() );

		$this->assertSame(
			array( '/blog/', '/blog/page/2/', '/blog/page/3/', '/category/news/', '/category/news/page/2/' ),
			( new Routes() )->archives()
		);
	}

	public function test_a_posts_front_page_paginates_the_root(): void {
		$this->options = array( 'posts_per_page' => 10 );
		Functions\when( 'wp_count_posts' )->justReturn( (object) array( 'publish' => 12 ) );
		Functions\when( 'get_taxonomies' )->justReturn( array() );
		Functions\when( 'get_users' )->justReturn( array() );

		$this->assertSame( array( '/', '/page/2/' ), ( new Routes() )->archives() );
	}

	public function test_sitemap_children_are_same_host_xml_paths(): void {
		$xml = '<sitemapindex><sitemap><loc>https://ex.test/wp-sitemap-posts-post-1.xml</loc></sitemap>'
			. '<sitemap><loc> https://ex.test/wp-sitemap-taxonomies-category-1.xml </loc></sitemap>'
			. '<sitemap><loc>https://cdn.ex.test/other.xml</loc></sitemap>'
			. '<sitemap><loc>https://ex.test/about/</loc></sitemap></sitemapindex>';

		$this->assertSame(
			array( '/wp-sitemap-posts-post-1.xml', '/wp-sitemap-taxonomies-category-1.xml' ),
			Routes::sitemap_children( $xml, 'https://ex.test/' )
		);
	}

	public function test_redirect_map_holds_the_front_page_path_and_old_slugs(): void {
		$this->options = array(
			'show_on_front' => 'page',
			'page_on_front' => 1,
		);
		Functions\when( 'get_page_uri' )->justReturn( 'home' );
		Functions\when( 'get_post_types' )->justReturn( array( 'post' => 'post' ) );
		Functions\when( 'get_posts' )->justReturn( array( 7 ) );
		Functions\when( 'get_post_field' )->justReturn( 'hello' );
		Functions\when( 'get_post_meta' )->justReturn( array( 'hello-world', 'hello' ) );
		Functions\when( 'sanitize_title' )->returnArg();

		$this->assertSame(
			array(
				'/home/'             => 'https://ex.test/',
				'/news/hello-world/' => 'https://ex.test/news/hello/',
			),
			( new RedirectMap() )->build()
		);
	}

	public function test_redirect_map_filter_entries_are_normalized_and_the_root_dropped(): void {
		Functions\when( 'get_post_types' )->justReturn( array() );
		Functions\when( 'get_posts' )->justReturn( array() );
		Monkey\Filters\expectApplied( 'wp_headless_static_redirects' )->once()->andReturn(
			array(
				'old-pricing' => 'https://ex.test/pricing/',
				'/'           => 'https://elsewhere.test/',
				'/bad/'       => 42,
			)
		);

		$this->assertSame( array( '/old-pricing/' => 'https://ex.test/pricing/' ), ( new RedirectMap() )->build() );
	}
}
