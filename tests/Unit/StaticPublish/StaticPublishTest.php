<?php
/**
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\StaticPublish;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WPHeadless\Config\Config;
use WPHeadless\StaticPublish\LocalStorage;
use WPHeadless\StaticPublish\Publisher;
use WPHeadless\StaticPublish\StaticPublish;
use WPHeadless\Tests\Unit\StaticPublish\Fakes\MemoryStorage;
use WPHeadless\Tests\Unit\StaticPublish\Fakes\Options;

/** The module with a settable constant and an injectable publisher. */
class TestableStaticPublish extends StaticPublish {
	public ?bool $constant_value = null;

	public ?Publisher $injected = null;

	public int $publisher_calls = 0;

	protected function constant(): ?bool {
		return $this->constant_value;
	}

	public function publisher(): ?Publisher {
		$this->publisher_calls++;
		return null !== $this->injected ? $this->injected : parent::publisher();
	}
}

final class StaticPublishTest extends TestCase {
	use MockeryPHPUnitIntegration;

	/** @var array<int, array{0:int, 1:string}> wp_schedule_single_event() calls. */
	private array $scheduled = array();

	private $next_scheduled = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Options::install();
		Functions\when( 'wp_next_scheduled' )->alias(
			function () {
				return $this->next_scheduled;
			}
		);
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $timestamp, $hook ) {
				$this->scheduled[]    = array( $timestamp, $hook );
				$this->next_scheduled = $timestamp;
				return true;
			}
		);
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'get_post_type_object' )->alias(
			static function ( $type ) {
				return (object) array( 'public' => 'secret' !== $type );
			}
		);
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'remove_query_arg' )->alias(
			static function ( $key, $url ) {
				return preg_replace( '/[?&]' . $key . '=[^&]*/', '', $url );
			}
		);
	}

	protected function tearDown(): void {
		unset( $_SERVER['HTTP_X_WP_HEADLESS_STATIC'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $values Config values keyed by dot path.
	 */
	private function module( array $values = array() ): TestableStaticPublish {
		$config = $this->getMockBuilder( Config::class )->disableOriginalConstructor()->getMock();
		$config->method( 'get' )->willReturnCallback(
			static function ( $key, $default = null ) use ( $values ) {
				return array_key_exists( $key, $values ) ? $values[ $key ] : $default;
			}
		);
		$config->method( 'asset_mount' )->willReturn( '_wp-headless' );
		return new TestableStaticPublish( $config );
	}

	private function opt_in(): void {
		Options::$values[ StaticPublish::OPTION ] = 'on';
	}

	private function post( string $status, string $type = 'page' ): object {
		return (object) array(
			'ID'          => 5,
			'post_type'   => $type,
			'post_status' => $status,
		);
	}

	// --- opt-in ---

	public function test_off_by_default(): void {
		$module = $this->module();
		$module->mark_dirty();

		$this->assertFalse( $module->enabled() );
		$this->assertArrayNotHasKey( Publisher::DIRTY, Options::$values );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_the_option_turns_it_on(): void {
		$this->opt_in();
		$this->assertTrue( $this->module()->enabled() );
	}

	public function test_the_constant_wins_over_the_option(): void {
		$this->opt_in();
		$module                 = $this->module();
		$module->constant_value = false;
		$this->assertFalse( $module->enabled() );

		Options::$values[ StaticPublish::OPTION ] = 'off';
		$module->constant_value                   = true;
		$this->assertTrue( $module->enabled() );
	}

	public function test_a_configured_target_alone_never_turns_it_on(): void {
		$module = $this->module(
			array(
				'modules.static_publish.target' => 'local',
				'modules.static_publish.path'   => '/srv/static',
			)
		);
		$module->mark_dirty();

		$this->assertNull( $module->publisher() );
		$this->assertArrayNotHasKey( Publisher::DIRTY, Options::$values );
	}

	public function test_opted_in_without_a_target_has_no_publisher(): void {
		$this->opt_in();
		$this->assertNull( $this->module()->publisher() );
	}

	// --- storage selection ---

	public function test_the_local_target(): void {
		$storage = $this->module(
			array(
				'modules.static_publish.target' => 'local',
				'modules.static_publish.path'   => '/srv/static',
			)
		)->storage();

		$this->assertInstanceOf( LocalStorage::class, $storage );
		$this->assertSame( 'file:///srv/static', $storage->id() );
	}

	public function test_a_bad_local_path_is_no_target(): void {
		$log = ini_get( 'error_log' );
		ini_set( 'error_log', '/dev/null' );
		$storage = $this->module(
			array(
				'modules.static_publish.target' => 'local',
				'modules.static_publish.path'   => 'relative/path',
			)
		)->storage();
		ini_set( 'error_log', (string) $log );

		$this->assertNull( $storage );
	}

	public function test_the_s3_target_stays_off_without_the_sdk_or_a_client(): void {
		$storage = $this->module(
			array(
				'modules.static_publish.target' => 's3',
				'modules.static_publish.s3'     => array( 'bucket' => 'assets' ),
			)
		)->storage();

		$this->assertNull( $storage );
	}

	public function test_a_host_supplies_a_writer_through_the_filter(): void {
		$memory = new MemoryStorage();
		Filters\expectApplied( 'wp_headless_static_storage' )->once()->andReturn( $memory );

		$this->assertSame( $memory, $this->module()->storage() );
	}

	// --- changes mark the site dirty; nothing publishes in the request ---

	public function test_a_change_schedules_one_publish_after_the_delay(): void {
		$this->opt_in();
		$module = $this->module( array( 'modules.static_publish.delay' => 120 ) );
		$module->mark_dirty();
		$module->mark_dirty();

		$this->assertNotEmpty( Options::$values[ Publisher::DIRTY ] );
		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( StaticPublish::CRON, $this->scheduled[0][1] );
		$this->assertGreaterThanOrEqual( time() + 119, $this->scheduled[0][0] );
	}

	public function test_publishing_a_post_marks_dirty_without_publishing(): void {
		$this->opt_in();
		$module = $this->module();
		Filters\expectApplied( 'wp_headless_static_storage' )->never();
		$module->on_post_change( 5, $this->post( 'publish' ), true, $this->post( 'draft' ) );

		$this->assertNotEmpty( Options::$values[ Publisher::DIRTY ] ?? null );
		$this->assertSame( 0, $module->publisher_calls );
	}

	public function test_unpublishing_a_post_marks_dirty(): void {
		$this->opt_in();
		$this->module()->on_post_change( 5, $this->post( 'private' ), true, $this->post( 'publish' ) );

		$this->assertNotEmpty( Options::$values[ Publisher::DIRTY ] ?? null );
	}

	public function test_trashing_a_published_post_marks_dirty(): void {
		$this->opt_in();
		$this->module()->on_post_change( 5, $this->post( 'trash' ), true, $this->post( 'publish' ) );

		$this->assertNotEmpty( Options::$values[ Publisher::DIRTY ] ?? null );
	}

	public function test_editing_a_draft_changes_nothing(): void {
		$this->opt_in();
		$this->module()->on_post_change( 5, $this->post( 'draft' ), true, $this->post( 'draft' ) );

		$this->assertArrayNotHasKey( Publisher::DIRTY, Options::$values );
	}

	public function test_a_revision_changes_nothing(): void {
		$this->opt_in();
		Functions\when( 'wp_is_post_revision' )->justReturn( 4 );
		$this->module()->on_post_change( 6, $this->post( 'publish' ), false, null );

		$this->assertArrayNotHasKey( Publisher::DIRTY, Options::$values );
	}

	public function test_a_non_public_type_changes_nothing(): void {
		$this->opt_in();
		$this->module()->on_post_change( 5, $this->post( 'publish', 'secret' ), true, null );

		$this->assertArrayNotHasKey( Publisher::DIRTY, Options::$values );
	}

	public function test_a_reading_setting_change_marks_dirty(): void {
		$this->opt_in();
		$module = $this->module();
		$module->on_option_updated( 'wp_headless_static_manifest' );
		$this->assertArrayNotHasKey( Publisher::DIRTY, Options::$values, "The publisher's own options never trigger a publish." );

		$module->on_option_updated( 'page_on_front' );
		$this->assertNotEmpty( Options::$values[ Publisher::DIRTY ] ?? null );
	}

	public function test_deleting_a_published_post_outright_marks_dirty(): void {
		$this->opt_in();
		Functions\when( 'get_post' )->justReturn( $this->post( 'publish' ) );
		$this->module()->on_post_deleted( 5 );

		$this->assertNotEmpty( Options::$values[ Publisher::DIRTY ] ?? null );
	}

	// --- cron ---

	public function test_cron_does_nothing_when_the_site_is_clean(): void {
		$this->opt_in();
		$module = $this->module();
		$module->run_scheduled();

		$this->assertSame( 0, $module->publisher_calls );
	}

	public function test_cron_publishes_a_dirty_site(): void {
		$this->opt_in();
		Options::$values[ Publisher::DIRTY ] = time();
		$module                              = $this->module();
		$module->injected                    = $this->createMock( Publisher::class );
		$module->injected->expects( $this->once() )->method( 'run' )->willReturn( array( 'routes' => 1 ) );
		$module->run_scheduled();

		$this->assertSame( array(), $this->scheduled );
	}

	public function test_cron_retries_later_when_another_run_holds_the_lock(): void {
		$this->opt_in();
		Options::$values[ Publisher::DIRTY ] = time();
		$module                              = $this->module();
		$module->injected                    = $this->createMock( Publisher::class );
		$module->injected->method( 'run' )->willReturn( null );
		$module->run_scheduled();

		$this->assertCount( 1, $this->scheduled );
	}

	public function test_cron_swallows_a_failed_run_it_already_recorded(): void {
		$this->opt_in();
		Options::$values[ Publisher::DIRTY ] = time();
		$module                              = $this->module();
		$module->injected                    = $this->createMock( Publisher::class );
		$module->injected->method( 'run' )->willThrowException( new \RuntimeException( 'boom' ) );
		$module->run_scheduled();

		$this->addToAssertionCount( 1 ); // No exception escaped into wp-cron.
	}

	// --- the publisher's own reads ---

	public function test_the_publishers_reads_carry_no_rotating_nonce(): void {
		$this->opt_in();
		$_SERVER['HTTP_X_WP_HEADLESS_STATIC'] = '1';
		$data                                 = $this->module()->static_runtime(
			array(
				'rest' => array( 'nonce' => 'abc123' ),
				'urls' => array( 'logout' => 'https://ex.test/wp-login.php?action=logout&_wpnonce=f00' ),
			)
		);

		$this->assertSame( '', $data['rest']['nonce'] );
		$this->assertSame( 'https://ex.test/wp-login.php?action=logout', $data['urls']['logout'] );
	}

	public function test_visitors_keep_their_nonce(): void {
		$this->opt_in();
		$data = $this->module()->static_runtime( array( 'rest' => array( 'nonce' => 'abc123' ) ) );

		$this->assertSame( 'abc123', $data['rest']['nonce'] );
	}

	public function test_a_signed_in_user_keeps_the_nonce_even_with_the_header(): void {
		$this->opt_in();
		$_SERVER['HTTP_X_WP_HEADLESS_STATIC'] = '1';
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		$data = $this->module()->static_runtime( array( 'rest' => array( 'nonce' => 'abc123' ) ) );

		$this->assertSame( 'abc123', $data['rest']['nonce'] );
	}

	public function test_the_header_does_nothing_on_a_site_that_did_not_opt_in(): void {
		$_SERVER['HTTP_X_WP_HEADLESS_STATIC'] = '1';
		$data                                 = $this->module()->static_runtime( array( 'rest' => array( 'nonce' => 'abc123' ) ) );

		$this->assertSame( 'abc123', $data['rest']['nonce'] );
	}
}
