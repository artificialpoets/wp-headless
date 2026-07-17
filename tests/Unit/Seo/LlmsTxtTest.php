<?php
/**
 * Tests for LlmsTxt::render_output() — the pure markdown assembly behind
 * /llms.txt and /llms-full.txt. Data collection is WordPress-query glue
 * covered by live verification; here we lock the document format down.
 *
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\Seo;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use WPHeadless\Config\Config;
use WPHeadless\Seo\LlmsTxt;

class LlmsTxtTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make(): LlmsTxt {
		$config = $this->getMockBuilder( Config::class )->disableOriginalConstructor()->getMock();

		return new LlmsTxt( $config );
	}

	/**
	 * @param array<string, mixed> $overrides
	 * @return array<string, mixed>
	 */
	private function data( array $overrides = array() ): array {
		return array_merge(
			array(
				'title'       => 'Acme',
				'summary'     => 'Widgets, honestly reviewed.',
				'description' => 'Independent widget journalism since 2019.',
				'sections'    => array(),
				'optional'    => array(),
			),
			$overrides
		);
	}

	// --- Header block ---

	public function test_renders_h1_blockquote_summary_and_description_block(): void {
		$out = $this->make()->render_output( $this->data() );

		$this->assertSame(
			"# Acme\n\n> Widgets, honestly reviewed.\n\nIndependent widget journalism since 2019.\n",
			$out
		);
	}

	public function test_empty_description_is_omitted_without_double_blank_lines(): void {
		$out = $this->make()->render_output( $this->data( array( 'description' => '' ) ) );

		$this->assertSame( "# Acme\n\n> Widgets, honestly reviewed.\n", $out );
		$this->assertStringNotContainsString( "\n\n\n", $out );
	}

	public function test_empty_summary_is_omitted(): void {
		$out = $this->make()->render_output( $this->data( array( 'summary' => '' ) ) );

		$this->assertSame( "# Acme\n\nIndependent widget journalism since 2019.\n", $out );
	}

	public function test_fully_empty_data_renders_empty_string(): void {
		$this->assertSame( '', $this->make()->render_output( array() ) );
	}

	// --- Index sections ---

	public function test_renders_section_heading_and_exact_item_lines(): void {
		$out = $this->make()->render_output(
			$this->data(
				array(
					'description' => '',
					'sections'    => array(
						'Posts' => array(
							array(
								'title'       => 'Hello',
								'url'         => 'https://x.test/hello/',
								'description' => 'First post.',
							),
							array(
								'title'       => 'Two',
								'url'         => 'https://x.test/two/',
								'description' => '',
							),
						),
					),
				)
			)
		);

		$this->assertSame(
			"# Acme\n\n> Widgets, honestly reviewed.\n\n## Posts\n\n- [Hello](https://x.test/hello/): First post.\n- [Two](https://x.test/two/)\n",
			$out
		);
	}

	public function test_empty_sections_are_skipped_cleanly(): void {
		$out = $this->make()->render_output(
			$this->data(
				array(
					'sections' => array(
						'Posts' => array(),
						'Pages' => array(
							array(
								'title'       => 'About',
								'url'         => 'https://x.test/about/',
								'description' => '',
							),
						),
					),
				)
			)
		);

		$this->assertStringNotContainsString( '## Posts', $out );
		$this->assertStringContainsString( "## Pages\n\n- [About](https://x.test/about/)\n", $out );
		$this->assertStringNotContainsString( "\n\n\n", $out );
	}

	// --- Full variant ---

	public function test_full_renders_h3_heading_url_and_content_blocks(): void {
		$out = $this->make()->render_output(
			$this->data(
				array(
					'description' => '',
					'sections'    => array(
						'Posts' => array(
							array(
								'title'       => 'Hello',
								'url'         => 'https://x.test/hello/',
								'description' => 'First post.',
								'content'     => 'Entire first post body, uncut.',
							),
						),
					),
				)
			),
			true
		);

		$this->assertSame(
			"# Acme\n\n> Widgets, honestly reviewed.\n\n## Posts\n\n### Hello\n\nhttps://x.test/hello/\n\nEntire first post body, uncut.\n",
			$out
		);
	}

	public function test_full_item_without_content_omits_the_content_block(): void {
		$out = $this->make()->render_output(
			$this->data(
				array(
					'summary'     => '',
					'description' => '',
					'sections'    => array(
						'Posts' => array(
							array(
								'title' => 'Hello',
								'url'   => 'https://x.test/hello/',
							),
						),
					),
				)
			),
			true
		);

		$this->assertSame( "# Acme\n\n## Posts\n\n### Hello\n\nhttps://x.test/hello/\n", $out );
	}

	// --- Optional footer ---

	public function test_optional_footer_lists_sitemap_and_rest_root(): void {
		$out = $this->make()->render_output(
			$this->data(
				array(
					'optional' => array(
						array(
							'title' => 'Sitemap',
							'url'   => 'https://x.test/wp-sitemap.xml',
						),
						array(
							'title' => 'REST API',
							'url'   => 'https://x.test/wp-json/',
						),
					),
				)
			)
		);

		$this->assertStringContainsString(
			"## Optional\n\n- [Sitemap](https://x.test/wp-sitemap.xml)\n- [REST API](https://x.test/wp-json/)\n",
			$out
		);
	}

	public function test_optional_footer_is_omitted_when_empty(): void {
		$out = $this->make()->render_output( $this->data() );

		$this->assertStringNotContainsString( '## Optional', $out );
	}
}
