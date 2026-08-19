<?php
/**
 * Frontend bridge for headless theme builds.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Runtime;

use WPHeadless\Config\Config;
use WPHeadless\Contracts\Module;
use WPHeadless\Http\ConditionalRequest;
use WPHeadless\Theme\ThemeManager;

class FrontendBridge implements Module {
	/** @var Config */
	private Config $config;

	/** @var RequestMatcher */
	private RequestMatcher $matcher;

	/** @var HtmlDocument */
	private HtmlDocument $document;

	/** @var RequestDataBuilder */
	private RequestDataBuilder $request_data;

	public function __construct( Config $config, ThemeManager $theme_manager ) {
		$this->config       = $config;
		$this->request_data = new RequestDataBuilder();
		$runtime_data       = new RuntimeDataBuilder( $config, $theme_manager, $this->request_data );

		$this->matcher  = new RequestMatcher( $config, $theme_manager );
		$this->document = new HtmlDocument( $config, $theme_manager, $runtime_data );
	}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_serve_frontend' ), 0 );
	}

	public function maybe_serve_frontend(): void {
		if ( ! $this->matcher->should_serve_frontend() ) {
			return;
		}

		// Ask the resolver about the current URL — it recognises /login/, /books/,
		// attachment ids, etc. that WP itself would surface as 404. The React app
		// renders these correctly, so we should respond with the right HTTP status.
		$current_url = home_url( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/' );
		$resolved    = $this->request_data->for_url( $current_url );
		$render_url  = $current_url;

		// The URL resolver reimplements WordPress routing by hand, so it 404s
		// any route it doesn't know: plugin rewrites (WooCommerce endpoints,
		// bbPress), pretty search (/search/term/), custom permastructs. But
		// WordPress's own main query already ran for this request and IS
		// authoritative. When the resolver couldn't classify a URL that WP
		// resolved fine, fall back to WP's real query conditionals for both the
		// status and the payload — otherwise a valid page 404s. Passing a null
		// render URL makes HtmlDocument build the payload from for_current_request().
		if ( 'unresolved' === ( $resolved['kind'] ?? '' ) && ! is_404() ) {
			$resolved   = $this->request_data->for_current_request();
			$render_url = null;
		}

		$is_404 = self::resolve_is_404( $resolved, is_404() );

		// Honour WordPress's canonical redirects before serving: ?p=123 →
		// pretty permalink, missing/extra trailing slash, and renamed slugs
		// (wp_old_slug_redirect) should 301 rather than serve a 200 duplicate.
		// These normally hook template_redirect at priority 10, but we run at
		// priority 0 and exit — so invoke them here. Only for URLs WordPress
		// itself recognises: synthetic routes (auth pages, resolver-only kinds)
		// have no real queried object to canonicalise, and is_404() is true for
		// them, so redirect_canonical() would no-op or misfire. redirect_canonical()
		// performs the redirect and exits when it decides to; otherwise it returns.
		if ( ! $is_404 && ! is_404() && $this->config->get( 'frontend.canonical_redirects', true ) ) {
			if ( function_exists( 'wp_old_slug_redirect' ) ) {
				wp_old_slug_redirect();
			}
			redirect_canonical();
		}

		// RENDER FIRST, emit status/headers after. HtmlDocument::render() fires
		// wp_head()/wp_footer() inside output buffers; rendering into a variable
		// means third-party callbacks that call header() land BEFORE our
		// emission — the cache policy can then inspect headers_list() and stand
		// down, and the body is available to hash for the ETag. (The resolved
		// URL is passed through so the runtime payload uses for_url(); when we
		// fell back to WP's real query above, $render_url is null so the
		// payload is built from for_current_request() to match.)
		$html = $this->document->render( $render_url );

		// The body embeds the REST nonce, so this ETag rotates with the nonce
		// tick automatically — a 304 can only ever re-affirm a cached body
		// whose nonce is still the currently-served one.
		$etag         = '"' . md5( $html ) . '"';
		$not_modified = ! $is_404
			&& ConditionalRequest::matches_etag( $etag, ConditionalRequest::if_none_match() );

		if ( ! headers_sent() ) { // a render-time flush() forfeits header control; degrade gracefully.
			status_header( $not_modified ? 304 : ( $is_404 ? 404 : 200 ) );
			if ( ! $not_modified ) {
				header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
			}
			// A 304 re-sends the cache directives (RFC 7232 §4.1).
			$this->send_cache_headers( $is_404 );
			if ( ! $is_404 ) {
				header( 'ETag: ' . $etag );
			}
		}

		if ( $not_modified ) {
			exit; // no body on 304.
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Emit the response's cache policy via the `wp_headless_cache_headers`
	 * filter. `null` (nobody decided — e.g. the cache module is disabled)
	 * falls back to core's nocache_headers(), the pre-0.3 behavior; an empty
	 * array emits nothing (defer to headers already sent during render); a
	 * map is emitted verbatim.
	 *
	 * @param bool $is_404 Whether the response is a 404.
	 */
	private function send_cache_headers( bool $is_404 ): void {
		/**
		 * Filters the cache headers for the served document shell.
		 *
		 * @param array<string,string>|null $headers null = undecided (nocache
		 *                                           fallback), array() = emit
		 *                                           nothing, map = emit these.
		 * @param array<string,mixed>       $context { is_404: bool }.
		 * @param Config                    $config  Plugin configuration.
		 */
		$headers = apply_filters( 'wp_headless_cache_headers', null, array( 'is_404' => $is_404 ), $this->config );
		if ( ! is_array( $headers ) ) {
			nocache_headers();
			return;
		}
		foreach ( $headers as $name => $value ) {
			header( $name . ': ' . $value );
		}
	}

	/**
	 * Decide the response status from the resolver's verdict and WordPress's.
	 *
	 * The resolver matches route SHAPE — a permalink structure plus an object
	 * that exists — and never asks whether the query behind it returns
	 * anything. So it happily resolves `/blog/page/2/` on a two-post blog, or a
	 * term archive whose every post is a draft, and both used to serve a 200
	 * carrying the app's "not found" view. A 200 that renders not-found is
	 * worse than a real 404: crawlers bank it as thin content instead of
	 * dropping the URL.
	 *
	 * WordPress's own main query already ran for this request and knows whether
	 * there is anything to show, so its verdict wins for every route WordPress
	 * can actually query.
	 *
	 * The exception is the routes the resolver owns outright. Auth pages
	 * (`/login/`, `/profile/`, …) exist only in the React app, so WordPress
	 * 404s them by definition — deferring to WP there would 404 every login
	 * screen. `is_auth` marks them, and for those the resolver stays
	 * authoritative.
	 *
	 * @param array<string, mixed> $resolved  Resolver output for the URL.
	 * @param bool                 $wp_is_404 WordPress's own main-query verdict.
	 * @return bool True when the response should carry a 404.
	 */
	public static function resolve_is_404( array $resolved, bool $wp_is_404 ): bool {
		if ( ! empty( $resolved['is_404'] ) ) {
			return true;
		}

		// Resolver-owned synthetic routes: WordPress has no concept of them.
		if ( ! empty( $resolved['is_auth'] ) ) {
			return false;
		}

		return $wp_is_404;
	}
}
