<?php
/**
 * Plugin Name: WP Headless
 * Plugin URI: https://github.com/artificialpoets/wp-headless
 * Update URI: https://github.com/artificialpoets/wp-headless
 * Description: Turn any properly-built WordPress theme into a React headless frontend. The plugin engages headless mode when the active theme ships dist/index.html, serving the SPA with a full runtime payload, asset proxy, and REST primitives for every URL kind WordPress can route to.
 * Version: 0.1.1
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author: Artificial Poets
 * Author URI: https://artificialpoets.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-headless
 * Domain Path: /languages
 *
 * @package WPHeadless
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
	return;
}

define( 'WP_HEADLESS_VERSION', '0.1.1' );
define( 'WP_HEADLESS_FILE', __FILE__ );
define( 'WP_HEADLESS_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_HEADLESS_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'WPHeadless\\';

		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class, strlen( $prefix ) );
		$relative_path  = str_replace( '\\', '/', $relative_class ) . '.php';
		$file           = WP_HEADLESS_DIR . 'src/' . $relative_path;

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

/**
 * Get the shared plugin instance.
 *
 * @return \WPHeadless\Plugin
 */
function wp_headless_plugin() {
	static $plugin = null;

	if ( null === $plugin ) {
		$plugin = new \WPHeadless\Plugin();
	}

	return $plugin;
}

wp_headless_plugin()->register();

register_activation_hook(
	WP_HEADLESS_FILE,
	static function () {
		wp_headless_plugin()->activate();
	}
);

register_deactivation_hook(
	WP_HEADLESS_FILE,
	static function () {
		wp_headless_plugin()->deactivate();
	}
);

/**
 * Self-hosted update channel.
 *
 * Pre-wp.org release: updates are published as GitHub Releases on
 * artificialpoets/wp-headless. The Plugin Update Checker library hooks
 * WordPress's update transients, so the plugin appears in
 * Dashboard → Updates exactly like a wp.org plugin would.
 *
 * Once this plugin ships on wp.org (v1.0.0+), drop this block and let
 * core's wp.org-backed updater take over — both channels would fight
 * over the same slug otherwise.
 */
$wph_puc = WP_HEADLESS_DIR . 'vendor/yahnis-elsts/plugin-update-checker/plugin-update-checker.php';
if ( file_exists( $wph_puc ) ) {
	require_once $wph_puc;

	$wph_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/artificialpoets/wp-headless/',
		WP_HEADLESS_FILE,
		'wp-headless'
	);
	$wph_update_checker->setBranch( 'main' );

	// Use the zip uploaded to each GitHub Release (built clean by CI) rather
	// than the auto-generated source tarball, which would include dev files.
	$wph_update_checker->getVcsApi()->enableReleaseAssets();
}

/**
 * Auto-update opt-in.
 *
 * Tells WordPress's twice-daily update cron to install new wp-headless
 * releases without admin interaction. Site owners can override per-site
 * by returning false from the same filter at a higher priority.
 */
add_filter(
	'auto_update_plugin',
	static function ( $update, $item ) {
		if ( isset( $item->slug ) && 'wp-headless' === $item->slug ) {
			return true;
		}
		return $update;
	},
	10,
	2
);
