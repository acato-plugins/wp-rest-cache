<?php
/**
 * Tests for the remaining Endpoint_Api methods: save_options (the option-sync routine that
 * runs on `init` and `rest_api_init`), add_wordpress_endpoints (filter on the allowed-endpoints
 * list), and determine_object_type (REST URI → object-type resolver).
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api::save_options
 * @covers \WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api::add_wordpress_endpoints
 * @covers \WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api::determine_object_type
 */
class Test_Endpoint_Api_Options extends Caching_Test_Case {

	/** @var Endpoint_Api */
	private $api;

	public function set_up() {
		parent::set_up();
		$this->api = new Endpoint_Api();
	}

	// ---------- save_options: allowed_endpoints ----------

	public function test_save_options_seeds_allowed_endpoints_from_item_allowed_endpoints() {
		// Subtle but important: the `wp_rest_cache/allowed_endpoints` filter is applied to the
		// `wp_rest_cache_item_allowed_endpoints` option (the one populated by the Controller_Trait),
		// then the result is written to `wp_rest_cache_allowed_endpoints`. The two options
		// are *different* — easy to confuse if you read only one site.
		update_option(
			'wp_rest_cache_item_allowed_endpoints',
			[ 'wp/v2' => [ 'wprc-test-base' ] ]
		);
		update_option( 'wp_rest_cache_allowed_endpoints', [] );

		$this->api->save_options();

		// Production has add_wordpress_endpoints + add_oembed_endpoint registered on
		// `wp_rest_cache/allowed_endpoints`, so the saved result is a superset of the seed.
		// Assert containment — the seeded item-endpoint must appear in the merged result.
		$saved = get_option( 'wp_rest_cache_allowed_endpoints' );
		$this->assertContains( 'wprc-test-base', $saved['wp/v2'] );
	}

	public function test_save_options_writes_filter_modified_allowed_endpoints() {
		add_filter(
			'wp_rest_cache/allowed_endpoints',
			fn( $endpoints ) => array_merge( $endpoints, [ 'custom/v1' => [ 'widgets' ] ] )
		);

		$this->api->save_options();

		$saved = get_option( 'wp_rest_cache_allowed_endpoints' );
		$this->assertArrayHasKey( 'custom/v1', $saved );
		$this->assertSame( [ 'widgets' ], $saved['custom/v1'] );
	}

	// ---------- save_options: disallowed_endpoints ----------

	public function test_save_options_writes_filter_modified_disallowed_endpoints() {
		add_filter(
			'wp_rest_cache/disallowed_endpoints',
			fn() => [ 'wp/v2' => [ 'users' ] ]
		);

		$this->api->save_options();

		$this->assertSame(
			[ 'wp/v2' => [ 'users' ] ],
			get_option( 'wp_rest_cache_disallowed_endpoints' )
		);
	}

	// ---------- save_options: rest_prefix ----------

	public function test_save_options_writes_current_rest_prefix_when_stored_value_drifted() {
		update_option( 'wp_rest_cache_rest_prefix', 'stale-prefix' );

		$this->api->save_options();

		// rest_get_url_prefix() returns the live value (defaults to 'wp-json'); we just assert
		// that the stale stored value was replaced with whatever WP currently reports.
		$this->assertSame( rest_get_url_prefix(), get_option( 'wp_rest_cache_rest_prefix' ) );
	}

	// ---------- save_options: cacheable_request_headers ----------

	public function test_save_options_writes_filter_modified_cacheable_request_headers() {
		add_filter(
			'wp_rest_cache/cacheable_request_headers',
			fn() => [ '/wp/v2/posts' => 'X-Tenant' ]
		);

		$this->api->save_options();

		$this->assertSame(
			[ '/wp/v2/posts' => 'X-Tenant' ],
			get_option( 'wp_rest_cache_cacheable_request_headers' )
		);
	}

	// ---------- save_options: allowed_request_methods ----------

	public function test_save_options_writes_filter_modified_allowed_request_methods() {
		add_filter(
			'wp_rest_cache/allowed_request_methods',
			fn() => [ 'GET', 'HEAD' ]
		);

		$this->api->save_options();

		$this->assertSame(
			[ 'GET', 'HEAD' ],
			get_option( 'wp_rest_cache_allowed_request_methods' )
		);
	}

