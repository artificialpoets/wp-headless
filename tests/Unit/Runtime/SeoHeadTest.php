<?php
/**
 * Tests for SeoHead's pure rendering / detection logic.
 *
 * build_meta() is WordPress-query glue covered by the integration suite + live
 * verification; here we lock down escaping, the SEO-plugin stand-down, and the
 * description clipper.
 *
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\Runtime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPHeadless\Config\Config;
use WPHeadless\Runtime\SeoHead;
use WPHeadless\Theme\ThemeManager;

class ExposedSeoHead extends SeoHead {
	public function exposeRenderMeta( array $meta ): string {
		return $this->render_meta( $meta );
	}
	public function exposeSeoPluginActive(): bool {
		return $this->seo_plugin_active();
	}
	public function exposeClip( string $text, int $limit = 160 ): string {
		return $this->clip( $text, $limit );
	}
}

class SeoHeadTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'esc_attr' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
		Functions\when( 'esc_url' )->alias( static fn( $s ) => (string) $s );
		Functions\when( 'wp_json_encode' )->alias( static fn( $d, $f = 0 ) => json_encode( $d, $f ) );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => trim( strip_tags( (string) $s ) ) );
		Functions\when( 'is_singular' )->justReturn( false ); // render_meta canonical branch
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make(): ExposedSeoHead {
		$config = $this->getMockBuilder( Config::class )->disableOriginalConstructor()->getMock();
		$tm     = $this->getMockBuilder( ThemeManager::class )->disableOriginalConstructor()->getMock();
		return new ExposedSeoHead( $config, $tm );
	}

	// --- render_meta ---

	public function test_renders_description_meta(): void {
		$out = $this->make()->exposeRenderMeta( array( 'description' => 'A short summary.' ) );
		$this->assertStringContainsString( '<meta name="description" content="A short summary." />', $out );
	}

	public function test_escapes_quotes_in_description(): void {
		$out = $this->make()->exposeRenderMeta( array( 'description' => 'He said "hi" & left' ) );
		$this->assertStringNotContainsString( 'content="He said "hi"', $out );
		$this->assertStringContainsString( '&quot;hi&quot;', $out );
	}

	public function test_renders_og_and_twitter(): void {
		$out = $this->make()->exposeRenderMeta( array(
			'og'      => array( 'type' => 'article', 'title' => 'Post', 'url' => 'https://x.test/p/', 'image' => 'https://x.test/i.jpg' ),
			'twitter' => array( 'card' => 'summary_large_image', 'title' => 'Post' ),
		) );
		$this->assertStringContainsString( '<meta property="og:type" content="article" />', $out );
		$this->assertStringContainsString( '<meta property="og:url" content="https://x.test/p/" />', $out );
		$this->assertStringContainsString( '<meta property="og:image" content="https://x.test/i.jpg" />', $out );
		$this->assertStringContainsString( '<meta name="twitter:card" content="summary_large_image" />', $out );
	}

	public function test_skips_empty_values(): void {
		$out = $this->make()->exposeRenderMeta( array( 'og' => array( 'title' => 'Only', 'image' => '' ) ) );
		$this->assertStringContainsString( 'og:title', $out );
		$this->assertStringNotContainsString( 'og:image', $out );
	}

	public function test_emits_canonical_for_non_singular(): void {
		$out = $this->make()->exposeRenderMeta( array( 'canonical' => 'https://x.test/cat/' ) );
		$this->assertStringContainsString( '<link rel="canonical" href="https://x.test/cat/" />', $out );
	}

	public function test_jsonld_escapes_script_breakout(): void {
		$out = $this->make()->exposeRenderMeta( array(
			'jsonld' => array( '@type' => 'Article', 'headline' => 'x</script><script>alert(1)' ),
		) );
		$this->assertStringContainsString( '<script type="application/ld+json">', $out );
		$this->assertStringNotContainsString( '</script><script>alert(1)', $out );
		$this->assertStringContainsString( '<\\/script>', $out );
	}

	// --- seo_plugin_active ---

	public function test_no_seo_plugin_by_default(): void {
		$this->assertFalse( $this->make()->exposeSeoPluginActive() );
	}

	public function test_filter_can_force_seo_plugin_active(): void {
		Functions\when( 'apply_filters' )->justReturn( true );
		$this->assertTrue( $this->make()->exposeSeoPluginActive() );
	}

	// --- clip ---

	public function test_clip_leaves_short_text(): void {
		$this->assertSame( 'Hello world', $this->make()->exposeClip( 'Hello world' ) );
	}

	public function test_clip_collapses_whitespace(): void {
		$this->assertSame( 'a b c', $this->make()->exposeClip( "a   b\n\tc" ) );
	}

	public function test_clip_truncates_on_word_boundary_with_ellipsis(): void {
		$text = str_repeat( 'word ', 60 ); // 300 chars
		$out  = $this->make()->exposeClip( $text, 40 );
		$this->assertLessThanOrEqual( 40, mb_strlen( $out ) );
		$this->assertStringEndsWith( '…', $out );
		$this->assertStringNotContainsString( 'wor…', $out ); // clipped at a space, not mid-word
	}
}
