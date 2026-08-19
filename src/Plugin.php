<?php
/**
 * Plugin bootstrap.
 *
 * @package WPHeadless
 */

namespace WPHeadless;

use WPHeadless\Admin\SettingsPage;
use WPHeadless\Api\Comments;
use WPHeadless\Api\ContentFields;
use WPHeadless\Api\MenuEndpoint;
use WPHeadless\Api\ResolveEndpoint;
use WPHeadless\Api\RuntimeEndpoint;
use WPHeadless\Config\Config;
use WPHeadless\Contracts\Module;
use WPHeadless\Export\DataExport;
use WPHeadless\Http\CachePolicy;
use WPHeadless\Http\Cors;
use WPHeadless\Routing\RewriteRules;
use WPHeadless\Runtime\AssetProxy;
use WPHeadless\Runtime\BlockAnnotator;
use WPHeadless\Runtime\FrontendBridge;
use WPHeadless\Runtime\NavMenus;
use WPHeadless\Runtime\Prerender;
use WPHeadless\Runtime\SeoHead;
use WPHeadless\Theme\ThemeManager;

class Plugin {
	/** @var Config */
	private Config $config;

	/** @var ThemeManager */
	private ThemeManager $theme_manager;

	/**
	 * Keyed module map. The key is the module's public identity: it is the
	 * config toggle (`modules.{key}.enabled`), the handle add-ons use to
	 * replace or remove a built-in via the `wp_headless_modules` filter,
	 * and the row identity on the settings status page.
	 *
	 * @var array<string, Module>
	 */
	private array $modules = array();

	/** @var bool Whether register() has run (guards double boot). */
	private bool $booted = false;

	/** @var array<string, bool> Keys of modules whose register() ran. */
	private array $registered = array();

	/**
	 * @param Config|null                $config        Optional config (tests/embedders).
	 * @param ThemeManager|null          $theme_manager Optional theme manager.
	 * @param array<string, Module>|null $modules       Optional module map override;
	 *                                                  null builds the default map.
	 */
	public function __construct( ?Config $config = null, ?ThemeManager $theme_manager = null, ?array $modules = null ) {
		$this->config        = $config ?? new Config();
		$this->theme_manager = $theme_manager ?? new ThemeManager();

		if ( null !== $modules ) {
			$this->modules = $modules;
			return;
		}

		$this->modules = array(
			'rewrite_rules'    => new RewriteRules( $this->config ),
			'nav_menus'        => new NavMenus(),
			'block_annotator'  => new BlockAnnotator(),
			'asset_proxy'      => new AssetProxy( $this->config, $this->theme_manager ),
			'frontend_bridge'  => new FrontendBridge( $this->config, $this->theme_manager ),
			'cache'            => new CachePolicy( $this->config ),
			'prerender'        => new Prerender( $this->config ),
			'data_export'      => new DataExport( $this->config ),
			'seo_head'         => new SeoHead( $this->config, $this->theme_manager ),
			'content_fields'   => new ContentFields( $this->config ),
			'comments'         => new Comments(),
			'menu_endpoint'    => new MenuEndpoint( $this->config ),
			'runtime_endpoint' => new RuntimeEndpoint( $this->config, $this->theme_manager ),
			'resolve_endpoint' => new ResolveEndpoint( $this->config ),
			'cors'             => new Cors( $this->config ),
			'head_cleanup'     => new Seo\HeadCleanup( $this->config, $this->theme_manager ),
			'llms_txt'         => new Seo\LlmsTxt( $this->config ),
			'robots_txt'       => new Seo\RobotsTxt( $this->config ),
			'settings_page'    => new SettingsPage( $this->theme_manager, $this->config ),
		);
	}

	public function register(): void {
		if ( $this->booted ) {
			return;
		}

		/**
		 * Filters the module map before boot.
		 *
		 * Add-on plugins register their own Module implementations here (or
		 * replace/remove built-ins by key). Runs once, on plugins_loaded.
		 *
		 * @param array<string, Module> $modules       Keyed module map.
		 * @param Config                $config        Plugin config.
		 * @param ThemeManager          $theme_manager Theme manager.
		 */
		$modules = apply_filters( 'wp_headless_modules', $this->modules, $this->config, $this->theme_manager );

		foreach ( $modules as $key => $module ) {
			if ( ! $module instanceof Module ) {
				continue;
			}

			$this->modules[ (string) $key ] = $module;

			if ( ! $this->module_enabled( (string) $key ) ) {
				continue;
			}

			$module->register();
			$this->registered[ (string) $key ] = true;
		}

		$this->booted = true;

		/**
		 * Fires once the plugin has booted all enabled modules.
		 *
		 * @param Plugin $plugin The booted plugin instance.
		 */
		do_action( 'wp_headless_booted', $this );
	}

	/**
	 * Register an additional module after construction.
	 *
	 * Intended for code that runs too late for the `wp_headless_modules`
	 * filter (themes load after plugins_loaded). When the plugin has already
	 * booted and the module is config-enabled, register() is called
	 * immediately.
	 */
	public function add_module( string $key, Module $module ): void {
		$this->modules[ $key ] = $module;

		if ( $this->booted && $this->module_enabled( $key ) ) {
			$module->register();
			$this->registered[ $key ] = true;
		}
	}

	/**
	 * Status metadata for every known module (settings page, debugging).
	 *
	 * @return array<string, array{class: string, enabled: bool, registered: bool}>
	 */
	public function modules(): array {
		$status = array();

		foreach ( $this->modules as $key => $module ) {
			$status[ $key ] = array(
				'class'      => get_class( $module ),
				'enabled'    => $this->module_enabled( $key ),
				'registered' => ! empty( $this->registered[ $key ] ),
			);
		}

		return $status;
	}

	/** The shared config instance (add-ons and the settings page read it). */
	public function config(): Config {
		return $this->config;
	}

	/** Whether a module key is enabled (default true; config can disable). */
	private function module_enabled( string $key ): bool {
		return false !== $this->config->get( 'modules.' . $key . '.enabled', true );
	}

	public function activate(): void {
		RewriteRules::register_rules( $this->config );
		Seo\LlmsTxt::register_rules( $this->config );
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
