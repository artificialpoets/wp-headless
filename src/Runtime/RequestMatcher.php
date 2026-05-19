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
		if ( is_feed() || is_trackback() || is_robots() || is_customize_preview() ) {
			return false;
		}

		if ( '' !== (string) get_query_var( RewriteRules::ASSET_QUERY_VAR, '' ) ) {
			return false;
		}

		return (bool) apply_filters( 'wp_headless_should_serve_frontend', true, $this->config );
	}
}
