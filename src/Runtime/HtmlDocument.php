<?php
/**
 * HTML document rewriting and runtime injection.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Runtime;

use WPHeadless\Config\Config;
use WPHeadless\Theme\ThemeManager;

class HtmlDocument {
	/** @var Config */
	private Config $config;

	/** @var ThemeManager */
	private ThemeManager $theme_manager;

	/** @var RuntimeDataBuilder */
	private RuntimeDataBuilder $runtime_data;

	public function __construct( Config $config, ThemeManager $theme_manager, RuntimeDataBuilder $runtime_data ) {
		$this->config       = $config;
		$this->theme_manager = $theme_manager;
		$this->runtime_data = $runtime_data;
	}

	public function render( ?string $resolved_url = null ): string {
		$index_path = $this->theme_manager->resolve_index_path() ?? $this->config->index_file_path();
		$html       = (string) file_get_contents( $index_path );
		$runtime_data = $this->runtime_data->build( $resolved_url );

		$html = $this->rewrite_asset_urls( $html );

		if ( $this->config->get( 'frontend.inject_wp_head', true ) ) {
			$html = $this->inject_before_closing_tag( $html, '</head>', $this->capture_wp_head() );
		}

		$runtime_json   = wp_json_encode( $runtime_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$runtime_json   = str_replace( '</', '<\/', (string) $runtime_json );
		$runtime_script = '<script>window.WP_HEADLESS = ' . $runtime_json . ';</script>';
		$html           = $this->inject_before_closing_tag( $html, '</head>', $runtime_script );

		if ( $this->config->get( 'frontend.inject_wp_footer', true ) ) {
			$html = $this->inject_before_closing_tag( $html, '</body>', $this->capture_wp_footer() );
		}

		return (string) apply_filters( 'wp_headless_document_html', $html, $runtime_data, $this->config );
	}

	protected function rewrite_asset_urls( string $html ): string {
		$html = preg_replace_callback(
			'/(<(?:script|img|source)\b[^>]*\bsrc=)(["\'])([^"\']+)\2/i',
			function ( array $matches ) {
				return $matches[1] . $matches[2] . $this->rewrite_public_url( $matches[3] ) . $matches[2];
			},
			$html
		);

		$html = preg_replace_callback(
			'/(<(?:link)\b[^>]*\bhref=)(["\'])([^"\']+)\2/i',
			function ( array $matches ) {
				return $matches[1] . $matches[2] . $this->rewrite_public_url( $matches[3] ) . $matches[2];
			},
			(string) $html
		);

		// Strip the crossorigin attribute from script and link tags.
		// Vite adds it for CDN scenarios; with same-origin serving it forces CORS
		// mode which breaks requests when no Access-Control-Allow-Origin header is present.
		return (string) preg_replace(
			'/(<(?:script|link)\b[^>]*?)\s+crossorigin(?:=["\'][^"\']*["\'])?(?=[\s>\/])/i',
			'$1',
			(string) $html
		);
	}

	protected function rewrite_public_url( string $url ): string {
		if ( '' === $url ) {
			return $url;
		}

		$original_url = $url;

		if ( preg_match( '#^(?:[a-z]+:)?//#i', $url ) || 0 === strpos( $url, 'data:' ) || 0 === strpos( $url, 'mailto:' ) || 0 === strpos( $url, 'tel:' ) || 0 === strpos( $url, '#' ) ) {
			return $url;
		}

		$fragment = '';
		if ( false !== strpos( $url, '#' ) ) {
			list( $url, $fragment ) = explode( '#', $url, 2 );
			$fragment               = '#' . $fragment;
		}

		$query_string = '';
		if ( false !== strpos( $url, '?' ) ) {
			list( $url, $query_string ) = explode( '?', $url, 2 );
			$query_string               = '?' . $query_string;
		}

		$url = preg_replace( '#^(?:\./)+#', '', $url );
		$url = ltrim( (string) $url, '/' );

		if ( ! $this->should_rewrite_url( $url ) ) {
			return $original_url;
		}

		return untrailingslashit( $this->config->asset_base_url() ) . '/' . $url . $query_string . $fragment;
	}

	protected function should_rewrite_url( string $url ): bool {
		if ( '' === $url ) {
			return false;
		}

		if ( 0 === strpos( $url, 'assets/' ) ) {
			return true;
		}

		return 1 === preg_match( '/\.(?:css|js|mjs|json|map|ico|png|jpe?g|gif|svg|webp|avif|woff2?|ttf|otf|eot|txt|webmanifest)$/i', $url );
	}

	protected function inject_before_closing_tag( string $html, string $closing_tag, string $content ): string {
		if ( '' === $content ) {
			return $html;
		}

		$position = strripos( $html, $closing_tag );

		if ( false === $position ) {
			return $html . "\n" . $content;
		}

		return substr( $html, 0, $position ) . $content . "\n" . substr( $html, $position );
	}

	private function capture_wp_head(): string {
		ob_start();
		wp_head();
		return (string) ob_get_clean();
	}

	private function capture_wp_footer(): string {
		ob_start();
		wp_footer();
		return (string) ob_get_clean();
	}
}
