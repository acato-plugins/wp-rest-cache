<?php
/**
 * Tests for Endpoint_Api::skip_caching — the per-request decision tree that decides whether
 * the plugin will look at / write to the cache for the current request.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api::skip_caching
 */
class Test_Endpoint_Api_Skip_Caching extends Caching_Test_Case {

	/** @var array<string,mixed> $_SERVER backup so we can mutate REQUEST_METHOD freely. */
	private $server_backup = [];

	public function set_up() {
		parent::set_up();
		$this->server_backup = [ 'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? null ];
	}

	public function tear_down() {
		foreach ( $this->server_backup as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}
		parent::tear_down();
	}

	// ---------- Top-of-tree filter ----------

	public function test_skip_caching_filter_true_short_circuits_immediately() {
		add_filter( 'wp_rest_cache/skip_caching', '__return_true' );

		$api = $this->arrange( '/wp-json/wp/v2/posts' );

		$this->assertTrue( $api->skip_caching() );
	}

	// ---------- Nonce header ----------

	public function test_nonce_header_present_skips_caching_by_default() {
		$api = $this->arrange( '/wp-json/wp/v2/posts', 'GET', 'some-nonce-value' );

		$this->assertTrue( $api->skip_caching() );
	}

	public function test_nonce_header_present_does_not_skip_when_nonce_filter_disabled() {
		// The default behavior is "skip when nonced"; consumers can opt out by returning false
		// from `wp_rest_cache/skip_nonce_caching` (e.g. for authenticated-but-cacheable endpoints).
		add_filter( 'wp_rest_cache/skip_nonce_caching', '__return_false' );
		$this->enable_endpoint( 'wp/v2', 'posts' );

		$api = $this->arrange( '/wp-json/wp/v2/posts', 'GET', 'some-nonce-value' );

		$this->assertFalse( $api->skip_caching() );
	}

	// ---------- Allowed request methods ----------

	public function test_request_method_not_in_allowlist_skips_caching() {
		// Default allowed methods are ['GET']; POST is excluded.
		$api = $this->arrange( '/wp-json/wp/v2/posts', 'POST' );

		$this->assertTrue( $api->skip_caching() );
	}

	public function test_request_method_added_to_allowlist_is_not_a_skip_reason() {
		update_option( 'wp_rest_cache_allowed_request_methods', [ 'GET', 'POST' ] );
		$this->enable_endpoint( 'wp/v2', 'posts' );

		$api = $this->arrange( '/wp-json/wp/v2/posts', 'POST' );

		$this->assertFalse( $api->skip_caching() );
	}

	// ---------- REST prefix detection ----------

	public function test_non_rest_request_uri_skips_caching() {
		// Anything outside /wp-json/ (or rest_route=) isn't a REST call — no caching.
		$api = $this->arrange( '/wp-admin/index.php' );

		$this->assertTrue( $api->skip_caching() );
	}

	public function test_rest_route_parameter_form_is_recognized_as_rest_request() {
		// Alt form of the REST endpoint: ?rest_route=%2Fwp%2Fv2%2Fposts (e.g. permalinks off).
		$this->enable_endpoint( 'wp/v2', 'posts' );

		$api = $this->arrange( '/?rest_route=' . rawurlencode( '/wp/v2/posts' ) );

		$this->assertFalse( $api->skip_caching() );
	}

	// ---------- Allowed / disallowed endpoint matching ----------

	public function test_no_configured_allowed_endpoints_skips_caching() {
		// Fresh install: allowed_endpoints is empty → nothing is cacheable.
		update_option( 'wp_rest_cache_allowed_endpoints', [] );

		$api = $this->arrange( '/wp-json/wp/v2/posts' );

		$this->assertTrue( $api->skip_caching() );
	}

	public function test_uri_outside_configured_endpoints_skips_caching() {
		$this->enable_endpoint( 'wp/v2', 'posts' );

		$api = $this->arrange( '/wp-json/wp/v2/pages' );

		$this->assertTrue( $api->skip_caching() );
	}

	public function test_uri_matching_an_allowed_endpoint_does_not_skip_caching() {
		$this->enable_endpoint( 'wp/v2', 'posts' );

		$api = $this->arrange( '/wp-json/wp/v2/posts' );

		$this->assertFalse( $api->skip_caching() );
	}

	public function test_disallowed_endpoint_overrides_allowed_match_and_skips_caching() {
		// `disallowed_endpoints` is the last word — even if a URI is allowed, an overlapping
		// disallow entry causes the skip.
		$this->enable_endpoint( 'wp/v2', 'posts' );
		update_option(
			'wp_rest_cache_disallowed_endpoints',
			[ 'wp/v2' => [ 'posts' ] ]
		);

		$api = $this->arrange( '/wp-json/wp/v2/posts' );

		$this->assertTrue( $api->skip_caching() );
	}

	public function test_disallowed_entry_for_unrelated_endpoint_does_not_affect_match() {
		$this->enable_endpoint( 'wp/v2', 'posts' );
		update_option(
			'wp_rest_cache_disallowed_endpoints',
			[ 'wp/v2' => [ 'users' ] ]
		);

		$api = $this->arrange( '/wp-json/wp/v2/posts' );

		$this->assertFalse( $api->skip_caching() );
	}

	// ---------- Custom REST prefix ----------

	public function test_custom_rest_prefix_option_is_used_for_matching() {
		// Some sites rebrand /wp-json/ to /api/ or similar via the rest_url_prefix filter.
		update_option( 'wp_rest_cache_rest_prefix', 'api' );
		$this->enable_endpoint( 'wp/v2', 'posts' );

		$api = $this->arrange( '/api/wp/v2/posts' );

		$this->assertFalse( $api->skip_caching() );
	}

	// ---------- skip_cache GET param (documented gap) ----------

	public function test_skip_cache_query_param_short_circuits_to_skip() {
		// `?skip_cache` GET param is a per-request opt-out for cache reads.
		$this->enable_endpoint( 'wp/v2', 'posts' );
		$_GET['skip_cache'] = '1';

		$api = $this->arrange( '/wp-json/wp/v2/posts' );

		$this->assertTrue( $api->skip_caching() );

		unset( $_GET['skip_cache'] );
	}

	public function test_skip_cache_param_can_be_disabled_via_allow_skip_cache_filter() {
		// Pin the protection knob: `wp_rest_cache/allow_skip_cache` → false makes the GET
		// param a no-op, so an external attacker can't trivially DOS the cache by appending
		// ?skip_cache to every URL.
		$this->enable_endpoint( 'wp/v2', 'posts' );
		$_GET['skip_cache'] = '1';
		add_filter( 'wp_rest_cache/allow_skip_cache', '__return_false' );

		$api = $this->arrange( '/wp-json/wp/v2/posts' );

		$this->assertFalse( $api->skip_caching() );

		unset( $_GET['skip_cache'] );
	}

	// ----- helpers -----

	/**
	 * Build an Endpoint_Api instance with the bits skip_caching reads (request_uri, request
	 * object with optional nonce header, REQUEST_METHOD), without running the full build_cache_key
	 * flow.
	 */
	private function arrange( $request_uri, $method = 'GET', $nonce = null ) {
		$request = new \WP_REST_Request();
		if ( null !== $nonce ) {
			$request->set_header( 'x_wp_nonce', $nonce );
		}

		$api = new Endpoint_Api();
		$this->set_private_property( $api, 'request_uri', $request_uri );
		$this->set_private_property( $api, 'request', $request );

		$_SERVER['REQUEST_METHOD'] = $method;

		return $api;
	}

	/**
	 * Convenience: enable a single namespace/endpoint pair in the allowlist option (overwriting
	 * any previous value).
	 */
	private function enable_endpoint( $namespace, $endpoint ) {
		update_option(
			'wp_rest_cache_allowed_endpoints',
			[ $namespace => [ $endpoint ] ]
		);
	}
}
