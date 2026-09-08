<?php
/**
 * PHPUnit bootstrap: loads the WordPress test library and this plugin.
 *
 * Set WP_TESTS_DIR (or WP_DEVELOP_DIR) to the WordPress test suite; see
 * README.md for how to install it.
 *
 * @package InfiniteIcons
 */

declare( strict_types = 1 );

$_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $_tests_dir && getenv( 'WP_DEVELOP_DIR' ) ) {
	$_tests_dir = rtrim( getenv( 'WP_DEVELOP_DIR' ), '/\\' ) . '/tests/phpunit';
}

if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Could not find {$_tests_dir}/includes/functions.php.\nSet WP_TESTS_DIR to the WordPress test suite directory.\n" );
	exit( 1 );
}

// The WordPress bootstrap reads a constant, not the environment variable.
if ( ! defined( 'WP_TESTS_CONFIG_FILE_PATH' ) && getenv( 'WP_TESTS_CONFIG_FILE_PATH' ) ) {
	define( 'WP_TESTS_CONFIG_FILE_PATH', getenv( 'WP_TESTS_CONFIG_FILE_PATH' ) );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Loads the plugin into the test WordPress instance.
 *
 * @return void
 */
function infinite_icons_manually_load_plugin(): void {
	require dirname( __DIR__, 2 ) . '/infinite-icons.php';
}

tests_add_filter( 'muplugins_loaded', 'infinite_icons_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/TestCase.php';
