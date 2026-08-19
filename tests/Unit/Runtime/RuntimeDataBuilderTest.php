<?php
/**
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\Runtime;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use WPHeadless\Config\Config;
use WPHeadless\Runtime\RequestDataBuilder;
use WPHeadless\Runtime\RuntimeDataBuilder;
use WPHeadless\Theme\ThemeManager;

class ExposedRuntimeDataBuilder extends RuntimeDataBuilder {
	public function exposePayloadAllowlist(): array {
		return $this->payload_allowlist();
	}

	public function exposePayloadKeyWanted( string $key, array $keys ): bool {
		return $this->payload_key_wanted( $key, $keys );
	}

	public function exposePayloadCacheEnabled(): bool {
		return $this->payload_cache_enabled();
	}
}

final class RuntimeDataBuilderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $values Config overrides keyed by path.
	 */
	private function builder( array $values = array() ): ExposedRuntimeDataBuilder {
		$config = $this->getMockBuilder( Config::class )
			->disableOriginalConstructor()
			->getMock();
		$config->method( 'get' )->willReturnCallback(
			static function ( $key, $default = null ) use ( $values ) {
				return array_key_exists( $key, $values ) ? $values[ $key ] : $default;
			}
		);
		$theme_manager = $this->getMockBuilder( ThemeManager::class )
			->disableOriginalConstructor()
			->getMock();
		$request_data = $this->getMockBuilder( RequestDataBuilder::class )
			->disableOriginalConstructor()
			->getMock();
		return new ExposedRuntimeDataBuilder( $config, $theme_manager, $request_data );
	}

	// --- payload_allowlist ---

	public function test_default_allowlist_is_empty(): void {
		$this->assertSame( array(), $this->builder()->exposePayloadAllowlist() );
	}

	public function test_allowlist_is_normalized_to_strings(): void {
		$builder = $this->builder( array( 'modules.cache.payload_keys' => array( 'menus', '', 'theme' ) ) );
		$this->assertSame( array( 'menus', 'theme' ), $builder->exposePayloadAllowlist() );
	}

	public function test_non_array_allowlist_keeps_everything(): void {
		$builder = $this->builder( array( 'modules.cache.payload_keys' => 'menus' ) );
		$this->assertSame( array(), $builder->exposePayloadAllowlist() );
	}

	// --- payload_key_wanted ---

	public function test_empty_allowlist_keeps_every_key(): void {
		$builder = $this->builder();
		$this->assertTrue( $builder->exposePayloadKeyWanted( 'theme', array() ) );
		$this->assertTrue( $builder->exposePayloadKeyWanted( 'customCss', array() ) );
	}

	public function test_allowlist_prunes_unlisted_keys(): void {
		$builder = $this->builder();
		$keys    = array( 'menus' );
		$this->assertTrue( $builder->exposePayloadKeyWanted( 'menus', $keys ) );
		$this->assertFalse( $builder->exposePayloadKeyWanted( 'theme', $keys ) );
		$this->assertFalse( $builder->exposePayloadKeyWanted( 'customCss', $keys ) );
		$this->assertFalse( $builder->exposePayloadKeyWanted( 'postTypes', $keys ) );
	}

	// --- payload_cache_enabled ---

	public function test_payload_cache_defaults_on(): void {
		$this->assertTrue( $this->builder()->exposePayloadCacheEnabled() );
	}

	public function test_disabling_the_module_disables_payload_cache(): void {
		$builder = $this->builder( array( 'modules.cache.enabled' => false ) );
		$this->assertFalse( $builder->exposePayloadCacheEnabled() );
	}

	public function test_disabling_payload_alone_disables_payload_cache(): void {
		$builder = $this->builder( array( 'modules.cache.payload' => false ) );
		$this->assertFalse( $builder->exposePayloadCacheEnabled() );
	}
}
