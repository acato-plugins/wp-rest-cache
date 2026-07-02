<?php
/**
 * Tests for the Endpoint_Api class — the REST request-caching path.
 *
 * Focus is the testable-in-CLI surface: URI normalization, cache-key derivation, response
 * inspection (save_cache_headers / save_cache), and the skip-decision tree (skip_caching).
 * The full `get_api_cache` orchestrator exits the process on cache hit, so it isn't covered
 * here — that needs a separate process-isolation harness.
 *
 * The class uses `filter_var( $_SERVER[ ... ], ... )` (not `filter_input(INPUT_SERVER, ... )`),
 * which means runtime mutations of $_SERVER actually flow through. The `filter_has_var(INPUT_GET)`
 * branch in skip_caching is the one branch tied to SAPI request startup and is documented
 * as skipped.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api;
use WP_Rest_Cache_Plugin\Includes\Caching\Caching;
use WP_Rest_Cache_Plugin\Includes\Util;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api
 */
class Test_Endpoint_Api extends Caching_Test_Case {

	/** @var array<string,mixed> Snapshot of $_SERVER keys we mutate, restored in tear_down. */
	private $server_backup = [];

	public function set_up() {
		parent::set_up();
		$this->server_backup = [
			'REQUEST_URI'    => $_SERVER['REQUEST_URI']    ?? null,
			'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? null,
		];
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

	// ---------- build_request_uri ----------

	public function test_build_request_uri_strips_trailing_slash() {
		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts/';

		$api    = new Endpoint_Api();
		$result = $this->invoke_private( $api, 'build_request_uri', [] );

		$this->assertSame( '/wp-json/wp/v2/posts', $result );
	}

	public function test_build_request_uri_sorts_query_parameters_alphabetically() {
		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts?per_page=10&page=2&context=view';

		$api    = new Endpoint_Api();
		$result = $this->invoke_private( $api, 'build_request_uri', [] );

		$this->assertSame( '/wp-json/wp/v2/posts?context=view&page=2&per_page=10', $result );
	}

	public function test_build_request_uri_drops_uncached_parameters_from_query_string() {
		// Anything listed in `wp_rest_cache_uncached_parameters` is stripped so cache keys
		// don't fragment on cache-busting params (timestamps, request IDs, etc.).
		update_option( 'wp_rest_cache_uncached_parameters', [ '_t', 'nonce' ] );
		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts?a=1&_t=999&nonce=xyz&b=2';

		$api    = new Endpoint_Api();
		$result = $this->invoke_private( $api, 'build_request_uri', [] );

		$this->assertSame( '/wp-json/wp/v2/posts?a=1&b=2', $result );
	}

	public function test_build_request_uri_collapses_leading_double_slash() {
		$_SERVER['REQUEST_URI'] = '//wp-json/wp/v2/posts';

		$api    = new Endpoint_Api();
		$result = $this->invoke_private( $api, 'build_request_uri', [] );

		$this->assertSame( '/wp-json/wp/v2/posts', $result );
	}

	public function test_build_request_uri_strips_home_url_prefix() {
		$_SERVER['REQUEST_URI'] = Util::get_home_url() . '/wp-json/wp/v2/posts';

		$api    = new Endpoint_Api();
		$result = $this->invoke_private( $api, 'build_request_uri', [] );

		$this->assertSame( '/wp-json/wp/v2/posts', $result );
	}

	// ---------- build_cache_key ----------

	public function test_build_cache_key_is_stable_for_identical_requests() {
		$_SERVER['REQUEST_URI']    = '/wp-json/wp/v2/posts';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$key_a = $this->build_key();
		$key_b = $this->build_key();

		$this->assertSame( $key_a, $key_b );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $key_a );
	}

	public function test_build_cache_key_differs_when_uri_differs() {
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
		$key_a                  = $this->build_key();

		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/pages';
		$key_b                  = $this->build_key();

		$this->assertNotSame( $key_a, $key_b );
	}

	public function test_build_cache_key_treats_get_method_as_empty_for_backward_compat() {
		// GET and "no method given" must produce the same key — protects against a key
		// fragmentation surprise when the request method header is absent vs. literal GET.
		$_SERVER['REQUEST_URI']    = '/wp-json/wp/v2/posts';
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$key_get                   = $this->build_key();

		unset( $_SERVER['REQUEST_METHOD'] );
		$key_unset = $this->build_key();

		$this->assertSame( $key_get, $key_unset );
	}

	public function test_build_cache_key_differs_for_non_get_methods() {
		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$key_get                   = $this->build_key();

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$key_post                  = $this->build_key();

		$this->assertNotSame( $key_get, $key_post );
	}

	public function test_cache_key_filter_can_override_the_generated_key() {
		add_filter(
			'wp_rest_cache/cache_key',
			fn( $key ) => 'overridden-' . substr( $key, 0, 8 )
		);

		$_SERVER['REQUEST_URI']    = '/wp-json/wp/v2/posts';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$key = $this->build_key();

		$this->assertStringStartsWith( 'overridden-', $key );
	}

	// ---------- save_cache_headers ----------

