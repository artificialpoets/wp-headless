<?php
/**
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\StaticPublish;

use PHPUnit\Framework\TestCase;
use WPHeadless\StaticPublish\LocalStorage;
use WPHeadless\StaticPublish\StorageError;

final class LocalStorageTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		parent::setUp();
		$this->root = sys_get_temp_dir() . '/wph-local-' . bin2hex( random_bytes( 4 ) );
	}

	protected function tearDown(): void {
		$this->remove( $this->root );
		parent::tearDown();
	}

	private function remove( string $path ): void {
		if ( is_dir( $path ) ) {
			foreach ( (array) scandir( $path ) as $entry ) {
				if ( '.' !== $entry && '..' !== $entry ) {
					$this->remove( $path . '/' . $entry );
				}
			}
			rmdir( $path );
		} elseif ( file_exists( $path ) ) {
			unlink( $path );
		}
	}

	public function test_put_writes_nested_files_and_leaves_no_temporary_file(): void {
		$storage = new LocalStorage( $this->root );
		$storage->put( 'about/team/index.html', '<p>team</p>', 'text/html', 'public' );

		$this->assertSame( '<p>team</p>', file_get_contents( $this->root . '/about/team/index.html' ) );
		$this->assertSame( array( '.', '..', 'index.html' ), scandir( $this->root . '/about/team' ) );
	}

	public function test_put_replaces_an_existing_file(): void {
		$storage = new LocalStorage( $this->root );
		$storage->put( 'index.html', 'v1', 'text/html', 'public' );
		$storage->put( 'index.html', 'v2', 'text/html', 'public' );

		$this->assertSame( 'v2', file_get_contents( $this->root . '/index.html' ) );
	}

	public function test_delete_removes_a_file_and_ignores_a_missing_one(): void {
		$storage = new LocalStorage( $this->root );
		$storage->put( 'gone/index.html', 'x', 'text/html', 'public' );
		$storage->delete( 'gone/index.html' );
		$storage->delete( 'never/index.html' );

		$this->assertFileDoesNotExist( $this->root . '/gone/index.html' );
	}

	public function test_a_key_that_escapes_the_root_is_refused(): void {
		$storage = new LocalStorage( $this->root );
		$this->expectException( StorageError::class );
		$storage->put( '../escape.html', 'x', 'text/html', 'public' );
	}

	public function test_a_failed_write_throws(): void {
		mkdir( $this->root, 0777, true );
		file_put_contents( $this->root . '/blocker', 'a file where a directory must go' );
		$storage = new LocalStorage( $this->root );

		$this->expectException( StorageError::class );
		$storage->put( 'blocker/index.html', 'x', 'text/html', 'public' );
	}

	public function test_a_relative_or_root_directory_is_refused(): void {
		foreach ( array( '', '/', 'relative/dir', '/srv/../etc' ) as $root ) {
			try {
				new LocalStorage( $root );
				$this->fail( 'Accepted ' . var_export( $root, true ) );
			} catch ( StorageError $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_the_id_names_the_directory(): void {
		$this->assertSame( 'file:///srv/static', ( new LocalStorage( '/srv/static/' ) )->id() );
	}
}
