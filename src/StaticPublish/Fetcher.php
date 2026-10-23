<?php
/**
 * Anonymous reads from the site's own origin.
 *
 * @package WPHeadless
 */

namespace WPHeadless\StaticPublish;

/**
 * Reads a path from the WordPress origin exactly as an anonymous browser
 * would: no cookies, no redirects followed, and Chrome's navigation Accept
 * header. The stored copy is then the same document a visitor gets.
 *
 * The Accept header matters. WordPress treats a request whose Accept lists
 * `application/json` as a JSON request (`wp_is_json_request()`), and
 * wp-headless stands down for it, so an Accept that names JSON would store
 * WordPress's classic render instead of the app shell.
 *
 * By default the read goes to the public URL. When the public hostname
 * resolves to a CDN (which may itself serve the static copy), set
 * `modules.static_publish.origin` to the origin's own address, e.g.
 * `http://127.0.0.1:8080`: the read then goes there with the public `Host`
 * header and, for an https site, `X-Forwarded-Proto: https`.
 */
class Fetcher {
	/** Chrome's Accept header for a page navigation. */
	public const ACCEPT_HTML = 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8';

	/** Request header that marks the publisher's own reads. */
	public const HEADER = 'X-WP-Headless-Static';

	/** @var string Scheme, host and port of the public site. */
	private string $public_base;

	/** @var string Public host (and port), sent as Host to an origin. */
	private string $host;

	/** @var string Origin base URL, '' to read from the public URL. */
	private string $origin;

	/** @var int Seconds before a read counts as failed. */
	private int $timeout;

	/**
	 * @param string $home    The site's home URL.
	 * @param string $origin  Origin base URL, '' for the public URL.
	 * @param int    $timeout Seconds.
	 */
	public function __construct( string $home, string $origin = '', int $timeout = 30 ) {
		$parts             = (array) wp_parse_url( $home );
		$this->host        = ( $parts['host'] ?? '' ) . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$this->public_base = ( $parts['scheme'] ?? 'https' ) . '://' . $this->host;
		$this->origin      = rtrim( $origin, '/' );
		$this->timeout     = max( 1, $timeout );
	}

	/**
	 * GET one path.
	 *
	 * @param string $path Absolute path with optional query, e.g. `/about-us/`.
	 * @return array{status:int,headers:array<string,string>,body:string}|null
	 *         Null when the read failed in transport (timeout, refused).
	 */
	public function get( string $path ): ?array {
		$headers = array(
			'Accept'     => self::ACCEPT_HTML,
			self::HEADER => '1',
		);
		$base    = $this->public_base;
		if ( '' !== $this->origin ) {
			$base            = $this->origin;
			$headers['Host'] = $this->host;
			if ( 0 === strpos( $this->public_base, 'https://' ) ) {
				$headers['X-Forwarded-Proto'] = 'https';
			}
		}

		$response = wp_remote_get(
			$base . '/' . ltrim( $path, '/' ),
			array(
				'timeout'     => $this->timeout,
				'redirection' => 0,
				'headers'     => $headers,
				'cookies'     => array(),
				'user-agent'  => 'Mozilla/5.0 (compatible; wp-headless-static-publisher/' . ( defined( 'WP_HEADLESS_VERSION' ) ? WP_HEADLESS_VERSION : '0' ) . ')',
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status <= 0 ) {
			return null;
		}

		$lower = array();
		foreach ( (array) $this->header_list( wp_remote_retrieve_headers( $response ) ) as $name => $value ) {
			$lower[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
		}
		return array(
			'status'  => $status,
			'headers' => $lower,
			'body'    => (string) wp_remote_retrieve_body( $response ),
		);
	}

	/**
	 * Headers as a plain array (core returns a case-insensitive dictionary).
	 *
	 * @param mixed $headers Headers from wp_remote_retrieve_headers().
	 * @return array<string,mixed>
	 */
	private function header_list( $headers ): array {
		if ( is_array( $headers ) ) {
			return $headers;
		}
		if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
			return (array) $headers->getAll();
		}
		return $headers instanceof \Traversable ? iterator_to_array( $headers ) : array();
	}
}