	public function test_save_cache_headers_captures_response_headers_into_endpoint_state() {
		$response = new WP_HTTP_Response( null, 200, [ 'X-Total' => '42' ] );

		$api = new Endpoint_Api();
		$this->assertFalse( $api->save_cache_headers( false, $response ) );

		$captured = $this->get_private_property( $api, 'response_headers' );
		$this->assertArrayHasKey( 'X-Total', $captured );
		$this->assertSame( '42', $captured['X-Total'] );
	}

	public function test_save_cache_headers_passes_served_value_through_unchanged() {
		// save_cache_headers is hooked at priority 9999 onto rest_pre_serve_request, so its
		// return value must match the $served it was given.
		$response = new WP_HTTP_Response( null, 200, [] );

		$api = new Endpoint_Api();

		$this->assertFalse( $api->save_cache_headers( false, $response ) );
		$this->assertTrue( $api->save_cache_headers( true, $response ) );
	}

	public function test_cache_headers_filter_can_replace_headers_before_capture() {
		$response = new WP_HTTP_Response( null, 200, [ 'X-Total' => '42' ] );

		add_filter(
			'wp_rest_cache/cache_headers',
			fn( $headers ) => array_merge( $headers, [ 'X-Custom' => 'injected' ] )
		);

		$api = new Endpoint_Api();
		$api->save_cache_headers( false, $response );

		$captured = $this->get_private_property( $api, 'response_headers' );
		$this->assertSame( 'injected', $captured['X-Custom'] );
	}

	// ---------- save_cache ----------

	public function test_save_cache_does_not_persist_when_data_status_is_not_200() {
		// `data.status` is what WP REST sets when the handler returns a WP_Error.
		$result = [ 'data' => [ 'status' => 404 ] ];

		$api = new Endpoint_Api();
		$this->arrange_request( $api, '/wp-json/wp/v2/missing', 'GET' );

		$returned = $api->save_cache( $result );

		$this->assertSame( $result, $returned, 'save_cache must always return its input' );
		$this->assertSame( 0, $this->count_cache_rows() );
	}

	public function test_save_cache_caches_a_normal_200_response() {
		$result = [ 'id' => 1, 'title' => 'hello' ];

		$api = new Endpoint_Api();
		$this->arrange_request( $api, '/wp-json/wp/v2/posts/1', 'GET' );

		$api->save_cache( $result );

		$this->assertSame( 1, $this->count_cache_rows() );
		global $wpdb;
		$row = $wpdb->get_row(
			"SELECT request_uri, request_method, cache_type FROM `{$wpdb->prefix}wrc_caches`",
			ARRAY_A
		);
		$this->assertSame( '/wp-json/wp/v2/posts/1', $row['request_uri'] );
		$this->assertSame( 'GET', $row['request_method'] );
		$this->assertSame( 'endpoint', $row['cache_type'] );
	}

	public function test_save_cache_skips_empty_result_set_by_default() {
		// Default `wp_rest_cache/skip_empty_result_set` is true — empty bodies aren't cached.
		$api = new Endpoint_Api();
		$this->arrange_request( $api, '/wp-json/wp/v2/empty', 'GET' );

		$api->save_cache( [] );

		$this->assertSame( 0, $this->count_cache_rows() );
	}

	public function test_save_cache_caches_empty_result_when_skip_filter_returns_false() {
		// Returning false from skip_empty_result_set allows caching of empty arrays
		// (useful for "no results" queries where the empty answer is itself a result).
		add_filter( 'wp_rest_cache/skip_empty_result_set', '__return_false' );

		$api = new Endpoint_Api();
		$this->arrange_request( $api, '/wp-json/wp/v2/empty', 'GET' );

		$api->save_cache( [] );

		$this->assertSame( 1, $this->count_cache_rows() );
	}

	public function test_save_cache_caches_non_get_method_when_arranged() {
		$result = [ 'id' => 99 ];

		$api = new Endpoint_Api();
		$this->arrange_request( $api, '/wp-json/wp/v2/posts/99', 'POST' );

		$api->save_cache( $result );

		global $wpdb;
		$method = $wpdb->get_var( "SELECT request_method FROM `{$wpdb->prefix}wrc_caches`" );
		$this->assertSame( 'POST', $method );
	}

	// ----- helpers -----

	/**
	 * Build the cache key end-to-end (build_cache_key → reads private $cache_key).
	 */
	private function build_key() {
		$api = new Endpoint_Api();
		$this->invoke_private( $api, 'build_cache_key', [] );
		return $this->get_private_property( $api, 'cache_key' );
	}

	/**
	 * Plant the request state Endpoint_Api needs before save_cache reads it (instead of
	 * running the full build_cache_key flow with its $_SERVER reads — save_cache also reads
	 * REQUEST_METHOD directly and gates on http_response_code(), so set both).
	 */
	private function arrange_request( Endpoint_Api $api, $uri, $method ) {
		$this->set_private_property( $api, 'request_uri', $uri );
		$this->set_private_property( $api, 'cache_key', md5( $uri . $method ) );
		$this->set_private_property( $api, 'request_headers', [] );
		$_SERVER['REQUEST_METHOD'] = $method;
		// http_response_code is set to 200 once in tests/bootstrap.php; can't be changed
		// after WP test-lib output begins.
	}

	private function count_cache_rows() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}wrc_caches`" );
	}
}
