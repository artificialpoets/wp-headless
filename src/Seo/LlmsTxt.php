<?php
/**
 * llms.txt endpoint module.
 *
 * @package WPHeadless
 */

namespace WPHeadless\Seo;

use WPHeadless\Config\Config;
use WPHeadless\Contracts\Module;

/**
 * Serves /llms.txt (and optionally /llms-full.txt) — the site map for
 * LLM/AI agents (llmstxt.org). Essential for headless sites: the served
 * HTML body is an empty SPA shell, so this endpoint is a primary
 * machine-readable content channel.
 */
class LlmsTxt implements Module {
	public const QUERY_VAR      = 'wp_headless_llms';
	public const FULL_QUERY_VAR = 'wp_headless_llms_full';

	/** @var Config */
	private Config $config;

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	public function register(): void {
		if ( ! $this->config->get( 'seo.llms.enabled', true ) ) {
			return;
		}

		// Implemented in the SEO/AEO module phase.
	}
}
