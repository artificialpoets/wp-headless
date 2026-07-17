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
	/**
	 * AI/LLM crawler user agents blocked by the 'block' policy when no
	 * explicit seo.robots_txt.block_agents list is configured. Filterable
	 * via `wp_headless_ai_crawlers`.
	 */
	private const DEFAULT_AI_CRAWLERS = array(
		'GPTBot',
		'ChatGPT-User',
		'CCBot',
		'anthropic-ai',
		'ClaudeBot',
		'Claude-Web',
		'Google-Extended',
		'PerplexityBot',
		'Bytespider',
		'Amazonbot',
		'Applebot-Extended',
		'meta-externalagent',
	);

	/** @var Config */
	private Config $config;

	public function __construct( Config $config ) {
		$this->config = $config;
	}

	public function register(): void {
		if ( ! $this->config->get( 'seo.robots_txt.enabled', true ) ) {
			return;
		}

		// Priority 99: append after core and after SEO plugins have added
		// their Sitemap lines, so our sections always land at the bottom.
		add_filter( 'robots_txt', array( $this, 'append_policy' ), 99, 2 );
	}

	/**
	 * Append the AI-crawler policy to the virtual robots.txt.
	 *
	 * The incoming output (core's lines plus anything earlier filters added,
	 * e.g. a Sitemap line) is preserved untouched — this only ever appends.
	 *
	 * @param string $output Existing robots.txt body.
	 * @param mixed  $public Whether the site is public. Unused: core already
	 *                       renders the private-site policy in $output.
	 * @return string
	 */
	public function append_policy( string $output, $public ): string {
		$sections = array();

		if ( 'block' === (string) $this->config->get( 'seo.robots_txt.ai_policy', 'allow' ) ) {
			foreach ( $this->blocked_agents() as $agent ) {
				$sections[] = 'User-agent: ' . $agent . "\nDisallow: /\n";
			}
		}

		// Policy-independent: point AI agents at the llms.txt index. Even a
		// site that blocks bulk crawlers wants lookups answered from it.
		if ( $this->config->get( 'seo.llms.enabled', true ) ) {
			$sections[] = '# llms.txt: ' . home_url( '/llms.txt' ) . "\n";
		}

		$extra = (string) $this->config->get( 'seo.robots_txt.extra', '' );

		if ( '' !== $extra ) {
			$sections[] = $extra . "\n";
		}

		if ( array() === $sections ) {
			return $output;
		}

		return $output . "\n" . implode( "\n", $sections );
	}

	/**
	 * User agents the 'block' policy disallows: the configured list verbatim,
	 * or the filterable built-in AI crawler list.
	 *
	 * @return array<int, string>
	 */
	protected function blocked_agents(): array {
		$configured = (array) $this->config->get( 'seo.robots_txt.block_agents', array() );

		if ( array() !== $configured ) {
			return array_values( array_map( 'strval', $configured ) );
		}

		/**
		 * Filters the built-in AI crawler block list.
		 *
		 * Only consulted when seo.robots_txt.block_agents is empty — an
		 * explicit config list is taken verbatim.
		 *
		 * @param array<int, string> $agents Default AI crawler user agents.
		 */
		$agents = apply_filters( 'wp_headless_ai_crawlers', self::DEFAULT_AI_CRAWLERS );

		return array_values( array_map( 'strval', (array) $agents ) );
	}
}
