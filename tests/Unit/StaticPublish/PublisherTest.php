<?php
/**
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\StaticPublish;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WPHeadless\Export\DataExport;
use WPHeadless\StaticPublish\AssetMirror;
use WPHeadless\StaticPublish\Keys;
use WPHeadless\StaticPublish\Lock;
use WPHeadless\StaticPublish\Publisher;
use WPHeadless\StaticPublish\RedirectMap;
use WPHeadless\Tests\Unit\StaticPublish\Fakes\FakeFetcher;
use WPHeadless\Tests\Unit\StaticPublish\Fakes\FakeRoutes;
use WPHeadless\Tests\Unit\StaticPublish\Fakes\MemoryStorage;
use WPHeadless\Tests\Unit\StaticPublish\Fakes\Options;

final class PublisherTest extends TestCase {
	use MockeryPHPUnitIntegration;

	private MemoryStorage $storage;

	private FakeFetcher $fetcher;

	private FakeRoutes $routes;

	/** @var array<string,string> */
	private array $redirect_map = array();

	private string $log_file;

	private string $previous_log;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Options::install();
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'https://ex.test' . $path;
			}
		);
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $value, $flags = 0 ) {
				return json_encode( $value, $flags );
			}
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'get_post_field' )->justReturn( '' );
		Functions\when( 'get_post' )->alias(
			static function ( $id ) {
				return (object) array(
					'ID'        => (int) $id,
					'post_type' => 'page',
				);
			}
		);

		$this->storage = new MemoryStorage();
		$this->fetcher = new FakeFetcher();
		$this->routes  = new FakeRoutes();
		$this->routes->singulars = array(
			array(
				'path' => '/',
				'id'   => 1,
				'type' => 'page',
			),
			array(
				'path' => '/about',
				'id'   => 2,
				'type' => 'page',
			),
		);
		$this->fetcher->responses['/']       = FakeFetcher::page( 'home v1' );
		$this->fetcher->responses['/about/'] = FakeFetcher::page( 'about v1' );

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

	private function publisher( ?DataExport $data = null, string $dist = '', string $wake_url = '' ): Publisher {
		$redirects = $this->createMock( RedirectMap::class );
		$redirects->method( 'build' )->willReturnCallback(
			function () {
				return $this->redirect_map;
			}
		);
		return new Publisher(
			$this->storage,
			$this->fetcher,
			array(
				'dist'     => $dist,
				'mount'    => '_wp-headless',
				'wake_url' => $wake_url,
			),
			$this->routes,
			$redirects,
			$data,
			'' !== $dist ? new AssetMirror() : null
		);
	}

	private function count_puts( string $key ): int {
		return count( array_keys( $this->storage->puts, $key, true ) );
	}

	// --- storing documents ---

	public function test_stores_each_published_route_with_the_origin_headers(): void {
		$stats = $this->publisher()->run();

		$this->assertSame( 'home v1', $this->storage->objects['index.html']['body'] );
		$this->assertSame( 'about v1', $this->storage->objects['about/index.html']['body'] );
		$this->assertSame( 'text/html; charset=UTF-8', $this->storage->objects['about/index.html']['type'] );
		$this->assertSame( 'public, max-age=60, s-maxage=300', $this->storage->objects['about/index.html']['cache'] );
		$this->assertSame( 2, $stats['documents']['stored'] );
		$this->assertSame( 2, $stats['routes'] );
	}

	public function test_a_private_cache_policy_is_replaced_by_the_static_default(): void {
		$this->fetcher->responses['/about/'] = FakeFetcher::page( 'about v1', 200, 'private, no-store' );
		$this->publisher()->run();

		$this->assertSame( Publisher::DEFAULT_CACHE, $this->storage->objects['about/index.html']['cache'] );
	}

	public function test_an_unchanged_document_is_not_rewritten(): void {
		$this->publisher()->run();
		$this->publisher()->run();

		$this->assertSame( 1, $this->count_puts( 'about/index.html' ) );
	}

	public function test_a_changed_document_is_rewritten(): void {
		$this->publisher()->run();
		$this->fetcher->responses['/about/'] = FakeFetcher::page( 'about v2' );
		$this->publisher()->run();

		$this->assertSame( 2, $this->count_puts( 'about/index.html' ) );
		$this->assertSame( 'about v2', $this->storage->objects['about/index.html']['body'] );
	}

	// --- failed reads keep the last good copy; gone pages lose it ---

	public function test_a_timeout_keeps_the_last_good_copy(): void {
		$this->publisher()->run();
		$this->fetcher->responses['/about/'] = null;
		$stats = $this->publisher()->run();

		$this->assertSame( 'about v1', $this->storage->objects['about/index.html']['body'] );
		$this->assertNotContains( 'about/index.html', $this->storage->deletes );
		$this->assertSame( 1, $stats['documents']['failed'] );
	}

	public function test_a_server_error_keeps_the_last_good_copy(): void {
		$this->publisher()->run();
		$this->fetcher->responses['/about/'] = FakeFetcher::page( 'oops', 503 );
		$this->publisher()->run();

		$this->assertSame( 'about v1', $this->storage->objects['about/index.html']['body'] );
		$this->assertNotContains( 'about/index.html', $this->storage->deletes );
	}

	public function test_a_rate_limited_read_keeps_the_last_good_copy(): void {
		$this->publisher()->run();
		$this->fetcher->responses['/about/'] = FakeFetcher::page( 'slow down', 429 );
		$this->publisher()->run();

		$this->assertArrayHasKey( 'about/index.html', $this->storage->objects );
	}

	public function test_a_404_removes_the_copy(): void {
		$this->publisher()->run();
		$this->fetcher->responses['/about/'] = FakeFetcher::page( 'gone', 404 );
		$this->publisher()->run();

		$this->assertContains( 'about/index.html', $this->storage->deletes );
		$this->assertArrayNotHasKey( 'about/index.html', $this->storage->objects );
	}

	public function test_a_410_removes_the_copy(): void {
		$this->publisher()->run();
		$this->fetcher->responses['/about/'] = FakeFetcher::page( 'gone', 410 );
		$this->publisher()->run();

		$this->assertArrayNotHasKey( 'about/index.html', $this->storage->objects );
	}

	public function test_a_route_that_now_redirects_loses_its_copy(): void {
		$this->publisher()->run();
		$this->fetcher->responses['/about/'] = array(
			'status'  => 301,
			'headers' => array( 'location' => 'https://ex.test/about-us/' ),
			'body'    => '',
		);
		$this->publisher()->run();

		$this->assertArrayNotHasKey( 'about/index.html', $this->storage->objects );
	}

	public function test_a_post_made_private_draft_or_trashed_leaves_on_the_next_publish(): void {
		$this->publisher()->run();
		// The route list holds publish posts only, so the post drops out.
		$this->routes->singulars = array( $this->routes->singulars[0] );
		$stats = $this->publisher()->run();

		$this->assertContains( 'about/index.html', $this->storage->deletes );
		$this->assertSame( 1, $stats['swept'] );
	}

	public function test_nothing_is_swept_when_no_published_post_could_be_read(): void {
		$this->publisher()->run();
		$this->routes->singulars = array(); // e.g. a database hiccup.
		$stats = $this->publisher()->run();

		$this->assertSame( array(), $this->storage->deletes );
		$this->assertSame( 0, $stats['swept'] );
	}

	public function test_a_failed_write_keeps_the_old_copy_and_is_retried_next_run(): void {
		$this->publisher()->run();
		$this->fetcher->responses['/about/'] = FakeFetcher::page( 'about v2' );
		$this->storage->fail_puts            = array( 'about/index.html' );
		$stats = $this->publisher()->run();

		$this->assertSame( 'about v1', $this->storage->objects['about/index.html']['body'] );
		$this->assertNotContains( 'about/index.html', $this->storage->deletes );
		$this->assertSame( 1, $stats['documents']['failed'] );

		$this->storage->fail_puts = array();
		$this->publisher()->run();
		$this->assertSame( 'about v2', $this->storage->objects['about/index.html']['body'] );
	}

	public function test_force_rewrites_unchanged_objects_and_still_sweeps(): void {
		$this->publisher()->run();
		$this->routes->singulars = array( $this->routes->singulars[0] );
		$this->publisher()->run( array( 'force' => true ) );

		$this->assertSame( 2, $this->count_puts( 'index.html' ) );
		$this->assertContains( 'about/index.html', $this->storage->deletes );
	}

	public function test_a_new_target_starts_with_a_full_write(): void {
		$this->publisher()->run();
		$this->storage->id = 'memory://elsewhere';
		$this->publisher()->run();

		$this->assertSame( 2, $this->count_puts( 'about/index.html' ) );
	}

	// --- site files: 404 shell, redirect map, crawler files ---

	public function test_the_404_page_is_stored_as_the_shell(): void {
		$this->publisher()->run();

		$this->assertSame( 'missing', $this->storage->objects[ Keys::NOT_FOUND ]['body'] );
		$this->assertContains( Publisher::NOT_FOUND_PROBE, $this->fetcher->requests );
	}

	public function test_the_redirect_map_is_published(): void {
		$this->redirect_map = array( '/home/' => 'https://ex.test/' );
		$this->publisher()->run();

		$this->assertSame(
			array( '/home/' => 'https://ex.test/' ),
			json_decode( $this->storage->objects[ Keys::REDIRECTS ]['body'], true )
		);
		$this->assertSame( 'application/json; charset=utf-8', $this->storage->objects[ Keys::REDIRECTS ]['type'] );
	}

	public function test_an_empty_redirect_map_is_a_json_object(): void {
		$this->publisher()->run();

		$this->assertSame( '{}', $this->storage->objects[ Keys::REDIRECTS ]['body'] );
	}

	public function test_sitemap_children_are_published(): void {
		$this->routes->files = array( '/wp-sitemap.xml' );
		$this->fetcher->responses['/wp-sitemap.xml']              = FakeFetcher::page( '<sitemapindex><sitemap><loc>https://ex.test/wp-sitemap-posts-page-1.xml</loc></sitemap><sitemap><loc>https://other.test/x.xml</loc></sitemap></sitemapindex>' );
		$this->fetcher->responses['/wp-sitemap-posts-page-1.xml'] = FakeFetcher::page( '<urlset/>' );
		$this->publisher()->run();

		$this->assertArrayHasKey( 'wp-sitemap.xml', $this->storage->objects );
		$this->assertSame( '<urlset/>', $this->storage->objects['wp-sitemap-posts-page-1.xml']['body'] );
		$this->assertNotContains( '/x.xml', $this->fetcher->requests );
	}

	public function test_the_paths_filter_adds_routes(): void {
		Filters\expectApplied( 'wp_headless_static_paths' )->once()->andReturn( array( '/events/' ) );
		$this->fetcher->responses['/events/'] = FakeFetcher::page( 'events' );
		$this->publisher()->run();

		$this->assertSame( 'events', $this->storage->objects['events/index.html']['body'] );
	}

	// --- status file, lock, dirty flag ---

	public function test_the_status_file_says_running_first_then_published(): void {
		$this->publisher()->run();

		$this->assertSame( Keys::STATUS, $this->storage->history[0][0] );
		$first = json_decode( $this->storage->history[0][1], true );
		$this->assertTrue( $first['running'] );
		$this->assertNull( $first['published'] );

		$last = $this->storage->status();
		$this->assertFalse( $last['running'] );
		$this->assertNotNull( $last['published'] );
		$this->assertSame( array( 'v', 'running', 'started', 'published', 'nextWake' ), array_keys( $last ) );
	}

	public function test_next_wake_is_when_the_next_scheduled_post_goes_live(): void {
		Functions\when( 'get_posts' )->justReturn( array( 7 ) );
		Functions\when( 'get_post_field' )->justReturn( '2026-11-01 09:30:00' );
		$this->publisher()->run();

		$this->assertSame( '2026-11-01T09:30:00+00:00', $this->storage->status()['nextWake'] );
	}

	// --- wake page ---

	public function test_the_wake_page_is_stored_with_the_wake_url_as_a_safe_json_string(): void {
		$this->publisher( null, '', 'https://wake.example/fn?x=1&y=</script>"' )->run();

		$page = $this->storage->objects[ Keys::WAKE ];
		// JSON escapes of & < > " (built with chr(92) for the backslash).
		$u = chr( 92 ) . 'u00';
		$this->assertStringContainsString( 'var WAKE_URL = "https://wake.example/fn?x=1' . $u . '26y=' . $u . '3C/script' . $u . '3E' . $u . '22";', $page['body'] );
		$this->assertStringNotContainsString( '__WAKE_URL__', $page['body'] );
		$this->assertStringNotContainsString( '</script>"', $page['body'] );
		$this->assertSame( 'text/html; charset=utf-8', $page['type'] );
		$this->assertSame( 'no-cache, max-age=0', $page['cache'] );
	}

	public function test_no_wake_page_without_a_wake_url(): void {
		$stats = $this->publisher()->run();

		$this->assertArrayNotHasKey( Keys::WAKE, $this->storage->objects );
		$this->assertSame( 1, $stats['site']['skipped'] );
	}

	public function test_a_wake_url_that_is_not_http_is_refused(): void {
		$this->assertNull( Publisher::wake_page_html( 'var WAKE_URL = __WAKE_URL__;', 'javascript:alert(1)' ) );
		$this->assertNull( Publisher::wake_page_html( 'var WAKE_URL = __WAKE_URL__;', '/relative/path' ) );
		$this->publisher( null, '', 'javascript:alert(1)' )->run();

		$this->assertArrayNotHasKey( Keys::WAKE, $this->storage->objects );
	}

	public function test_the_wake_page_is_retracted_once_the_wake_url_is_gone(): void {
		$this->publisher( null, '', 'https://wake.example/fn' )->run();
		$this->assertArrayHasKey( Keys::WAKE, $this->storage->objects );

		$stats = $this->publisher()->run();

		$this->assertArrayNotHasKey( Keys::WAKE, $this->storage->objects );
		$this->assertSame( 1, $stats['site']['removed'] );
	}

	public function test_an_unchanged_wake_page_is_not_rewritten(): void {
		$this->publisher( null, '', 'https://wake.example/fn' )->run();
		$this->publisher( null, '', 'https://wake.example/fn' )->run();

		$this->assertSame( 1, $this->count_puts( Keys::WAKE ) );
	}

	public function test_a_failed_run_records_the_error_class_only_and_stays_dirty(): void {
		$this->routes->throw = new \RuntimeException( 'Database gone at /var/www/html/wp-includes/class-wpdb.php' );
		Actions\expectDone( 'wp_headless_static_publish_failed' )->once();

		try {
			$this->publisher()->run();
			$this->fail( 'The run should rethrow.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Database gone', $e->getMessage() );
		}

		$status = $this->storage->status();
		$this->assertSame( 'RuntimeException', $status['error'] );
		$this->assertFalse( $status['running'] );
		$this->assertArrayHasKey( 'failedAt', $status );
		$raw = $this->storage->objects[ Keys::STATUS ]['body'];
		$this->assertStringNotContainsString( 'Database gone', $raw );
		$this->assertStringNotContainsString( '/var/www', $raw );
		$this->assertNotEmpty( Options::$values[ Publisher::DIRTY ] ?? null );
		$this->assertArrayNotHasKey( Lock::OPTION, Options::$values, 'The lock is released.' );
		$this->assertStringContainsString( 'Database gone', (string) file_get_contents( $this->log_file ), 'The message goes to the error log.' );
	}

	public function test_a_failed_run_keeps_the_last_successful_publish_time(): void {
		$this->publisher()->run();
		$published           = $this->storage->status()['published'];
		$this->routes->throw = new \LogicException( 'boom' );
		try {
			$this->publisher()->run();
		} catch ( \LogicException $e ) {
			unset( $e );
		}

		$this->assertSame( $published, $this->storage->status()['published'] );
		$this->assertSame( 'LogicException', $this->storage->status()['error'] );
	}

	public function test_an_anonymous_error_class_never_leaks_its_file_path(): void {
		$error = new class( 'x' ) extends \RuntimeException {
		};
		$this->assertSame( 'anonymous', Publisher::error_name( $error ) );
		$this->assertSame( 'StorageError', Publisher::error_name( new \WPHeadless\StaticPublish\StorageError( 'x' ) ) );
	}

	public function test_a_second_run_exits_while_the_lock_is_held_and_queues_the_change(): void {
		Options::$values[ Lock::OPTION ] = time() + 100;

		$this->assertNull( $this->publisher()->run() );
		$this->assertSame( array(), $this->storage->puts );
		$this->assertNotEmpty( Options::$values[ Publisher::DIRTY ] ?? null );
	}

	public function test_an_expired_lock_from_a_dead_run_is_taken_over(): void {
		Options::$values[ Lock::OPTION ] = time() - 5;

		$this->assertNotNull( $this->publisher()->run() );
		$this->assertArrayNotHasKey( Lock::OPTION, Options::$values );
	}

	public function test_a_change_during_a_run_makes_it_go_round_again(): void {
		$changed               = false;
		$this->fetcher->before = static function ( string $path ) use ( &$changed ): void {
			if ( '/about/' === $path && ! $changed ) {
				$changed                                  = true;
				Options::$values[ Publisher::DIRTY ] = time();
			}
		};
		$stats = $this->publisher()->run();

		$this->assertSame( 2, $stats['rounds'] );
		$this->assertArrayNotHasKey( Publisher::DIRTY, Options::$values );
		// Counts add up across rounds: stored in the first, unchanged in the second.
		$this->assertSame( 2, $stats['documents']['stored'] );
		$this->assertSame( 2, $stats['documents']['unchanged'] );
		$this->assertSame( 2, $stats['routes'] );
	}

	public function test_a_successful_run_clears_the_dirty_flag_and_fires_published(): void {
		Options::$values[ Publisher::DIRTY ] = time();
		Actions\expectDone( 'wp_headless_static_published' )->once();
		$this->publisher()->run();

		$this->assertArrayNotHasKey( Publisher::DIRTY, Options::$values );
	}

	// --- data artifacts and the asset mirror ---

	public function test_data_artifacts_are_stored_and_their_build_time_is_not_hashed(): void {
		$builds = 0;
		$data   = $this->createMock( DataExport::class );
		$data->method( 'build' )->willReturnCallback(
			static function ( $post ) use ( &$builds ) {
				$builds++;
				$path = 1 === $post->ID ? '/' : '/about';
				return array(
					'path' => $path,
					'json' => json_encode(
						array(
							'version'   => 1,
							'generated' => 'build-' . $builds,
							'path'      => $path,
						)
					),
				);
			}
		);
		$this->publisher( $data )->run();
		$this->publisher( $data )->run();

		$this->assertArrayHasKey( '__data/routes/index.json', $this->storage->objects );
		$this->assertSame( 1, $this->count_puts( '__data/routes/about/index.json' ) );
	}

	public function test_a_route_without_an_artifact_loses_its_old_one(): void {
		$data = $this->createMock( DataExport::class );
		$data->method( 'build' )->willReturnOnConsecutiveCalls(
			array(
				'path' => '/',
				'json' => '{}',
			),
			array(
				'path' => '/about',
				'json' => '{}',
			),
			array(
				'path' => '/',
				'json' => '{}',
			),
			null
		);
		$this->publisher( $data )->run();
		$this->publisher( $data )->run();

		$this->assertContains( '__data/routes/about/index.json', $this->storage->deletes );
	}

	public function test_the_build_is_mirrored_before_documents_and_never_swept(): void {
		$dist = sys_get_temp_dir() . '/wph-dist-' . bin2hex( random_bytes( 4 ) );
		mkdir( $dist . '/assets', 0777, true );
		file_put_contents( $dist . '/index.html', '<div id="root"></div>' );
		file_put_contents( $dist . '/assets/index-C19sr62o.js', 'console.log(1)' );

		$this->publisher( null, $dist )->run();
		$asset = '_wp-headless/assets/index-C19sr62o.js';
		$this->assertSame( 'console.log(1)', $this->storage->objects[ $asset ]['body'] );
		$this->assertSame( 'public, max-age=31536000, immutable', $this->storage->objects[ $asset ]['cache'] );
		$this->assertArrayNotHasKey( '_wp-headless/index.html', $this->storage->objects );
		$this->assertLessThan(
			array_search( 'index.html', $this->storage->puts, true ),
			array_search( $asset, $this->storage->puts, true )
		);

		unlink( $dist . '/assets/index-C19sr62o.js' );
		$this->publisher( null, $dist )->run();
		$this->assertNotContains( $asset, $this->storage->deletes );

		unlink( $dist . '/index.html' );
		rmdir( $dist . '/assets' );
		rmdir( $dist );
	}
}
