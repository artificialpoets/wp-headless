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

	private function resolve_file_path( string $relative_path ): string {
		$relative_path = rawurldecode( $relative_path );
		$relative_path = ltrim( strtok( $relative_path, '?' ), '/' );
		$relative_path = str_replace( "\0", '', $relative_path );

		$build_root = $this->theme_manager->resolve_dist_path() ?? $this->config->build_root();
		$root       = wp_normalize_path( trailingslashit( $build_root ) );
		$file       = wp_normalize_path( $root . $relative_path );

		if ( 0 !== strpos( $file, $root ) ) {
			return '';
		}

		if ( ! is_readable( $file ) || ! is_file( $file ) ) {
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
		if ( 1 === preg_match( '/[.-][a-f0-9]{8,}\./i', $filename ) ) {
			return 'public, max-age=31536000, immutable';
		}

		return 'public, max-age=3600';
	}
}
