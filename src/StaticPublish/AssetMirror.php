<?php
/**
 * The theme build, mirrored next to the documents.
 *
 * @package WPHeadless
 */

namespace WPHeadless\StaticPublish;

use WPHeadless\Runtime\AssetProxy;

/**
 * Documents load the theme's built JS and CSS from the asset mount
 * (`/_wp-headless/...`, answered by the AssetProxy in PHP) or from
 * `frontend.asset_base_url`. While WordPress sleeps neither may answer, so
 * the publisher copies the active build (`{theme}/dist/`) to
 * `<prefix>/<path>` in storage, where `<prefix>` is the path documents load
 * it from (StaticPublish::asset_target()), with the proxy's own content
 * types and cache policy.
 *
 * Mirrored files are never swept: HTML still cached at an edge may
 * reference an earlier build's hashed chunks.
 */
class AssetMirror {
	/** Extra content types beyond AssetProxy::MIME_TYPES. */
	private const EXTRA_TYPES = array(
		'png'  => 'image/png',
		'jpg'  => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'gif'  => 'image/gif',
		'webp' => 'image/webp',
		'avif' => 'image/avif',
		'txt'  => 'text/plain; charset=utf-8',
		'html' => 'text/html; charset=utf-8',
	);

	/**
	 * Every file to mirror.
	 *
	 * Skips the build's root `index.html` (the shell template; documents
	 * are published from the rendered pages), dotfiles, package manifests,
	 * and any extension without a known web content type.
	 *
	 * @param string $dist  Absolute build directory.
	 * @param string $mount Key prefix, e.g. `_wp-headless` or `wp-content/themes/<theme>/dist`.
	 * @return array<int, array{key:string, file:string, type:string, cache:string}>
	 */
	public function files( string $dist, string $mount ): array {
		$dist  = rtrim( $dist, '/' );
		$mount = trim( $mount, '/' );
		if ( '' === $dist || '' === $mount || ! is_dir( $dist ) ) {
			return array();
		}
		$files    = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dist, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $iterator as $file ) {
			if ( ! $file instanceof \SplFileInfo || ! $file->isFile() || $file->isLink() ) {
				continue;
			}
			$relative = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dist ) ) ), '/' );
			$type     = $this->type_of( $relative );
			if ( null === $type || 'index.html' === $relative || preg_match( '#(^|/)(\.|package(-lock)?\.json$)#', $relative ) ) {
				continue;
			}
			$key = $mount . '/' . $relative;
			if ( ! Keys::is_safe( $key ) ) {
				continue;
			}
			$files[] = array(
				'key'   => $key,
				'file'  => $file->getPathname(),
				'type'  => $type,
				'cache' => AssetProxy::cache_control_for( $file->getFilename() ),
			);
		}
		usort(
			$files,
			static function ( array $a, array $b ): int {
				return strcmp( $a['key'], $b['key'] );
			}
		);
		return $files;
	}

	/**
	 * Content type for a path, or null when it is not a web asset.
	 *
	 * @param string $path Relative path.
	 */
	private function type_of( string $path ): ?string {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( isset( AssetProxy::MIME_TYPES[ $ext ] ) ) {
			return AssetProxy::MIME_TYPES[ $ext ];
		}
		return self::EXTRA_TYPES[ $ext ] ?? null;
	}
}
