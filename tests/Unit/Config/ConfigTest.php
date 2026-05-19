<?php
/**
 * Tests for Config pure logic methods.
 *
 * @package WPHeadless\Tests
 */

namespace WPHeadless\Tests\Unit\Config;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use WPHeadless\Config\Config;

/**
 * Expose protected Config methods for testing.
 * The constructor calls WP functions so we stub them in setUp() before constructing.
 */
class ExposedConfig extends Config {
    public function exposeMerge( array $base, array $overrides ): array {
        return $this->merge( $base, $overrides );
    }

    public function exposeNormalizeStringList( $values ): array {
        return $this->normalize_string_list( $values );
    }

    public function exposeIsAbsolutePath( string $path ): bool {
        return $this->is_absolute_path( $path );
    }

    public function exposeNormalizeIndexFile( string $index_file ): string {
        return $this->normalize_index_file( $index_file );
    }
}

class ConfigTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // Stub all WP functions called during Config::__construct().
        // apply_filters( $hook, $value, ...) — return the second arg (the value, not the hook name).
        Functions\when( 'apply_filters' )->returnArg( 2 );
        Functions\when( 'home_url' )->justReturn( 'https://example.com' );
        Functions\when( 'trailingslashit' )->alias( fn( string $s ) => rtrim( $s, '/' ) . '/' );
        Functions\when( 'untrailingslashit' )->alias( fn( string $s ) => rtrim( $s, '/\\' ) );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make(): ExposedConfig {
        return new ExposedConfig();
    }

    // -------------------------------------------------------------------------
    // get()
    // -------------------------------------------------------------------------

    public function test_get_returns_top_level_value(): void {
        $config = $this->make();
        $this->assertTrue( $config->get( 'enabled' ) );
    }

    public function test_get_uses_dot_notation(): void {
        $config = $this->make();
        $this->assertSame( '_wp-headless', $config->get( 'frontend.asset_mount' ) );
    }

    public function test_get_three_levels_deep(): void {
        $config = $this->make();
        // cors.allowed_methods is an array by default.
        $methods = $config->get( 'cors.allowed_methods' );
        $this->assertIsArray( $methods );
        $this->assertContains( 'GET', $methods );
    }

    public function test_get_returns_null_for_missing_key(): void {
        $config = $this->make();
        $this->assertNull( $config->get( 'nonexistent.key' ) );
    }

    public function test_get_returns_default_for_missing_key(): void {
        $config = $this->make();
        $this->assertSame( 'fallback', $config->get( 'nonexistent.key', 'fallback' ) );
    }

    public function test_get_returns_default_for_partially_missing_path(): void {
        $config = $this->make();
        $this->assertSame( 42, $config->get( 'frontend.nonexistent', 42 ) );
    }

    // -------------------------------------------------------------------------
    // merge()
    // -------------------------------------------------------------------------

    public function test_merge_scalar_override_replaces_base(): void {
        $config = $this->make();
        $result = $config->exposeMerge(
            [ 'enabled' => true, 'namespace' => 'v1' ],
            [ 'enabled' => false ]
        );
        $this->assertFalse( $result['enabled'] );
        $this->assertSame( 'v1', $result['namespace'] );
    }

    public function test_merge_arrays_are_merged_recursively(): void {
        $config = $this->make();
        $result = $config->exposeMerge(
            [ 'cors' => [ 'enabled' => true, 'max_age' => 86400 ] ],
            [ 'cors' => [ 'max_age' => 3600 ] ]
        );
        $this->assertTrue( $result['cors']['enabled'] );
        $this->assertSame( 3600, $result['cors']['max_age'] );
    }

    public function test_merge_adds_new_keys_from_override(): void {
        $config = $this->make();
        $result = $config->exposeMerge( [ 'a' => 1 ], [ 'b' => 2 ] );
        $this->assertSame( 1, $result['a'] );
        $this->assertSame( 2, $result['b'] );
    }

    public function test_merge_empty_override_returns_base_unchanged(): void {
        $config = $this->make();
        $base   = [ 'a' => 1, 'b' => [ 'c' => 2 ] ];
        $result = $config->exposeMerge( $base, [] );
        $this->assertSame( $base, $result );
    }

    public function test_merge_scalar_override_wins_over_nested_array(): void {
        // When override value is scalar and base value is array, scalar wins.
        $config = $this->make();
        $result = $config->exposeMerge(
            [ 'cors' => [ 'enabled' => true ] ],
            [ 'cors' => 'disabled' ]
        );
        $this->assertSame( 'disabled', $result['cors'] );
    }

    // -------------------------------------------------------------------------
    // normalize_string_list()
    // -------------------------------------------------------------------------

    public function test_normalize_string_list_trims_whitespace(): void {
        $config = $this->make();
        $result = $config->exposeNormalizeStringList( [ '  GET  ', ' POST' ] );
        $this->assertSame( [ 'GET', 'POST' ], $result );
    }

    public function test_normalize_string_list_deduplicates(): void {
        $config = $this->make();
        $result = $config->exposeNormalizeStringList( [ 'GET', 'GET', 'POST' ] );
        $this->assertSame( [ 'GET', 'POST' ], $result );
    }

    public function test_normalize_string_list_removes_empty_strings(): void {
        $config = $this->make();
        $result = $config->exposeNormalizeStringList( [ 'GET', '', '  ', 'POST' ] );
        $this->assertSame( [ 'GET', 'POST' ], $result );
    }

    public function test_normalize_string_list_returns_empty_for_non_array(): void {
        $config = $this->make();
        $this->assertSame( [], $config->exposeNormalizeStringList( 'GET' ) );
        $this->assertSame( [], $config->exposeNormalizeStringList( null ) );
        $this->assertSame( [], $config->exposeNormalizeStringList( 42 ) );
    }

    public function test_normalize_string_list_returns_reindexed_array(): void {
        $config = $this->make();
        // After dedup the array should be numerically re-indexed from 0.
        $result = $config->exposeNormalizeStringList( [ 0 => 'A', 5 => 'B' ] );
        $this->assertSame( [ 0 => 'A', 1 => 'B' ], $result );
    }

    // -------------------------------------------------------------------------
    // is_absolute_path()
    // -------------------------------------------------------------------------

    public function test_unix_absolute_path_is_absolute(): void {
        $config = $this->make();
        $this->assertTrue( $config->exposeIsAbsolutePath( '/var/www/html' ) );
    }

    public function test_windows_backslash_path_is_absolute(): void {
        $config = $this->make();
        $this->assertTrue( $config->exposeIsAbsolutePath( 'C:\\xampp\\htdocs' ) );
    }

    public function test_windows_forward_slash_path_is_absolute(): void {
        $config = $this->make();
        $this->assertTrue( $config->exposeIsAbsolutePath( 'C:/xampp/htdocs' ) );
    }

    public function test_relative_path_is_not_absolute(): void {
        $config = $this->make();
        $this->assertFalse( $config->exposeIsAbsolutePath( 'relative/path' ) );
    }

    public function test_dot_relative_path_is_not_absolute(): void {
        $config = $this->make();
        $this->assertFalse( $config->exposeIsAbsolutePath( './relative' ) );
    }

    public function test_empty_string_is_not_absolute(): void {
        $config = $this->make();
        $this->assertFalse( $config->exposeIsAbsolutePath( '' ) );
    }

    // -------------------------------------------------------------------------
    // normalize_index_file()
    // -------------------------------------------------------------------------

    public function test_absolute_index_file_is_returned_as_is(): void {
        $config = $this->make();
        $this->assertSame( '/var/www/html/dist/index.html', $config->exposeNormalizeIndexFile( '/var/www/html/dist/index.html' ) );
    }

    public function test_absolute_unix_path_is_preserved(): void {
        // /dist/index.html starts with / so is_absolute_path returns true — returned as-is.
        $config = $this->make();
        $this->assertSame( '/dist/index.html', $config->exposeNormalizeIndexFile( '/dist/index.html' ) );
    }

    public function test_relative_index_file_without_leading_slash_unchanged(): void {
        $config = $this->make();
        $this->assertSame( 'dist/index.html', $config->exposeNormalizeIndexFile( 'dist/index.html' ) );
    }

    public function test_whitespace_is_trimmed(): void {
        $config = $this->make();
        $this->assertSame( 'dist/index.html', $config->exposeNormalizeIndexFile( '  dist/index.html  ' ) );
    }
}
