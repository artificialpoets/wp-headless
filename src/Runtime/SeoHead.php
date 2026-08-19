<?php
/**
 * Server-side SEO markup for headless pages.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Runtime;

use WPHeadless\Config\Config;
use WPHeadless\Contracts\Module;
use WPHeadless\Seo\SchemaGraph;
use WPHeadless\Theme\ThemeManager;

/**
 * Emits a meta description, Open Graph, Twitter Card, and JSON-LD into the
 * server-rendered shell's <head>, derived from the real main query.
 *
 * Why this exists: social unfurlers (Slack, iMessage, Facebook, X) and many
 * crawlers do NOT run JavaScript, so a pure client-side head hook gives them
 * nothing. WordPress core only emits a <title> and canonical; description /
 * og / twitter / JSON-LD come from an SEO plugin. On a bare install there is
 * no SEO plugin, so this module fills that gap server-side.
 *
 * It stands down entirely when a known SEO plugin is active (Yoast, Rank Math,
 * All in One SEO, SEOPress, The SEO Framework) so it never fights the plugin
 * that owns the head, and it only runs on pages the headless frontend actually
 * serves. Everything is filterable via `wp_headless_output_seo_meta` (on/off)
 * and `wp_headless_seo_meta` (the assembled data).
 */
class SeoHead implements Module {

	/** @var Config */
	private Config $config;

	/** @var RequestMatcher */
	private RequestMatcher $matcher;

	/** @var SchemaGraph */
	private SchemaGraph $schema_graph;

	public function __construct( Config $config, ThemeManager $theme_manager ) {
		$this->config       = $config;
		$this->matcher      = new RequestMatcher( $config, $theme_manager );
		$this->schema_graph = new SchemaGraph( $config );
	}

	public function register(): void {
		// Priority 4 keeps our tags grouped just before core's rel_canonical (10).
		add_action( 'wp_head', array( $this, 'output' ), 4 );
	}

