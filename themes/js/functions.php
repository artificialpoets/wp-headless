<?php
/**
 * Theme bootstrap. Most rendering happens in the React app — this file
 * registers WordPress-side theme support and menu locations so the editor
 * UI behaves correctly even when the wp-headless plugin is doing the
 * actual frontend rendering.
 *
 * @package WPHeadlessStarterJs
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'after_setup_theme',
	static function (): void {
		add_theme_support( 'title-tag' );
		add_theme_support( 'post-thumbnails' );
		add_theme_support(
			'html5',
			array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' )
		);
		add_theme_support( 'custom-logo' );
		add_theme_support(
			'post-formats',
			array( 'aside', 'gallery', 'link', 'image', 'quote', 'status', 'video', 'audio', 'chat' )
		);
		add_theme_support( 'responsive-embeds' );
		add_theme_support( 'editor-styles' );
		add_theme_support( 'wp-block-styles' );
		add_theme_support( 'automatic-feed-links' );

		// The wp-headless plugin also registers these, but registering them in the
		// theme means the Appearance → Menus UI still surfaces these locations if
		// the plugin is briefly deactivated.
		register_nav_menus(
			array(
				'primary' => __( 'Primary Navigation', 'wp-headless-starter-js' ),
				'footer'  => __( 'Footer Navigation', 'wp-headless-starter-js' ),
			)
		);
	}
);
