<?php
/**
 * Tests for NavMenus boot-timing behavior.
 *
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\Runtime;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WPHeadless\Runtime\NavMenus;

class NavMenusTest extends TestCase {
    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_registers_locations_directly_when_hook_already_fired(): void {
        Functions\when( 'did_action' )->justReturn( 1 );
        Functions\when( '__' )->returnArg( 1 );
        Functions\expect( 'register_nav_menus' )->once();

        ( new NavMenus() )->register();
    }

    public function test_defers_to_after_setup_theme_when_hook_has_not_fired(): void {
        Functions\when( 'did_action' )->justReturn( 0 );
        Functions\expect( 'register_nav_menus' )->never();

        $menus = new NavMenus();
        $menus->register();

        self::assertNotFalse(
            has_action( 'after_setup_theme', array( $menus, 'register_locations' ) )
        );
    }
}
