<?php
/**
 * Frontend bridge for headless theme builds.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Runtime;

use WPHeadless\Config\Config;
use WPHeadless\Contracts\Module;
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

		status_header( $is_404 ? 404 : 200 );
		header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
		nocache_headers();

		// Pass the resolved URL through so HtmlDocument's runtime payload uses
		// for_url() rather than for_current_request() — that's what makes
		// `/profile/`, `/books/`, auth routes and the like recognised as
		// their proper `kind` in `window.WP_HEADLESS.request` from the very
		// first render. When we fell back to WP's real query above, $render_url
		// is null so the payload is built from for_current_request() to match.
		echo $this->document->render( $render_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
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
