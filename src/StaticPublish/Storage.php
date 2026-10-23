<?php
/**
 * Storage target contract.
 *
 * @package WPHeadless
 */

namespace WPHeadless\StaticPublish;

/**
 * Where the static publisher writes. The plugin ships a local-directory
 * target and an S3 target; a host supplies any other writer (R2, GCS, KV,
 * its own S3 client) through the `wp_headless_static_storage` filter.
 *
 * Keys are relative and '/'-separated (see Keys). A write that fails must
 * throw StorageError: the publisher then keeps the last good copy and
 * counts the failure instead of recording the new content as written.
 */
interface Storage {
	/**
	 * A stable identity for the target, without credentials, e.g.
	 * `s3://bucket/prefix` or `file:///srv/static`. The publisher's
	 * content-hash manifest is kept per identity, so a new target starts
	 * from a full write.
	 */
	public function id(): string;

	/**
	 * Write one object, replacing any existing one.
	 *
	 * @param string $key           Relative key.
	 * @param string $body          Bytes.
	 * @param string $content_type  Content-Type to serve it with.
	 * @param string $cache_control Cache-Control to serve it with.
	 * @throws StorageError When the write did not happen.
	 */
	public function put( string $key, string $body, string $content_type, string $cache_control ): void;

	/**
	 * Delete one object. A key that does not exist is not an error.
	 *
	 * @param string $key Relative key.
	 * @throws StorageError When the delete did not happen.
	 */
	public function delete( string $key ): void;
}
