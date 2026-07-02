<?php
/**
 * Tests for Oembed_Api — the wiring that opts the /oembed/1.0/embed endpoint into the
 * plugin's caching layer, plus the oembed-specific overrides for object-type detection,
 * single-item flag, and relation insertion.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\API\Oembed_Api;
use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\API\Oembed_Api
 */
class Test_Oembed_Api extends Caching_Test_Case {

	const OEMBED_URI = '/wp-json/oembed/1.0/embed?url=https%3A%2F%2Fexample.org%2Fhello';
	const NON_OEMBED_URI = '/wp-json/wp/v2/posts/42';

	/** @var Oembed_Api */
	private $api;

	public function set_up() {
		parent::set_up();
		$this->api = new Oembed_Api();
	}

	// ---------- add_oembed_endpoint ----------

	public function test_add_oembed_endpoint_adds_to_empty_allowlist() {
		$result = $this->api->add_oembed_endpoint( [] );

		$this->assertSame( [ 'oembed/1.0' => [ 'embed' ] ], $result );
	}

	public function test_add_oembed_endpoint_is_idempotent_when_already_present() {
		$existing = [ 'oembed/1.0' => [ 'embed' ] ];

		$result = $this->api->add_oembed_endpoint( $existing );

		$this->assertSame( $existing, $result );
	}

	public function test_add_oembed_endpoint_appends_to_existing_oembed_namespace() {
		$existing = [ 'oembed/1.0' => [ 'proxy' ] ];

		$result = $this->api->add_oembed_endpoint( $existing );

		$this->assertSame(
			[ 'oembed/1.0' => [ 'proxy', 'embed' ] ],
			$result
		);
	}

	public function test_add_oembed_endpoint_preserves_other_namespaces() {
		$existing = [ 'wp/v2' => [ 'posts', 'pages' ] ];

		$result = $this->api->add_oembed_endpoint( $existing );

		$this->assertSame(
			[
				'wp/v2'      => [ 'posts', 'pages' ],
				'oembed/1.0' => [ 'embed' ],
			],
			$result
		);
	}

	// ---------- determine_object_type ----------

	public function test_determine_object_type_passes_through_already_determined_type() {
		// First arg is the upstream-determined type — if it's not 'unknown', oembed-specific
		// logic must not overwrite it.
		$result = $this->api->determine_object_type( 'post', 'k', [], self::OEMBED_URI );

		$this->assertSame( 'post', $result );
	}

	public function test_determine_object_type_resolves_to_post_type_for_oembed_uri_with_post() {
		$post_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		// In CLI, url_to_postid won't resolve a foreign URL; pin via the documented filter.
		$this->override_oembed_post_id( $post_id );

		$result = $this->api->determine_object_type( 'unknown', 'k', [], self::OEMBED_URI );

		$this->assertSame( 'page', $result );
	}

	public function test_determine_object_type_returns_unknown_when_no_matching_post() {
		// Filter explicitly returns no post → fallback to 'unknown'.
		$this->override_oembed_post_id( 0 );

		$result = $this->api->determine_object_type( 'unknown', 'k', [], self::OEMBED_URI );

		$this->assertSame( 'unknown', $result );
	}

	public function test_determine_object_type_returns_unknown_when_uri_is_not_oembed() {
		// Non-oembed URI → get_oembed_post_id bails early → caller stays 'unknown'.
		$result = $this->api->determine_object_type( 'unknown', 'k', [], self::NON_OEMBED_URI );

		$this->assertSame( 'unknown', $result );
	}

	// ---------- is_single_oembed_item ----------

	public function test_is_single_oembed_item_returns_true_for_oembed_uri() {
		$this->assertTrue( $this->api->is_single_oembed_item( false, [], self::OEMBED_URI ) );
		$this->assertTrue( $this->api->is_single_oembed_item( true, [], self::OEMBED_URI ) );
	}

	public function test_is_single_oembed_item_passes_through_for_non_oembed_uri() {
		$this->assertTrue( $this->api->is_single_oembed_item( true, [], self::NON_OEMBED_URI ) );
		$this->assertFalse( $this->api->is_single_oembed_item( false, [], self::NON_OEMBED_URI ) );
	}

	// ---------- process_cache_relations ----------

	public function test_process_cache_relations_inserts_relation_with_resolved_post_type() {
		$post_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$this->override_oembed_post_id( $post_id );

		$cache_id = $this->insert_cache();

		$this->api->process_cache_relations(
			$cache_id,
			[],                  // $data — unused by this method
			'unknown',           // $object_type — overwritten when a post type is resolved
			self::OEMBED_URI
		);

		$this->assertRelationExists( $cache_id, $post_id, 'page' );
	}

	public function test_process_cache_relations_does_nothing_when_uri_is_not_oembed() {
		$cache_id = $this->insert_cache();

		$this->api->process_cache_relations( $cache_id, [], 'unknown', self::NON_OEMBED_URI );

		$this->assertSame( 0, $this->count_relations_for( $cache_id ) );
	}

	public function test_process_cache_relations_does_nothing_when_no_matching_post() {
		$this->override_oembed_post_id( 0 );
		$cache_id = $this->insert_cache();

		$this->api->process_cache_relations( $cache_id, [], 'unknown', self::OEMBED_URI );

		$this->assertSame( 0, $this->count_relations_for( $cache_id ) );
	}

	public function test_get_oembed_post_id_resolves_a_full_url_param_via_url_to_postid() {
		// Regression: before the URL-concatenation fix, a full `url=https://example.org/...`
		// param was concatenated onto home_url and produced a malformed string that
		// url_to_postid() couldn't resolve. With the fix the full URL is passed through
		// untouched.
		$post_id   = self::factory()->post->create( [ 'post_type' => 'page' ] );
		$permalink = get_permalink( $post_id );
		$uri       = '/wp-json/oembed/1.0/embed?url=' . rawurlencode( $permalink );

		// No filter override — relying on url_to_postid() to do the real resolution.
		$result = $this->api->determine_object_type( 'unknown', 'k', [], $uri );

		$this->assertSame( 'page', $result );
	}

	public function test_oembed_request_post_id_filter_can_override_post_resolution() {
		// Demonstrates the public contract: consumers can intercept post-id resolution to
		// support custom URL structures (multisite path-prefixed, headless front-ends, etc.).
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );
		add_filter(
			'oembed_request_post_id',
			fn() => $page_id
		);

		$result = $this->api->determine_object_type( 'unknown', 'k', [], self::OEMBED_URI );

		$this->assertSame( 'page', $result );
	}

	// ----- helpers -----

	/**
	 * Hook the oembed_request_post_id filter to return a known value, bypassing the in-CLI
	 * fragility of url_to_postid() on cross-origin URLs.
	 */
	private function override_oembed_post_id( $post_id ) {
		add_filter(
			'oembed_request_post_id',
			fn() => $post_id
		);
	}

	private function count_relations_for( $cache_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}wrc_relations` WHERE cache_id = %d",
				$cache_id
			)
		);
	}

	private function assertRelationExists( $cache_id, $object_id, $object_type ) {
		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}wrc_relations`
				 WHERE cache_id = %d AND object_id = %s AND object_type = %s",
				$cache_id,
				(string) $object_id,
				$object_type
			)
		);
		$this->assertSame(
			1,
			$count,
			"Expected one relation (cache={$cache_id}, object={$object_id}, type={$object_type})"
		);
	}
}
