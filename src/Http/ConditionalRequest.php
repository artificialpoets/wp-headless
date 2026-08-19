<?php
/**
 * Conditional-request helpers (RFC 7232).
 *
 * @package WPHeadless
 */

namespace WPHeadless\Http;

/**
 * Pure helpers for ETag / If-None-Match / If-Modified-Since handling, shared
 * by the frontend bridge (document shell) and the asset proxy. All decision
 * logic is static and side-effect free so it can be unit-tested directly;
 * callers own reading validators, emitting headers, and short-circuiting.
 */
final class ConditionalRequest {

	/**
	 * The request's If-None-Match header, raw ('' when absent).
	 */
	public static function if_none_match(): string {
		return isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? (string) wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) : '';
	}

	/**
	 * The request's If-Modified-Since header, raw ('' when absent).
	 */
	public static function if_modified_since(): string {
		return isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ? (string) wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) : '';
	}

	/**
	 * Whether a response ETag matches an If-None-Match header value.
	 *
	 * Handles the '*' wildcard, comma-separated candidate lists, and 'W/'
	 * weak prefixes using weak comparison (RFC 7232 §3.2 mandates weak
	 * comparison for If-None-Match).
	 *
	 * @param string $etag          The response's ETag, quotes included.
	 * @param string $if_none_match The raw If-None-Match header value.
	 */
	public static function matches_etag( string $etag, string $if_none_match ): bool {
		if ( '' === $etag || '' === trim( $if_none_match ) ) {
			return false;
		}
		if ( '*' === trim( $if_none_match ) ) {
			return true;
		}
		$target = self::strip_weak( trim( $etag ) );
		foreach ( explode( ',', $if_none_match ) as $candidate ) {
			if ( self::strip_weak( trim( $candidate ) ) === $target ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the resource changed after the If-Modified-Since date.
	 *
	 * Malformed or empty dates return true (treat as modified — serve the
	 * full response, never a wrong 304).
	 *
	 * @param int    $mtime             Resource modification time (Unix).
	 * @param string $if_modified_since The raw If-Modified-Since header value.
	 */
	public static function modified_since( int $mtime, string $if_modified_since ): bool {
		if ( '' === trim( $if_modified_since ) ) {
			return true;
		}
		$since = strtotime( $if_modified_since );
		if ( false === $since ) {
			return true;
		}
		return $mtime > $since;
	}

	/**
	 * The full precedence glue: If-None-Match, when present, decides alone
	 * (If-Modified-Since MUST then be ignored, RFC 7232 §6); otherwise
	 * If-Modified-Since decides; with neither header the response is modified.
	 *
	 * @param string $etag  The response's ETag, quotes included.
	 * @param int    $mtime Resource modification time (Unix).
	 */
	public static function not_modified( string $etag, int $mtime ): bool {
		$if_none_match = self::if_none_match();
		if ( '' !== trim( $if_none_match ) ) {
			return self::matches_etag( $etag, $if_none_match );
		}
		$if_modified_since = self::if_modified_since();
		if ( '' !== trim( $if_modified_since ) ) {
			return ! self::modified_since( $mtime, $if_modified_since );
		}
		return false;
	}

	/**
	 * Drop a 'W/' weak-validator prefix for weak comparison.
	 */
	private static function strip_weak( string $etag ): string {
		if ( 0 === stripos( $etag, 'W/' ) ) {
			return substr( $etag, 2 );
		}
		return $etag;
	}
}
