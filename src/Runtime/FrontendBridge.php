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
		$is_404      = ! empty( $resolved['is_404'] );

		// Fall back to WP's own is_404() check if the resolver itself didn't reject it.
		if ( ! $is_404 && is_404() && 'unresolved' === ( $resolved['kind'] ?? '' ) ) {
			$is_404 = true;
		}

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
		// first render.
		echo $this->document->render( $current_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
