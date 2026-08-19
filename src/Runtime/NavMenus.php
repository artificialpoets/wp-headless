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
		// The plugin boots at after_setup_theme@100 — by then the hook is
		// already running, so a fresh add_action() at the default priority
		// would never fire. Register directly in that case.
		if ( did_action( 'after_setup_theme' ) ) {
			$this->register_locations();
			return;
		}

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
