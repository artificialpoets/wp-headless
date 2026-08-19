<?php
/**
 * Tests for RobotsTxt::append_policy() — the AI-crawler policy and llms.txt
 * pointer appended to core's virtual robots.txt.
 *
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\Seo;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPHeadless\Config\Config;
use WPHeadless\Seo\RobotsTxt;

class RobotsTxtTest extends TestCase {

	/** Core's public-site robots.txt body, as the filter receives it. */
	private const CORE_OUTPUT = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.test' . $path
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, mixed> $overrides Dot-key config overrides.
	 */
	private function makeRobots( array $overrides = array() ): RobotsTxt {
		$values = array_merge(
			array(
				'seo.robots_txt.ai_policy'    => 'allow',
				'seo.robots_txt.block_agents' => array(),
				'seo.robots_txt.extra'        => '',
				'seo.llms.enabled'            => true,
			),
			$overrides
		);

		$config = $this->getMockBuilder( Config::class )->disableOriginalConstructor()->getMock();
		$config->method( 'get' )->willReturnCallback(
			static fn( $key, $default = null ) => $values[ $key ] ?? $default
		);

		return new RobotsTxt( $config );
	}

	// --- allow policy ---

	public function test_allow_policy_appends_only_the_llms_pointer(): void {
		$out = $this->makeRobots()->append_policy( self::CORE_OUTPUT, true );

		$this->assertStringStartsWith( self::CORE_OUTPUT, $out );
		$this->assertStringContainsString( '# llms.txt: https://example.test/llms.txt', $out );
		$this->assertSame( 0, substr_count( $out, "\nDisallow: /\n" ), 'Allow policy must not emit blanket Disallow stanzas.' );
	}

	public function test_extra_lines_are_appended_verbatim(): void {
		$out = $this->makeRobots(
			array( 'seo.robots_txt.extra' => "Crawl-delay: 10\nUser-agent: FriendBot" )
		)->append_policy( self::CORE_OUTPUT, true );

		$this->assertStringContainsString( "Crawl-delay: 10\nUser-agent: FriendBot\n", $out );
	}

	public function test_nothing_to_append_returns_output_untouched(): void {
		$out = $this->makeRobots( array( 'seo.llms.enabled' => false ) )->append_policy( self::CORE_OUTPUT, true );

		$this->assertSame( self::CORE_OUTPUT, $out );
	}

	public function test_llms_pointer_is_skipped_when_llms_module_disabled(): void {
		$out = $this->makeRobots(
			array(
				'seo.llms.enabled'       => false,
				'seo.robots_txt.extra'   => 'Crawl-delay: 5',
			)
		)->append_policy( self::CORE_OUTPUT, true );

		$this->assertStringNotContainsString( 'llms.txt', $out );
		$this->assertStringContainsString( 'Crawl-delay: 5', $out );
	}

	// --- block policy ---

	public function test_block_policy_emits_a_stanza_per_default_agent(): void {
		$out = $this->makeRobots(
			array( 'seo.robots_txt.ai_policy' => 'block' )
		)->append_policy( self::CORE_OUTPUT, true );

		$this->assertStringContainsString( "User-agent: GPTBot\nDisallow: /\n", $out );
		$this->assertStringContainsString( "User-agent: ClaudeBot\nDisallow: /\n", $out );
		$this->assertSame(
			12,
			substr_count( $out, "\nDisallow: /\n" ),
			'One blanket Disallow per built-in AI crawler.'
		);
	}

	public function test_block_policy_still_appends_the_llms_pointer(): void {
		$out = $this->makeRobots(
			array( 'seo.robots_txt.ai_policy' => 'block' )
		)->append_policy( self::CORE_OUTPUT, true );

		$this->assertStringContainsString( '# llms.txt: https://example.test/llms.txt', $out );
	}

	public function test_block_agents_override_replaces_the_default_list(): void {
		$out = $this->makeRobots(
			array(
				'seo.robots_txt.ai_policy'    => 'block',
				'seo.robots_txt.block_agents' => array( 'FooBot', 'BarBot' ),
			)
		)->append_policy( self::CORE_OUTPUT, true );

		$this->assertStringContainsString( "User-agent: FooBot\nDisallow: /\n", $out );
		$this->assertStringContainsString( "User-agent: BarBot\nDisallow: /\n", $out );
		$this->assertStringNotContainsString( 'GPTBot', $out );
		$this->assertSame( 2, substr_count( $out, "\nDisallow: /\n" ) );
	}

	public function test_ai_crawlers_filter_can_replace_the_default_list(): void {
		Filters\expectApplied( 'wp_headless_ai_crawlers' )
			->once()
			->andReturn( array( 'CustomBot' ) );

		$out = $this->makeRobots(
			array( 'seo.robots_txt.ai_policy' => 'block' )
		)->append_policy( self::CORE_OUTPUT, true );

		$this->assertStringContainsString( "User-agent: CustomBot\nDisallow: /\n", $out );
		$this->assertStringNotContainsString( 'GPTBot', $out );
		$this->assertSame( 1, substr_count( $out, "\nDisallow: /\n" ) );
	}

	// --- incoming output is sacred ---

	public function test_incoming_output_including_sitemap_line_is_preserved_as_prefix(): void {
		$incoming = self::CORE_OUTPUT . "\nSitemap: https://example.test/wp-sitemap.xml\n";

		$out = $this->makeRobots(
			array( 'seo.robots_txt.ai_policy' => 'block' )
		)->append_policy( $incoming, true );

		$this->assertStringStartsWith( $incoming, $out );
	}
}
