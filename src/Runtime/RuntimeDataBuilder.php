<?php
/**
 * Runtime payload builder.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Runtime;

use WPHeadless\Config\Config;
use WPHeadless\Theme\ThemeManager;

class RuntimeDataBuilder {
	/** @var Config */
	private Config $config;

	/** @var ThemeManager */
	private ThemeManager $theme_manager;

	/** @var RequestDataBuilder */
	private RequestDataBuilder $request_data;

	public function __construct( Config $config, ThemeManager $theme_manager, RequestDataBuilder $request_data ) {
		$this->config        = $config;
		$this->theme_manager = $theme_manager;
		$this->request_data  = $request_data;
	}

	/**
	 * Build the runtime payload.
	 *
	 * @param string|null $resolved_url Optional URL to resolve.
	 * @return array<string, mixed>
	 */
	public function build( ?string $resolved_url = null ): array {
		$site_icon_id   = absint( get_option( 'site_icon' ) );
		$custom_logo_id = absint( get_theme_mod( 'custom_logo' ) );
		$home_url       = home_url( '/' );

		$data = array(
			'site'      => array(
				'name'         => get_bloginfo( 'name' ),
				'description'  => get_bloginfo( 'description' ),
				'url'          => $home_url,
				'language'     => get_locale(),
				'charset'      => get_bloginfo( 'charset' ),
				'textDirection' => is_rtl() ? 'rtl' : 'ltr',
				'timezone'     => wp_timezone_string(),
				'favicon'      => $site_icon_id ? $this->image_data( $site_icon_id ) : null,
				'logo'         => $custom_logo_id ? $this->image_data( $custom_logo_id ) : null,
				'header_image' => $this->header_image(),
				'background'   => $this->background_data(),
				'admin_email'  => is_user_logged_in() && current_user_can( 'manage_options' ) ? (string) get_bloginfo( 'admin_email' ) : null,
			),
			'rest'      => array(
				'root'      => rest_url(),
				'wpV2'      => rest_url( 'wp/v2/' ),
				'headless'  => rest_url( $this->config->namespace() . '/' ),
				'namespace' => $this->config->namespace(),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
			),
			'frontend'  => array(
				'assetBaseUrl'    => $this->config->asset_base_url(),
				'assetMount'      => '/' . $this->config->asset_mount(),
				'hasFrontendBuild' => $this->theme_manager->has_build() || $this->config->frontend_available(),
			),
			'menus'     => array(
				'locations' => $this->menu_locations(),
			),
			'urls'      => $this->auth_urls( $home_url ),
			'user'      => $this->current_user_data(),
			'postTypes' => $this->post_types_data(),
			'discussion' => $this->discussion_settings(),
			'customCss' => $this->custom_css(),
			'theme'     => $this->theme_data(),
			'request'   => null !== $resolved_url ? $this->request_data->for_url( $resolved_url ) : $this->request_data->for_current_request(),
		);

		return apply_filters( 'wp_headless_runtime_data', $data, $this->config, $resolved_url );
	}

	/**
	 * Auth-related WP URLs the React app may link to.
	 *
	 * @param string $home_url Home URL.
	 * @return array<string, string>
	 */
	private function auth_urls( string $home_url ): array {
		return array(
			'login'         => (string) wp_login_url( $home_url ),
			'logout'        => (string) wp_logout_url( $home_url ),
			'register'      => (string) wp_registration_url(),
			'lost_password' => (string) wp_lostpassword_url( $home_url ),
			'admin'         => (string) admin_url( '/' ),
			'profile'       => (string) admin_url( 'profile.php' ),
			'registration_enabled' => (bool) get_option( 'users_can_register' ),
		);
	}

	/**
	 * Summary of the current user, or null when not authenticated.
	 *
	 * @return array<string, mixed>|null
	 */
	private function current_user_data(): ?array {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		$user = wp_get_current_user();
		if ( ! ( $user instanceof \WP_User ) || ! $user->ID ) {
			return null;
		}

		$caps_of_interest = array(
			'edit_posts'        => current_user_can( 'edit_posts' ),
			'edit_others_posts' => current_user_can( 'edit_others_posts' ),
			'publish_posts'     => current_user_can( 'publish_posts' ),
			'delete_posts'      => current_user_can( 'delete_posts' ),
			'moderate_comments' => current_user_can( 'moderate_comments' ),
			'manage_options'    => current_user_can( 'manage_options' ),
		);

		return array(
			'id'           => (int) $user->ID,
			'slug'         => $user->user_nicename,
			'display_name' => $user->display_name,
			'username'     => $user->user_login,
			'email'        => current_user_can( 'manage_options' ) ? $user->user_email : null,
			'roles'        => array_values( (array) $user->roles ),
			'avatar'       => (string) get_avatar_url( $user->ID, array( 'size' => 96 ) ),
			'profile_link' => (string) get_edit_profile_url( $user->ID ),
			'author_link'  => (string) get_author_posts_url( $user->ID ),
			'capabilities' => $caps_of_interest,
		);
	}

	/**
	 * Registered public post types, including built-ins and CPTs.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function post_types_data(): array {
		$out = array();
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			$labels = (array) $pt->labels;
			$out[]  = array(
				'name'         => $pt->name,
				'label'        => $pt->label,
				'singular'     => $labels['singular_name'] ?? $pt->label,
				'rest_base'    => $pt->rest_base ?: $pt->name,
				'has_archive'  => $pt->has_archive,
				'archive_link' => $pt->has_archive ? (string) get_post_type_archive_link( $pt->name ) : null,
				'hierarchical' => (bool) $pt->hierarchical,
				'show_in_rest' => (bool) $pt->show_in_rest,
				'builtin'      => (bool) ( $pt->_builtin ?? false ),
				'supports_comments' => post_type_supports( $pt->name, 'comments' ),
				'supports_thumbnail' => post_type_supports( $pt->name, 'thumbnail' ),
			);
		}
		return $out;
	}

	/**
	 * Discussion (comments) settings the React app needs to honor.
	 *
	 * @return array<string, mixed>
	 */
	private function discussion_settings(): array {
		return array(
			'comment_registration'  => (bool) get_option( 'comment_registration' ),
			'require_name_email'    => (bool) get_option( 'require_name_email' ),
			'default_comment_status' => (string) get_option( 'default_comment_status' ),
			'thread_comments'       => (bool) get_option( 'thread_comments' ),
			'thread_comments_depth' => (int) get_option( 'thread_comments_depth', 5 ),
			'page_comments'         => (bool) get_option( 'page_comments' ),
			'comments_per_page'     => (int) get_option( 'comments_per_page', 50 ),
			'default_comments_page' => (string) get_option( 'default_comments_page', 'newest' ),
			'comment_order'         => (string) get_option( 'comment_order', 'asc' ),
			'show_avatars'          => (bool) get_option( 'show_avatars', true ),
		);
	}

	/**
	 * Customizer Additional CSS, ready to inject as <style>.
	 *
	 * @return string
	 */
	private function custom_css(): string {
		if ( ! function_exists( 'wp_get_custom_css' ) ) {
			return '';
		}
		return (string) wp_get_custom_css();
	}

	/**
	 * Customizer header image data, or null when unset.
	 *
	 * @return array<string, mixed>|null
	 */
	private function header_image(): ?array {
		if ( ! function_exists( 'get_header_image_tag' ) ) {
			return null;
		}
		$url = (string) get_header_image();
		if ( '' === $url ) {
			return null;
		}
		$data = (object) get_custom_header();
		return array(
			'url'         => $url,
			'width'       => isset( $data->width ) ? (int) $data->width : null,
			'height'      => isset( $data->height ) ? (int) $data->height : null,
			'thumbnail'   => isset( $data->thumbnail_url ) ? (string) $data->thumbnail_url : null,
			'attachment_id' => isset( $data->attachment_id ) ? (int) $data->attachment_id : null,
		);
	}

	/**
	 * Customizer background — image URL + colour, both optional.
	 *
	 * @return array<string, mixed>|null
	 */
	private function background_data(): ?array {
		$image = (string) get_background_image();
		$color = (string) get_background_color();
		if ( '' === $image && '' === $color ) {
			return null;
		}
		return array(
			'image'  => $image ?: null,
			'color'  => $color ? '#' . ltrim( $color, '#' ) : null,
			'repeat' => (string) get_theme_mod( 'background_repeat', 'repeat' ),
			'size'   => (string) get_theme_mod( 'background_size', 'auto' ),
			'attachment' => (string) get_theme_mod( 'background_attachment', 'scroll' ),
			'position'   => (string) get_theme_mod( 'background_position_x', 'left' ) . ' ' . (string) get_theme_mod( 'background_position_y', 'top' ),
		);
	}

	/**
	 * Summarize image data for runtime payloads.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return array<string, mixed>
	 */
	private function image_data( int $attachment_id ): array {
		return array(
			'id'        => $attachment_id,
			'url'       => wp_get_attachment_image_url( $attachment_id, 'full' ),
			'thumbnail' => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
			'alt'       => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
		);
	}

	/**
	 * Summarize menu location assignments.
	 *
	 * @return array<string, mixed>
	 */
	private function menu_locations(): array {
		$locations = get_nav_menu_locations();
		$result    = array();

		foreach ( $locations as $location => $menu_id ) {
			$menu = wp_get_nav_menu_object( $menu_id );

			$result[ $location ] = array(
				'id'   => (int) $menu_id,
				'name' => $menu ? $menu->name : null,
				'slug' => $menu ? $menu->slug : null,
			);
		}

		return $result;
	}

	/**
	 * Theme-level integration data — theme.json presets, registered block
	 * styles (variations), and per-block stylesheet URLs. Lets the React app
	 * mirror the editor experience (palette, font sizes, spacing) without
	 * the theme author having to duplicate theme.json content as CSS.
	 *
	 * @return array<string, mixed>
	 */
	private function theme_data(): array {
		return apply_filters(
			'wp_headless_theme_data',
			array(
				'styles'           => $this->theme_json_styles(),
				'blockStyles'      => $this->block_style_variations(),
				'blockStylesheets' => $this->block_stylesheet_urls(),
			)
		);
	}

	/**
	 * Read the merged theme.json data and surface the presets the React app
	 * cares about: colour palette, font sizes/families, spacing sizes, layout
	 * widths.
	 *
	 * @return array<string, mixed>
	 */
	private function theme_json_styles(): array {
		if ( ! class_exists( '\\WP_Theme_JSON_Resolver' ) ) {
			return array();
		}

		$merged = \WP_Theme_JSON_Resolver::get_merged_data();
		$data   = method_exists( $merged, 'get_data' ) ? $merged->get_data() : array();
		$settings = $data['settings'] ?? array();

		return array(
			'color' => array(
				'palette'   => $this->normalize_preset_list( $settings['color']['palette'] ?? array(), array( 'slug', 'name', 'color' ) ),
				'gradients' => $this->normalize_preset_list( $settings['color']['gradients'] ?? array(), array( 'slug', 'name', 'gradient' ) ),
			),
			'typography' => array(
				'fontSizes'    => $this->normalize_preset_list( $settings['typography']['fontSizes'] ?? array(), array( 'slug', 'name', 'size' ) ),
				'fontFamilies' => $this->normalize_preset_list( $settings['typography']['fontFamilies'] ?? array(), array( 'slug', 'name', 'fontFamily' ) ),
			),
			'spacing' => array(
				'spacingSizes' => $this->normalize_preset_list( $settings['spacing']['spacingSizes'] ?? array(), array( 'slug', 'name', 'size' ) ),
			),
			'layout' => array(
				'contentSize' => (string) ( $settings['layout']['contentSize'] ?? '' ) ?: null,
				'wideSize'    => (string) ( $settings['layout']['wideSize'] ?? '' ) ?: null,
			),
		);
	}

	/**
	 * theme.json preset lists can contain merged values from `default`, `theme`,
	 * and `custom` origin scopes. Flatten the latest one and project to the
	 * exact keys we want.
	 *
	 * @param mixed         $list Raw preset list (may be array, or nested by origin).
	 * @param array<string> $keys Whitelisted keys for projection.
	 * @return array<int, array<string, mixed>>
	 */
	private function normalize_preset_list( $list, array $keys ): array {
		if ( ! is_array( $list ) ) {
			return array();
		}
		// Some WP versions return ['theme' => [...], 'custom' => [...]] etc.
		// Pick whichever has items, theme taking priority.
		if ( isset( $list['theme'] ) || isset( $list['custom'] ) || isset( $list['default'] ) ) {
			$list = $list['custom'] ?? $list['theme'] ?? $list['default'] ?? array();
		}
		$out = array();
		foreach ( (array) $list as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$row = array();
			foreach ( $keys as $k ) {
				if ( isset( $item[ $k ] ) ) {
					$row[ $k ] = $item[ $k ];
				}
			}
			if ( ! empty( $row ) ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Every block style variation registered via register_block_style(), keyed
	 * by block name. Lets a React component check whether `is-style-{x}` on a
	 * given block corresponds to a known variation it should style.
	 *
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function block_style_variations(): array {
		if ( ! class_exists( '\\WP_Block_Styles_Registry' ) ) {
			return array();
		}
		$registry = \WP_Block_Styles_Registry::get_instance();
		$all      = method_exists( $registry, 'get_all_registered' ) ? $registry->get_all_registered() : array();

		$out = array();
		foreach ( $all as $block_name => $styles ) {
			if ( ! is_array( $styles ) ) {
				continue;
			}
			$rows = array();
			foreach ( $styles as $style ) {
				if ( ! is_array( $style ) ) {
					continue;
				}
				$rows[] = array(
					'name'      => (string) ( $style['name'] ?? '' ),
					'label'     => (string) ( $style['label'] ?? '' ),
					'isDefault' => (bool) ( $style['is_default'] ?? $style['isDefault'] ?? false ),
				);
			}
			if ( ! empty( $rows ) ) {
				$out[ $block_name ] = $rows;
			}
		}
		return $out;
	}

	/**
	 * Map of block-name → per-block stylesheet URL, for blocks that declare a
	 * `style` handle in their block.json. Lets the React app conditionally
	 * load only the stylesheets for blocks actually present in rendered HTML.
	 *
	 * @return array<string, string>
	 */
	private function block_stylesheet_urls(): array {
		if ( ! class_exists( '\\WP_Block_Type_Registry' ) ) {
			return array();
		}
		$registry = \WP_Block_Type_Registry::get_instance();
		$styles   = wp_styles();
		$out      = array();
		foreach ( $registry->get_all_registered() as $name => $type ) {
			$handles = (array) ( $type->style_handles ?? ( $type->style ? array( $type->style ) : array() ) );
			foreach ( $handles as $handle ) {
				if ( '' === $handle || ! isset( $styles->registered[ $handle ] ) ) {
					continue;
				}
				$src = $styles->registered[ $handle ]->src ?? '';
				if ( '' === $src ) {
					continue;
				}
				if ( ! is_string( $src ) || '' === $src ) {
					continue;
				}
				$out[ $name ] = $src;
				break;
			}
		}
		// Final guard — strip any entry whose value isn't a non-empty string.
		// Keeps the inline runtime payload lean (was shipping ~27 empty entries
		// for blocks whose style handle exists but has no src).
		return array_filter( $out, static fn( $url ) => is_string( $url ) && '' !== $url );
	}
}
