<?php
/**
 * Frontend request matching.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Runtime;

use WPHeadless\Config\Config;
use WPHeadless\Contracts\Module;
use WPHeadless\Routing\RewriteRules;
use WPHeadless\Seo\LlmsTxt;
use WPHeadless\Theme\ThemeManager;

class RequestMatcher {
	/** @var Config */
	private Config $config;

	/** @var ThemeManager */
	private ThemeManager $theme_manager;

	public function __construct( Config $config, ThemeManager $theme_manager ) {
		$this->config        = $config;
		$this->theme_manager = $theme_manager;
	}

	public function should_serve_frontend(): bool {
		$has_build = $this->theme_manager->has_build() || $this->config->frontend_available();
		$should_serve = $this->config->is_enabled() && $has_build;

		if ( ! $should_serve ) {
			return false;
		}

		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return false;
		}

		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return false;
		}

		if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) {
			return false;
		}

		// Note: we intentionally let `is_embed()` through. The headless theme
		// renders its own minimal oEmbed view (templates/embed) — without this
		// the WP-default embed-template.php would win and the iframe wouldn't
		// match the rest of the site's design.
		if ( is_feed() || is_trackback() || is_robots() || is_customize_preview() || is_favicon() ) {
			return false;
		}

		// Core XML sitemaps (WP 5.5+) render on `template_redirect` at the
		// default priority; FrontendBridge exits earlier and would otherwise
		// serve the SPA shell — with a 404, since the resolver doesn't recognize
		// the sitemap URL. There is no `is_sitemap()` conditional in core, so we
		// detect the request by its query vars and stand down so core (and
		// SEO-plugin sitemaps routed the same way) can render the XML.
		if ( '' !== (string) get_query_var( 'sitemap', '' ) || '' !== (string) get_query_var( 'sitemap-stylesheet', '' ) ) {
			return false;
		}

		if ( '' !== (string) get_query_var( RewriteRules::ASSET_QUERY_VAR, '' ) ) {
			return false;
		}

		// llms.txt requests are streamed (and exited) on parse_request@0; if
		// that module is ever disabled while its rewrite rules are still
		// cached, stand down so WordPress renders natively instead of the
		// shell 404ing a text endpoint.
		if ( '' !== (string) get_query_var( LlmsTxt::QUERY_VAR, '' ) ) {
			return false;
		}

		if ( '' !== (string) get_query_var( LlmsTxt::FULL_QUERY_VAR, '' ) ) {
			return false;
		}

		return (bool) apply_filters( 'wp_headless_should_serve_frontend', true, $this->config );
	}
}