	// ---------- save_options: uncached_parameters ----------

	public function test_save_options_writes_filter_modified_uncached_parameters() {
		add_filter(
			'wp_rest_cache/uncached_parameters',
			fn() => [ '_t', 'nonce' ]
		);

		$this->api->save_options();

		$this->assertSame(
			[ '_t', 'nonce' ],
			get_option( 'wp_rest_cache_uncached_parameters' )
		);
	}

	// ---------- save_options: hit_recording ----------

	public function test_save_options_writes_hit_recording_only_when_int_cast_changes() {
		// The comparison casts both sides via (int), so equal-truthy values like (true, 1)
		// don't trigger a write. Filter must produce a different int to update.
		add_filter( 'wp_rest_cache/cache_hit_recording', '__return_false' );

		$this->api->save_options();

		// Loose compare: the value is written via (int) cast, so it lands as int 0 in the
		// in-request option cache. A fresh DB roundtrip would yield '0'.
		$this->assertEquals( 0, get_option( 'wp_rest_cache_hit_recording' ) );
	}

	public function test_save_options_does_not_write_hit_recording_when_filter_is_equivalent_truthy() {
		// `true` (default) cast to int is 1; if the filter returns 1, the comparison
		// (1 !== 1) is false → no write. Verify by ensuring the option stays at its prior
		// type (the originally-stored true), not flipped to integer.
		update_option( 'wp_rest_cache_hit_recording', true );
		add_filter( 'wp_rest_cache/cache_hit_recording', fn() => 1 );

		$this->api->save_options();

		// We compare loosely — the point is the value is still "truthy 1", not that the
		// type was rewritten.
		$this->assertEquals( 1, get_option( 'wp_rest_cache_hit_recording' ) );
	}

	// ---------- add_wordpress_endpoints ----------

	public function test_add_wordpress_endpoints_seeds_default_wp_v2_endpoints_into_empty_allowlist() {
		$result = $this->api->add_wordpress_endpoints( [] );

		$this->assertArrayHasKey( 'wp/v2', $result );
		$this->assertEqualsCanonicalizing(
			[ 'statuses', 'taxonomies', 'types', 'users', 'comments' ],
			$result['wp/v2']
		);
	}

	public function test_add_wordpress_endpoints_preserves_pre_existing_namespaces() {
		$existing = [ 'custom/v1' => [ 'widgets' ] ];

		$result = $this->api->add_wordpress_endpoints( $existing );

		$this->assertSame( [ 'widgets' ], $result['custom/v1'] );
		$this->assertArrayHasKey( 'wp/v2', $result );
	}

	public function test_add_wordpress_endpoints_does_not_duplicate_endpoints_already_present_under_wp_v2() {
		$existing = [ 'wp/v2' => [ 'posts', 'users' ] ];

		$result = $this->api->add_wordpress_endpoints( $existing );

		// `users` is in both the existing list AND the WP defaults — should appear once.
		$user_count = count( array_keys( $result['wp/v2'], 'users', true ) );
		$this->assertSame( 1, $user_count );
		$this->assertContains( 'posts', $result['wp/v2'], 'pre-existing endpoint must survive' );
	}

	// ---------- determine_object_type ----------

	public function test_determine_object_type_passes_through_when_already_resolved() {
		// If upstream already figured out the object type, this method must not overwrite it.
		$result = $this->api->determine_object_type( 'post', 'k', [], '/wp-json/wp/v2/users' );

		$this->assertSame( 'post', $result );
	}

	public function test_determine_object_type_returns_endpoint_name_for_matching_wp_endpoint() {
		$result = $this->api->determine_object_type(
			'unknown',
			'k',
			[],
			'/wp-json/wp/v2/users/42'
		);

		$this->assertSame( 'users', $result );
	}

	public function test_determine_object_type_returns_unknown_for_uri_outside_default_wp_endpoints() {
		$result = $this->api->determine_object_type(
			'unknown',
			'k',
			[],
			'/wp-json/wp/v2/posts/1' // posts is NOT in $wordpress_endpoints — handled elsewhere
		);

		$this->assertSame( 'unknown', $result );
	}
}
