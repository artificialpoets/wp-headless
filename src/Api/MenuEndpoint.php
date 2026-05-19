<?php
/**
 * Menu REST endpoint.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Api;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WPHeadless\Config\Config;
use WPHeadless\Contracts\Module;

class MenuEndpoint implements Module {
	/**
	 * Config.
	 *
	 * @var Config
	 */
	private Config $config;

	/**
	 * Constructor.
	 *
	 * @param Config $config Shared config.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register menu routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->config->namespace(),
			'/menus',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_menu' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'       => array(
						'type' => 'integer',
					),
					'slug'     => array(
						'type' => 'string',
					),
					'location' => array(
						'type' => 'string',
					),
				),
			)
		);
	}

	/**
	 * Return a menu by id, slug, or location.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_menu( WP_REST_Request $request ) {
		$menu = null;

		if ( ! $request['location'] && ! $request['slug'] && ! $request['id'] ) {
			return new WP_Error(
				'wp_headless_menu_selector_required',
				__( 'Provide a menu id, slug, or location.', 'wp-headless' ),
				array( 'status' => 400 )
			);
		}

		if ( $request['location'] ) {
			$locations = get_nav_menu_locations();
			$menu_id   = isset( $locations[ (string) $request['location'] ] ) ? (int) $locations[ (string) $request['location'] ] : 0;
			$menu      = $menu_id ? wp_get_nav_menu_object( $menu_id ) : null;
		} elseif ( $request['slug'] ) {
			$menu = wp_get_nav_menu_object( (string) $request['slug'] );
		} elseif ( $request['id'] ) {
			$menu = wp_get_nav_menu_object( (int) $request['id'] );
		}

		if ( ! $menu || is_wp_error( $menu ) ) {
			// FSE / Navigation block fallback. Look for a `wp_navigation` post
			// matching the slug or id and walk its parsed blocks into the same
			// menu shape so the React app doesn't need to know which mechanism
			// the site is using.
			$nav_post = $this->find_navigation_post( $request );
			if ( $nav_post ) {
				return new WP_REST_Response( $this->navigation_post_to_menu( $nav_post ) );
			}

			return new WP_Error(
				'wp_headless_menu_not_found',
				__( 'Menu not found.', 'wp-headless' ),
				array( 'status' => 404 )
			);
		}

		$items = wp_get_nav_menu_items(
			$menu->term_id,
			array(
				'order'   => 'ASC',
				'orderby' => 'menu_order',
			)
		);

		return new WP_REST_Response(
			array(
				'id'          => (int) $menu->term_id,
				'name'        => $menu->name,
				'slug'        => $menu->slug,
				'description' => $menu->description,
				'items'       => $this->build_tree( is_array( $items ) ? $items : array() ),
			)
		);
	}

	/**
	 * Build a recursive menu tree.
	 *
	 * @param array<int, object> $items Menu items.
	 * @return array<int, array<string, mixed>>
	 */
	protected function build_tree( array $items ): array {
		$indexed  = array();
		$children = array();

		foreach ( $items as $item ) {
			$item_id   = (int) $item->ID;
			$parent_id = (int) $item->menu_item_parent;

			$indexed[ $item_id ] = array(
				'id'          => $item_id,
				'title'       => html_entity_decode( (string) $item->title, ENT_QUOTES | ENT_HTML5, 'UTF-8' ),
				'url'         => (string) $item->url,
				'target'      => (string) $item->target,
				'classes'     => is_array( $item->classes ) ? array_values( array_filter( $item->classes ) ) : array(),
				'description' => (string) ( get_post_meta( $item_id, '_menu_item_description', true ) ?: $item->description ),
				'type'        => (string) $item->type,
				'object'      => (string) $item->object,
				'object_id'   => (int) $item->object_id,
				'children'    => array(),
			);

			$children[ $parent_id ][] = $item_id;
		}

		$build = function ( int $parent_id ) use ( &$build, &$indexed, $children ): array {
			$branch = array();

			foreach ( $children[ $parent_id ] ?? array() as $child_id ) {
				$item             = $indexed[ $child_id ];
				$item['children'] = $build( $child_id );
				$branch[]         = apply_filters( 'wp_headless_menu_item', $item, $child_id );
			}

			return $branch;
		};

		return $build( 0 );
	}

	/**
	 * Find a `wp_navigation` post matching the slug or id in the request.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return \WP_Post|null
	 */
	private function find_navigation_post( WP_REST_Request $request ): ?\WP_Post {
		if ( ! post_type_exists( 'wp_navigation' ) ) {
			return null;
		}

		if ( $request['id'] ) {
			$post = get_post( (int) $request['id'] );
			return ( $post && 'wp_navigation' === $post->post_type ) ? $post : null;
		}

		if ( $request['slug'] ) {
			$posts = get_posts( array(
				'post_type'   => 'wp_navigation',
				'name'        => sanitize_title( (string) $request['slug'] ),
				'numberposts' => 1,
				'post_status' => 'publish',
			) );
			return $posts ? $posts[0] : null;
		}

		// No id/slug — pick the first published navigation post as a best-effort default.
		if ( $request['location'] ) {
			$posts = get_posts( array(
				'post_type'   => 'wp_navigation',
				'numberposts' => 1,
				'post_status' => 'publish',
			) );
			return $posts ? $posts[0] : null;
		}

		return null;
	}

	/**
	 * Walk a `wp_navigation` post's parsed blocks and produce the same menu
	 * shape `get_menu()` returns for classic menus.
	 *
	 * @param \WP_Post $post Navigation post.
	 * @return array<string, mixed>
	 */
	private function navigation_post_to_menu( \WP_Post $post ): array {
		$blocks = parse_blocks( (string) $post->post_content );
		$items  = $this->blocks_to_menu_items( $blocks );

		return array(
			'id'          => (int) $post->ID,
			'name'        => $post->post_title ?: '',
			'slug'        => $post->post_name ?: '',
			'description' => '',
			'source'      => 'wp_navigation',
			'items'       => $items,
		);
	}

	/**
	 * Convert an array of parsed blocks from a navigation post into menu items.
	 *
	 * Supports `core/navigation-link`, `core/navigation-submenu`,
	 * `core/page-list`, and `core/home-link`. Other block types are skipped.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @return array<int, array<string, mixed>>
	 */
	private function blocks_to_menu_items( array $blocks ): array {
		$items     = array();
		$auto_id   = 1;
		$home_url  = home_url( '/' );

		foreach ( $blocks as $block ) {
			$name  = (string) ( $block['blockName'] ?? '' );
			$attrs = (array) ( $block['attrs'] ?? array() );
			$inner = (array) ( $block['innerBlocks'] ?? array() );

			if ( 'core/home-link' === $name ) {
				$items[] = array(
					'id'        => $auto_id++,
					'title'     => (string) ( $attrs['label'] ?? __( 'Home', 'wp-headless' ) ),
					'url'       => $home_url,
					'target'    => '',
					'classes'   => array(),
					'description' => '',
					'type'      => 'home',
					'object'    => '',
					'object_id' => 0,
					'children'  => array(),
				);
				continue;
			}

			if ( 'core/navigation-link' === $name || 'core/navigation-submenu' === $name ) {
				$items[] = array(
					'id'        => $auto_id++,
					'title'     => (string) ( $attrs['label'] ?? '' ),
					'url'       => (string) ( $attrs['url'] ?? '' ),
					'target'    => ! empty( $attrs['opensInNewTab'] ) ? '_blank' : '',
					'classes'   => (array) ( $attrs['className'] ?? array() ),
					'description' => (string) ( $attrs['description'] ?? '' ),
					'type'      => (string) ( $attrs['type'] ?? '' ),
					'object'    => (string) ( $attrs['kind'] ?? '' ),
					'object_id' => (int) ( $attrs['id'] ?? 0 ),
					'children'  => $inner ? $this->blocks_to_menu_items( $inner ) : array(),
				);
				continue;
			}

			if ( 'core/page-list' === $name ) {
				$pages = get_pages( array( 'sort_column' => 'menu_order,post_title', 'post_status' => 'publish' ) );
				foreach ( $pages as $page ) {
					$items[] = array(
						'id'        => $auto_id++,
						'title'     => (string) get_the_title( $page ),
						'url'       => (string) get_permalink( $page ),
						'target'    => '',
						'classes'   => array(),
						'description' => '',
						'type'      => 'post_type',
						'object'    => 'page',
						'object_id' => (int) $page->ID,
						'children'  => array(),
					);
				}
				continue;
			}

			// Unknown navigation block — recurse into innerBlocks anyway so
			// we don't drop accidental nesting.
			if ( $inner ) {
				foreach ( $this->blocks_to_menu_items( $inner ) as $child ) {
					$items[] = $child;
				}
			}
		}

		// Apply the same per-item filter we expose on classic menus.
		return array_map(
			static fn( array $item ): array => apply_filters( 'wp_headless_menu_item', $item, (int) ( $item['id'] ?? 0 ) ),
			$items
		);
	}
}
