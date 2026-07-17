<?php
/**
 * Headless head-hygiene module.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Seo;

use WPHeadless\Config\Config;
use WPHeadless\Contracts\Module;
use WPHeadless\Theme\ThemeManager;

/**
 * Removes head output that has no purpose in a headless shell (RSD/xmlrpc
 * discovery, generator, shortlink, emoji bootstrap, …) when the plugin is
 * serving the frontend.
 */
class HeadCleanup implements Module {
	/** @var Config */
	private Config $config;

	/** @var ThemeManager */
	private ThemeManager $theme_manager;

	public function __construct( Config $config, ThemeManager $theme_manager ) {
		$this->config        = $config;
		$this->theme_manager = $theme_manager;
	}

	public function register(): void {
		if ( ! $this->config->get( 'seo.head_cleanup.enabled', true ) ) {
			return;
		}

		// Implemented in the SEO/AEO module phase.
	}
}
