<?php
/**
 * Schema.org @graph builder.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Seo;

use WPHeadless\Config\Config;
use WPHeadless\Runtime\SeoHead;

/**
 * Builds one connected schema.org @graph for the current main query:
 * Organization, WebSite, WebPage, BreadcrumbList, ImageObject, Article and
 * Person nodes that cross-reference each other through stable @id anchors
 * ({home}#organization, {canonical}#webpage, …), the shape crawlers already
 * know from the major SEO plugins.
 *
 * This is a service consumed by SeoHead (which owns rendering and the
 * stand-down rules), not a Module. Every piece is filterable:
 * `wp_headless_schema_pieces` (the keyed map), `wp_headless_schema_piece`
 * (each node), `wp_headless_schema_graph` (the final envelope).
 */
class SchemaGraph {

	/** @var Config */
	private Config $config;

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Build the @graph envelope for the current query, or null when schema
	 * output is disabled or the view has no sensible graph (search, 404).
	 *
	 * @return array<string,mixed>|null
	 */
	public function build(): ?array {
		if ( ! $this->config->get( 'seo.schema.enabled', true ) ) {
			return null;
		}

		if ( is_search() || is_404() ) {
			return null;
		}

		$context = $this->build_context();
		if ( null === $context ) {
			return null;
		}

		// Breadcrumb and primary image are built first: webpage() and
		// article() may only reference their @ids when the pieces exist.
		$breadcrumb    = $this->breadcrumb( $context );
		$primary_image = $this->primary_image( $context );

		$context['has_breadcrumb']    = null !== $breadcrumb;
		$context['has_primary_image'] = null !== $primary_image;

		$pieces = array(
			'organization'  => $this->organization( $context ),
			'website'       => $this->website( $context ),
			'webpage'       => $this->webpage( $context ),
			'breadcrumb'    => $breadcrumb,
			'primary_image' => $primary_image,
			'article'       => $this->article( $context ),
			'person'        => $this->person( $context ),
		);

		$pieces = (array) apply_filters( 'wp_headless_schema_pieces', $pieces, $context );

		foreach ( $pieces as $key => $piece ) {
			$pieces[ $key ] = apply_filters( 'wp_headless_schema_piece', $piece, (string) $key, $context );
		}

		$pieces = array_filter( $pieces );

		if ( empty( $pieces ) ) {
			return null;
		}

		$graph = array(
			'@context' => 'https://schema.org',
			'@graph'   => array_values( $pieces ),
		);

		return (array) apply_filters( 'wp_headless_schema_graph', $graph, $context );
	}

