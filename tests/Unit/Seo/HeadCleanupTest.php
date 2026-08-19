<?php
/**
 * Tests for HeadCleanup::maybe_clean() — which core head hooks get detached
 * when the headless frontend serves the request, and which must survive.
 *
 * Brain Monkey routes remove_action() through Actions\expectRemoved(); note
 * the priority argument is always part of the call (default 10), so every
 * with() lists it explicitly.
 *
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\Seo;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WPHeadless\Config\Config;
use WPHeadless\Runtime\RequestMatcher;
use WPHeadless\Seo\HeadCleanup;
use WPHeadless\Theme\ThemeManager;

class HeadCleanupTest extends TestCase {

	// Counts Brain Monkey's hook expectations as PHPUnit assertions so the
	// expectation-only tests below aren't reported as risky.
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param bool                 $should_serve  What the mocked matcher reports.
	 * @param array<string, mixed> $config_values Dot-key config overrides.
	 */
	private function makeCleanup( bool $should_serve = true, array $config_values = array() ): HeadCleanup {
		$values = array_merge(
			array(
				'seo.head_cleanup.enabled'          => true,
				'seo.head_cleanup.rsd'              => true,
				'seo.head_cleanup.wlw'              => true,
				'seo.head_cleanup.generator'        => true,
				'seo.head_cleanup.shortlink'        => true,
				'seo.head_cleanup.adjacent_posts'   => true,
				'seo.head_cleanup.emoji'            => true,
				'seo.head_cleanup.oembed_host_js'   => true,
				'seo.head_cleanup.oembed_discovery' => false,
				'seo.head_cleanup.feed_links_extra' => false,
				'seo.head_cleanup.rest_link'        => false,
			),
			$config_values
		);

		$config = $this->getMockBuilder( Config::class )->disableOriginalConstructor()->getMock();
		$config->method( 'get' )->willReturnCallback(
			static fn( $key, $default = null ) => $values[ $key ] ?? $default
		);

		$theme_manager = $this->getMockBuilder( ThemeManager::class )->disableOriginalConstructor()->getMock();

		$matcher = $this->getMockBuilder( RequestMatcher::class )->disableOriginalConstructor()->getMock();
		$matcher->method( 'should_serve_frontend' )->willReturn( $should_serve );

		return new HeadCleanup( $config, $theme_manager, $matcher );
	}

	public function test_default_map_removes_exactly_the_default_head_set(): void {
		Actions\expectRemoved( 'wp_head' )->once()->with( 'rsd_link', 10 );
		Actions\expectRemoved( 'wp_head' )->once()->with( 'wlwmanifest_link', 10 );
		Actions\expectRemoved( 'wp_head' )->once()->with( 'wp_generator', 10 );
		Actions\expectRemoved( 'wp_head' )->once()->with( 'wp_shortlink_wp_head', 10 );
		Actions\expectRemoved( 'template_redirect' )->once()->with( 'wp_shortlink_header', 11 );
		Actions\expectRemoved( 'wp_head' )->once()->with( 'adjacent_posts_rel_link_wp_head', 10 );
		Actions\expectRemoved( 'wp_head' )->once()->with( 'print_emoji_detection_script', 7 );
		Actions\expectRemoved( 'wp_print_styles' )->once()->with( 'print_emoji_styles', 10 );
		Filters\expectAdded( 'emoji_svg_url' )->once()->with( '__return_false' );
		Actions\expectRemoved( 'wp_head' )->once()->with( 'wp_oembed_add_host_js', 10 );

		// Disabled-by-default keys must NOT be detached.
		Actions\expectRemoved( 'wp_head' )->never()->with( 'wp_oembed_add_discovery_links', 10 );
		Actions\expectRemoved( 'wp_head' )->never()->with( 'feed_links_extra', 3 );
		Actions\expectRemoved( 'wp_head' )->never()->with( 'rest_output_link_wp_head', 10 );
		Actions\expectRemoved( 'template_redirect' )->never()->with( 'rest_output_link_header', 11 );

		$this->makeCleanup()->maybe_clean();
	}

	public function test_nothing_is_removed_when_frontend_is_not_serving(): void {
		Actions\expectRemoved( 'wp_head' )->never();
		Actions\expectRemoved( 'template_redirect' )->never();
		Actions\expectRemoved( 'wp_print_styles' )->never();
		Filters\expectAdded( 'emoji_svg_url' )->never();

		$this->makeCleanup( false )->maybe_clean();
	}

	public function test_config_can_disable_a_default_key_and_enable_an_optional_one(): void {
		Actions\expectRemoved( 'wp_head' )->never()->with( 'rsd_link', 10 );
		Actions\expectRemoved( 'wp_head' )->once()->with( 'rest_output_link_wp_head', 10 );
		Actions\expectRemoved( 'template_redirect' )->once()->with( 'rest_output_link_header', 11 );

		// The rest of the default set still runs.
		Actions\expectRemoved( 'wp_head' )->once()->with( 'wlwmanifest_link', 10 );
		Actions\expectRemoved( 'wp_head' )->once()->with( 'wp_generator', 10 );
		Actions\expectRemoved( 'wp_head' )->once()->with( 'wp_shortlink_wp_head', 10 );
		Actions\expectRemoved( 'template_redirect' )->once()->with( 'wp_shortlink_header', 11 );
		Actions\expectRemoved( 'wp_head' )->once()->with( 'adjacent_posts_rel_link_wp_head', 10 );
		Actions\expectRemoved( 'wp_head' )->once()->with( 'print_emoji_detection_script', 7 );
		Actions\expectRemoved( 'wp_print_styles' )->once()->with( 'print_emoji_styles', 10 );
		Actions\expectRemoved( 'wp_head' )->once()->with( 'wp_oembed_add_host_js', 10 );

		$this->makeCleanup(
			true,
			array(
				'seo.head_cleanup.rsd'       => false,
				'seo.head_cleanup.rest_link' => true,
			)
		)->maybe_clean();
	}

	public function test_filter_can_flip_cleanup_keys(): void {
		Filters\expectApplied( 'wp_headless_head_cleanup' )
			->once()
			->andReturnUsing(
				static function ( $map ) {
					$map['emoji']            = false;
					$map['oembed_discovery'] = true;
					return $map;
				}
			);

		Actions\expectRemoved( 'wp_head' )->never()->with( 'print_emoji_detection_script', 7 );
		Actions\expectRemoved( 'wp_print_styles' )->never()->with( 'print_emoji_styles', 10 );
		Filters\expectAdded( 'emoji_svg_url' )->never();
		Actions\expectRemoved( 'wp_head' )->once()->with( 'wp_oembed_add_discovery_links', 10 );

		// The untouched defaults still run.
		Actions\expectRemoved( 'wp_head' )->once()->with( 'rsd_link', 10 );
		Actions\expectRemoved( 'wp_head' )->once()->with( 'wlwmanifest_link', 10 );
		Actions\expectRemoved( 'wp_head' )->once()->with( 'wp_generator', 10 );
		Actions\expectRemoved( 'wp_head' )->once()->with( 'wp_shortlink_wp_head', 10 );
		Actions\expectRemoved( 'template_redirect' )->once()->with( 'wp_shortlink_header', 11 );
		Actions\expectRemoved( 'wp_head' )->once()->with( 'adjacent_posts_rel_link_wp_head', 10 );
		Actions\expectRemoved( 'wp_head' )->once()->with( 'wp_oembed_add_host_js', 10 );

		$this->makeCleanup()->maybe_clean();
	}
}
