<?php
/**
 * HTTP cache policy for the served document shell.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Http;

use WPHeadless\Config\Config;
use WPHeadless\Contracts\Module;
use WPHeadless\Runtime\RuntimeCache;

/**
 * Decides the Cache-Control policy for headless shell responses.
 *
 * Anonymous, cookie-free GET/HEAD responses become publicly cacheable
 * (`public, max-age, s-maxage, stale-while-revalidate` + `Vary: Cookie`) so
 * CDNs and page caches can finally store a headless page; anything carrying
 * WordPress identity — a logged-in user, an auth/postpass/comment cookie, or
 * a per-visitor REST nonce — stays `private, no-store` exactly as before.
 *
 * The nonce is the boundary the TTLs are built around: the served payload
 * embeds `wp_create_nonce( 'wp_rest' )`, which on a stock install is
 * identical for every anonymous visitor but rotates on a 12-hour tick
 * (validity 12–24 h). Public lifetimes are therefore clamped well below the
 * tick, and when a plugin makes anonymous nonces per-visitor by filtering
 * `nonce_user_logged_out` (WooCommerce does), the policy stands down to
 * private automatically — a shared cache must never replay one visitor's
 * nonce to another.
 *
 * The module attaches via the `wp_headless_cache_headers` filter consumed by
 * the frontend bridge; hosts adjust or replace the policy on the same filter.
 */
class CachePolicy implements Module {
	/**
	 * Public max-age ceiling (1 h) — keep browser copies far inside the tick.
	 */
	const MAX_AGE_CEILING = 3600;

	/**
	 * Public s-maxage ceiling (6 h) — half the 12 h nonce tick.
	 */
	const S_MAXAGE_CEILING = 21600;

	/** @var Config */
	private Config $config;

	/**
	 * @param Config $config Plugin configuration.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Hook registrations only. The cache module also owns the runtime-payload
	 * cache's invalidation triggers — caching without them would never
	 * invalidate, so both live behind the same `modules.cache.enabled` gate.
	 */
	public function register(): void {
		add_filter( 'wp_headless_cache_headers', array( $this, 'filter_headers' ), 10, 2 );
		add_filter( 'rest_post_dispatch', array( $this, 'filter_rest_response' ), 20, 3 );
		RuntimeCache::register_invalidation_hooks();
	}