	public function output(): void {
		// Only decorate pages the headless frontend is actually serving.
		if ( ! $this->matcher->should_serve_frontend() ) {
			return;
		}

		// Defer to a dedicated SEO plugin when one is present.
		if ( self::seo_plugin_active() ) {
			return;
		}

		if ( ! (bool) apply_filters( 'wp_headless_output_seo_meta', true ) ) {
			return;
		}

		$meta = (array) apply_filters( 'wp_headless_seo_meta', $this->build_meta() );

		echo $this->render_meta( $meta ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render_meta().
	}

	/**
	 * Detect a third-party SEO plugin that already owns head output.
	 */
	public static function seo_plugin_active(): bool {
		$active = defined( 'WPSEO_VERSION' )        // Yoast SEO.
			|| defined( 'RANK_MATH_VERSION' )        // Rank Math.
			|| defined( 'AIOSEO_VERSION' )           // All in One SEO.
			|| defined( 'SEOPRESS_VERSION' )         // SEOPress.
			|| class_exists( '\\The_SEO_Framework\\Load', false ); // The SEO Framework.

		return (bool) apply_filters( 'wp_headless_seo_plugin_active', $active );
	}

	/**
	 * Assemble the SEO data for the current query.
	 *
	 * Branch order matters: a static front page satisfies both is_front_page()
	 * and is_singular(), and the front page must win.
	 *
	 * Robots meta is intentionally absent here: core's wp_robots runs at
	 * wp_head priority 1 and HtmlDocument captures its output, so SeoHead
	 * never emits (or competes with) a robots tag.
	 *
	 * @return array{description:string,canonical:string,og:array<string,string>,twitter:array<string,string>,jsonld:?array}
	 */
	protected function build_meta(): array {
		$site_name = (string) get_bloginfo( 'name' );
		$meta      = array(
			'description' => '',
			'canonical'   => '',
			'og'          => array(),
			'twitter'     => array(),
			'jsonld'      => null,
		);

		if ( is_front_page() || is_home() ) {
			$canonical  = home_url( '/' );
			$front_post = null;
			$front_id   = (int) get_option( 'page_on_front' );
			if ( $front_id > 0 ) {
				$front_post = get_post( $front_id );
			}

			$description = $front_post ? self::clip( $this->post_description( $front_post ) ) : '';
			if ( '' === $description ) {
				$description = self::clip( (string) get_bloginfo( 'description' ) );
			}

			$image               = $this->resolve_social_image( $front_post );
			$meta['description'] = $description;
			$meta['canonical']   = $canonical;
			$meta['og']          = array(
				'type'        => 'website',
				'title'       => $site_name,
				'description' => $meta['description'],
				'url'         => $canonical,
				'site_name'   => $site_name,
				'image'       => $image['url'],
			);
			$meta['jsonld']      = $this->schema_graph->build();
		} elseif ( is_singular() ) {
			$post                = get_queried_object();
			$title               = (string) get_the_title( $post );
			$canonical           = (string) ( wp_get_canonical_url( $post ) ?: get_permalink( $post ) );
			$image               = $this->resolve_social_image( $post );
			$meta['description'] = self::clip( $this->post_description( $post ) );
			$meta['canonical']   = $canonical;
			$meta['og']          = array(
				'type'        => is_singular( 'post' ) ? 'article' : 'website',
				'title'       => $title,
				'description' => $meta['description'],
				'url'         => $canonical,
				'site_name'   => $site_name,
				'image'       => $image['url'],
			);
			$meta['jsonld']      = $this->schema_graph->build();
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term                = get_queried_object();
			$image               = $this->resolve_social_image();
			$meta['description'] = self::clip( (string) ( is_object( $term ) ? term_description( $term ) : '' ) );
			$meta['canonical']   = (string) get_term_link( $term );
			$meta['og']          = array(
				'type'        => 'website',
				'title'       => (string) single_term_title( '', false ),
				'description' => $meta['description'],
				'url'         => $meta['canonical'],
				'site_name'   => $site_name,
				'image'       => $image['url'],
			);
			$meta['jsonld']      = $this->schema_graph->build();
		} elseif ( is_author() ) {
			$user                = get_queried_object();
			$author_id           = is_object( $user ) && isset( $user->ID ) ? (int) $user->ID : 0;
			$image               = $this->resolve_social_image();
			$meta['description'] = self::clip( (string) get_the_author_meta( 'description', $author_id ) );
			$meta['canonical']   = (string) get_author_posts_url( $author_id );
			$meta['og']          = array(
				'type'        => 'website',
				'title'       => (string) get_the_author_meta( 'display_name', $author_id ),
				'description' => $meta['description'],
				'url'         => $meta['canonical'],
				'site_name'   => $site_name,
				'image'       => $image['url'],
			);
			$meta['jsonld']      = $this->schema_graph->build();
		} elseif ( is_post_type_archive() ) {
			$object              = get_queried_object();
			$label               = is_object( $object ) && isset( $object->labels->name ) ? (string) $object->labels->name : '';
			$post_type           = is_object( $object ) && isset( $object->name ) ? (string) $object->name : '';
			$image               = $this->resolve_social_image();
			$meta['description'] = self::clip( (string) get_the_post_type_description() );
			$meta['canonical']   = '' !== $post_type ? (string) ( get_post_type_archive_link( $post_type ) ?: '' ) : '';
			$meta['og']          = array(
				'type'        => 'website',
				'title'       => '' !== $label ? $label : $site_name,
				'description' => $meta['description'],
				'url'         => $meta['canonical'],
				'site_name'   => $site_name,
				'image'       => $image['url'],
			);
			$meta['jsonld']      = $this->schema_graph->build();
		} elseif ( is_date() ) {
			$image             = $this->resolve_social_image();
			$meta['canonical'] = $this->date_archive_link();
			$meta['og']        = array(
				'type'        => 'website',
				'title'       => $site_name,
				'description' => '',
				'url'         => $meta['canonical'],
				'site_name'   => $site_name,
				'image'       => $image['url'],
			);
			$meta['jsonld']    = $this->schema_graph->build();
		} elseif ( is_search() ) {
			$image      = $this->resolve_social_image();
			$meta['og'] = array(
				'type'      => 'website',
				'title'     => (string) get_search_query(),
				'site_name' => $site_name,
				'image'     => $image['url'],
			);
		}
		// is_404() (and anything unmatched) intentionally keeps the empty
		// defaults: no canonical, no og, no jsonld.

		if ( ! empty( $meta['og'] ) ) {
			$meta['twitter'] = array(
				'card'        => '' !== ( $meta['og']['image'] ?? '' ) ? 'summary_large_image' : 'summary',
				'title'       => (string) ( $meta['og']['title'] ?? '' ),
				'description' => (string) ( $meta['og']['description'] ?? '' ),
				'image'       => (string) ( $meta['og']['image'] ?? '' ),
			);
		}

		return $meta;
	}

	/**
	 * Render the assembled data as escaped <head> markup.
	 */
	protected function render_meta( array $meta ): string {
		$out = "\n<!-- WP Headless SEO -->\n";

		if ( ! empty( $meta['description'] ) ) {
			$out .= '<meta name="description" content="' . esc_attr( $meta['description'] ) . '" />' . "\n";
		}

		// Emit a canonical only where core wouldn't: core's rel_canonical
		// covers is_singular(), so adding one there would duplicate it.
		if ( ! empty( $meta['canonical'] ) && ! is_singular() ) {
			$out .= '<link rel="canonical" href="' . esc_url( $meta['canonical'] ) . '" />' . "\n";
		}

		foreach ( (array) ( $meta['og'] ?? array() ) as $key => $value ) {
			$value = (string) $value;
			if ( '' === $value ) {
				continue;
			}
			$property = 'og:' . $key;
			$content  = in_array( $key, array( 'url', 'image' ), true ) ? esc_url( $value ) : esc_attr( $value );
			$out     .= '<meta property="' . esc_attr( $property ) . '" content="' . $content . '" />' . "\n";
		}

		foreach ( (array) ( $meta['twitter'] ?? array() ) as $key => $value ) {
			$value = (string) $value;
			if ( '' === $value ) {
				continue;
			}
			$content = 'image' === $key ? esc_url( $value ) : esc_attr( $value );
			$out    .= '<meta name="' . esc_attr( 'twitter:' . $key ) . '" content="' . $content . '" />' . "\n";
		}

		if ( ! empty( $meta['jsonld'] ) ) {
			$json = wp_json_encode( $meta['jsonld'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			$json = str_replace( '</', '<\\/', (string) $json ); // Guard against </script> breakout.
			$out .= '<script type="application/ld+json">' . $json . '</script>' . "\n";
		}

		return $out;
	}

	/**
	 * Best-effort meta description for a post: its excerpt, else trimmed content.
	 *
	 * @param mixed $post
	 */
	protected function post_description( $post ): string {
		$excerpt = (string) get_the_excerpt( $post );
		if ( '' !== trim( $excerpt ) ) {
			return $excerpt;
		}
		$content = isset( $post->post_content ) ? (string) $post->post_content : '';
		return wp_strip_all_tags( strip_shortcodes( $content ) );
	}

	/**
	 * Resolve the social-share image through the site-wide fallback chain:
	 * featured image (large) → custom logo → site icon → configured
	 * seo.default_image. Every og-rendering branch shares this chain so
	 * unfurls never come up blank on a branded site.
	 *
	 * @param mixed $post Optional post whose featured image leads the chain.
	 * @return array{url:string,id:int}
	 */
	protected function resolve_social_image( $post = null ): array {
		if ( is_object( $post ) && ! empty( $post->ID ) ) {
			$thumb_id = (int) get_post_thumbnail_id( (int) $post->ID );
			if ( $thumb_id > 0 ) {
				$src = wp_get_attachment_image_url( $thumb_id, 'large' );
				if ( is_string( $src ) && '' !== $src ) {
					return array(
						'url' => $src,
						'id'  => $thumb_id,
					);
				}
			}
		}

		$logo_id = (int) get_theme_mod( 'custom_logo' );
		if ( $logo_id > 0 ) {
			$src = wp_get_attachment_image_url( $logo_id, 'full' );
			if ( is_string( $src ) && '' !== $src ) {
				return array(
					'url' => $src,
					'id'  => $logo_id,
				);
			}
		}

		$icon = (string) get_site_icon_url( 512 );
		if ( '' !== $icon ) {
			return array(
				'url' => $icon,
				'id'  => (int) get_option( 'site_icon' ),
			);
		}

		$default = (string) $this->config->get( 'seo.default_image', '' );
		if ( '' !== $default ) {
			return array(
				'url' => $default,
				'id'  => 0,
			);
		}

		return array(
			'url' => '',
			'id'  => 0,
		);
	}

	/**
	 * Featured-image URL for a post, or the site-wide fallback chain.
	 * Thin BC wrapper around resolve_social_image().
	 *
	 * @param mixed $post
	 */
	protected function post_image( $post ): string {
		$image = $this->resolve_social_image( $post );
		return $image['url'];
	}

	/**
	 * Canonical URL of the current date archive.
	 */
	protected function date_archive_link(): string {
		$year     = (int) get_query_var( 'year' );
		$monthnum = (int) get_query_var( 'monthnum' );
		$day      = (int) get_query_var( 'day' );

		if ( is_day() ) {
			return (string) get_day_link( $year, $monthnum, $day );
		}
		if ( is_month() ) {
			return (string) get_month_link( $year, $monthnum );
		}
		return (string) get_year_link( $year );
	}

	/**
	 * Collapse whitespace and clip to a description-friendly length.
	 */
	public static function clip( string $text, int $limit = 160 ): string {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) ?? '' );
		if ( mb_strlen( $text ) <= $limit ) {
			return $text;
		}
		$clipped = mb_substr( $text, 0, $limit - 1 );
		$space   = mb_strrpos( $clipped, ' ' );
		if ( false !== $space && $space > 0 ) {
			$clipped = mb_substr( $clipped, 0, $space );
		}
		return $clipped . '…';
	}
}
