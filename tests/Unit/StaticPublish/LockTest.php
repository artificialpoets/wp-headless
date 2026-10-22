<?php
/**
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\StaticPublish;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WPHeadless\StaticPublish\Lock;
use WPHeadless\Tests\Unit\StaticPublish\Fakes\Options;

final class LockTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Options::install();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_first_run_takes_the_lock_and_a_second_cannot(): void {
		$this->assertTrue( Lock::acquire( 900 ) );
		$this->assertFalse( Lock::acquire( 900 ) );
	}

	public function test_release_frees_the_lock(): void {
		Lock::acquire( 900 );
		Lock::release();

		$this->assertNull( Lock::expires() );
		$this->assertTrue( Lock::acquire( 900 ) );
	}

	public function test_an_expired_lock_is_taken_over(): void {
		Options::$values[ Lock::OPTION ] = time() - 1;

		$this->assertTrue( Lock::acquire( 900 ) );
		$this->assertGreaterThan( time(), Lock::expires() );
	}

	public function test_a_live_lock_is_never_overwritten(): void {
		Options::$values[ Lock::OPTION ] = (string) ( time() + 50 );

		$this->assertFalse( Lock::acquire( 900 ) );
		$this->assertSame( (string) ( time() + 50 ), Options::$values[ Lock::OPTION ] );
	}

	public function test_the_lock_is_created_with_insert_ignore(): void {
		Lock::acquire( 900 );

		// add_option() is not a mutex: its ON DUPLICATE KEY UPDATE lets a
		// second writer overwrite the row.
		$this->assertStringStartsWith( 'INSERT IGNORE', $GLOBALS['wpdb']->queries[0] );
	}

	public function test_extend_pushes_the_expiry_out(): void {
		Lock::acquire( 10 );
		Lock::extend( 900 );

		$this->assertGreaterThan( time() + 800, Lock::expires() );
	}
}
