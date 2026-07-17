<?php
/**
 * robots.txt / AI-crawler policy module.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Seo;

use WPHeadless\Config\Config;
use WPHeadless\Contracts\Module;

/**
 * Decorates core's virtual robots.txt with the site's AI-crawler policy
 * and an llms.txt pointer. Does not stand down for SEO plugins — they
 * manage search-engine SEO, not AI-crawler access policy.
 */
class RobotsTxt implements Module {
	/** @var Config */
	private Config $config;

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	public function register(): void {
		if ( ! $this->config->get( 'seo.robots_txt.enabled', true ) ) {
			return;
		}

		// Implemented in the SEO/AEO module phase.
	}
}
