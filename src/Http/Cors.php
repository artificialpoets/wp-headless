<?php
/**
 * REST CORS handling.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Http;

use WP;
use WPHeadless\Config\Config;
use WPHeadless\Contracts\Module;

class Cors implements Module {
	/**
	 * Config.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Constructor.
	 *
	 * @param Config $config Shared config.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'parse_request', array( $this, 'handle_preflight' ), 0 );
		add_filter( 'rest_pre_serve_request', array( $this, 'send_headers' ), 10, 4 );
	}

	/**
	 * Handle REST preflight requests.
	 *
	 * @param WP $wp Parsed request.
	 * @return void
	 */
	public function handle_preflight( WP $wp ): void {
		if ( ! $this->is_enabled() || 'OPTIONS' !== strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! $this->is_rest_request() ) {
			return;
		}

		$origin = $this->matched_origin();

		if ( '' === $origin ) {
			status_header( 403 );
			exit;
		}

		$this->emit_headers( $origin );
		status_header( 204 );
		exit;
	}

	/**
	 * Send CORS headers for REST responses.
	 *
	 * @param bool             $served Whether the request has been served.
	 * @param mixed            $result REST result.
	 * @param \WP_REST_Request $request Request instance.
	 * @param \WP_REST_Server  $server Server instance.
	 * @return bool
	 */
	public function send_headers( bool $served, $result, $request, $server ): bool {
		if ( ! $this->is_enabled() ) {
			return $served;
		}

		$origin = $this->matched_origin();

		if ( '' !== $origin ) {
			$this->emit_headers( $origin );
		}

		return $served;
	}

	/**
	 * Whether plugin CORS is enabled.
	 *
	 * @return bool
	 */
	private function is_enabled(): bool {
		return (bool) $this->config->get( 'cors.enabled', true );
	}

	/**
	 * Determine the current allowed origin.
	 *
	 * @return string
	 */
	private function matched_origin(): string {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';

		if ( '' === $origin ) {
			return '';
		}

		$allowed_origins = (array) $this->config->get( 'cors.allowed_origins', array() );
		$normalized      = $this->normalize_origin( $origin );

		foreach ( $allowed_origins as $allowed_origin ) {
			if ( '*' === $allowed_origin ) {
				return $normalized;
			}

			$allowed_normalized = $this->normalize_origin( (string) $allowed_origin );

			if ( '' !== $allowed_normalized && $normalized === $allowed_normalized ) {
				return $normalized;
			}

			if ( false !== strpos( (string) $allowed_origin, '*.' ) ) {
				$host           = (string) wp_parse_url( $normalized, PHP_URL_HOST );
				$allowed_scheme = (string) wp_parse_url( $allowed_origin, PHP_URL_SCHEME );
				$origin_scheme  = (string) wp_parse_url( $normalized, PHP_URL_SCHEME );
				$allowed_host   = (string) wp_parse_url( $allowed_origin, PHP_URL_HOST );

				if ( '' === $allowed_host && 0 === strpos( (string) $allowed_origin, '*.' ) ) {
					$allowed_host = (string) $allowed_origin;
				}

				$allowed_host = ltrim( $allowed_host, '*.' );

				if ( $allowed_scheme && $allowed_scheme !== $origin_scheme ) {
					continue;
				}

				if ( $host && $allowed_host && preg_match( '#(^|\.)' . preg_quote( $allowed_host, '#' ) . '$#i', $host ) ) {
					return $normalized;
				}
			}
		}

		return '';
	}

	/**
	 * Normalize a candidate origin to scheme://host[:port].
	 *
	 * @param string $origin Raw origin candidate.
	 * @return string
	 */
	protected function normalize_origin( string $origin ): string {
		$parts = wp_parse_url( $origin );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$normalized = $parts['scheme'] . '://' . $parts['host'];

		if ( ! empty( $parts['port'] ) ) {
			$normalized .= ':' . $parts['port'];
		}

		return $normalized;
	}

	/**
	 * Emit CORS headers.
	 *
	 * @param string $origin Matched origin.
	 * @return void
	 */
	private function emit_headers( string $origin ): void {
		header( 'Access-Control-Allow-Origin: ' . $origin );
		header( 'Access-Control-Allow-Methods: ' . implode( ', ', (array) $this->config->get( 'cors.allowed_methods', array() ) ) );
		header( 'Access-Control-Allow-Headers: ' . implode( ', ', (array) $this->config->get( 'cors.allowed_headers', array() ) ) );
		header( 'Access-Control-Expose-Headers: ' . implode( ', ', (array) $this->config->get( 'cors.exposed_headers', array() ) ) );
		header( 'Access-Control-Max-Age: ' . (string) $this->config->get( 'cors.max_age', 86400 ) );
		header( 'Vary: Origin' );

		if ( $this->config->get( 'cors.allow_credentials', true ) ) {
			header( 'Access-Control-Allow-Credentials: true' );
		}
	}

	/**
	 * Determine whether the current request targets the REST API.
	 *
	 * @return bool
	 */
	private function is_rest_request(): bool {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';

		if ( false !== strpos( $request_uri, 'rest_route=' ) ) {
			return true;
		}

		$rest_path = (string) wp_parse_url( rest_url(), PHP_URL_PATH );

		return '' !== $rest_path && false !== strpos( $request_uri, $rest_path );
	}
}
