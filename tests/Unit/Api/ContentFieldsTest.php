<?php
/**
 * Tests for ContentFields post-type discovery.
 *
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPHeadless\Api\ContentFields;
use WPHeadless\Config\Config;

class ExposedContentFields extends ContentFields {
	public function exposeDiscoverablePostTypes(): array {
		return $this->discoverable_post_types();
	}
}

class ContentFieldsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make(): ExposedContentFields {
		$config = $this->getMockBuilder( Config::class )->disableOriginalConstructor()->getMock();
		return new ExposedContentFields( $config );
	}

	public function test_includes_public_rest_cpts_and_excludes_attachment(): void {
		Functions\when( 'get_post_types' )->justReturn(
			array(
				'post'       => 'post',
				'page'       => 'page',
				'book'       => 'book',
				'attachment' => 'attachment',
			)
		);

		$types = $this->make()->exposeDiscoverablePostTypes();

		$this->assertContains( 'post', $types );
		$this->assertContains( 'page', $types );
		$this->assertContains( 'book', $types, 'A public REST-enabled CPT must be enriched.' );
		$this->assertNotContains( 'attachment', $types, 'Attachments are media, not editorial content.' );
	}

	public function test_returns_a_list_not_a_map(): void {
		Functions\when( 'get_post_types' )->justReturn( array( 'post' => 'post', 'page' => 'page' ) );
		$types = $this->make()->exposeDiscoverablePostTypes();
		$this->assertSame( array( 'post', 'page' ), $types );
	}
}
