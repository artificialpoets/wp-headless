<?php
/**
 * Streams frontend assets from the active headless theme's dist directory.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Runtime;

use WP;
use WPHeadless\Config\Config;
use WPHeadless\Contracts\Module;
use WPHeadless\Routing\RewriteRules;
use WPHeadless\Theme\ThemeManager;

class AssetProxy implements Module {
	/** @var Config */
	private Config $config;

	/** @var ThemeManager */
	private ThemeManager $theme_manager;

	public function __construct( Config $config, ThemeManager $theme_manager ) {
		$this->config        = $config;
		$this->theme_manager = $theme_manager;
	}

	public function register(): void {
		add_action( 'parse_request', array( $this, 'maybe_serve_asset' ), 0 );
	}

	public function maybe_serve_asset( WP $wp ): void {
		$relative_path = isset( $wp->query_vars[ RewriteRules::ASSET_QUERY_VAR ] ) ? (string) $wp->query_vars[ RewriteRules::ASSET_QUERY_VAR ] : '';

		if ( '' === $relative_path ) {
			return;
		}

		$file_path = $this->resolve_file_path( $relative_path );

		if ( '' === $file_path ) {
			status_header( 404 );
			exit;
		}

		$mime_type = $this->detect_mime_type( $file_path );

		status_header( 200 );
		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Length: ' . (string) filesize( $file_path ) );
		header( 'Cache-Control: ' . $this->cache_control_header( basename( $file_path ) ) );

		readfile( $file_path );
		exit;
	}

	protected function resolve_file_path( string $relative_path ): string {
		$relative_path = rawurldecode( $relative_path );
		$relative_path = ltrim( strtok( $relative_path, '?' ), '/' );
		$relative_path = str_replace( "\0", '', $relative_path );

		// Reject path-traversal sequences outright, before any filesystem access.
		// wp_normalize_path() collapses slashes but does NOT resolve '..', so a
		// string prefix check alone would let '/dist/../../wp-config.php' escape
		// the build root. Blocking '..' segments is the primary guard; the
		// realpath() containment check below is defense-in-depth against symlinks.
		if ( '' === $relative_path || preg_match( '#(^|/)\.\.(/|$)#', $relative_path ) ) {
			return '';
		}

		$build_root = $this->theme_manager->resolve_dist_path() ?? $this->config->build_root();
		$root       = wp_normalize_path( trailingslashit( $build_root ) );
		$file       = wp_normalize_path( $root . $relative_path );

		if ( 0 !== strpos( $file, $root ) ) {
			return '';
		}

		if ( ! is_readable( $file ) || ! is_file( $file ) ) {
			return '';
		}

		// Canonicalize and confirm the resolved real path is still inside the
		// build root — this catches any symlink inside dist/ that points outside.
		$real_root = realpath( untrailingslashit( $root ) );
		$real_file = realpath( $file );

		if ( false === $real_root || false === $real_file ) {
			return '';
		}

		if ( 0 !== strpos( wp_normalize_path( $real_file ), trailingslashit( wp_normalize_path( $real_root ) ) ) ) {
			return '';
		}

		return $file;
	}

	private function detect_mime_type( string $file_path ): string {
		// Explicit map for web asset types that WordPress restricts or misidentifies.
		$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		$map = array(
			'js'          => 'application/javascript',
			'mjs'         => 'application/javascript',
			'css'         => 'text/css',
			'json'        => 'application/json',
			'map'         => 'application/json',
			'webmanifest' => 'application/manifest+json',
			'svg'         => 'image/svg+xml',
			'woff'        => 'font/woff',
			'woff2'       => 'font/woff2',
			'ttf'         => 'font/ttf',
			'otf'         => 'font/otf',
			'ico'         => 'image/x-icon',
		);

		if ( isset( $map[ $ext ] ) ) {
			return $map[ $ext ];
		}

		$filetype = wp_check_filetype( $file_path );

		if ( ! empty( $filetype['type'] ) ) {
			return $filetype['type'];
		}

		if ( function_exists( 'mime_content_type' ) ) {
			$mime_type = mime_content_type( $file_path );

			if ( is_string( $mime_type ) && '' !== $mime_type ) {
				return $mime_type;
			}
		}

		return 'application/octet-stream';
	}

	protected function cache_control_header( string $filename ): string {
		if ( $this->looks_content_hashed( $filename ) ) {
			return 'public, max-age=31536000, immutable';
		}

		return 'public, max-age=3600';
	}

	/**
	 * Whether a filename carries a content hash and can be cached forever.
	 *
	 * Two hash styles are recognized:
	 * - Hex (webpack contenthash, older Vite): 8+ hex chars after a '.' or '-'
	 *   separator, e.g. app-a1b2c3d4.js.
	 * - Base64url (Rollup 4 / Vite 5+ default): exactly 8 chars of
	 *   [A-Za-z0-9_-] between separators, e.g. index.C19sr62o.js or
	 *   esm.-vnhfyxM.js. A segment made only of lowercase letters is NOT
	 *   treated as a hash, so names like app.renderer.js stay short-lived —
	 *   real base64url hashes virtually always contain a digit, an uppercase
	 *   letter, or '-'/'_'.
	 */
	protected function looks_content_hashed( string $filename ): bool {
		if ( 1 === preg_match( '/[.-][a-f0-9]{8,}\./i', $filename ) ) {
			return true;
		}

		if (
			1 === preg_match( '/[.-]([A-Za-z0-9_-]{8})\./', $filename, $matches )
			&& 1 !== preg_match( '/^[a-z]+$/', $matches[1] )
		) {
			return true;
		}

		return false;
	}
}
