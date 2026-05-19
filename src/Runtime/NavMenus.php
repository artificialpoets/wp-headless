<?php
/**
 * Registers the headless theme's nav menu locations.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Runtime;

use WPHeadless\Contracts\Module;

class NavMenus implements Module {

	public function register(): void {
		add_action( 'after_setup_theme', array( $this, 'register_locations' ) );
	}

	public function register_locations(): void {
		register_nav_menus(
			array(
				'primary' => __( 'Primary Navigation', 'wp-headless' ),
				'footer'  => __( 'Footer Navigation', 'wp-headless' ),
			)
		);
	}
}
