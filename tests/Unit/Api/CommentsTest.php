<?php
/**
 * Tests for the Comments module's anonymous-comment gate.
 *
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\Api;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPHeadless\Api\Comments;

class CommentsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// wp_headless_allow_anonymous_comments passthrough (arg 2 = value).
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_allows_anonymous_when_registration_not_required(): void {
		Functions\when( 'get_option' )->justReturn( false ); // comment_registration off
		$comments = new Comments();
		$this->assertTrue( $comments->allow_anonymous_comments( false ) );
	}

	public function test_rejects_anonymous_when_registration_required(): void {
		Functions\when( 'get_option' )->justReturn( true ); // comment_registration on
		$comments = new Comments();
		// Core's default is false; we must not loosen it when login is required.
		$this->assertFalse( $comments->allow_anonymous_comments( false ) );
	}

	public function test_preserves_explicit_allow_when_registration_required(): void {
		Functions\when( 'get_option' )->justReturn( true );
		$comments = new Comments();
		// If some other filter already allowed it, we pass that through.
		$this->assertTrue( $comments->allow_anonymous_comments( true ) );
	}
}
