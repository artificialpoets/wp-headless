<?php
/**
 * Plugin uninstall handler.
 *
 * Fires only when a user deletes the plugin through Plugins → Installed Plugins
 * → Delete (not on deactivate). Removes every option, transient, and user-meta
 * row the plugin has ever written, including from legacy versions, so the site
 * is left in the same state it was before the plugin was installed.
 *
 * If you fork this plugin and add new persistent state, register it here.
 *
 * @package WPHeadless
 */

// Bail if this file was loaded directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Options that the plugin currently writes, or wrote in past versions and
 * should be cleaned up if still present.
 */
$wp_headless_options = array(
	// Pre-1.0 — custom active-theme selector that lived in our own settings page.
	// Removed in 1.0 in favor of Appearance → Themes.
	'wp_headless_active_theme',

	// Pre-1.0 — any other namespaced options that may linger.
	'wp_headless_settings',
	'wp_headless_config',
);

foreach ( $wp_headless_options as $option_name ) {
	delete_option( $option_name );
	// Multisite: also clear from network options.
	if ( function_exists( 'delete_site_option' ) ) {
		delete_site_option( $option_name );
	}
}

// Transients we might have used for caches.
$wp_headless_transients = array(
	'wp_headless_runtime_cache',
	'wp_headless_menu_cache',
);

foreach ( $wp_headless_transients as $transient_name ) {
	delete_transient( $transient_name );
	if ( function_exists( 'delete_site_transient' ) ) {
		delete_site_transient( $transient_name );
	}
}

// Multisite: run the cleanup on every site in the network.
if ( is_multisite() ) {
	$sites = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $sites as $blog_id ) {
		switch_to_blog( (int) $blog_id );
		foreach ( $wp_headless_options as $option_name ) {
			delete_option( $option_name );
		}
		foreach ( $wp_headless_transients as $transient_name ) {
			delete_transient( $transient_name );
		}
		restore_current_blog();
	}
}