	/**
	 * Classify the current query and precompute everything the node builders
	 * share: the canonical URL and the stable @id anchors.
	 *
	 * Kind detection order matters: a static front page satisfies both
	 * is_front_page() and is_singular(), and the front page must win.
	 *
	 * @return array<string,mixed>|null Null when the view is unrecognized or
	 *                                  no canonical URL can be derived.
	 */
	protected function build_context(): ?array {
		$site_url  = home_url( '/' );
		$kind      = '';
		$canonical = '';
		$post      = null;
		$term      = null;
		$user      = null;
		$author_id = 0;

		if ( is_front_page() ) {
			$kind      = 'front_page';
			$canonical = $site_url;
			$queried   = get_queried_object();
			if ( is_object( $queried ) && isset( $queried->post_type ) ) {
				$post = $queried; // Static front page.
			}
		} elseif ( is_home() ) {
			$kind    = 'home';
			$queried = get_queried_object();
			if ( is_object( $queried ) && isset( $queried->post_type ) ) {
				$post      = $queried; // Separate posts page.
				$canonical = (string) get_permalink( $post );
			}
			if ( '' === $canonical ) {
				$canonical = $site_url;
			}
		} elseif ( is_singular() ) {
			$kind    = 'singular';
			$queried = get_queried_object();
			if ( is_object( $queried ) ) {
				$post      = $queried;
				$canonical = (string) ( wp_get_canonical_url( $post ) ?: get_permalink( $post ) );
				$author_id = isset( $post->post_author ) ? (int) $post->post_author : 0;
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$kind    = 'term_archive';
			$queried = get_queried_object();
			if ( is_object( $queried ) && isset( $queried->term_id ) ) {
				$term = $queried;
				$link = get_term_link( $term );
				if ( is_string( $link ) ) {
					$canonical = $link;
				}
			}
		} elseif ( is_author() ) {
			$kind    = 'author_archive';
			$queried = get_queried_object();
			if ( is_object( $queried ) && isset( $queried->ID ) ) {
				$user      = $queried;
				$author_id = (int) $queried->ID;
				$canonical = (string) get_author_posts_url( $author_id );
			}
		} elseif ( is_post_type_archive() ) {
			$kind      = 'post_type_archive';
			$queried   = get_queried_object();
			$post_type = is_object( $queried ) && isset( $queried->name ) ? (string) $queried->name : '';
			if ( '' !== $post_type ) {
				$canonical = (string) ( get_post_type_archive_link( $post_type ) ?: '' );
			}
		} elseif ( is_date() ) {
			$kind      = 'date_archive';
			$canonical = $this->date_archive_link();
		}

		if ( '' === $kind || '' === $canonical ) {
			return null;
		}

		$ids = array(
			'organization'  => $site_url . '#organization',
			'website'       => $site_url . '#website',
			'webpage'       => $canonical . '#webpage',
			'breadcrumb'    => $canonical . '#breadcrumb',
			'primary_image' => $canonical . '#primaryimage',
		);

		if ( $author_id > 0 ) {
			$ids['author'] = $site_url . '#/schema/person/' . $author_id;
		}

		return array(
			'kind'      => $kind,
			'canonical' => $canonical,
			'post'      => $post,
			'term'      => $term,
			'user'      => $user,
			'site_url'  => $site_url,
			'ids'       => $ids,
			'author_id' => $author_id,
		);
	}

	/**
	 * Organization node: the publisher every other node hangs off.
	 *
	 * @param array<string,mixed> $context Build context.
	 * @return array<string,mixed>|null
	 */
	protected function organization( array $context ): ?array {
		$name = (string) $this->config->get( 'seo.schema.organization.name', '' );
		if ( '' === $name ) {
			$name = (string) get_bloginfo( 'name' );
		}

		$node = array(
			'@type' => 'Organization',
			'@id'   => $context['ids']['organization'],
			'name'  => $name,
			'url'   => $context['site_url'],
		);

		$logo = $this->organization_logo( $context );
		if ( null !== $logo ) {
			$node['logo'] = $logo;
		}

		$same_as = (array) $this->config->get( 'seo.schema.organization.same_as', array() );
		if ( ! empty( $same_as ) ) {
			$node['sameAs'] = array_values( $same_as );
		}

		return $node;
	}

	/**
	 * Inline ImageObject for the organization logo, resolved through the
	 * chain: configured logo URL → custom logo attachment → site icon.
	 *
	 * @param array<string,mixed> $context Build context.
	 * @return array<string,mixed>|null
	 */
	protected function organization_logo( array $context ): ?array {
		$logo_id = $context['site_url'] . '#logo';

		$configured = (string) $this->config->get( 'seo.schema.organization.logo', '' );
		if ( '' !== $configured ) {
			return array(
				'@type' => 'ImageObject',
				'@id'   => $logo_id,
				'url'   => $configured,
			);
		}

		$attachment_id = (int) get_theme_mod( 'custom_logo' );
		if ( $attachment_id > 0 ) {
			$src = wp_get_attachment_image_src( $attachment_id, 'full' );
			if ( is_array( $src ) && ! empty( $src[0] ) ) {
				$logo = array(
					'@type' => 'ImageObject',
					'@id'   => $logo_id,
					'url'   => (string) $src[0],
				);
				if ( ! empty( $src[1] ) ) {
					$logo['width'] = (int) $src[1];
				}
				if ( ! empty( $src[2] ) ) {
					$logo['height'] = (int) $src[2];
				}
				return $logo;
			}
		}

		$icon = (string) get_site_icon_url( 512 );
		if ( '' !== $icon ) {
			return array(
				'@type' => 'ImageObject',
				'@id'   => $logo_id,
				'url'   => $icon,
			);
		}

		return null;
	}

	/**
	 * WebSite node, with the sitelinks SearchAction unless disabled.
	 *
	 * @param array<string,mixed> $context Build context.
	 * @return array<string,mixed>|null
	 */
	protected function website( array $context ): ?array {
		$node = array(
			'@type' => 'WebSite',
			'@id'   => $context['ids']['website'],
			'url'   => $context['site_url'],
			'name'  => (string) get_bloginfo( 'name' ),
		);

		$description = (string) get_bloginfo( 'description' );
		if ( '' !== $description ) {
			$node['description'] = $description;
		}

		$node['publisher']  = array( '@id' => $context['ids']['organization'] );
		$node['inLanguage'] = $this->language();

		if ( $this->config->get( 'seo.schema.search_action', true ) ) {
			$node['potentialAction'] = array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => home_url( '/?s={search_term_string}' ),
				),
				'query-input' => 'required name=search_term_string',
			);
		}

