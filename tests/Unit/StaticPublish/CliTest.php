<?php
/**
 * @package WPHeadless\Tests
 */

namespace {
	if ( ! class_exists( 'WP_CLI' ) ) {
		/** Records WP-CLI output; error() throws like the real one exits. */
		class WP_CLI {
			/** @var array<int, array{0:string, 1:string}> */
			public static array $calls = array();

			public static function error( $message ): void {
				self::$calls[] = array( 'error', (string) $message );
				throw new \WPHeadless\Tests\Unit\StaticPublish\CliExit( (string) $message );
			}

			public static function success( $message ): void {
				self::$calls[] = array( 'success', (string) $message );
			}

			public static function log( $message ): void {
				self::$calls[] = array( 'log', (string) $message );
			}

			public static function add_command( ...$args ): void {
				unset( $args );
			}
		}
	}
}

namespace WPHeadless\Tests\Unit\StaticPublish {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
	use PHPUnit\Framework\TestCase;
	use WPHeadless\StaticPublish\Cli;
	use WPHeadless\StaticPublish\Publisher;
	use WPHeadless\StaticPublish\StaticPublish;
	use WPHeadless\Tests\Unit\StaticPublish\Fakes\MemoryStorage;
	use WPHeadless\Tests\Unit\StaticPublish\Fakes\Options;

	/** WP_CLI::error() — the process would exit non-zero here. */
	final class CliExit extends \RuntimeException {
	}

	final class CliTest extends TestCase {
		use MockeryPHPUnitIntegration;

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			Options::install();
			\WP_CLI::$calls = array();
			Functions\when( 'wp_json_encode' )->alias(
				static function ( $value, $flags = 0 ) {
					return json_encode( $value, $flags );
				}
			);
			Functions\when( 'wp_next_scheduled' )->justReturn( false );
		}

		protected function tearDown(): void {
			Monkey\tearDown();
			parent::tearDown();
		}

		private function module( ?Publisher $publisher, bool $enabled = true ): StaticPublish {
			$module = $this->getMockBuilder( StaticPublish::class )
				->disableOriginalConstructor()
				->onlyMethods( array( 'publisher', 'enabled', 'storage' ) )
				->getMock();
			$module->method( 'publisher' )->willReturn( $publisher );
			$module->method( 'enabled' )->willReturn( $enabled );
			$module->method( 'storage' )->willReturn( new MemoryStorage() );
			return $module;
		}

		private function last_call(): array {
			return end( \WP_CLI::$calls );
		}

		public function test_publish_exits_non_zero_when_the_site_did_not_opt_in(): void {
			$this->expectException( CliExit::class );
			$this->expectExceptionMessage( 'wp option update wp_headless_static_publish on' );
			( new Cli( $this->module( null, false ) ) )->publish( array(), array() );
		}

		public function test_publish_exits_non_zero_without_a_target(): void {
			$this->expectException( CliExit::class );
			$this->expectExceptionMessage( 'no storage target' );
			( new Cli( $this->module( null, true ) ) )->publish( array(), array() );
		}

		public function test_a_second_publish_exits_non_zero_while_one_runs(): void {
			$publisher = $this->createMock( Publisher::class );
			$publisher->method( 'run' )->willReturn( null );

			$this->expectException( CliExit::class );
			$this->expectExceptionMessage( 'Another publish holds the lock' );
			( new Cli( $this->module( $publisher ) ) )->publish( array(), array() );
		}

		public function test_a_failed_publish_exits_non_zero(): void {
			$publisher = $this->createMock( Publisher::class );
			$publisher->method( 'run' )->willThrowException( new \RuntimeException( 'storage down' ) );

			$this->expectException( CliExit::class );
			$this->expectExceptionMessage( 'stays dirty' );
			( new Cli( $this->module( $publisher ) ) )->publish( array(), array() );
		}

		public function test_publish_passes_force_and_reports_the_counts(): void {
			$publisher = $this->createMock( Publisher::class );
			$publisher->expects( $this->once() )
				->method( 'run' )
				->with( $this->callback( static fn ( $args ) => true === $args['force'] && is_callable( $args['log'] ) ) )
				->willReturn( array( 'routes' => 3 ) );
			( new Cli( $this->module( $publisher ) ) )->publish( array(), array( 'force' => true ) );

			$this->assertSame( array( 'success', 'Published: {"routes":3}' ), $this->last_call() );
		}

		public function test_status_reports_target_lock_and_dirty_flag(): void {
			Options::$values[ Publisher::DIRTY ] = 1700000000;
			Options::$values['wp_headless_static_lock'] = 1700000900;
			( new Cli( $this->module( null ) ) )->status();

			$status = json_decode( $this->last_call()[1], true );
			$this->assertTrue( $status['enabled'] );
			$this->assertSame( 'memory://site', $status['target'] );
			$this->assertSame( '2023-11-14T22:13:20+00:00', $status['dirty'] );
			$this->assertSame( '2023-11-14T22:28:20+00:00', $status['locked'] );
			$this->assertNull( $status['published'] );
		}
	}
}
