<?php
/**
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\StaticPublish;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WPHeadless\StaticPublish\Fetcher;

final class FetcherTest extends TestCase {
	use MockeryPHPUnitIntegration;

	/** @var array{0:string, 1:array<string,mixed>}|null */
	private ?array $request = null;

	/** @var mixed */
	private $response;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'is_wp_error' )->alias(
			static function ( $thing ) {
				return $thing instanceof \Exception;
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static function ( $response ) {
				return $response['response']['code'];
			}
		);
		Functions\when( 'wp_remote_retrieve_headers' )->alias(
			static function ( $response ) {
				return $response['headers'];
			}
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static function ( $response ) {
				return $response['body'];
			}
		);
		$this->response = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(
				'Content-Type'  => 'text/html; charset=UTF-8',
				'Cache-Control' => 'public, max-age=60',
			),
			'body'     => '<div id="root"></div>',
		);
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url, $args ) {
				$this->request = array( $url, $args );
				return $this->response;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_reads_as_an_anonymous_browser(): void {
		( new Fetcher( 'https://ex.test/' ) )->get( '/about/' );

		list( $url, $args ) = $this->request;
		$this->assertSame( 'https://ex.test/about/', $url );
		$this->assertSame( Fetcher::ACCEPT_HTML, $args['headers']['Accept'] );
		$this->assertSame( array(), $args['cookies'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertSame( '1', $args['headers'][ Fetcher::HEADER ] );
	}

	public function test_the_accept_header_never_names_json(): void {
		// An Accept listing application/json makes wp_is_json_request()
		// true, and wp-headless stands down for it.
		$this->assertStringNotContainsString( 'json', Fetcher::ACCEPT_HTML );
		$this->assertStringStartsWith( 'text/html', Fetcher::ACCEPT_HTML );
	}

	public function test_an_origin_gets_the_public_host_and_proto(): void {
		( new Fetcher( 'https://www.ex.test/', 'http://127.0.0.1:8080/' ) )->get( '/about/' );

		list( $url, $args ) = $this->request;
		$this->assertSame( 'http://127.0.0.1:8080/about/', $url );
		$this->assertSame( 'www.ex.test', $args['headers']['Host'] );
		$this->assertSame( 'https', $args['headers']['X-Forwarded-Proto'] );
	}

	public function test_an_origin_keeps_the_public_port_in_host(): void {
		( new Fetcher( 'http://localhost:8888/', 'http://127.0.0.1' ) )->get( '/' );

		$this->assertSame( 'localhost:8888', $this->request[1]['headers']['Host'] );
		$this->assertArrayNotHasKey( 'X-Forwarded-Proto', $this->request[1]['headers'] );
	}

	public function test_returns_status_lowercased_headers_and_body(): void {
		$res = ( new Fetcher( 'https://ex.test/' ) )->get( '/' );

		$this->assertSame( 200, $res['status'] );
		$this->assertSame( 'public, max-age=60', $res['headers']['cache-control'] );
		$this->assertSame( 'text/html; charset=UTF-8', $res['headers']['content-type'] );
		$this->assertSame( '<div id="root"></div>', $res['body'] );
	}

	public function test_a_redirect_is_returned_not_followed(): void {
		$this->response = array(
			'response' => array( 'code' => 301 ),
			'headers'  => array( 'Location' => 'https://ex.test/new/' ),
			'body'     => '',
		);
		$res = ( new Fetcher( 'https://ex.test/' ) )->get( '/old/' );

		$this->assertSame( 301, $res['status'] );
		$this->assertSame( 'https://ex.test/new/', $res['headers']['location'] );
	}

	public function test_a_transport_failure_is_null(): void {
		$this->response = new \Exception( 'cURL error 28: timed out' );
		$this->assertNull( ( new Fetcher( 'https://ex.test/' ) )->get( '/' ) );
	}
}
