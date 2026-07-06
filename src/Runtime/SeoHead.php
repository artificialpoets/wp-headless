<?php
/**
 * Server-side SEO markup for headless pages.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Runtime;

use WPHeadless\Config\Config;
use WPHeadless\Contracts\Module;
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

	/** @var RequestMatcher */
	private RequestMatcher $matcher;

	public function __construct( Config $config, ThemeManager $theme_manager ) {
		$this->matcher = new RequestMatcher( $config, $theme_manager );
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
		if ( $this->seo_plugin_active() ) {
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
	protected function seo_plugin_active(): bool {
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

		if ( is_singular() ) {
			$post                = get_queried_object();
			$title               = (string) get_the_title( $post );
			$canonical           = (string) ( wp_get_canonical_url( $post ) ?: get_permalink( $post ) );
			$image               = $this->post_image( $post );
			$meta['description'] = $this->clip( $this->post_description( $post ) );
			$meta['canonical']   = $canonical;
			$meta['og']          = array(
				'type'        => is_singular( 'post' ) ? 'article' : 'website',
				'title'       => $title,
				'description' => $meta['description'],
				'url'         => $canonical,
				'site_name'   => $site_name,
				'image'       => $image,
			);
			$meta['jsonld']      = $this->article_jsonld( $post, $title, $canonical, $image, $site_name );
		} elseif ( is_front_page() || is_home() ) {
			$canonical           = home_url( '/' );
			$meta['description'] = $this->clip( (string) get_bloginfo( 'description' ) );
			$meta['canonical']   = $canonical;
			$meta['og']          = array(
				'type'        => 'website',
				'title'       => $site_name,
				'description' => $meta['description'],
				'url'         => $canonical,
				'site_name'   => $site_name,
				'image'       => '',
			);
			$meta['jsonld']      = array(
				'@context' => 'https://schema.org',
				'@type'    => 'WebSite',
				'name'     => $site_name,
				'url'      => $canonical,
			);
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term                = get_queried_object();
			$meta['description'] = $this->clip( (string) ( is_object( $term ) ? term_description( $term ) : '' ) );
			$meta['canonical']   = (string) get_term_link( $term );
			$meta['og']          = array(
				'type'        => 'website',
				'title'       => (string) single_term_title( '', false ),
				'description' => $meta['description'],
				'url'         => $meta['canonical'],
				'site_name'   => $site_name,
				'image'       => '',
			);
		}

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
	 * Featured-image URL for a post, or '' when none.
	 *
	 * @param mixed $post
	 */
	protected function post_image( $post ): string {
		$id = isset( $post->ID ) ? (int) $post->ID : 0;
		if ( $id <= 0 ) {
			return '';
		}
		$thumb_id = get_post_thumbnail_id( $id );
		if ( ! $thumb_id ) {
			return '';
		}
		$src = wp_get_attachment_image_url( $thumb_id, 'large' );
		return is_string( $src ) ? $src : '';
	}

	/**
	 * Minimal Article JSON-LD for a singular post.
	 *
	 * @param mixed $post
	 * @return array<string,mixed>
	 */
	protected function article_jsonld( $post, string $title, string $canonical, string $image, string $site_name ): array {
		$node = array(
			'@context'         => 'https://schema.org',
			'@type'            => is_singular( 'post' ) ? 'Article' : 'WebPage',
			'headline'         => $title,
			'mainEntityOfPage' => $canonical,
			'url'              => $canonical,
			'publisher'        => array(
				'@type' => 'Organization',
				'name'  => $site_name,
			),
		);

		if ( '' !== $image ) {
			$node['image'] = $image;
		}
		if ( isset( $post->post_date_gmt ) ) {
			$node['datePublished'] = gmdate( 'c', strtotime( (string) $post->post_date_gmt . ' UTC' ) );
		}
		if ( isset( $post->post_modified_gmt ) ) {
			$node['dateModified'] = gmdate( 'c', strtotime( (string) $post->post_modified_gmt . ' UTC' ) );
		}
		if ( isset( $post->post_author ) ) {
			$author = get_the_author_meta( 'display_name', (int) $post->post_author );
			if ( is_string( $author ) && '' !== $author ) {
				$node['author'] = array(
					'@type' => 'Person',
					'name'  => $author,
				);
			}
		}

		return $node;
	}

	/**
	 * Collapse whitespace and clip to a description-friendly length.
	 */
	protected function clip( string $text, int $limit = 160 ): string {
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