		return $node;
	}

	/**
	 * WebPage node for the current view; CollectionPage on archives and
	 * ProfilePage on author archives.
	 *
	 * @param array<string,mixed> $context Build context.
	 * @return array<string,mixed>|null
	 */
	protected function webpage( array $context ): ?array {
		$kind = (string) $context['kind'];

		$type = 'WebPage';
		if ( in_array( $kind, array( 'home', 'term_archive', 'post_type_archive', 'date_archive' ), true ) ) {
			$type = 'CollectionPage';
		} elseif ( 'author_archive' === $kind ) {
			$type = 'ProfilePage';
		}

		$node = array(
			'@type'    => $type,
			'@id'      => $context['ids']['webpage'],
			'url'      => $context['canonical'],
			'name'     => $this->webpage_name( $context ),
			'isPartOf' => array( '@id' => $context['ids']['website'] ),
		);

		if ( 'author_archive' === $kind && isset( $context['ids']['author'] ) ) {
			$node['mainEntity'] = array( '@id' => $context['ids']['author'] );
		}

		if ( ! empty( $context['has_breadcrumb'] ) ) {
			$node['breadcrumb'] = array( '@id' => $context['ids']['breadcrumb'] );
		}

		if ( ! empty( $context['has_primary_image'] ) ) {
			$node['primaryImageOfPage'] = array( '@id' => $context['ids']['primary_image'] );
		}

		$post = $context['post'];

		if ( 'singular' === $kind && is_object( $post ) ) {
			if ( ! empty( $post->post_date_gmt ) ) {
				$published = strtotime( (string) $post->post_date_gmt . ' UTC' );
				if ( false !== $published ) {
					$node['datePublished'] = gmdate( 'c', $published );
				}
			}
			if ( ! empty( $post->post_modified_gmt ) ) {
				$modified = strtotime( (string) $post->post_modified_gmt . ' UTC' );
				if ( false !== $modified ) {
					$node['dateModified'] = gmdate( 'c', $modified );
				}
			}
		}

		if ( is_object( $post ) ) {
			$description = SeoHead::clip( $this->post_description( $post ) );
			if ( '' !== $description ) {
				$node['description'] = $description;
			}
		}

		$node['inLanguage'] = $this->language();

		return $node;
	}

	/**
	 * BreadcrumbList node. The front page has no trail; every other view is
	 * Home → (ancestors) → current, where the current crumb carries no item
	 * URL per Google's guidelines.
	 *
	 * @param array<string,mixed> $context Build context.
	 * @return array<string,mixed>|null
	 */
	protected function breadcrumb( array $context ): ?array {
		$kind = (string) $context['kind'];

		if ( 'front_page' === $kind ) {
			return null;
		}

		$items   = array();
		$items[] = array(
			'name' => __( 'Home', 'wp-headless' ),
			'url'  => $context['site_url'],
		);

		$post = $context['post'];
		$term = $context['term'];

		if ( 'singular' === $kind && is_object( $post ) ) {
			if ( 'post' === ( $post->post_type ?? '' ) ) {
				$page_for_posts = (int) get_option( 'page_for_posts' );
				if ( $page_for_posts > 0 ) {
					$items[] = array(
						'name' => (string) get_the_title( $page_for_posts ),
						'url'  => (string) get_permalink( $page_for_posts ),
					);
				}
			} else {
				// Root-first: get_post_ancestors() returns the closest parent first.
				$ancestors = array_reverse( (array) get_post_ancestors( $post ) );
				foreach ( $ancestors as $ancestor_id ) {
					$items[] = array(
						'name' => (string) get_the_title( $ancestor_id ),
						'url'  => (string) get_permalink( $ancestor_id ),
					);
				}
			}
		} elseif ( 'term_archive' === $kind && is_object( $term ) ) {
			// Root-first: get_ancestors() returns the closest parent first.
			$ancestors = array_reverse( (array) get_ancestors( (int) $term->term_id, (string) $term->taxonomy, 'taxonomy' ) );
			foreach ( $ancestors as $ancestor_id ) {
				$ancestor = get_term( $ancestor_id, (string) $term->taxonomy );
				if ( ! $ancestor || is_wp_error( $ancestor ) ) {
					continue;
				}
				$link = get_term_link( $ancestor );
				if ( ! is_string( $link ) ) {
					continue;
				}
				$items[] = array(
					'name' => (string) $ancestor->name,
					'url'  => $link,
				);
			}
		}

		// Current view, never linked.
		$items[] = array(
			'name' => $this->webpage_name( $context ),
			'url'  => null,
		);

		$list     = array();
		$position = 0;
		foreach ( $items as $item ) {
			++$position;
			$entry = array(
				'@type'    => 'ListItem',
				'position' => $position,
				'name'     => (string) $item['name'],
			);
			if ( ! empty( $item['url'] ) ) {
				$entry['item'] = (string) $item['url'];
			}
			$list[] = $entry;
		}

		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => $context['ids']['breadcrumb'],
			'itemListElement' => $list,
		);
	}

	/**
	 * ImageObject for the page's genuine primary image: the featured image of
	 * the contextual post. No logo/icon fallback here — that chain belongs to
	 * SeoHead's og:image, not to primaryImageOfPage.
	 *
	 * @param array<string,mixed> $context Build context.
	 * @return array<string,mixed>|null
	 */
	protected function primary_image( array $context ): ?array {
		$post = $context['post'];
		if ( ! is_object( $post ) || empty( $post->ID ) ) {
			return null;
		}

		$thumbnail_id = (int) get_post_thumbnail_id( (int) $post->ID );
		if ( $thumbnail_id <= 0 ) {
			return null;
		}

		$src = wp_get_attachment_image_src( $thumbnail_id, 'full' );
		if ( ! is_array( $src ) || empty( $src[0] ) ) {
			return null;
		}

		$node = array(
			'@type' => 'ImageObject',
			'@id'   => $context['ids']['primary_image'],
			'url'   => (string) $src[0],
		);

		if ( ! empty( $src[1] ) ) {
			$node['width'] = (int) $src[1];
		}
		if ( ! empty( $src[2] ) ) {
			$node['height'] = (int) $src[2];
		}

		$caption = (string) wp_get_attachment_caption( $thumbnail_id );
		if ( '' !== $caption ) {
			$node['caption'] = $caption;
		}

		return $node;
	}

	/**
	 * Article node for singular views whose post type opted in via
	 * seo.schema.article_post_types.
	 *
	 * @param array<string,mixed> $context Build context.
	 * @return array<string,mixed>|null
	 */
	protected function article( array $context ): ?array {
		if ( 'singular' !== $context['kind'] ) {
			return null;
		}

		$post = $context['post'];
		if ( ! is_object( $post ) ) {
			return null;
		}

		$post_types = (array) $this->config->get( 'seo.schema.article_post_types', array( 'post' ) );
		if ( ! in_array( (string) ( $post->post_type ?? '' ), $post_types, true ) ) {
			return null;
		}

		$node = array(
			'@type'            => 'Article',
			'@id'              => $context['canonical'] . '#article',
			'headline'         => SeoHead::clip( (string) get_the_title( $post ), 110 ),
			'mainEntityOfPage' => array( '@id' => $context['ids']['webpage'] ),
			'isPartOf'         => array( '@id' => $context['ids']['webpage'] ),
		);

		if ( isset( $context['ids']['author'] ) ) {
			$node['author'] = array( '@id' => $context['ids']['author'] );
		}

		$node['publisher'] = array( '@id' => $context['ids']['organization'] );

		if ( ! empty( $context['has_primary_image'] ) ) {
			$node['image'] = array( '@id' => $context['ids']['primary_image'] );
		}

		if ( ! empty( $post->post_date_gmt ) ) {
			$published = strtotime( (string) $post->post_date_gmt . ' UTC' );
			if ( false !== $published ) {
				$node['datePublished'] = gmdate( 'c', $published );
			}
		}
		if ( ! empty( $post->post_modified_gmt ) ) {
			$modified = strtotime( (string) $post->post_modified_gmt . ' UTC' );
			if ( false !== $modified ) {
				$node['dateModified'] = gmdate( 'c', $modified );
			}
		}

		$node['inLanguage'] = $this->language();

		return $node;
	}

	/**
	 * Person node for the singular post's author or the author archive's user.
	 *
	 * @param array<string,mixed> $context Build context.
	 * @return array<string,mixed>|null
	 */
	protected function person( array $context ): ?array {
		$author_id = isset( $context['author_id'] ) ? (int) $context['author_id'] : 0;
		if ( $author_id <= 0 || ! isset( $context['ids']['author'] ) ) {
			return null;
		}

		$node = array(
			'@type' => 'Person',
			'@id'   => $context['ids']['author'],
			'name'  => (string) get_the_author_meta( 'display_name', $author_id ),
			'url'   => (string) get_author_posts_url( $author_id ),
		);

		$description = (string) get_the_author_meta( 'description', $author_id );
		if ( '' !== $description ) {
			$node['description'] = $description;
		}

		$avatar = (string) get_avatar_url( $author_id, array( 'size' => 96 ) );
		if ( '' !== $avatar ) {
			$node['image'] = array(
				'@type' => 'ImageObject',
				'url'   => $avatar,
			);
		}

		return $node;
	}

	/**
	 * Human name for the current view, shared by the WebPage node and the
	 * final breadcrumb: post/page title, term name, author display name,
	 * post type label, date title, else the site name.
	 *
	 * @param array<string,mixed> $context Build context.
	 */
	protected function webpage_name( array $context ): string {
		$kind = (string) $context['kind'];
		$post = $context['post'];
		$term = $context['term'];

		if ( is_object( $post ) ) {
			$title = (string) get_the_title( $post );
			if ( '' !== $title ) {
				return $title;
			}
		}

		if ( 'term_archive' === $kind && is_object( $term ) && isset( $term->name ) ) {
			return (string) $term->name;
		}

		if ( 'author_archive' === $kind && ! empty( $context['author_id'] ) ) {
			return (string) get_the_author_meta( 'display_name', (int) $context['author_id'] );
		}

		if ( 'post_type_archive' === $kind ) {
			$label = $this->post_type_label();
			if ( '' !== $label ) {
				return $label;
			}
		}

		if ( 'date_archive' === $kind ) {
			$title = $this->date_archive_title();
			if ( '' !== $title ) {
				return $title;
			}
		}

		return (string) get_bloginfo( 'name' );
	}

	/**
	 * Best-effort description for a post: its excerpt, else trimmed content.
	 * Mirrors SeoHead::post_description().
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
	 * Label of the queried post type on a post type archive.
	 */
	protected function post_type_label(): string {
		$object = get_queried_object();
		if ( is_object( $object ) && isset( $object->labels->name ) ) {
			return (string) $object->labels->name;
		}
		return '';
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
	 * Display title of the current date archive ("2026", "June 2026", or the
	 * site's date format for day archives).
	 */
	protected function date_archive_title(): string {
		$year     = (int) get_query_var( 'year' );
		$monthnum = (int) get_query_var( 'monthnum' );
		$day      = (int) get_query_var( 'day' );

		if ( $year <= 0 ) {
			return '';
		}

		$timestamp = gmmktime( 0, 0, 0, $monthnum > 0 ? $monthnum : 1, $day > 0 ? $day : 1, $year );

		if ( is_day() ) {
			return (string) date_i18n( (string) get_option( 'date_format' ), $timestamp );
		}
		if ( is_month() ) {
			return (string) date_i18n( 'F Y', $timestamp );
		}
		return (string) $year;
	}

	/**
	 * BCP 47-ish language tag for inLanguage.
	 */
	protected function language(): string {
		return str_replace( '_', '-', (string) get_locale() );
	}
}
