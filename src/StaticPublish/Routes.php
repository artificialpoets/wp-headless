<?php
/**
 * What the static publisher publishes.
 *
 * @package WPHeadless
 */

namespace WPHeadless\StaticPublish;

/**
 * Collects the public URLs of a site: every published singular, the
 * archives with their pagination, and the plain-text and XML files crawlers
 * read. Only `publish` posts without a password count; a post made private,
 * drafted, scheduled, trashed or password-protected drops out of the list,
 * so its copy is swept on the next publish.
 */
class Routes {
	/**
	 * Every published singular as { path, id, type }. The static front page
	 * maps to `/` (get_permalink() can report its slug path).
	 *
	 * @return array<int, array{path:string, id:int, type:string}>
	 */
	public function singulars(): array {
		$routes = array();
		$front  = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		foreach ( (array) get_post_types( array( 'public' => true ), 'names' ) as $type ) {
			if ( 'attachment' === $type ) {
				continue;
			}
			$ids = get_posts(
				array(
					'post_type'        => $type,
					'post_status'      => 'publish',
					'has_password'     => false,
					'posts_per_page'   => -1,
					'fields'           => 'ids',
					'orderby'          => 'ID',
					'order'            => 'ASC',
				)
			);
			foreach ( (array) $ids as $id ) {
				$link = ( $front && (int) $id === $front ) ? home_url( '/' ) : get_permalink( (int) $id );
				$path = self::path_of( $link );
				if ( null !== $path ) {
					$routes[] = array(
						'path' => $path,
						'id'   => (int) $id,
						'type' => (string) $type,
					);
				}
			}
		}
		return $routes;
	}

	/**
	 * Archive paths with their pagination: the posts index, every public
	 * term with published posts, every author with published posts.
	 *
	 * @return array<int, string> Paths with a trailing slash.
	 */
	public function archives(): array {
		$paths    = array();
		$per_page = max( 1, (int) get_option( 'posts_per_page', 10 ) );
		$add      = static function ( $link, int $count ) use ( &$paths, $per_page ): void {
			$path = self::path_of( $link );
			if ( null === $path ) {
				return;
			}
			$path    = '/' === $path ? '/' : $path . '/';
			$paths[] = $path;
			$pages   = (int) ceil( max( 0, $count ) / $per_page );
			for ( $n = 2; $n <= $pages; $n++ ) {
				$paths[] = $path . 'page/' . $n . '/';
			}
		};

		$published = (int) ( wp_count_posts( 'post' )->publish ?? 0 );
		$posts_on  = (int) get_option( 'page_for_posts' );
		if ( 'page' !== get_option( 'show_on_front' ) ) {
			$add( home_url( '/' ), $published );
		} elseif ( $posts_on > 0 ) {
			$add( get_permalink( $posts_on ), $published );
		}

		foreach ( (array) get_taxonomies( array( 'public' => true ), 'names' ) as $taxonomy ) {
			if ( 'post_format' === $taxonomy ) {
				continue;
			}
			$terms = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => true,
				)
			);
			if ( ! is_array( $terms ) ) {
				continue;
			}
			foreach ( $terms as $term ) {
				$add( get_term_link( $term ), (int) $term->count );
			}
		}

		foreach ( (array) get_users( array( 'has_published_posts' => array( 'post' ) ) ) as $user ) {
			$add( get_author_posts_url( (int) $user->ID, (string) $user->user_nicename ), (int) count_user_posts( (int) $user->ID, 'post', true ) );
		}

		return array_values( array_unique( $paths ) );
	}

	/**
	 * Files crawlers and agents read. A file the site does not serve
	 * answers 404 and is simply not stored.
	 *
	 * @return array<int, string>
	 */
	public function files(): array {
		return array( '/robots.txt', '/llms.txt', '/llms-full.txt', '/wp-sitemap.xml', '/wp-sitemap.xsl', '/wp-sitemap-index.xsl', '/feed/' );
	}

	/**
	 * Same-host paths a sitemap lists in its `<loc>` elements.
	 *
	 * @param string $xml  Sitemap body.
	 * @param string $home The site's home URL.
	 * @return array<int, string>
	 */
	public static function sitemap_children( string $xml, string $home ): array {
		$host  = (string) wp_parse_url( $home, PHP_URL_HOST );
		$paths = array();
		if ( '' !== $host && preg_match_all( '#<loc>\s*([^<\s]+)\s*</loc>#i', $xml, $matches ) ) {
			foreach ( $matches[1] as $url ) {
				$url = html_entity_decode( $url, ENT_QUOTES | ENT_XML1 );
				if ( wp_parse_url( $url, PHP_URL_HOST ) === $host ) {
					$path = (string) wp_parse_url( $url, PHP_URL_PATH );
					if ( preg_match( '#\.xml$#', $path ) ) {
						$paths[] = $path;
					}
				}
			}
		}
		return array_values( array_unique( $paths ) );
	}

	/**
	 * The path of a same-site URL without its trailing slash (`/` for the
	 * root), or null when the URL has no usable path.
	 *
	 * @param mixed $link URL.
	 */
	public static function path_of( $link ): ?string {
		if ( ! is_string( $link ) || '' === $link ) {
			return null;
		}
		$path = wp_parse_url( $link, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path || '/' !== $path[0] ) {
			return null;
		}
		return '/' === $path ? '/' : rtrim( $path, '/' );
	}
}
