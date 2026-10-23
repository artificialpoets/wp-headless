<?php
/**
 * Content negotiation on the Accept header (RFC 9110 §12.5.1).
 *
 * @package WPHeadless
 */

namespace WPHeadless\Http;

/**
 * Whether a request is a JSON client, decided the way a server negotiates
 * content rather than by substring.
 *
 * Core's wp_is_json_request() is true whenever Accept lists any JSON type,
 * at any quality. Used as the frontend gate, that let one request carrying
 * `Accept: application/json, text/html` get WordPress's native render of a
 * page URL. A CDN whose cache key leaves Accept out (most do) then served
 * that render to every visitor of the URL. Here a request is a JSON client
 * only when it prefers JSON to HTML, or when its body is JSON.
 */
final class AcceptNegotiation {

	/**
	 * Whether the current request is a JSON client.
	 */
	public static function is_json_client(): bool {
		$content_type = isset( $_SERVER['CONTENT_TYPE'] ) ? (string) wp_unslash( $_SERVER['CONTENT_TYPE'] ) : '';
		if ( '' !== $content_type && self::is_json_type( $content_type ) ) {
			return true;
		}
		$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? (string) wp_unslash( $_SERVER['HTTP_ACCEPT'] ) : '';
		return self::prefers_json( $accept );
	}

	/**
	 * Whether an Accept header ranks JSON above HTML. A tie goes to HTML,
	 * so `*` / `*` (curl's default) and `application/json, text/html` both
	 * get the page. An empty header asks for nothing in particular: HTML.
	 *
	 * @param string $accept Raw Accept header.
	 */
	public static function prefers_json( string $accept ): bool {
		$ranges = self::parse( $accept );
		if ( ! $ranges ) {
			return false;
		}
		return self::quality( $ranges, 'application', 'json' ) > max(
			self::quality( $ranges, 'text', 'html' ),
			self::quality( $ranges, 'application', 'xhtml+xml' )
		);
	}

	/**
	 * Whether a media type (parameters allowed) is JSON: application/json
	 * or a +json suffix type, as core's wp_is_json_media_type() reads it.
	 *
	 * @param string $media_type e.g. `application/json; charset=utf-8`.
	 */
	public static function is_json_type( string $media_type ): bool {
		$type = strtolower( trim( explode( ';', $media_type, 2 )[0] ) );
		return 'application/json' === $type || ( '' !== $type && '+json' === substr( $type, -5 ) );
	}

	/**
	 * Media ranges with their quality, e.g. `[['text','html',1.0], ['*','*',0.8]]`.
	 * Malformed ranges are skipped.
	 *
	 * @param string $accept Raw Accept header.
	 * @return array<int,array{0:string,1:string,2:float}>
	 */
	private static function parse( string $accept ): array {
		$ranges = array();
		foreach ( explode( ',', $accept ) as $part ) {
			$params = explode( ';', $part );
			$range  = strtolower( trim( array_shift( $params ) ) );
			if ( ! preg_match( '#^([a-z0-9!\#$&^_.+*-]+)/([a-z0-9!\#$&^_.+*-]+)$#', $range, $m ) ) {
				continue;
			}
			$q = 1.0;
			foreach ( $params as $param ) {
				$pair = explode( '=', $param, 2 );
				if ( 2 === count( $pair ) && 'q' === strtolower( trim( $pair[0] ) ) && is_numeric( trim( $pair[1] ) ) ) {
					$q = max( 0.0, min( 1.0, (float) trim( $pair[1] ) ) );
				}
			}
			$ranges[] = array( $m[1], $m[2], $q );
		}
		return $ranges;
	}

	/**
	 * The quality a list of ranges gives one media type: that of the most
	 * specific matching range (type/subtype, then type/*, then *&#47;*), 0
	 * when none matches. For JSON, `application/<x>+json` counts as exact.
	 *
	 * @param array<int,array{0:string,1:string,2:float}> $ranges  Parsed ranges.
	 * @param string                                       $type    e.g. `text`.
	 * @param string                                       $subtype e.g. `html`.
	 */
	private static function quality( array $ranges, string $type, string $subtype ): float {
		$best = array( -1, 0.0 ); // specificity, quality
		foreach ( $ranges as $range ) {
			list( $r_type, $r_subtype, $q ) = $range;
			if ( $r_type === $type && ( $r_subtype === $subtype || ( 'json' === $subtype && '+json' === substr( $r_subtype, -5 ) ) ) ) {
				$specificity = 2;
			} elseif ( $r_type === $type && '*' === $r_subtype ) {
				$specificity = 1;
			} elseif ( '*' === $r_type && '*' === $r_subtype ) {
				$specificity = 0;
			} else {
				continue;
			}
			if ( $specificity > $best[0] || ( $specificity === $best[0] && $q > $best[1] ) ) {
				$best = array( $specificity, $q );
			}
		}
		return $best[1];
	}
}
