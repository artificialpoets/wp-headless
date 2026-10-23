<?php
/**
 * S3-compatible storage target.
 *
 * @package WPHeadless
 */

namespace WPHeadless\StaticPublish;

/**
 * Writes objects to an S3-compatible bucket (AWS S3, R2, MinIO, …) through
 * a client object that has the AWS SDK for PHP's `putObject(array)` and
 * `deleteObject(array)` methods.
 *
 * The plugin has no dependency on any SDK and holds no credentials. The
 * client is either supplied by the host through the
 * `wp_headless_static_s3_client` filter, or — only when the AWS SDK for PHP
 * is already loaded — built with the SDK's default credential chain
 * (environment, shared config, instance or task role).
 */
final class S3Storage implements Storage {
	/** @var object Client with putObject() and deleteObject(). */
	private $client;

	/** @var string */
	private string $bucket;

	/** @var string Key prefix without surrounding slashes, '' for none. */
	private string $prefix;

	/**
	 * @param object $client Client with putObject() and deleteObject().
	 * @param string $bucket Bucket name.
	 * @param string $prefix Key prefix, e.g. `sites/example`.
	 */
	public function __construct( $client, string $bucket, string $prefix = '' ) {
		if ( ! is_object( $client ) || ! method_exists( $client, 'putObject' ) || ! method_exists( $client, 'deleteObject' ) ) {
			throw new StorageError( 'The S3 target needs a client with putObject() and deleteObject().' );
		}
		if ( '' === $bucket ) {
			throw new StorageError( 'The S3 target needs a bucket.' );
		}
		$this->client = $client;
		$this->bucket = $bucket;
		$this->prefix = trim( $prefix, '/' );
	}

	/**
	 * Build the target from `modules.static_publish.s3` settings, or null
	 * when there is no bucket or no client.
	 *
	 * @param array<string,mixed> $settings { bucket, prefix, region, endpoint, path_style }.
	 */
	public static function from_settings( array $settings ): ?self {
		$bucket = isset( $settings['bucket'] ) ? (string) $settings['bucket'] : '';
		if ( '' === $bucket ) {
			return null;
		}

		/**
		 * Supplies the S3 client the static publisher writes with.
		 *
		 * Return any object with the AWS SDK's putObject(array) and
		 * deleteObject(array) methods to use it instead of an SDK client
		 * built here. Without it, a client is built only when the AWS SDK
		 * for PHP is loaded; otherwise the S3 target stays off.
		 *
		 * @param object|null         $client   Client, or null to let the plugin build one.
		 * @param array<string,mixed> $settings The `modules.static_publish.s3` settings.
		 */
		$client = apply_filters( 'wp_headless_static_s3_client', null, $settings );
		if ( null === $client && class_exists( '\Aws\S3\S3Client' ) ) {
			$args = array(
				'version' => 'latest',
				'region'  => (string) ( $settings['region'] ?? 'us-east-1' ),
			);
			if ( ! empty( $settings['endpoint'] ) ) {
				$args['endpoint'] = (string) $settings['endpoint'];
			}
			if ( ! empty( $settings['path_style'] ) ) {
				$args['use_path_style_endpoint'] = true;
			}
			$client = new \Aws\S3\S3Client( $args );
		}
		if ( ! is_object( $client ) ) {
			return null;
		}
		return new self( $client, $bucket, (string) ( $settings['prefix'] ?? '' ) );
	}

	public function id(): string {
		return 's3://' . $this->bucket . ( '' === $this->prefix ? '' : '/' . $this->prefix );
	}

	public function put( string $key, string $body, string $content_type, string $cache_control ): void {
		try {
			$this->client->putObject(
				array(
					'Bucket'       => $this->bucket,
					'Key'          => $this->key( $key ),
					'Body'         => $body,
					'ContentType'  => $content_type,
					'CacheControl' => $cache_control,
				)
			);
		} catch ( StorageError $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			throw new StorageError( 'S3 put failed for ' . $key . ': ' . $e->getMessage(), 0, $e );
		}
	}

	public function delete( string $key ): void {
		try {
			$this->client->deleteObject(
				array(
					'Bucket' => $this->bucket,
					'Key'    => $this->key( $key ),
				)
			);
		} catch ( StorageError $e ) {
			throw $e;
		} catch ( \Throwable $e ) {
			throw new StorageError( 'S3 delete failed for ' . $key . ': ' . $e->getMessage(), 0, $e );
		}
	}

	/**
	 * Full object key under the prefix.
	 *
	 * @param string $key Relative key.
	 * @throws StorageError When the key is unsafe.
	 */
	private function key( string $key ): string {
		if ( ! Keys::is_safe( $key ) ) {
			throw new StorageError( 'Refused an unsafe key.' );
		}
		return '' === $this->prefix ? $key : $this->prefix . '/' . $key;
	}
}
