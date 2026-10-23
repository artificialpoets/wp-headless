<?php
/**
 * Redirects WordPress answers, as a map an edge can read.
 *
 * @package WPHeadless
 */

namespace WPHeadless\StaticPublish;

/**
 * A redirected path has no copy in storage. While WordPress is awake, a
 * storage miss reaches it and it sends the 301. While it sleeps, an edge
 * reads this map (published as `__data/__redirects.json`) and sends the
 * same 301 instead of a 404.
 *
 * Built in: the static front page's own path (`/home/` → `/`, which
 * redirect_canonical() answers) and renamed posts' old slugs
 * (wp_old_slug_redirect()). Redirect plugins add theirs through the
 * `wp_headless_static_redirects` filter.
 */
class RedirectMap {
	/**
	 * @return array<string, string> Path (leading and trailing slash) → absolute URL.
	 */
	public function build(): array {
		$map = array();

		$front = (int) get_option( 'page_on_front' );
		if ( 'page' === get_option( 'show_on_front' ) && $front > 0 ) {
			$uri = (string) get_page_uri( $front );
			if ( '' !== $uri ) {
				$map[ self::normalize( $uri ) ] = home_url( '/' );
			}
		}

		$renamed = get_posts(
			array(
				'post_type'      => array_values( (array) get_post_types( array( 'public' => true ), 'names' ) ),
				'post_status'    => 'publish',
				'has_password'   => false,
				'posts_per_page' => -1,
				'meta_key'       => '_wp_old_slug', // phpcs:ignore WordPress.DB.SlowDBQuery -- once per publish.
				'fields'         => 'ids',
			)
		);
		foreach ( (array) $renamed as $post_id ) {
			$link = get_permalink( (int) $post_id );
			$slug = (string) get_post_field( 'post_name', (int) $post_id );
			$path = Routes::path_of( $link );
			if ( null === $path || '' === $slug || substr( $path, -strlen( $slug ) ) !== $slug ) {
				continue;
			}
			$parent = substr( $path, 0, -strlen( $slug ) );
			foreach ( (array) get_post_meta( (int) $post_id, '_wp_old_slug' ) as $old ) {
				$old = sanitize_title( (string) $old );
				if ( '' !== $old && $old !== $slug ) {
					$map[ self::normalize( $parent . $old ) ] = (string) $link;
				}
			}
		}

		/**
		 * Filters the redirect map the static publisher writes to
		 * `__data/__redirects.json`.
		 *
		 * @param array<string,string> $map Path (leading and trailing slash) → absolute URL.
		 */
		$map = apply_filters( 'wp_headless_static_redirects', $map );

		$clean = array();
		foreach ( (array) $map as $from => $to ) {
			if ( is_string( $from ) && is_string( $to ) && '' !== $to ) {
				$clean[ self::normalize( $from ) ] = $to;
			}
		}
		unset( $clean['/'] );
		ksort( $clean );
		return $clean;
	}

	/**
	 * `/a/b` and `a/b/` both become `/a/b/`; the root stays `/`.
	 *
	 * @param string $path Path or URL.
	 */
	public static function normalize( string $path ): string {
		$path = trim( (string) wp_parse_url( $path, PHP_URL_PATH ), '/' );
		return '' === $path ? '/' : '/' . $path . '/';
	}
}
