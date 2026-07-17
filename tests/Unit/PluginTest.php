<?php
/**
 * Tests for Plugin module registry behavior.
 *
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WPHeadless\Config\Config;
use WPHeadless\Contracts\Module;
use WPHeadless\Plugin;
use WPHeadless\Theme\ThemeManager;

/** Config stub with a fixed data tree; bypasses the WP-dependent constructor. */
class StubConfig extends Config {
    /** @var array<string, mixed> */
    private array $data;

    public function __construct( array $data = array() ) {
        // Deliberately no parent::__construct() — get() is fully overridden.
        $this->data = $data;
    }

    public function get( string $key, $default = null ) {
        $segments = explode( '.', $key );
        $value    = $this->data;

        foreach ( $segments as $segment ) {
            if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
                return $default;
            }
            $value = $value[ $segment ];
        }

        return $value;
    }
}

/** Module spy counting register() calls. */
class SpyModule implements Module {
    public int $registered = 0;

    public function register(): void {
        $this->registered++;
    }
}

class PluginTest extends TestCase {
    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @param array<string, mixed> $config */
    private function makePlugin( array $modules, array $config = array() ): Plugin {
        $theme_manager = $this->createMock( ThemeManager::class );

        return new Plugin( new StubConfig( $config ), $theme_manager, $modules );
    }

    public function test_register_boots_every_module_by_default(): void {
        $a = new SpyModule();
        $b = new SpyModule();

        $plugin = $this->makePlugin( array( 'a' => $a, 'b' => $b ) );
        $plugin->register();

        $this->assertSame( 1, $a->registered );
        $this->assertSame( 1, $b->registered );
    }

    public function test_register_is_idempotent(): void {
        $a      = new SpyModule();
        $plugin = $this->makePlugin( array( 'a' => $a ) );

        $plugin->register();
        $plugin->register();

        $this->assertSame( 1, $a->registered );
    }

    public function test_config_can_disable_a_module_by_key(): void {
        $a = new SpyModule();
        $b = new SpyModule();

        $plugin = $this->makePlugin(
            array( 'a' => $a, 'b' => $b ),
            array( 'modules' => array( 'b' => array( 'enabled' => false ) ) )
        );
        $plugin->register();

        $this->assertSame( 1, $a->registered );
        $this->assertSame( 0, $b->registered );
        $this->assertFalse( $plugin->modules()['b']['enabled'] );
    }

    public function test_modules_filter_can_add_and_remove_modules(): void {
        $builtin = new SpyModule();
        $addon   = new SpyModule();

        Monkey\Filters\expectApplied( 'wp_headless_modules' )
            ->once()
            ->andReturnUsing(
                static function ( array $modules ) use ( $addon ) {
                    unset( $modules['builtin'] );
                    $modules['addon'] = $addon;
                    return $modules;
                }
            );

        $plugin = $this->makePlugin( array( 'builtin' => $builtin ) );
        $plugin->register();

        $this->assertSame( 0, $builtin->registered );
        $this->assertSame( 1, $addon->registered );
        $this->assertArrayHasKey( 'addon', $plugin->modules() );
    }

    public function test_non_module_filter_entries_are_skipped(): void {
        Monkey\Filters\expectApplied( 'wp_headless_modules' )
            ->once()
            ->andReturnUsing(
                static function ( array $modules ) {
                    $modules['junk'] = 'not-a-module';
                    return $modules;
                }
            );

        $plugin = $this->makePlugin( array() );
        $plugin->register();

        $this->assertArrayNotHasKey( 'junk', $plugin->modules() );
    }

    public function test_booted_action_fires_once(): void {
        Monkey\Actions\expectDone( 'wp_headless_booted' )
            ->once()
            ->with( \Mockery::type( Plugin::class ) );

        $plugin = $this->makePlugin( array() );
        $plugin->register();
        $plugin->register();
    }

    public function test_add_module_after_boot_registers_immediately(): void {
        $plugin = $this->makePlugin( array() );
        $plugin->register();

        $late = new SpyModule();
        $plugin->add_module( 'late', $late );

        $this->assertSame( 1, $late->registered );
        $this->assertTrue( $plugin->modules()['late']['registered'] );
    }

    public function test_add_module_after_boot_honors_config_gate(): void {
        $plugin = $this->makePlugin(
            array(),
            array( 'modules' => array( 'late' => array( 'enabled' => false ) ) )
        );
        $plugin->register();

        $late = new SpyModule();
        $plugin->add_module( 'late', $late );

        $this->assertSame( 0, $late->registered );
        $this->assertFalse( $plugin->modules()['late']['registered'] );
    }

    public function test_add_module_before_boot_defers_registration(): void {
        $late   = new SpyModule();
        $plugin = $this->makePlugin( array() );

        $plugin->add_module( 'late', $late );
        $this->assertSame( 0, $late->registered );

        $plugin->register();
        $this->assertSame( 1, $late->registered );
    }

    public function test_modules_reports_status_metadata(): void {
        $a      = new SpyModule();
        $plugin = $this->makePlugin( array( 'a' => $a ) );
        $plugin->register();

        $status = $plugin->modules();

        $this->assertSame( SpyModule::class, $status['a']['class'] );
        $this->assertTrue( $status['a']['enabled'] );
        $this->assertTrue( $status['a']['registered'] );
    }
}
