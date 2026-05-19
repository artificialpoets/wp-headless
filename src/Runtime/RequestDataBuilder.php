<?php
/**
 * Request context helpers.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Runtime;

use WP_Post;
use WP_Post_Type;
use WP_Term;
use WP_User;

class RequestDataBuilder {
	/**
	 * Build request data from the current WordPress query.
	 *
	 * @return array<string, mixed>
	 */
	public function for_current_request(): array {
		$queried_object = get_queried_object();
		$post           = $queried_object instanceof WP_Post ? $queried_object : null;
		$paged          = max( 1, (int) get_query_var( 'paged' ) ?: (int) get_query_var( 'page' ) ?: 1 );

		return array(
			'url'                  => $this->current_url(),
			'path'                 => $this->current_path(),
			'is_front_page'        => is_front_page(),
			'is_home'              => is_home(),
			'is_singular'          => is_singular(),
			'is_archive'           => is_archive(),
			'is_author'            => is_author(),
			'is_date'              => is_date(),
			'is_year'              => is_year(),
			'is_month'             => is_month(),
			'is_day'               => is_day(),
			'is_attachment'        => is_attachment(),
			'is_post_type_archive' => is_post_type_archive(),
			'is_preview'           => is_preview(),
			'is_auth'              => false,
			'is_embed'             => is_embed(),
			'is_search'            => is_search(),
			'is_404'               => is_404(),
			'page'                 => $paged,
			'search_query'         => is_search() ? (string) get_search_query( false ) : null,
			'date_archive'         => $this->current_date_archive(),
			'robots'               => $this->current_robots(),
			'queried_object'       => $this->summarize_queried_object( $queried_object ),
			'post'                 => $post ? $this->summarize_post( $post ) : null,
			'queried_object_id'    => get_queried_object_id(),
		);
	}

	/**
	 * Resolve the robots directives WP would output for the current request,
	 * funnelled through the standard `wp_robots` filter so any plugin (Yoast,
	 * Rank Math, etc.) that registers a robots directive is honoured.
	 *
	 * @return string|null Comma-joined value like "max-image-preview:large,follow,index" or null.
	 */
	private function current_robots(): ?string {
		if ( ! function_exists( 'wp_robots' ) ) {
			return null;
		}
		$directives = (array) apply_filters( 'wp_robots', array() );
		$out = array();
		foreach ( $directives as $directive => $value ) {
			if ( false === $value ) {
				continue;
			}
			$out[] = is_string( $value ) && '' !== $value ? "{$directive}:{$value}" : (string) $directive;
		}
		return $out ? implode( ', ', $out ) : null;
	}

	/**
	 * Resolve a URL to a best-effort WordPress object payload.
	 *
	 * Returns the same shape as for_current_request() so the frontend
	 * can use is_* flags uniformly for both initial and client-side renders.
	 *
	 * @param string $url URL or path.
	 * @return array<string, mixed>
	 */
	public function for_url( string $url ): array {
		$url        = $this->normalize_url( $url );
		$path       = (string) wp_parse_url( $url, PHP_URL_PATH );
		$normalized = $this->normalize_path( $path );

		// Strip a trailing /page/N/ segment so archive URLs match their canonical form.
		$page = 1;
		if ( preg_match( '#^(.*?)/page/([0-9]+)/?$#', $normalized, $m ) ) {
			$normalized = trailingslashit( $m[1] );
			$page       = max( 1, (int) $m[2] );
		}

		$qs        = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$qs_params = array();
		if ( $qs ) {
			wp_parse_str( $qs, $qs_params );
		}
		// Honour ?paged= or ?page=
		if ( ! empty( $qs_params['paged'] ) ) {
			$page = max( 1, (int) $qs_params['paged'] );
		} elseif ( ! empty( $qs_params['page'] ) ) {
			$page = max( 1, (int) $qs_params['page'] );
		}

		$base = $this->base_template( $page );

		// Auth routes — let the React app render its own forms.
		$auth_kind = $this->match_auth_route( $normalized );
		if ( null !== $auth_kind ) {
			return array_merge( $base, array(
				'url'     => $url,
				'path'    => $normalized,
				'kind'    => $auth_kind,
				'is_auth' => true,
			) );
		}

		// WP-to-WP oEmbed iframe form: `?embed=true` or path ending in `/embed/`.
		// WP renders these via the `embed_template` filter. In headless mode the
		// React app handles them with a minimal Embed template.
		if ( ! empty( $qs_params['embed'] ) && in_array( strtolower( (string) $qs_params['embed'] ), array( '1', 'true' ), true ) ) {
			$embed_post_id = url_to_postid( preg_replace( '/[?&]embed=[^&]*/', '', $url ) );
			if ( $embed_post_id ) {
				$post = get_post( $embed_post_id );
				if ( $post instanceof WP_Post ) {
					return array_merge( $base, array(
						'url'               => $url,
						'path'              => $normalized,
						'kind'              => 'embed',
						'is_embed'          => true,
						'is_singular'       => true,
						'queried_object'    => $this->summarize_post( $post ),
						'queried_object_id' => $embed_post_id,
						'post'              => $this->summarize_post( $post ),
					) );
				}
			}
		}

		if ( preg_match( '#^(.+?)/embed/?$#', $normalized, $em ) ) {
			$base_path = trailingslashit( $em[1] );
			$embed_url = home_url( $base_path );
			$embed_post_id = url_to_postid( $embed_url );
			if ( $embed_post_id ) {
				$post = get_post( $embed_post_id );
				if ( $post instanceof WP_Post ) {
					return array_merge( $base, array(
						'url'               => $url,
						'path'              => $normalized,
						'kind'              => 'embed',
						'is_embed'          => true,
						'is_singular'       => true,
						'queried_object'    => $this->summarize_post( $post ),
						'queried_object_id' => $embed_post_id,
						'post'              => $this->summarize_post( $post ),
					) );
				}
			}
		}

		// Preview request: ?preview=true&p=N (or ?preview_id=N&preview_nonce=...)
		$preview_post_id = $this->resolve_preview_post_id( $qs_params );
		if ( $preview_post_id ) {
			$post = get_post( $preview_post_id );
			if ( $post instanceof WP_Post ) {
				$queried = $this->summarize_post( $post );
				return array_merge( $base, array(
					'url'               => $url,
					'path'              => $normalized,
					'kind'              => 'post_preview',
					'is_singular'       => true,
					'is_preview'        => true,
					'queried_object'    => $queried,
					'queried_object_id' => $preview_post_id,
					'post'              => $queried,
				) );
			}
		}

		// 1. Search via ?s=
		if ( ! empty( $qs_params['s'] ) ) {
			return array_merge( $base, array(
				'url'          => $url,
				'path'         => $normalized,
				'kind'         => 'search',
				'is_search'    => true,
				'search_query' => sanitize_text_field( wp_unslash( $qs_params['s'] ) ),
			) );
		}

		// Attachment by id: ?attachment_id=N
		if ( ! empty( $qs_params['attachment_id'] ) ) {
			$attachment_id = (int) $qs_params['attachment_id'];
			$attachment    = $this->resolve_attachment( $attachment_id );
			if ( $attachment ) {
				return array_merge( $base, array(
					'url'               => $url,
					'path'              => $normalized,
					'kind'              => 'attachment',
					'is_singular'       => true,
					'is_attachment'     => true,
					'queried_object'    => $attachment,
					'queried_object_id' => $attachment_id,
					'post'              => $attachment,
				) );
			}
		}

		$front_page_id = absint( get_option( 'page_on_front' ) );
		$posts_page_id = absint( get_option( 'page_for_posts' ) );
		$show_on_front = (string) get_option( 'show_on_front' );

		// 2. Singular post/page (or any registered CPT, or attachment via pretty URL) via url_to_postid().
		$post_id = url_to_postid( $url );
		if ( $post_id ) {
			$post = get_post( $post_id );

			if ( $post instanceof WP_Post ) {
				$is_front     = 'page' === $show_on_front && $front_page_id === $post_id;
				$is_attachment = 'attachment' === $post->post_type;
				$queried      = $is_attachment ? $this->summarize_attachment( $post ) : $this->summarize_post( $post );

				return array_merge( $base, array(
					'url'               => $url,
					'path'              => $normalized,
					'kind'              => $is_front ? 'front_page' : ( $is_attachment ? 'attachment' : 'post' ),
					'is_front_page'     => $is_front,
					'is_singular'       => true,
					'is_attachment'     => $is_attachment,
					'queried_object'    => $queried,
					'queried_object_id' => $post_id,
					'post'              => $queried,
				) );
			}
		}

		// 3. Static front page on /
		if ( '/' === $normalized && 'page' === $show_on_front && $front_page_id ) {
			$post = get_post( $front_page_id );

			if ( $post instanceof WP_Post ) {
				$queried = $this->summarize_post( $post );

				return array_merge( $base, array(
					'url'               => $url,
					'path'              => '/',
					'kind'              => 'front_page',
					'is_front_page'     => true,
					'is_singular'       => true,
					'queried_object'    => $queried,
					'queried_object_id' => $front_page_id,
					'post'              => $queried,
				) );
			}
		}

		// 4. Posts archive at /
		if ( '/' === $normalized && 'posts' === $show_on_front ) {
			return array_merge( $base, array(
				'url'           => $url,
				'path'          => '/',
				'kind'          => 'posts_archive',
				'is_front_page' => true,
				'is_home'       => true,
			) );
		}

		// 5. Static posts page (blog page)
		if ( $posts_page_id ) {
			$posts_page = get_post( $posts_page_id );

			if ( $posts_page instanceof WP_Post ) {
				$posts_page_path = $this->normalize_path( (string) wp_parse_url( get_permalink( $posts_page ), PHP_URL_PATH ) );

				if ( $posts_page_path === $normalized ) {
					$queried = $this->summarize_post( $posts_page );

					return array_merge( $base, array(
						'url'               => $url,
						'path'              => $normalized,
						'kind'              => 'posts_page',
						'is_home'           => true,
						'queried_object'    => $queried,
						'queried_object_id' => $posts_page_id,
					) );
				}
			}
		}

		// 6. Term archives (category, tag, custom taxonomies).
		$term = $this->resolve_term_archive( $normalized );
		if ( $term instanceof WP_Term ) {
			return array_merge( $base, array(
				'url'               => $url,
				'path'              => $normalized,
				'kind'              => 'term_archive',
				'is_archive'        => true,
				'queried_object'    => $this->summarize_term( $term ),
				'queried_object_id' => (int) $term->term_id,
			) );
		}

		// 7. Author archive (/author/{nicename}/)
		$user = $this->resolve_author_archive( $normalized );
		if ( $user instanceof WP_User ) {
			return array_merge( $base, array(
				'url'               => $url,
				'path'              => $normalized,
				'kind'              => 'author_archive',
				'is_archive'        => true,
				'is_author'         => true,
				'queried_object'    => $this->summarize_user( $user ),
				'queried_object_id' => (int) $user->ID,
			) );
		}

		// 8. Custom post type archive (/{cpt_slug}/)
		$cpt = $this->resolve_post_type_archive( $normalized );
		if ( $cpt instanceof WP_Post_Type ) {
			return array_merge( $base, array(
				'url'               => $url,
				'path'              => $normalized,
				'kind'              => 'post_type_archive',
				'is_archive'        => true,
				'is_post_type_archive' => true,
				'queried_object'    => $this->summarize_post_type( $cpt ),
				'queried_object_id' => 0,
			) );
		}

		// 9. Date archives (/YYYY/, /YYYY/MM/, /YYYY/MM/DD/)
		$date = $this->resolve_date_archive( $normalized );
		if ( $date ) {
			return array_merge( $base, array(
				'url'          => $url,
				'path'         => $normalized,
				'kind'         => 'date_archive',
				'is_archive'   => true,
				'is_date'      => true,
				'is_year'      => isset( $date['year'] ) && ! isset( $date['month'] ) && ! isset( $date['day'] ),
				'is_month'     => isset( $date['month'] ) && ! isset( $date['day'] ),
				'is_day'       => isset( $date['day'] ),
				'date_archive' => $date,
			) );
		}

		// 9. Unresolved → 404
		return array_merge( $base, array(
			'url'    => $url,
			'path'   => $normalized,
			'kind'   => 'unresolved',
			'is_404' => true,
		) );
	}

	/**
	 * Default array shape for the response so each branch only sets what differs.
	 *
	 * @param int $page Current page number (>=1).
	 * @return array<string, mixed>
	 */
	private function base_template( int $page ): array {
		return array(
			'is_front_page'        => false,
			'is_home'              => false,
			'is_singular'          => false,
			'is_archive'           => false,
			'is_author'            => false,
			'is_date'              => false,
			'is_year'              => false,
			'is_month'             => false,
			'is_day'               => false,
			'is_attachment'        => false,
			'is_post_type_archive' => false,
			'is_preview'           => false,
			'is_auth'              => false,
			'is_embed'             => false,
			'is_search'            => false,
			'is_404'               => false,
			'page'                 => $page,
			'search_query'         => null,
			'date_archive'         => null,
			'robots'               => null,
			'queried_object'       => null,
			'queried_object_id'    => 0,
			'post'                 => null,
		);
	}

	/**
	 * Summarize the current queried object.
	 *
	 * @param mixed $object Queried object.
	 * @return array<string, mixed>|null
	 */
	private function summarize_queried_object( $object ): ?array {
		if ( $object instanceof WP_Post ) {
			$summary         = $this->summarize_post( $object );
			$summary['kind'] = 'post';
			return $summary;
		}

		if ( $object instanceof WP_Term ) {
			return $this->summarize_term( $object );
		}

		if ( $object instanceof WP_Post_Type ) {
			return array(
				'kind'      => 'post_type',
				'name'      => $object->name,
				'label'     => $object->label,
				'rest_base' => $object->rest_base,
			);
		}

		if ( $object instanceof WP_User ) {
			return $this->summarize_user( $object );
		}

		return null;
	}

	/**
	 * Summarize a post object.
	 *
	 * @param WP_Post $post Post object.
	 * @return array<string, mixed>
	 */
	private function summarize_post( WP_Post $post ): array {
		return array(
			'id'        => (int) $post->ID,
			'post_type' => $post->post_type,
			'slug'      => $post->post_name,
			'status'    => $post->post_status,
			'title'     => get_the_title( $post ),
			'link'      => get_permalink( $post ),
		);
	}

	/**
	 * Summarize a term object, including ancestor chain when the taxonomy is
	 * hierarchical. The chain is leaf-to-root in WP's `get_ancestors()` order
	 * but we reverse it to root-to-leaf so the React app can render breadcrumbs
	 * directly without flipping the array.
	 *
	 * @param WP_Term $term Term object.
	 * @return array<string, mixed>
	 */
	private function summarize_term( WP_Term $term ): array {
		$ancestors = array();
		if ( is_taxonomy_hierarchical( $term->taxonomy ) ) {
			$ancestor_ids = (array) get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' );
			// Root → leaf order.
			$ancestor_ids = array_reverse( $ancestor_ids );
			foreach ( $ancestor_ids as $ancestor_id ) {
				$anc = get_term( (int) $ancestor_id, $term->taxonomy );
				if ( $anc instanceof WP_Term ) {
					$ancestors[] = array(
						'id'   => (int) $anc->term_id,
						'slug' => $anc->slug,
						'name' => $anc->name,
						'link' => get_term_link( $anc ),
					);
				}
			}
		}

		return array(
			'kind'        => 'term',
			'id'          => (int) $term->term_id,
			'taxonomy'    => $term->taxonomy,
			'slug'        => $term->slug,
			'name'        => $term->name,
			'description' => $term->description,
			'count'       => (int) $term->count,
			'parent'      => (int) $term->parent,
			'ancestors'   => $ancestors,
			'link'        => get_term_link( $term ),
		);
	}

	/**
	 * Summarize a user object for public author payloads.
	 *
	 * @param WP_User $user User object.
	 * @return array<string, mixed>
	 */
	private function summarize_user( WP_User $user ): array {
		$avatar = get_avatar_url( $user->ID, array( 'size' => 96 ) );

		return array(
			'kind'         => 'user',
			'id'           => (int) $user->ID,
			'slug'         => $user->user_nicename,
			'display_name' => $user->display_name,
			'description'  => (string) get_user_meta( $user->ID, 'description', true ),
			'avatar'       => $avatar ? (string) $avatar : null,
			'link'         => get_author_posts_url( $user->ID ),
		);
	}

	/**
	 * Build a date_archive payload for the current WP query when applicable.
	 *
	 * @return array<string, int>|null
	 */
	private function current_date_archive(): ?array {
		if ( ! is_date() ) {
			return null;
		}

		$year  = (int) get_query_var( 'year' );
		$month = (int) get_query_var( 'monthnum' );
		$day   = (int) get_query_var( 'day' );

		$out = array();
		if ( $year ) {
			$out['year'] = $year;
		}
		if ( $month ) {
			$out['month'] = $month;
		}
		if ( $day ) {
			$out['day'] = $day;
		}

		return $out ?: null;
	}

	/**
	 * Current request URL.
	 *
	 * @return string
	 */
	protected function current_url(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
		return home_url( $request_uri );
	}

	/**
	 * Current request path.
	 *
	 * @return string
	 */
	protected function current_path(): string {
		return $this->normalize_path( (string) wp_parse_url( $this->current_url(), PHP_URL_PATH ) );
	}

	/**
	 * Normalize a URL or path to an absolute URL.
	 *
	 * @param string $url URL or path.
	 * @return string
	 */
	protected function normalize_url( string $url ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return home_url( '/' );
		}

		if ( 0 === strpos( $url, 'http://' ) || 0 === strpos( $url, 'https://' ) ) {
			return $url;
		}

		return home_url( '/' . ltrim( $url, '/' ) );
	}

	/**
	 * Normalize a request path.
	 *
	 * @param string $path Request path.
	 * @return string
	 */
	protected function normalize_path( string $path ): string {
		$path = '/' . ltrim( $path, '/' );

		if ( '/' !== $path ) {
			$path = untrailingslashit( $path ) . '/';
		}

		return $path;
	}

	/**
	 * Try to match a normalized path to a taxonomy term archive URL.
	 *
	 * Iterates over all public taxonomies, derives the rewrite slug (respecting
	 * category_base / tag_base options and with_front), and tries get_term_by()
	 * on the last path segment so hierarchical category slugs work too.
	 *
	 * @param string $normalized Normalized path (leading and trailing slash).
	 * @return WP_Term|null Matched term, or null if no match.
	 */
	private function resolve_term_archive( string $normalized ): ?WP_Term {
		global $wp_rewrite;

		$front = $wp_rewrite instanceof \WP_Rewrite
			? trim( (string) $wp_rewrite->front, '/' )
			: '';

		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tax ) {
			if ( ! $tax->rewrite ) {
				continue;
			}

			$rewrite_slug = isset( $tax->rewrite['slug'] ) ? trim( (string) $tax->rewrite['slug'], '/' ) : $tax->name;
			$with_front   = ! empty( $tax->rewrite['with_front'] );

			$prefix = '/';
			if ( $with_front && $front ) {
				$prefix .= $front . '/';
			}
			$prefix .= $rewrite_slug . '/';

			if ( 0 !== strpos( $normalized, $prefix ) ) {
				continue;
			}

			$remainder = trim( substr( $normalized, strlen( $prefix ) ), '/' );
			if ( '' === $remainder ) {
				continue;
			}

			$parts     = explode( '/', $remainder );
			$term_slug = (string) end( $parts );

			$term = get_term_by( 'slug', $term_slug, $tax->name );
			if ( $term instanceof WP_Term ) {
				return $term;
			}
		}

		return null;
	}

	/**
	 * Try to match a normalized path to an author archive URL (/author/{nicename}/).
	 *
	 * Respects the author_base rewrite option and the front prefix.
	 *
	 * @param string $normalized Normalized path.
	 * @return WP_User|null Matched user, or null.
	 */
	private function resolve_author_archive( string $normalized ): ?WP_User {
		global $wp_rewrite;

		$author_base = $wp_rewrite instanceof \WP_Rewrite && $wp_rewrite->author_base
			? trim( (string) $wp_rewrite->author_base, '/' )
			: 'author';

		$front = $wp_rewrite instanceof \WP_Rewrite
			? trim( (string) $wp_rewrite->front, '/' )
			: '';

		$prefix = '/';
		if ( $front ) {
			$prefix .= $front . '/';
		}
		$prefix .= $author_base . '/';

		if ( 0 !== strpos( $normalized, $prefix ) ) {
			return null;
		}

		$nicename = trim( substr( $normalized, strlen( $prefix ) ), '/' );
		if ( '' === $nicename ) {
			return null;
		}

		$user = get_user_by( 'slug', $nicename );
		return $user instanceof WP_User ? $user : null;
	}

	/**
	 * Try to match a normalized path to a date archive (/YYYY/, /YYYY/MM/, /YYYY/MM/DD/).
	 *
	 * Respects the front prefix.
	 *
	 * @param string $normalized Normalized path.
	 * @return array<string, int>|null Date parts or null.
	 */
	private function resolve_date_archive( string $normalized ): ?array {
		global $wp_rewrite;

		$front = $wp_rewrite instanceof \WP_Rewrite
			? trim( (string) $wp_rewrite->front, '/' )
			: '';

		$prefix = '/' . ( $front ? $front . '/' : '' );
		if ( 0 !== strpos( $normalized, $prefix ) ) {
			return null;
		}

		$remainder = trim( substr( $normalized, strlen( $prefix ) ), '/' );
		if ( '' === $remainder ) {
			return null;
		}

		$parts = explode( '/', $remainder );

		// Year only
		if ( count( $parts ) === 1 && preg_match( '/^[0-9]{4}$/', $parts[0] ) ) {
			$year = (int) $parts[0];
			if ( $year >= 1900 && $year <= 2200 ) {
				return array( 'year' => $year );
			}
		}

		// Year + month
		if ( count( $parts ) === 2 && preg_match( '/^[0-9]{4}$/', $parts[0] ) && preg_match( '/^[0-9]{1,2}$/', $parts[1] ) ) {
			$year  = (int) $parts[0];
			$month = (int) $parts[1];
			if ( $year >= 1900 && $year <= 2200 && $month >= 1 && $month <= 12 ) {
				return array(
					'year'  => $year,
					'month' => $month,
				);
			}
		}

		// Year + month + day
		if ( count( $parts ) === 3
			&& preg_match( '/^[0-9]{4}$/', $parts[0] )
			&& preg_match( '/^[0-9]{1,2}$/', $parts[1] )
			&& preg_match( '/^[0-9]{1,2}$/', $parts[2] ) ) {
			$year  = (int) $parts[0];
			$month = (int) $parts[1];
			$day   = (int) $parts[2];
			if ( $year >= 1900 && $year <= 2200 && $month >= 1 && $month <= 12 && $day >= 1 && $day <= 31 ) {
				return array(
					'year'  => $year,
					'month' => $month,
					'day'   => $day,
				);
			}
		}

		return null;
	}

	/**
	 * Match a normalized path against a registered post type's archive slug.
	 *
	 * Built-in post types (`post`, `page`, `attachment`) and types without
	 * `has_archive` are skipped — only registered CPTs with `has_archive: true`
	 * or a custom archive slug count.
	 *
	 * @param string $normalized Normalized path.
	 * @return WP_Post_Type|null Matching post type, or null.
	 */
	private function resolve_post_type_archive( string $normalized ): ?WP_Post_Type {
		global $wp_rewrite;

		$front = $wp_rewrite instanceof \WP_Rewrite
			? trim( (string) $wp_rewrite->front, '/' )
			: '';

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			// Skip built-ins that we already handle.
			if ( in_array( $pt->name, array( 'post', 'page', 'attachment' ), true ) ) {
				continue;
			}

			if ( empty( $pt->has_archive ) ) {
				continue;
			}

			$archive_slug = is_string( $pt->has_archive ) ? $pt->has_archive : $pt->rewrite['slug'] ?? $pt->name;
			$with_front   = ! empty( $pt->rewrite['with_front'] );

			$prefix = '/';
			if ( $with_front && $front ) {
				$prefix .= $front . '/';
			}
			$prefix .= trim( (string) $archive_slug, '/' ) . '/';

			if ( $normalized === $prefix ) {
				return $pt;
			}
		}

		return null;
	}

	/**
	 * Match `/login/`, `/register/`, `/lost-password/` (and trailing variants).
	 *
	 * @param string $normalized Normalized path.
	 * @return string|null One of 'login'|'register'|'lost_password' or null.
	 */
	private function match_auth_route( string $normalized ): ?string {
		$map = array(
			'/login/'              => 'login',
			'/register/'           => 'register',
			'/sign-up/'            => 'register',
			'/lost-password/'      => 'lost_password',
			'/forgot/'             => 'lost_password',
			'/profile/'            => 'profile',
			'/profile/passwords/'  => 'profile_passwords',
		);
		return $map[ $normalized ] ?? null;
	}

	/**
	 * Detect a valid post-preview request and return the preview post id.
	 *
	 * Honors WordPress's standard preview parameters:
	 *   - ?preview=true&p=N           — author previewing own latest revision
	 *   - ?preview_id=N&preview_nonce — public preview link
	 *
	 * @param array<string, mixed> $qs Query string parameters.
	 * @return int|null Post id when the preview is allowed, otherwise null.
	 */
	private function resolve_preview_post_id( array $qs ): ?int {
		if ( ! empty( $qs['preview_id'] ) && ! empty( $qs['preview_nonce'] ) ) {
			$id    = (int) $qs['preview_id'];
			$nonce = (string) $qs['preview_nonce'];
			if ( wp_verify_nonce( $nonce, 'post_preview_' . $id ) ) {
				return $id;
			}
			return null;
		}

		$is_preview = isset( $qs['preview'] ) && in_array( strtolower( (string) $qs['preview'] ), array( '1', 'true' ), true );
		if ( ! $is_preview ) {
			return null;
		}

		$id = 0;
		if ( ! empty( $qs['p'] ) ) {
			$id = (int) $qs['p'];
		} elseif ( ! empty( $qs['page_id'] ) ) {
			$id = (int) $qs['page_id'];
		} elseif ( ! empty( $qs['preview_id'] ) ) {
			$id = (int) $qs['preview_id'];
		}

		if ( ! $id || ! is_user_logged_in() ) {
			return null;
		}

		// Author must have edit cap on the post.
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return null;
		}

		return $id;
	}

	/**
	 * Resolve an attachment by id when it exists and is public.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array<string, mixed>|null
	 */
	private function resolve_attachment( int $attachment_id ): ?array {
		$post = get_post( $attachment_id );
		if ( ! $post instanceof WP_Post || 'attachment' !== $post->post_type ) {
			return null;
		}
		return $this->summarize_attachment( $post );
	}

	/**
	 * Summarize an attachment for queried_object payloads.
	 *
	 * @param WP_Post $post Attachment post.
	 * @return array<string, mixed>
	 */
	private function summarize_attachment( WP_Post $post ): array {
		$meta = wp_get_attachment_metadata( $post->ID );

		return array(
			'kind'         => 'attachment',
			'id'           => (int) $post->ID,
			'slug'         => $post->post_name,
			'post_type'    => 'attachment',
			'status'       => $post->post_status,
			'title'        => get_the_title( $post ),
			'caption'      => wp_get_attachment_caption( $post->ID ),
			'description'  => $post->post_content,
			'alt'          => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'mime'         => get_post_mime_type( $post ),
			'url'          => wp_get_attachment_url( $post->ID ),
			'parent_id'    => (int) $post->post_parent,
			'parent_link'  => $post->post_parent ? get_permalink( $post->post_parent ) : null,
			'width'        => isset( $meta['width'] ) ? (int) $meta['width'] : null,
			'height'       => isset( $meta['height'] ) ? (int) $meta['height'] : null,
			'srcset'       => wp_attachment_is_image( $post->ID ) ? (string) wp_get_attachment_image_srcset( $post->ID, 'full' ) : null,
			'sizes'        => wp_attachment_is_image( $post->ID ) ? (string) wp_get_attachment_image_sizes( $post->ID, 'full' ) : null,
			'link'         => get_permalink( $post ),
		);
	}

	/**
	 * Summarize a post type for queried_object payloads (used for CPT archives).
	 *
	 * @param WP_Post_Type $pt Post type.
	 * @return array<string, mixed>
	 */
	private function summarize_post_type( WP_Post_Type $pt ): array {
		$labels = (array) $pt->labels;
		return array(
			'kind'         => 'post_type',
			'name'         => $pt->name,
			'label'        => $pt->label,
			'description'  => (string) $pt->description,
			'rest_base'    => $pt->rest_base ?: $pt->name,
			'has_archive'  => $pt->has_archive,
			'singular'     => $labels['singular_name'] ?? $pt->label,
			'archive_link' => function_exists( 'get_post_type_archive_link' ) ? (string) get_post_type_archive_link( $pt->name ) : null,
		);
	}
}
