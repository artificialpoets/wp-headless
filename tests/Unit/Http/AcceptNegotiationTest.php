<?php
/**
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\Http;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPHeadless\Http\AcceptNegotiation;

final class AcceptNegotiationTest extends TestCase {

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
		unset( $_SERVER['HTTP_ACCEPT'], $_SERVER['CONTENT_TYPE'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	// --- prefers_json ---

	public function test_browser_navigation_is_not_a_json_client(): void {
		$this->assertFalse( AcceptNegotiation::prefers_json( 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8' ) );
	}

	public function test_json_ahead_of_html_is_a_json_client(): void {
		$this->assertTrue( AcceptNegotiation::prefers_json( 'application/json, text/html;q=0.9' ) );
	}

	public function test_json_alone_is_a_json_client(): void {
		$this->assertTrue( AcceptNegotiation::prefers_json( 'application/json' ) );
	}

	public function test_a_tie_goes_to_html(): void {
		// The request that used to get WordPress's native render, and with it
		// a CDN cache entry every visitor of the URL then read.
		$this->assertFalse( AcceptNegotiation::prefers_json( 'application/json, text/html' ) );
	}

	public function test_wildcards_and_empty_headers_get_html(): void {
		$this->assertFalse( AcceptNegotiation::prefers_json( '*/*' ) );
		$this->assertFalse( AcceptNegotiation::prefers_json( '' ) );
		$this->assertFalse( AcceptNegotiation::prefers_json( 'garbage' ) );
	}

	public function test_a_wildcard_below_json_still_prefers_json(): void {
		$this->assertTrue( AcceptNegotiation::prefers_json( 'application/json, */*;q=0.1' ) );
	}

	public function test_json_suffix_types_count_as_json(): void {
		$this->assertTrue( AcceptNegotiation::prefers_json( 'application/ld+json, text/html;q=0.5' ) );
	}

	public function test_the_most_specific_range_sets_the_quality(): void {
		// Both HTML types are refused outright, although */* would accept them.
		$this->assertTrue( AcceptNegotiation::prefers_json( 'text/html;q=0, application/xhtml+xml;q=0, */*;q=0.5' ) );
		// application/* covers JSON at 0.2; HTML is taken at 0.5 through */*.
		$this->assertFalse( AcceptNegotiation::prefers_json( 'application/*;q=0.2, */*;q=0.5' ) );
	}

	public function test_xhtml_counts_as_html(): void {
		$this->assertFalse( AcceptNegotiation::prefers_json( 'application/xhtml+xml, application/json;q=0.9' ) );
	}

	public function test_quality_parameters_are_read_case_insensitively_and_clamped(): void {
		$this->assertTrue( AcceptNegotiation::prefers_json( 'Text/HTML; Q=0.4, application/json; q=7' ) );
	}

	// --- is_json_type ---

	public function test_json_media_types(): void {
		$this->assertTrue( AcceptNegotiation::is_json_type( 'application/json; charset=utf-8' ) );
		$this->assertTrue( AcceptNegotiation::is_json_type( 'application/merge-patch+json' ) );
		$this->assertFalse( AcceptNegotiation::is_json_type( 'application/x-www-form-urlencoded' ) );
		$this->assertFalse( AcceptNegotiation::is_json_type( '' ) );
	}

	// --- is_json_client ---

	public function test_a_json_body_is_a_json_client_whatever_it_accepts(): void {
		$_SERVER['CONTENT_TYPE'] = 'application/json';
		$_SERVER['HTTP_ACCEPT']  = 'text/html';
		$this->assertTrue( AcceptNegotiation::is_json_client() );
	}

	public function test_a_form_post_from_a_browser_is_not_a_json_client(): void {
		$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
		$_SERVER['HTTP_ACCEPT']  = 'text/html,application/xhtml+xml,*/*;q=0.8';
		$this->assertFalse( AcceptNegotiation::is_json_client() );
	}

	public function test_no_headers_is_not_a_json_client(): void {
		$this->assertFalse( AcceptNegotiation::is_json_client() );
	}
}
