<?php
/**
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\StaticPublish;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WPHeadless\StaticPublish\S3Storage;
use WPHeadless\StaticPublish\StorageError;

/** Records calls like the AWS SDK client; can throw like it. */
final class RecordingS3Client {
	/** @var array<int, array{0:string, 1:array<string,mixed>}> */
	public array $calls = array();

	public ?\Throwable $throw = null;

	public function putObject( array $args ): array {
		return $this->call( 'putObject', $args );
	}

	public function deleteObject( array $args ): array {
		return $this->call( 'deleteObject', $args );
	}

	private function call( string $name, array $args ): array {
		if ( null !== $this->throw ) {
			throw $this->throw;
		}
		$this->calls[] = array( $name, $args );
		return array();
	}
}

/** Answers its operations through __call(), as the AWS SDK's S3Client does. */
final class MagicS3Client {
	/** @var array<int, array{0:string, 1:array<string,mixed>}> */
	public array $calls = array();

	public function __call( string $name, array $args ) {
		$this->calls[] = array( $name, $args[0] ?? array() );
		return array();
	}
}

final class S3StorageTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_put_writes_under_the_prefix_with_headers(): void {
		$client  = new RecordingS3Client();
		$storage = new S3Storage( $client, 'assets', '/static-cache/site/' );
		$storage->put( 'about/index.html', '<p>about</p>', 'text/html; charset=UTF-8', 'public, max-age=60' );

		$this->assertSame(
			array(
				'putObject',
				array(
					'Bucket'       => 'assets',
					'Key'          => 'static-cache/site/about/index.html',
					'Body'         => '<p>about</p>',
					'ContentType'  => 'text/html; charset=UTF-8',
					'CacheControl' => 'public, max-age=60',
				),
			),
			$client->calls[0]
		);
	}

	public function test_without_a_prefix_the_key_is_the_bucket_root(): void {
		$client  = new RecordingS3Client();
		$storage = new S3Storage( $client, 'site-bucket' );
		$storage->delete( 'gone/index.html' );

		$this->assertSame(
			array(
				'deleteObject',
				array(
					'Bucket' => 'site-bucket',
					'Key'    => 'gone/index.html',
				),
			),
			$client->calls[0]
		);
		$this->assertSame( 's3://site-bucket', $storage->id() );
	}

	public function test_a_client_error_becomes_a_storage_error(): void {
		$client        = new RecordingS3Client();
		$client->throw = new \RuntimeException( 'AccessDenied' );
		$storage       = new S3Storage( $client, 'assets', 'p' );

		$this->expectException( StorageError::class );
		$storage->put( 'index.html', 'x', 'text/html', 'public' );
	}

	public function test_an_unsafe_key_is_refused_before_the_client_is_called(): void {
		$client  = new RecordingS3Client();
		$storage = new S3Storage( $client, 'assets', 'p' );
		try {
			$storage->put( '../other-site/index.html', 'x', 'text/html', 'public' );
			$this->fail( 'An unsafe key was accepted.' );
		} catch ( StorageError $e ) {
			$this->assertSame( array(), $client->calls );
		}
	}

	public function test_the_id_names_bucket_and_prefix_only(): void {
		$this->assertSame( 's3://assets/static-cache/site', ( new S3Storage( new RecordingS3Client(), 'assets', 'static-cache/site' ) )->id() );
	}

	public function test_from_settings_uses_a_host_supplied_client(): void {
		$client = new RecordingS3Client();
		Filters\expectApplied( 'wp_headless_static_s3_client' )->once()->andReturn( $client );
		$storage = S3Storage::from_settings(
			array(
				'bucket' => 'assets',
				'prefix' => 'site',
			)
		);
		$this->assertInstanceOf( S3Storage::class, $storage );
		$storage->put( 'index.html', 'x', 'text/html', 'public' );
		$this->assertSame( 'site/index.html', $client->calls[0][1]['Key'] );
	}

	public function test_from_settings_is_null_without_a_bucket(): void {
		$this->assertNull( S3Storage::from_settings( array( 'prefix' => 'site' ) ) );
	}

	public function test_from_settings_is_null_without_the_sdk_or_a_client(): void {
		$this->assertFalse( class_exists( '\Aws\S3\S3Client' ), 'The test environment must not ship the AWS SDK.' );
		$this->assertNull( S3Storage::from_settings( array( 'bucket' => 'assets' ) ) );
	}

	public function test_a_client_with_magic_methods_is_accepted(): void {
		$client  = new MagicS3Client();
		$storage = new S3Storage( $client, 'assets', 'p' );
		$storage->put( 'index.html', '<p>home</p>', 'text/html; charset=UTF-8', 'no-cache' );
		$storage->delete( 'old/index.html' );

		$this->assertSame( array( 'putObject', 'deleteObject' ), array_column( $client->calls, 0 ) );
		$this->assertSame( 'p/index.html', $client->calls[0][1]['Key'] );
		$this->assertSame( 'p/old/index.html', $client->calls[1][1]['Key'] );
	}

	public function test_a_client_without_the_needed_methods_is_refused(): void {
		$this->expectException( StorageError::class );
		new S3Storage( new \stdClass(), 'assets' );
	}
}