	/**
	 * Gather environment facts, then delegate to the pure decision seam.
	 *
	 * @param array<string,string>|null $headers Earlier filter's decision, if any.
	 * @param array<string,mixed>       $context { is_404: bool }.
	 * @return array<string,string>|null
	 */
	public function filter_headers( $headers, array $context ) {
		if ( is_array( $headers ) ) {
			return $headers; // an earlier filter already decided.
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		if ( 'GET' !== $method && 'HEAD' !== $method ) {
			return $this->private_headers();
		}
		if ( $this->response_already_constrained() ) {
			// Render-time code emitted Cache-Control or minted a cookie —
			// its intent wins; emit nothing on top.
			return array();
		}
		return $this->cache_headers(
			! empty( $context['is_404'] ),
			is_user_logged_in(),
			$this->has_wp_cookies( array_keys( (array) $_COOKIE ) ),
			(bool) has_filter( 'nonce_user_logged_out' )
		);
	}

	/**
	 * The pure decision table.
	 *
	 * @param bool $is_404               Whether the response is a 404.
	 * @param bool $is_logged_in         Whether a user is logged in.
	 * @param bool $has_cookies          Whether the request carries WP identity cookies.
	 * @param bool $nonce_is_per_visitor Whether anonymous nonces are per-visitor.
	 * @return array<string,string>
	 */
	protected function cache_headers( bool $is_404, bool $is_logged_in, bool $has_cookies, bool $nonce_is_per_visitor ): array {
		if ( $is_logged_in || $has_cookies || $nonce_is_per_visitor ) {
			return $this->private_headers();
		}
		if ( $is_404 ) {
			$not_found_s_maxage = absint( $this->config->get( 'modules.cache.not_found_s_maxage', 60 ) );
			return array(
				// Browsers always revalidate 404s; edges absorb 404 storms briefly.
				'Cache-Control' => 'public, max-age=0, s-maxage=' . $not_found_s_maxage,
				'Vary'          => 'Cookie',
			);
		}
		$max_age  = min( absint( $this->config->get( 'modules.cache.max_age', 60 ) ), self::MAX_AGE_CEILING );
		$s_maxage = min( absint( $this->config->get( 'modules.cache.s_maxage', 300 ) ), self::S_MAXAGE_CEILING );
		$swr      = absint( $this->config->get( 'modules.cache.stale_while_revalidate', 3600 ) );
		return array(
			'Cache-Control' => sprintf( 'public, max-age=%d, s-maxage=%d, stale-while-revalidate=%d', $max_age, $s_maxage, $swr ),
			'Vary'          => 'Cookie',
		);
	}

	/**
	 * Whether any cookie name signals WordPress identity or personalization.
	 *
	 * @param array<int,string> $cookie_names Request cookie names.
	 */
	protected function has_wp_cookies( array $cookie_names ): bool {
		foreach ( $cookie_names as $name ) {
			$name = (string) $name;
			if (
				0 === strpos( $name, 'wordpress_' ) ||       // logged_in / sec / test_cookie
				0 === strpos( $name, 'wp-postpass_' ) ||
				0 === strpos( $name, 'wp-settings-' ) ||
				0 === strpos( $name, 'comment_author_' )
			) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The stand-down policy — identical intent to core's nocache_headers().
	 *
	 * @return array<string,string>
	 */
	protected function private_headers(): array {
		return array(
			'Cache-Control' => 'private, no-store, max-age=0',
		);
	}

	/**
	 * Whether render-time code already constrained cacheability.
	 */
	protected function response_already_constrained(): bool {
		foreach ( headers_list() as $header ) {
			if ( 0 === stripos( $header, 'Cache-Control:' ) || 0 === stripos( $header, 'Set-Cookie:' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Emit cache headers on anonymous REST GET reads for allowlisted routes,
	 * so CDNs/proxies can cache the API calls a headless frontend makes on
	 * client-side navigation. Same stand-downs as the document policy.
	 *
	 * @param mixed            $response Result to send (WP_REST_Response).
	 * @param mixed            $server   REST server instance.
	 * @param mixed            $request  The request (WP_REST_Request).
	 * @return mixed
	 */
	public function filter_rest_response( $response, $server, $request ) {
		unset( $server );
		if ( ! is_object( $response ) || ! method_exists( $response, 'header' ) ) {
			return $response;
		}
		if ( ! is_object( $request ) || ! method_exists( $request, 'get_method' ) || 'GET' !== $request->get_method() ) {
			return $response;
		}
		if ( method_exists( $response, 'get_status' ) && 200 !== (int) $response->get_status() ) {
			return $response;
		}
		$headers = $this->rest_cache_headers(
			(string) $request->get_route(),
			is_user_logged_in(),
			$this->has_wp_cookies( array_keys( (array) $_COOKIE ) ),
			(bool) has_filter( 'nonce_user_logged_out' )
		);
		if ( null === $headers ) {
			return $response;
		}
		foreach ( $headers as $name => $value ) {
			$response->header( $name, $value );
		}
		return $response;
	}

	/**
	 * The pure REST decision seam. Null = leave the response untouched
	 * (route not allowlisted, or the feature is off).
	 *
	 * @param string $route                The REST route (no query string).
	 * @param bool   $is_logged_in         Whether a user is logged in.
	 * @param bool   $has_cookies          Whether WP identity cookies are present.
	 * @param bool   $nonce_is_per_visitor Whether anonymous nonces are per-visitor.
	 * @return array<string,string>|null
	 */
	protected function rest_cache_headers( string $route, bool $is_logged_in, bool $has_cookies, bool $nonce_is_per_visitor ): ?array {
		if ( false === $this->config->get( 'modules.cache.rest', true ) ) {
			return null;
		}
		if ( ! $this->rest_route_cacheable( $route ) ) {
			return null;
		}
		if ( $is_logged_in || $has_cookies || $nonce_is_per_visitor ) {
			return $this->private_headers();
		}
		$max_age  = min( absint( $this->config->get( 'modules.cache.rest_max_age', 0 ) ), self::MAX_AGE_CEILING );
		$s_maxage = min( absint( $this->config->get( 'modules.cache.rest_s_maxage', 300 ) ), self::S_MAXAGE_CEILING );
		$swr      = absint( $this->config->get( 'modules.cache.rest_stale_while_revalidate', 600 ) );
		return array(
			'Cache-Control' => sprintf( 'public, max-age=%d, s-maxage=%d, stale-while-revalidate=%d', $max_age, $s_maxage, $swr ),
			'Vary'          => 'Cookie, Origin',
		);
	}

	/**
	 * Exact-match allowlist with a structural safety net: never per-user or
	 * by-id routes, never route templates — even when operator-added.
	 *
	 * @param string $route The REST route.
	 */
	protected function rest_route_cacheable( string $route ): bool {
		$default = array(
			'/' . untrailingslashit( (string) $this->config->get( 'namespace', 'wp-headless/v1' ) ) . '/runtime',
			'/' . untrailingslashit( (string) $this->config->get( 'namespace', 'wp-headless/v1' ) ) . '/resolve',
			'/' . untrailingslashit( (string) $this->config->get( 'namespace', 'wp-headless/v1' ) ) . '/menus',
		);
		$extra = $this->config->get( 'modules.cache.rest_routes', array() );
		if ( is_array( $extra ) ) {
			foreach ( $extra as $r ) {
				if ( is_string( $r ) && '' !== $r && '/' === $r[0] ) {
					$default[] = untrailingslashit( $r );
				}
			}
		}
		if ( ! in_array( untrailingslashit( $route ), $default, true ) ) {
			return false;
		}
		// Structural denials mirror the safety bar of host-side REST caches.
		if ( preg_match( '#/(users|settings|me)(/|$)#i', $route ) || preg_match( '#/\d+(/|$)#', $route ) || false !== strpos( $route, '(?P' ) ) {
			return false;
		}
		return true;
	}
}
