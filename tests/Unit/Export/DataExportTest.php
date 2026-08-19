<?php
/**
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\Export;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WPHeadless\Config\Config;
use WPHeadless\Export\DataExport;
use WPHeadless\Runtime\RequestDataBuilder;

class ExposedDataExport extends DataExport {
	/** @var array<int|string,mixed>|null Canned rest_content() result. */
	public $canned_content = array( array( 'id' => 7 ) );

	public function exposeExportableType( string $type ): bool {
		return $this->exportable_type( $type );
	}

	public function exposeEnvelope( string $path, string $permalink, array $content ): array {
		return $this->envelope( $path, $permalink, $content );
	}

	/**
	 * The internal REST dispatch needs core classes a unit environment
	 * does not have — the seam is overridden with canned content; the
	 * real implementation is exercised against a live install.
	 */
	protected function rest_content( string $post_type, string $slug ): ?array {
		return $this->canned_content;
	}
}

final class DataExportTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'untrailingslashit' )->alias(
			static function ( $value ) {
				return rtrim( (string) $value, '/' );
			}
		);
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		// No front page assigned unless a test says otherwise.
		Functions\when( 'get_option' )->justReturn( null );
		Functions\when( 'wp_json_encode' )->alias(
			static function ( $value, $flags = 0 ) {
				return json_encode( $value, $flags );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $values Config overrides keyed by path.
	 */
	private function exporter( array $values = array(), ?RequestDataBuilder $resolver = null ): ExposedDataExport {
		$config = $this->getMockBuilder( Config::class )
			->disableOriginalConstructor()
			->getMock();
		$config->method( 'get' )->willReturnCallback(
			static function ( $key, $default = null ) use ( $values ) {
				return array_key_exists( $key, $values ) ? $values[ $key ] : $default;
			}
		);
		if ( null === $resolver ) {
			$resolver = $this->getMockBuilder( RequestDataBuilder::class )
				->disableOriginalConstructor()
				->getMock();
			$resolver->method( 'for_url' )->willReturn( array( 'kind' => 'post' ) );
		}
		return new ExposedDataExport( $config, $resolver );
	}

	private function restType( bool $show_in_rest = true, string $rest_base = 'pages' ): object {
		return (object) array(
			'show_in_rest' => $show_in_rest,
			'rest_base'    => $rest_base,
		);
	}

	// --- export_path_for (pure) ---

	public function test_front_page_permalink_maps_to_root(): void {
		$this->assertSame( '/', $this->exporter()->export_path_for( 'https://ex.test/' ) );
	}

	public function test_page_permalink_loses_its_trailing_slash(): void {
		$this->assertSame( '/about-us', $this->exporter()->export_path_for( 'https://ex.test/about-us/' ) );
	}

	public function test_nested_permalink_keeps_full_path(): void {
		$this->assertSame( '/use-cases/search', $this->exporter()->export_path_for( 'https://ex.test/use-cases/search/' ) );
	}

	public function test_pathless_permalink_is_not_exportable(): void {
		$this->assertNull( $this->exporter()->export_path_for( 'https://ex.test' ) );
	}

	public function test_garbage_permalink_is_not_exportable(): void {
		$this->assertNull( $this->exporter()->export_path_for( 'not a url' ) );
	}

	// --- exportable_type ---

	public function test_rest_exposed_type_is_exportable(): void {
		Functions\when( 'get_post_type_object' )->justReturn( $this->restType() );
		$this->assertTrue( $this->exporter()->exposeExportableType( 'page' ) );
	}

	public function test_non_rest_type_is_never_exportable(): void {
		Functions\when( 'get_post_type_object' )->justReturn( $this->restType( false ) );
		$this->assertFalse( $this->exporter()->exposeExportableType( 'secret' ) );
	}

	public function test_module_toggle_disables_exports(): void {
		Functions\when( 'get_post_type_object' )->justReturn( $this->restType() );
		$exporter = $this->exporter( array( 'modules.data_export.enabled' => false ) );
		$this->assertFalse( $exporter->exposeExportableType( 'page' ) );
	}

	public function test_post_type_allowlist_prunes_unlisted_types(): void {
		Functions\when( 'get_post_type_object' )->justReturn( $this->restType() );
		$exporter = $this->exporter( array( 'modules.data_export.post_types' => array( 'page' ) ) );
		$this->assertTrue( $exporter->exposeExportableType( 'page' ) );
		$this->assertFalse( $exporter->exposeExportableType( 'post' ) );
	}

	// --- envelope ---

	public function test_envelope_carries_version_path_request_and_content(): void {
		$envelope = $this->exporter()->exposeEnvelope( '/about-us', 'https://ex.test/about-us/', array( array( 'id' => 7 ) ) );
		$this->assertSame( DataExport::FORMAT_VERSION, $envelope['version'] );
		$this->assertSame( '/about-us', $envelope['path'] );
		$this->assertSame( array( 'kind' => 'post' ), $envelope['request'] );
		$this->assertSame( array( array( 'id' => 7 ) ), $envelope['content'] );
		$this->assertArrayHasKey( 'generated', $envelope );
	}

	// --- lifecycle: export on publish ---

	public function test_publish_transition_fires_exported_action_with_path_and_json(): void {
		Functions\when( 'get_post_type_object' )->justReturn( $this->restType() );
		Functions\when( 'get_permalink' )->justReturn( 'https://ex.test/about-us/' );
		Actions\expectDone( 'wp_headless_data_exported' )
			->once()
			->whenHappen(
				function ( $path, $json, $context ) {
					$this->assertSame( '/about-us', $path );
					$decoded = json_decode( $json, true );
					$this->assertSame( array( array( 'id' => 7 ) ), $decoded['content'] );
					$this->assertSame( 42, $context['post_id'] );
				}
			);

		$post = (object) array(
			'ID'          => 42,
			'post_type'   => 'page',
			'post_name'   => 'about-us',
			'post_status' => 'publish',
		);
		$this->exporter()->on_transition( 'publish', 'draft', $post );
	}

	public function test_front_page_post_exports_to_root(): void {
		Functions\when( 'get_post_type_object' )->justReturn( $this->restType() );
		Functions\when( 'get_permalink' )->justReturn( 'https://ex.test/home/' );
		Functions\when( 'home_url' )->justReturn( 'https://ex.test/' );
		Functions\when( 'get_option' )->alias(
			static function ( $key ) {
				$options = array(
					'show_on_front' => 'page',
					'page_on_front' => 42,
				);
				return isset( $options[ $key ] ) ? $options[ $key ] : null;
			}
		);

		Actions\expectDone( 'wp_headless_data_exported' )
			->once()
			->whenHappen(
				function ( $path, $json, $context ) {
					$this->assertSame( '/', $path );
					$this->assertSame( 42, $context['post_id'] );
				}
			);

		$post = (object) array(
			'ID'          => 42,
			'post_type'   => 'page',
			'post_name'   => 'home',
			'post_status' => 'publish',
		);
		$this->exporter()->on_transition( 'publish', 'draft', $post );
	}

	public function test_front_page_unpublish_retracts_root(): void {
		Functions\when( 'get_post_type_object' )->justReturn( $this->restType() );
		Functions\when( 'get_permalink' )->justReturn( 'https://ex.test/home/' );
		Functions\when( 'home_url' )->justReturn( 'https://ex.test/' );
		Functions\when( 'get_option' )->alias(
			static function ( $key ) {
				$options = array(
					'show_on_front' => 'page',
					'page_on_front' => 42,
				);
				return isset( $options[ $key ] ) ? $options[ $key ] : null;
			}
		);

		Actions\expectDone( 'wp_headless_data_deleted' )
			->once()
			->whenHappen(
				function ( $path, $context ) {
					$this->assertSame( '/', $path );
					$this->assertSame( 42, $context['post_id'] );
				}
			);

		$post = (object) array(
			'ID'        => 42,
			'post_type' => 'page',
			'post_name' => 'home',
		);
		$this->exporter()->on_transition( 'draft', 'publish', $post );
	}

	public function test_front_page_setting_off_keeps_the_slug_path(): void {
		Functions\when( 'get_post_type_object' )->justReturn( $this->restType() );
		Functions\when( 'get_permalink' )->justReturn( 'https://ex.test/home/' );
		Functions\when( 'get_option' )->alias(
			static function ( $key ) {
				return 'show_on_front' === $key ? 'posts' : 42;
			}
		);

		Actions\expectDone( 'wp_headless_data_exported' )
			->once()
			->whenHappen(
				function ( $path, $json, $context ) {
					$this->assertSame( '/home', $path );
				}
			);

		$post = (object) array(
			'ID'          => 42,
			'post_type'   => 'page',
			'post_name'   => 'home',
			'post_status' => 'publish',
		);
		$this->exporter()->on_transition( 'publish', 'draft', $post );
	}

	public function test_unpublish_transition_fires_deleted_action(): void {
		Functions\when( 'get_post_type_object' )->justReturn( $this->restType() );
		Functions\when( 'get_permalink' )->justReturn( 'https://ex.test/about-us/' );

		Actions\expectDone( 'wp_headless_data_deleted' )
			->once()
			->whenHappen(
				function ( $path, $context ) {
					$this->assertSame( '/about-us', $path );
					$this->assertSame( 42, $context['post_id'] );
				}
			);

		$post = (object) array(
			'ID'        => 42,
			'post_type' => 'page',
			'post_name' => 'about-us',
		);
		$this->exporter()->on_transition( 'draft', 'publish', $post );
	}

	public function test_draft_to_draft_fires_nothing(): void {
		Actions\expectDone( 'wp_headless_data_exported' )->never();
		Actions\expectDone( 'wp_headless_data_deleted' )->never();
		$post = (object) array(
			'ID'        => 42,
			'post_type' => 'page',
			'post_name' => 'about-us',
		);
		$this->exporter()->on_transition( 'draft', 'auto-draft', $post );
	}

	public function test_rest_refusal_yields_no_artifact(): void {
		Functions\when( 'get_post_type_object' )->justReturn( $this->restType() );
		Functions\when( 'get_permalink' )->justReturn( 'https://ex.test/about-us/' );

		Actions\expectDone( 'wp_headless_data_exported' )->never();
		$exporter                 = $this->exporter();
		$exporter->canned_content = null; // the dispatch refused (error/hidden type)
		$post                     = (object) array(
			'ID'        => 42,
			'post_type' => 'page',
			'post_name' => 'about-us',
		);
		$exporter->on_transition( 'publish', 'draft', $post );
	}

	public function test_deleted_published_post_fires_deleted_action(): void {
		Functions\when( 'get_post_type_object' )->justReturn( $this->restType() );
		Functions\when( 'get_permalink' )->justReturn( 'https://ex.test/gone/' );
		Actions\expectDone( 'wp_headless_data_deleted' )->once();
		$post = (object) array(
			'ID'          => 43,
			'post_type'   => 'page',
			'post_name'   => 'gone',
			'post_status' => 'publish',
		);
		$this->exporter()->on_deleted( 43, $post );
	}

	public function test_deleted_draft_fires_nothing(): void {
		Actions\expectDone( 'wp_headless_data_deleted' )->never();
		$post = (object) array(
			'ID'          => 44,
			'post_type'   => 'page',
			'post_name'   => 'draft-thing',
			'post_status' => 'draft',
		);
		$this->exporter()->on_deleted( 44, $post );
	}
}
