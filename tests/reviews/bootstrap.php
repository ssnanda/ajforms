<?php
if ( PHP_SAPI !== 'cli' ) { exit; }
// Requires an existing WordPress PHPUnit test installation and a disposable test database.
$ajcore_tests_dir = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';
if ( ! is_file( $ajcore_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Set WP_TESTS_DIR to an existing WordPress PHPUnit test library. No dependencies are installed by this suite.\n" ); exit( 1 );
}
require_once $ajcore_tests_dir . '/includes/functions.php';
tests_add_filter( 'muplugins_loaded', function() {
	// Unmatched HTTP calls always fail locally. Tests cannot reach Google (or any other network).
	add_filter( 'pre_http_request', function() { return new WP_Error( 'unexpected_test_http', 'Network access disabled in reviews tests.' ); }, 1, 3 );
	define( 'AJCORE_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
	require_once AJCORE_PLUGIN_DIR . 'includes/settings-encryption.php';
	require_once AJCORE_PLUGIN_DIR . 'modules/reviews/bootstrap.php';
} );
require $ajcore_tests_dir . '/includes/bootstrap.php';
require_once __DIR__ . '/fixtures.php';
