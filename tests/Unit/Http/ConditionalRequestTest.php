<?php
/**
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPHeadless\Http\ConditionalRequest;

final class ConditionalRequestTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_unslash' )->alias(
			static function ( $value ) {
				return $value;
			}
		);
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_IF_NONE_MATCH'], $_SERVER['HTTP_IF_MODIFIED_SINCE'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	// --- matches_etag ---

	public function test_exact_match(): void {
		$this->assertTrue( ConditionalRequest::matches_etag( '"abc"', '"abc"' ) );
	}

	public function test_weak_request_validator_matches_strong_etag(): void {
		$this->assertTrue( ConditionalRequest::matches_etag( '"abc"', 'W/"abc"' ) );
	}

	public function test_weak_etag_matches_strong_request_validator(): void {
		$this->assertTrue( ConditionalRequest::matches_etag( 'W/"abc"', '"abc"' ) );
	}

	public function test_comma_list_containing_the_etag_matches(): void {
		$this->assertTrue( ConditionalRequest::matches_etag( '"b"', '"a", "b", "c"' ) );
	}

	public function test_star_matches_anything(): void {
		$this->assertTrue( ConditionalRequest::matches_etag( '"whatever"', '*' ) );
	}

	public function test_mismatch_is_false(): void {
		$this->assertFalse( ConditionalRequest::matches_etag( '"abc"', '"def"' ) );
	}

	public function test_empty_if_none_match_is_false(): void {
		$this->assertFalse( ConditionalRequest::matches_etag( '"abc"', '' ) );
	}

	public function test_empty_etag_is_false(): void {
		$this->assertFalse( ConditionalRequest::matches_etag( '', '"abc"' ) );
	}

	// --- modified_since ---

	public function test_equal_time_is_not_modified(): void {
		$this->assertFalse( ConditionalRequest::modified_since( 1000000000, gmdate( 'D, d M Y H:i:s', 1000000000 ) . ' GMT' ) );
	}

	public function test_older_mtime_is_not_modified(): void {
		$this->assertFalse( ConditionalRequest::modified_since( 999999000, gmdate( 'D, d M Y H:i:s', 1000000000 ) . ' GMT' ) );
	}

	public function test_newer_mtime_is_modified(): void {
		$this->assertTrue( ConditionalRequest::modified_since( 1000000001, gmdate( 'D, d M Y H:i:s', 1000000000 ) . ' GMT' ) );
	}

	public function test_garbage_date_is_modified(): void {
		$this->assertTrue( ConditionalRequest::modified_since( 1000000000, 'not-a-date' ) );
	}

	public function test_empty_date_is_modified(): void {
		$this->assertTrue( ConditionalRequest::modified_since( 1000000000, '' ) );
	}

	// --- not_modified precedence (RFC 7232 §6) ---

	public function test_matching_inm_yields_not_modified(): void {
		$_SERVER['HTTP_IF_NONE_MATCH'] = '"abc"';
		$this->assertTrue( ConditionalRequest::not_modified( '"abc"', 1000000000 ) );
	}

	public function test_inm_mismatch_ignores_a_matching_ims(): void {
		$_SERVER['HTTP_IF_NONE_MATCH']     = '"def"';
		$_SERVER['HTTP_IF_MODIFIED_SINCE'] = gmdate( 'D, d M Y H:i:s', 1000000000 ) . ' GMT';
		$this->assertFalse( ConditionalRequest::not_modified( '"abc"', 1000000000 ) );
	}

	public function test_ims_alone_decides_when_inm_absent(): void {
		$_SERVER['HTTP_IF_MODIFIED_SINCE'] = gmdate( 'D, d M Y H:i:s', 1000000000 ) . ' GMT';
		$this->assertTrue( ConditionalRequest::not_modified( '"abc"', 999999000 ) );
	}

	public function test_no_validators_means_modified(): void {
		$this->assertFalse( ConditionalRequest::not_modified( '"abc"', 1000000000 ) );
	}
}
