<?php
/**
 * Headless head-hygiene module.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Seo;

use WPHeadless\Config\Config;
use WPHeadless\Contracts\Module;
use WPHeadless\Runtime\RequestMatcher;
use WPHeadless\Theme\ThemeManager;

/**
 * Removes head output that has no purpose in a headless shell (RSD/xmlrpc
 * discovery, generator, shortlink, emoji bootstrap, …) when the plugin is
 * serving the frontend.
 *
 * Deliberately left in place — never removed here:
 * - feed_links (main RSS/Atom links): feeds stay a real, WordPress-rendered
 *   surface on a headless site.
 * - rel_canonical: SeoHead depends on core's canonical for singular views.
 * - wp_robots: index/noindex policy must keep flowing from core.
 * - wp_site_icon: favicons are real UX in the SPA shell.
 * - _wp_render_title_tag: the served shell still needs its <title>.
 */
class HeadCleanup implements Module {
	/** @var Config */
	private Config $config;

	/** @var RequestMatcher */
	private RequestMatcher $matcher;

	/**
	 * @param Config              $config        Shared config.
	 * @param ThemeManager        $theme_manager Theme manager, consumed by the matcher.
	 * @param RequestMatcher|null $matcher       Optional matcher override (tests).
	 */
	public function __construct( Config $config, ThemeManager $theme_manager, ?RequestMatcher $matcher = null ) {
		$this->config  = $config;
		$this->matcher = $matcher ?? new RequestMatcher( $config, $theme_manager );
	}

	public function register(): void {
		if ( ! $this->config->get( 'seo.head_cleanup.enabled', true ) ) {
			return;
		}

		// 'wp' is the earliest hook where the main query's conditionals
		// (which should_serve_frontend() consults) are reliable, and it still
		// runs before template_redirect and wp_head — early enough to detach
		// everything below.
		add_action( 'wp', array( $this, 'maybe_clean' ) );
	}

	/**
	 * Detach the configured head output on frontend-served requests.
	 *
	 * @return void
	 */
	public function maybe_clean(): void {
		if ( ! $this->matcher->should_serve_frontend() ) {
			return;
		}

		$map = array(
			'rsd'              => (bool) $this->config->get( 'seo.head_cleanup.rsd', true ),
			'wlw'              => (bool) $this->config->get( 'seo.head_cleanup.wlw', true ),
			'generator'        => (bool) $this->config->get( 'seo.head_cleanup.generator', true ),
			'shortlink'        => (bool) $this->config->get( 'seo.head_cleanup.shortlink', true ),
			'adjacent_posts'   => (bool) $this->config->get( 'seo.head_cleanup.adjacent_posts', true ),
			'emoji'            => (bool) $this->config->get( 'seo.head_cleanup.emoji', true ),
			'oembed_host_js'   => (bool) $this->config->get( 'seo.head_cleanup.oembed_host_js', true ),
			'oembed_discovery' => (bool) $this->config->get( 'seo.head_cleanup.oembed_discovery', false ),
			'feed_links_extra' => (bool) $this->config->get( 'seo.head_cleanup.feed_links_extra', false ),
			'rest_link'        => (bool) $this->config->get( 'seo.head_cleanup.rest_link', false ),
		);

		/**
		 * Filters the head-cleanup map before it is applied.
		 *
		 * @param array<string, bool> $map    Cleanup key => whether to remove.
		 * @param Config              $config Plugin config.
		 */
		$map = (array) apply_filters( 'wp_headless_head_cleanup', $map, $this->config );

		if ( ! empty( $map['rsd'] ) ) {
			remove_action( 'wp_head', 'rsd_link' );
		}

		if ( ! empty( $map['wlw'] ) ) {
			remove_action( 'wp_head', 'wlwmanifest_link' );
		}

		if ( ! empty( $map['generator'] ) ) {
			remove_action( 'wp_head', 'wp_generator' );
		}

		if ( ! empty( $map['shortlink'] ) ) {
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		}

		if ( ! empty( $map['adjacent_posts'] ) ) {
			remove_action( 'wp_head', 'adjacent_posts_rel_link_wp_head' );
		}

		if ( ! empty( $map['emoji'] ) ) {
			remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
			remove_action( 'wp_print_styles', 'print_emoji_styles' );
			add_filter( 'emoji_svg_url', '__return_false' );
		}

		if ( ! empty( $map['oembed_host_js'] ) ) {
			remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		}

		if ( ! empty( $map['oembed_discovery'] ) ) {
			remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		}

		if ( ! empty( $map['feed_links_extra'] ) ) {
			remove_action( 'wp_head', 'feed_links_extra', 3 );
		}

		if ( ! empty( $map['rest_link'] ) ) {
			remove_action( 'wp_head', 'rest_output_link_wp_head' );
			remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		}
	}
}
