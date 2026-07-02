<?php
/**
 * Multisite-only tests for Endpoint_Api::build_request_uri — covers the subsite-path
 * stripping branch that only runs when is_multisite() returns true.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api::build_request_uri
 * @group multisite
 */
class Test_Endpoint_Api_Multisite extends Caching_Test_Case {

	private $server_backup = [];

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test — run via `composer test-multisite`.' );
		}
		parent::set_up();
		$this->server_backup = [ 'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null ];
	}

	public function tear_down() {
		foreach ( $this->server_backup as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}
		// switch_to_blog leaves a stack — make sure we're back on the main blog so other
		// tests start from a known state.
		while ( ms_is_switched() ) {
			restore_current_blog();
		}
		parent::tear_down();
	}

	public function test_subsite_path_prefix_is_stripped_from_request_uri() {
		// Create a path-based subsite (WP test bootstrap installs in subdir mode by default,
		// so subsites have paths like `/subsite-N/`).
		$subsite_id = self::factory()->blog->create( [ 'path' => '/subsite-strip/' ] );
		switch_to_blog( $subsite_id );

		$_SERVER['REQUEST_URI'] = '/subsite-strip/wp-json/wp/v2/posts';

		$result = $this->invoke_private( new Endpoint_Api(), 'build_request_uri', [] );

		// The subsite path is stripped so the cache key is consistent across sites for the
		// same REST endpoint.
		$this->assertSame( '/wp-json/wp/v2/posts', $result );
	}

	public function test_subsite_path_with_query_string_is_stripped_while_query_is_preserved() {
		$subsite_id = self::factory()->blog->create( [ 'path' => '/subsite-q/' ] );
		switch_to_blog( $subsite_id );

		$_SERVER['REQUEST_URI'] = '/subsite-q/wp-json/wp/v2/posts?per_page=10';

		$result = $this->invoke_private( new Endpoint_Api(), 'build_request_uri', [] );

		$this->assertSame( '/wp-json/wp/v2/posts?per_page=10', $result );
	}

	public function test_uri_not_starting_with_subsite_path_is_left_alone() {
		// Defensive: the inner `str_starts_with` check protects against a URI that — for
		// whatever reason — doesn't carry the subsite path prefix (e.g. internally-generated
		// REST routes). Such URIs pass through unchanged.
		$subsite_id = self::factory()->blog->create( [ 'path' => '/subsite-skip/' ] );
		switch_to_blog( $subsite_id );

		$_SERVER['REQUEST_URI'] = '/some-other-prefix/wp-json/wp/v2/posts';

		$result = $this->invoke_private( new Endpoint_Api(), 'build_request_uri', [] );

		$this->assertSame( '/some-other-prefix/wp-json/wp/v2/posts', $result );
	}

	public function test_main_site_path_is_root_and_is_not_treated_as_a_prefix_to_strip() {
		// Pins the `'/' !== $path` inner guard: the main site's path is `/`, so the strip
		// branch must NOT fire (otherwise it would lop the leading slash off every REST URI).
		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';

		$result = $this->invoke_private( new Endpoint_Api(), 'build_request_uri', [] );

		$this->assertSame( '/wp-json/wp/v2/posts', $result );
	}
}
