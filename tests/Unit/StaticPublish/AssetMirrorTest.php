<?php
/**
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\StaticPublish;

use PHPUnit\Framework\TestCase;
use WPHeadless\StaticPublish\AssetMirror;

final class AssetMirrorTest extends TestCase {
	private string $dist;

	protected function setUp(): void {
		parent::setUp();
		$this->dist = sys_get_temp_dir() . '/wph-mirror-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dist . '/assets', 0777, true );
		foreach ( array(
			'index.html'                => '<div id="root"></div>',
			'assets/index-C19sr62o.js'  => 'js',
			'assets/index-BlOpsoqo.css' => 'css',
			'assets/logo.svg'           => '<svg/>',
			'assets/readme.md'          => '# not served',
			'assets/.DS_Store'          => 'junk',
			'package.json'              => '{}',
			'robots.txt'                => 'User-agent: *',
		) as $file => $body ) {
			file_put_contents( $this->dist . '/' . $file, $body );
		}
	}

	protected function tearDown(): void {
		foreach ( (array) glob( $this->dist . '/{,.}*', GLOB_BRACE ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		foreach ( (array) glob( $this->dist . '/assets/{,.}*', GLOB_BRACE ) as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		rmdir( $this->dist . '/assets' );
		rmdir( $this->dist );
		parent::tearDown();
	}

	public function test_lists_web_files_under_the_mount_with_the_proxy_policy(): void {
		$files = ( new AssetMirror() )->files( $this->dist, '/_wp-headless/' );
		$by    = array();
		foreach ( $files as $file ) {
			$by[ $file['key'] ] = $file;
		}

		$this->assertSame(
			array(
				'_wp-headless/assets/index-BlOpsoqo.css',
				'_wp-headless/assets/index-C19sr62o.js',
				'_wp-headless/assets/logo.svg',
				'_wp-headless/robots.txt',
			),
			array_keys( $by )
		);
		$this->assertSame( 'application/javascript', $by['_wp-headless/assets/index-C19sr62o.js']['type'] );
		$this->assertSame( 'public, max-age=31536000, immutable', $by['_wp-headless/assets/index-C19sr62o.js']['cache'] );
		$this->assertSame( 'public, max-age=3600', $by['_wp-headless/assets/logo.svg']['cache'] );
	}

	public function test_a_missing_build_lists_nothing(): void {
		$this->assertSame( array(), ( new AssetMirror() )->files( $this->dist . '/nope', '_wp-headless' ) );
	}
}
