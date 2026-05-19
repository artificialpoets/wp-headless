<?php
/**
 * PHPUnit bootstrap — loads Patchwork before any class under test,
 * then the Composer autoloader, then defines the WordPress constants
 * that Config::defaults() and normalize() reference.
 */

// Patchwork must be required before any source class is loaded.
require_once dirname( __DIR__ ) . '/vendor/antecedent/patchwork/Patchwork.php';
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// WordPress constants referenced by the classes under test.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/wp/' );
}
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
    define( 'WP_CONTENT_DIR', '/tmp/wp/wp-content' );
}
