<?php
/**
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\StaticPublish;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WPHeadless\StaticPublish\Activity;
use WPHeadless\StaticPublish\Keys;
use WPHeadless\Tests\Unit\StaticPublish\Fakes\MemoryStorage;

final class ActivityTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private MemoryStorage $storage;

	/** @var array<string,mixed> Transients by name. */
	private array $transients = array();

	private bool $logged_in = true;

	private bool $can_edit = true;

	private string $log_file;

	private string $previous_log;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->storage = new MemoryStorage();
		Functions\when( 'is_user_logged_in' )->alias(
			function () {
				return $this->logged_in;
			}
		);
		Functions\when( 'current_user_can' )->alias(
			function ( $cap ) {
				return 'edit_posts' === $cap && $this->can_edit;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $name ) {
				return $this->transients[ $name ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $name, $value ) {
				$this->transients[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $name ) {
				unset( $this->transients[ $name ] );
				return true;
			}
		);
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );

		$this->log_file     = (string) tempnam( sys_get_temp_dir(), 'wph-log' );
		$this->previous_log = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->log_file );
	}

	protected function tearDown(): void {
		ini_set( 'error_log', $this->previous_log );
		@unlink( $this->log_file );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function activity( bool $publisher_on = true ): Activity {
		return new Activity(
			function () use ( $publisher_on ) {
				return $publisher_on ? $this->storage : null;
			}
		);
	}

	public function test_an_editor_request_stamps_after_the_response(): void {
		$activity = $this->activity();
		Actions\expectAdded( 'shutdown' )->once()->with( array( $activity, 'write' ), 110 );

		$activity->note();
		$this->assertArrayNotHasKey( Keys::ACTIVITY, $this->storage->objects, 'nothing is written during the request' );

		$this->assertTrue( $activity->write() );
		$stamp = $this->storage->objects[ Keys::ACTIVITY ];
		$body  = json_decode( $stamp['body'], true );
		$this->assertSame( array( 'lastEditor' ), array_keys( $body ) );
		$this->assertEqualsWithDelta( time(), strtotime( $body['lastEditor'] ), 5 );
		$this->assertSame( 'application/json; charset=utf-8', $stamp['type'] );
		$this->assertSame( 'no-cache, max-age=0', $stamp['cache'] );
		$this->assertSame( 1, $this->transients[ Activity::TRANSIENT ] );
	}

	public function test_at_most_one_stamp_a_minute(): void {
		$this->transients[ Activity::TRANSIENT ] = 1;
		Actions\expectAdded( 'shutdown' )->never();

		$this->activity()->note();

		$this->assertSame( array(), $this->storage->puts );
	}

	public function test_a_visitor_never_stamps(): void {
		$this->logged_in = false;
		Actions\expectAdded( 'shutdown' )->never();

		$this->activity()->note();

		$this->assertArrayNotHasKey( Activity::TRANSIENT, $this->transients );
	}

	public function test_a_signed_in_user_who_cannot_edit_never_stamps(): void {
		$this->can_edit = false;
		Actions\expectAdded( 'shutdown' )->never();

		$this->activity()->note();

		$this->assertArrayNotHasKey( Activity::TRANSIENT, $this->transients );
	}

	public function test_nothing_happens_while_the_publisher_is_off(): void {
		Actions\expectAdded( 'shutdown' )->never();
		$activity = $this->activity( false );

		$activity->note();

		$this->assertArrayNotHasKey( Activity::TRANSIENT, $this->transients );
		$this->assertFalse( $activity->write() );
	}

	public function test_a_failed_write_drops_the_guard_so_the_next_request_retries(): void {
		$this->storage->fail_puts = array( Keys::ACTIVITY );
		$activity                 = $this->activity();
		$activity->note();

		$this->assertFalse( $activity->write() );
		$this->assertArrayNotHasKey( Activity::TRANSIENT, $this->transients );
	}
}
