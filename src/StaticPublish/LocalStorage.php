<?php
/**
 * Local-directory storage target.
 *
 * @package WPHeadless
 */

namespace WPHeadless\StaticPublish;

/**
 * Writes objects as files under one directory, for a web server on the same
 * machine to serve (or a sync job to ship elsewhere). Each write goes to a
 * temporary file first and is renamed into place, so a reader never sees a
 * half-written document.
 *
 * A filesystem keeps no per-object headers: the serving web server chooses
 * Content-Type by extension and sets its own Cache-Control. Extension-less
 * non-HTML documents (the feed at `feed/index.html`) need a rule there.
 */
final class LocalStorage implements Storage {
	/** @var string Absolute directory, no trailing slash. */
	private string $root;

	/**
	 * @param string $root Absolute directory. Created on the first write.
	 * @throws StorageError When the directory is relative or the filesystem root.
	 */
	public function __construct( string $root ) {
		$root     = rtrim( $root, '/\\' );
		$absolute = '' !== $root && ( '/' === $root[0] || preg_match( '#^[A-Za-z]:[/\\\\]#', $root ) );
		if ( ! $absolute || preg_match( '#(^|[/\\\\])\.\.([/\\\\]|$)#', $root ) ) {
			throw new StorageError( 'The local target needs an absolute directory other than the filesystem root.' );
		}
		$this->root = $root;
	}

	public function id(): string {
		return 'file://' . $this->root;
	}

	public function put( string $key, string $body, string $content_type, string $cache_control ): void {
		unset( $content_type, $cache_control );
		$file = $this->path( $key );
		$dir  = dirname( $file );
		if ( ! is_dir( $dir ) && ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new StorageError( 'Could not create the directory for ' . $key );
		}
		$tmp = $dir . '/.' . basename( $file ) . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		if ( false === @file_put_contents( $tmp, $body ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions
			throw new StorageError( 'Could not write ' . $key );
		}
		if ( ! @rename( $tmp, $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions
			throw new StorageError( 'Could not move ' . $key . ' into place' );
		}
	}

	public function delete( string $key ): void {
		$file = $this->path( $key );
		if ( is_file( $file ) && ! @unlink( $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions
			throw new StorageError( 'Could not delete ' . $key );
		}
	}

	/**
	 * Absolute file path of a key.
	 *
	 * @param string $key Relative key.
	 * @throws StorageError When the key could escape the root.
	 */
	private function path( string $key ): string {
		if ( ! Keys::is_safe( $key ) ) {
			throw new StorageError( 'Refused an unsafe key.' );
		}
		return $this->root . '/' . $key;
	}
}
