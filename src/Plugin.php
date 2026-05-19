<?php
/**
 * Plugin bootstrap.
 *
 * @package WPHeadless
 */

namespace WPHeadless;

use WPHeadless\Admin\SettingsPage;
use WPHeadless\Api\ContentFields;
use WPHeadless\Api\MenuEndpoint;
use WPHeadless\Api\ResolveEndpoint;
use WPHeadless\Api\RuntimeEndpoint;
use WPHeadless\Config\Config;
use WPHeadless\Contracts\Module;
use WPHeadless\Http\Cors;
use WPHeadless\Routing\RewriteRules;
use WPHeadless\Runtime\AssetProxy;
use WPHeadless\Runtime\BlockAnnotator;
use WPHeadless\Runtime\FrontendBridge;
use WPHeadless\Runtime\NavMenus;
use WPHeadless\Theme\ThemeManager;

class Plugin {
	/** @var Config */
	private Config $config;

	/** @var ThemeManager */
	private ThemeManager $theme_manager;

	/** @var array<int, Module> */
	private array $modules = array();

	public function __construct() {
		$this->config        = new Config();
		$this->theme_manager = new ThemeManager();

		$this->modules = array(
			new RewriteRules( $this->config ),
			new NavMenus(),
			new BlockAnnotator(),
			new AssetProxy( $this->config, $this->theme_manager ),
			new FrontendBridge( $this->config, $this->theme_manager ),
			new ContentFields( $this->config ),
			new MenuEndpoint( $this->config ),
			new RuntimeEndpoint( $this->config, $this->theme_manager ),
			new ResolveEndpoint( $this->config ),
			new Cors( $this->config ),
			new SettingsPage( $this->theme_manager, $this->config ),
		);
	}

	public function register(): void {
		foreach ( $this->modules as $module ) {
			$module->register();
		}
	}

	public function activate(): void {
		RewriteRules::register_rules( $this->config );
		flush_rewrite_rules();
		$this->cleanup_legacy_options();
	}

	public function deactivate(): void {
		flush_rewrite_rules();
	}

	/**
	 * One-time cleanup of options written by pre-1.0 versions of the plugin.
	 *
	 * Pre-1.0 stored the active headless theme in the `wp_headless_active_theme`
	 * option and activated through a custom settings page. In 1.0 activation moved
	 * to Appearance → Themes — this option is no longer read and is dropped here
	 * to keep the options table clean on existing sites that upgrade in place.
	 *
	 * Safe to call repeatedly. Sites that never installed pre-1.0 just no-op.
	 */
	private function cleanup_legacy_options(): void {
		$legacy_options = array(
			'wp_headless_active_theme',
			'wp_headless_settings',
		);

		foreach ( $legacy_options as $option ) {
			delete_option( $option );
			if ( is_multisite() && function_exists( 'delete_site_option' ) ) {
				delete_site_option( $option );
			}
		}
	}
}
