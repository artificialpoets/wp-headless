<?php
/**
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\StaticPublish;

use PHPUnit\Framework\TestCase;
use WPHeadless\StaticPublish\Keys;

final class KeysTest extends TestCase {
	public function test_the_root_is_index_html(): void {
		$this->assertSame( 'index.html', Keys::document( '/' ) );
	}

	public function test_a_directory_path_gets_an_index_html(): void {
		$this->assertSame( 'about-us/index.html', Keys::document( '/about-us/' ) );
		$this->assertSame( 'use-cases/search/index.html', Keys::document( '/use-cases/search' ) );
		$this->assertSame( 'category/news/page/2/index.html', Keys::document( '/category/news/page/2/' ) );
	}

	public function test_a_file_path_is_its_own_key(): void {
		$this->assertSame( 'robots.txt', Keys::document( '/robots.txt' ) );
		$this->assertSame( 'wp-sitemap-posts-post-1.xml', Keys::document( '/wp-sitemap-posts-post-1.xml' ) );
	}

	public function test_the_query_string_is_ignored(): void {
		$this->assertSame( 'about/index.html', Keys::document( '/about/?utm=x' ) );
	}

	public function test_route_data_mirrors_the_document_key(): void {
		$this->assertSame( '__data/routes/index.json', Keys::route_data( '/' ) );
		$this->assertSame( '__data/routes/about-us/index.json', Keys::route_data( '/about-us' ) );
		$this->assertSame( '__data/routes/use-cases/search/index.json', Keys::route_data( '/use-cases/search' ) );
	}

	public function test_safe_keys(): void {
		$this->assertTrue( Keys::is_safe( 'about/index.html' ) );
		$this->assertTrue( Keys::is_safe( Keys::STATUS ) );
	}

	public function test_unsafe_keys_are_refused(): void {
		foreach ( array( '', '/etc/passwd', '../wp-config.php', 'a/../../b', 'a//b', 'a/./b', "a\0b", 'a\\b' ) as $key ) {
			$this->assertFalse( Keys::is_safe( $key ), var_export( $key, true ) );
		}
	}
}
