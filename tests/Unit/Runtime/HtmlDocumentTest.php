<?php
/**
 * Tests for HtmlDocument pure string methods.
 *
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\Runtime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPHeadless\Config\Config;
use WPHeadless\Runtime\HtmlDocument;
use WPHeadless\Runtime\RuntimeDataBuilder;
use WPHeadless\Theme\ThemeManager;

/**
 * Expose protected HtmlDocument methods for testing.
 */
class ExposedHtmlDocument extends HtmlDocument {
    public function exposeRewriteAssetUrls( string $html ): string {
        return $this->rewrite_asset_urls( $html );
    }

    public function exposeRewritePublicUrl( string $url ): string {
        return $this->rewrite_public_url( $url );
    }

    public function exposeShouldRewriteUrl( string $url ): bool {
        return $this->should_rewrite_url( $url );
    }

    public function exposeInjectBeforeClosingTag( string $html, string $tag, string $content ): string {
        return $this->inject_before_closing_tag( $html, $tag, $content );
    }
}

class HtmlDocumentTest extends TestCase {

    /** @var Config&\PHPUnit\Framework\MockObject\MockObject */
    private $config;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'apply_filters' )->returnArg( 2 );
        Functions\when( 'untrailingslashit' )->alias( fn( string $s ) => rtrim( $s, '/\\' ) );

        $this->config = $this->getMockBuilder( Config::class )
            ->disableOriginalConstructor()
            ->getMock();
        $this->config->method( 'asset_base_url' )->willReturn( 'https://example.com/_wp-headless' );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make(): ExposedHtmlDocument {
        $runtime = $this->getMockBuilder( RuntimeDataBuilder::class )
            ->disableOriginalConstructor()
            ->getMock();
        $theme_manager = $this->getMockBuilder( ThemeManager::class )
            ->disableOriginalConstructor()
            ->getMock();
        return new ExposedHtmlDocument( $this->config, $theme_manager, $runtime );
    }

    // -------------------------------------------------------------------------
    // rewrite_asset_urls()
    // -------------------------------------------------------------------------

    public function test_script_src_is_rewritten(): void {
        $html   = '<script src="/assets/app.js"></script>';
        $result = $this->make()->exposeRewriteAssetUrls( $html );
        $this->assertStringContainsString( 'https://example.com/_wp-headless/assets/app.js', $result );
    }

    public function test_link_href_is_rewritten(): void {
        $html   = '<link rel="stylesheet" href="/assets/style.css">';
        $result = $this->make()->exposeRewriteAssetUrls( $html );
        $this->assertStringContainsString( 'https://example.com/_wp-headless/assets/style.css', $result );
    }

    public function test_img_src_is_rewritten(): void {
        $html   = '<img src="/assets/logo.png" alt="Logo">';
        $result = $this->make()->exposeRewriteAssetUrls( $html );
        $this->assertStringContainsString( 'https://example.com/_wp-headless/assets/logo.png', $result );
    }

    public function test_source_src_is_rewritten(): void {
        $html   = '<source src="/assets/video.mp4" type="video/mp4">';
        $result = $this->make()->exposeRewriteAssetUrls( $html );
        $this->assertStringContainsString( 'https://example.com/_wp-headless/assets/video.mp4', $result );
    }

    public function test_external_url_in_script_is_not_rewritten(): void {
        $html   = '<script src="https://cdn.external.com/lib.js"></script>';
        $result = $this->make()->exposeRewriteAssetUrls( $html );
        $this->assertSame( $html, $result );
    }

    public function test_data_uri_in_img_is_not_rewritten(): void {
        $html   = '<img src="data:image/png;base64,abc123">';
        $result = $this->make()->exposeRewriteAssetUrls( $html );
        $this->assertSame( $html, $result );
    }

    // -------------------------------------------------------------------------
    // rewrite_public_url()
    // -------------------------------------------------------------------------

    public function test_relative_asset_path_is_rewritten(): void {
        $url    = 'assets/app-abc123.js';
        $result = $this->make()->exposeRewritePublicUrl( $url );
        $this->assertSame( 'https://example.com/_wp-headless/assets/app-abc123.js', $result );
    }

    public function test_leading_slash_is_stripped_before_rewriting(): void {
        $result = $this->make()->exposeRewritePublicUrl( '/assets/style.css' );
        $this->assertSame( 'https://example.com/_wp-headless/assets/style.css', $result );
    }

    public function test_dot_slash_prefix_is_stripped(): void {
        $result = $this->make()->exposeRewritePublicUrl( './assets/app.js' );
        $this->assertSame( 'https://example.com/_wp-headless/assets/app.js', $result );
    }

    public function test_query_string_is_preserved(): void {
        $result = $this->make()->exposeRewritePublicUrl( 'assets/app.js?v=1' );
        $this->assertStringEndsWith( '?v=1', $result );
    }

    public function test_fragment_is_preserved(): void {
        $result = $this->make()->exposeRewritePublicUrl( 'assets/app.js#section' );
        $this->assertStringEndsWith( '#section', $result );
    }

    public function test_protocol_relative_url_is_unchanged(): void {
        $url    = '//cdn.example.com/lib.js';
        $result = $this->make()->exposeRewritePublicUrl( $url );
        $this->assertSame( $url, $result );
    }

    public function test_mailto_is_unchanged(): void {
        $url    = 'mailto:hello@example.com';
        $result = $this->make()->exposeRewritePublicUrl( $url );
        $this->assertSame( $url, $result );
    }

    public function test_hash_fragment_alone_is_unchanged(): void {
        $url    = '#section';
        $result = $this->make()->exposeRewritePublicUrl( $url );
        $this->assertSame( $url, $result );
    }

    public function test_empty_url_is_unchanged(): void {
        $result = $this->make()->exposeRewritePublicUrl( '' );
        $this->assertSame( '', $result );
    }

    public function test_bare_page_path_is_not_rewritten(): void {
        // A bare slug with no recognized extension should not be rewritten.
        $url    = 'about-us';
        $result = $this->make()->exposeRewritePublicUrl( $url );
        $this->assertSame( $url, $result );
    }

    // -------------------------------------------------------------------------
    // should_rewrite_url()
    // -------------------------------------------------------------------------

    public function test_assets_prefix_returns_true(): void {
        $this->assertTrue( $this->make()->exposeShouldRewriteUrl( 'assets/app.js' ) );
    }

    public function test_js_extension_returns_true(): void {
        $this->assertTrue( $this->make()->exposeShouldRewriteUrl( 'app.js' ) );
    }

    public function test_css_extension_returns_true(): void {
        $this->assertTrue( $this->make()->exposeShouldRewriteUrl( 'style.css' ) );
    }

    public function test_png_extension_returns_true(): void {
        $this->assertTrue( $this->make()->exposeShouldRewriteUrl( 'logo.png' ) );
    }

    public function test_woff2_extension_returns_true(): void {
        $this->assertTrue( $this->make()->exposeShouldRewriteUrl( 'font.woff2' ) );
    }

    public function test_webmanifest_extension_returns_true(): void {
        $this->assertTrue( $this->make()->exposeShouldRewriteUrl( 'manifest.webmanifest' ) );
    }

    public function test_svg_extension_returns_true(): void {
        $this->assertTrue( $this->make()->exposeShouldRewriteUrl( 'icon.svg' ) );
    }

    public function test_bare_slug_returns_false(): void {
        $this->assertFalse( $this->make()->exposeShouldRewriteUrl( 'about-us' ) );
    }

    public function test_empty_string_returns_false(): void {
        $this->assertFalse( $this->make()->exposeShouldRewriteUrl( '' ) );
    }

    public function test_wp_admin_path_returns_false(): void {
        $this->assertFalse( $this->make()->exposeShouldRewriteUrl( '/wp-admin/' ) );
    }

    // -------------------------------------------------------------------------
    // inject_before_closing_tag()
    // -------------------------------------------------------------------------

    public function test_content_injected_before_closing_head(): void {
        $html   = '<html><head><title>Test</title></head><body></body></html>';
        $result = $this->make()->exposeInjectBeforeClosingTag( $html, '</head>', '<meta name="test">' );
        $this->assertSame(
            '<html><head><title>Test</title><meta name="test">' . "\n" . '</head><body></body></html>',
            $result
        );
    }

    public function test_content_injected_before_closing_body(): void {
        $html   = '<html><body><p>Hello</p></body></html>';
        $result = $this->make()->exposeInjectBeforeClosingTag( $html, '</body>', '<script>console.log(1)</script>' );
        $this->assertStringContainsString( '<script>console.log(1)</script>', $result );
        $this->assertStringContainsString( '</body>', $result );
        // The script should appear before </body>.
        $this->assertLessThan(
            strpos( $result, '</body>' ),
            strpos( $result, '<script>' )
        );
    }

    public function test_injects_before_last_occurrence_of_tag(): void {
        // Two </head> tags — should inject before the last one.
        $html   = '<head></head><head></head>';
        $result = $this->make()->exposeInjectBeforeClosingTag( $html, '</head>', 'INJECT' );
        $this->assertSame( '<head></head><head>INJECT' . "\n" . '</head>', $result );
    }

    public function test_appends_after_newline_when_tag_not_found(): void {
        $html   = '<html><body>No head here</body></html>';
        $result = $this->make()->exposeInjectBeforeClosingTag( $html, '</head>', 'INJECT' );
        $this->assertStringEndsWith( "\n" . 'INJECT', $result );
    }

    public function test_empty_content_returns_html_unchanged(): void {
        $html   = '<html><head></head></html>';
        $result = $this->make()->exposeInjectBeforeClosingTag( $html, '</head>', '' );
        $this->assertSame( $html, $result );
    }
}
