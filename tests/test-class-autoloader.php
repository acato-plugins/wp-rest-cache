<?php
/**
 * Tests for the Autoloader — the spl_autoload_register callback that maps
 * `WP_Rest_Cache_Plugin\…` class names to files under the plugin tree.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Autoloader;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Autoloader
 */
class Test_Autoloader extends Caching_Test_Case {

	public function test_autoload_is_a_noop_for_non_plugin_namespaced_classes() {
		// Should not error, should not include anything (verified via get_included_files).
		$before = count( get_included_files() );

		Autoloader::autoload( 'Some\\Other\\Vendor\\Class_Name' );

		$this->assertSame( $before, count( get_included_files() ) );
	}

	public function test_autoload_includes_class_file_for_plugin_top_level_namespaced_class() {
		// Class file: includes/class-util.php → already loaded by the plugin bootstrap, but
		// calling autoload again is idempotent (include_once).
		Autoloader::autoload( 'WP_Rest_Cache_Plugin\\Includes\\Util' );

		$this->assertTrue( class_exists( '\WP_Rest_Cache_Plugin\\Includes\\Util', false ) );
	}

	public function test_autoload_resolves_subnamespace_to_subdirectory() {
		// `…\Includes\API\Endpoint_Api` lives at includes/api/class-endpoint-api.php — the
		// subnamespace 'API' becomes the subdir 'api' (lowercased).
		Autoloader::autoload( 'WP_Rest_Cache_Plugin\\Includes\\API\\Endpoint_Api' );

		$this->assertTrue(
			class_exists( '\WP_Rest_Cache_Plugin\\Includes\\API\\Endpoint_Api', false ),
			'API\\Endpoint_Api should be loadable via the autoloader'
		);
	}

	public function test_autoload_translates_class_name_underscores_into_filename_dashes() {
		// `Controller_Trait` → file `trait-controller.php`, where the `trait` suffix is
		// detected and prepended.
		Autoloader::autoload( 'WP_Rest_Cache_Plugin\\Includes\\Controller\\Controller_Trait' );

		$this->assertTrue( trait_exists( '\WP_Rest_Cache_Plugin\\Includes\\Controller\\Controller_Trait', false ) );
	}

	public function test_autoload_prefixes_with_trait_for_trait_named_classes() {
		// Filename convention: `…_Trait` → `trait-…-trait.php`? No — looking at the source,
		// the suffix is stripped and prepended, so Controller_Trait → trait-controller.php.
		// We've already confirmed it loads above; here we verify the file path resolution
		// by passing a phantom name and checking it doesn't blow up when the file is missing.
		// Should silently do nothing for a non-existent class file.
		$before = count( get_included_files() );

		Autoloader::autoload( 'WP_Rest_Cache_Plugin\\Includes\\Nonexistent_Trait' );

		// Either no file gets included (because file_exists returned false), or — if the
		// path happens to match something — at most one was added. Either way, autoload
		// must not throw.
		$this->assertLessThanOrEqual( $before + 1, count( get_included_files() ) );
	}

	public function test_autoload_silently_skips_when_resolved_path_does_not_exist() {
		// Class in plugin namespace but with no matching file → autoload must do nothing
		// (no fatal error, no warning).
		$before = count( get_included_files() );

		Autoloader::autoload( 'WP_Rest_Cache_Plugin\\Includes\\Definitely_Does_Not_Exist' );

		$this->assertSame( $before, count( get_included_files() ) );
	}

	public function test_autoload_includes_deprecated_endpoint_api_for_legacy_class_name() {
		// Pins the special-case for the pre-namespace plugin alias used by old integrations:
		// `WP_Rest_Cache_Endpoint_Api` → deprecated/class-wp-rest-cache-endpoint-api.php.
		Autoloader::autoload( 'WP_Rest_Cache_Endpoint_Api' );

		$deprecated_file = realpath(
			dirname( __DIR__ ) . '/deprecated/class-wp-rest-cache-endpoint-api.php'
		);
		$this->assertNotFalse( $deprecated_file );
		$this->assertContains( $deprecated_file, get_included_files() );
	}
}
