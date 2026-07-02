<?php
/**
 * Tests for delete_cache_by_endpoint and its three strictness modes.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching::delete_cache_by_endpoint
 */
class Test_Caching_Delete_By_Endpoint extends Caching_Test_Case {

	// ---------- FLUSH_STRICT ----------

	public function test_strict_flushes_exact_path_match() {
		$match     = $this->insert_cache( [ 'request_uri' => '/wp/v2/posts' ] );
		$different = $this->insert_cache( [ 'request_uri' => '/wp/v2/posts/42' ] );

		$result = Caching::get_instance()->delete_cache_by_endpoint(
			'/wp/v2/posts',
			Caching::FLUSH_STRICT
		);

		$this->assertTrue( $result );
		$this->assertExpired( $match );
		$this->assertNotExpired( $different );
	}

	public function test_strict_normalizes_trailing_slash_in_input() {
		// The plugin rtrim()s the trailing slash from the input path before matching.
		$match = $this->insert_cache( [ 'request_uri' => '/wp/v2/posts' ] );

		$result = Caching::get_instance()->delete_cache_by_endpoint(
			'/wp/v2/posts/',
			Caching::FLUSH_STRICT
		);

		$this->assertTrue( $result );
		$this->assertExpired( $match );
	}

	public function test_strict_with_query_canonicalizes_params_by_sorting() {
		// Query params are ksort()ed before matching, so order in the input doesn't matter
		// as long as the stored URI was canonicalized the same way.
		$match = $this->insert_cache( [ 'request_uri' => '/wp/v2/posts?a=1&b=2' ] );

		$result = Caching::get_instance()->delete_cache_by_endpoint(
			'/wp/v2/posts?b=2&a=1',
			Caching::FLUSH_STRICT
		);

		$this->assertTrue( $result );
		$this->assertExpired( $match );
	}

	public function test_strict_does_not_match_path_with_different_query() {
		$other = $this->insert_cache( [ 'request_uri' => '/wp/v2/posts?a=1' ] );

		$result = Caching::get_instance()->delete_cache_by_endpoint(
			'/wp/v2/posts?a=2',
			Caching::FLUSH_STRICT
		);

		$this->assertFalse( $result );
		$this->assertNotExpired( $other );
	}

	// ---------- FLUSH_PARAMS ----------

	public function test_params_matches_any_query_string_on_same_path() {
		$with_query_1 = $this->insert_cache( [ 'request_uri' => '/wp/v2/posts?page=1' ] );
		$with_query_2 = $this->insert_cache( [ 'request_uri' => '/wp/v2/posts?per_page=10' ] );

		$result = Caching::get_instance()->delete_cache_by_endpoint(
			'/wp/v2/posts',
			Caching::FLUSH_PARAMS
		);

		$this->assertTrue( $result );
		$this->assertExpired( $with_query_1 );
		$this->assertExpired( $with_query_2 );
	}

	public function test_params_does_not_match_bare_path_without_query() {
		// FLUSH_PARAMS LIKEs `path?%` — the `?` is mandatory.
		$bare = $this->insert_cache( [ 'request_uri' => '/wp/v2/posts' ] );

		$result = Caching::get_instance()->delete_cache_by_endpoint(
			'/wp/v2/posts',
			Caching::FLUSH_PARAMS
		);

		$this->assertFalse( $result );
		$this->assertNotExpired( $bare );
	}

	public function test_params_does_not_match_subpath() {
		$subpath = $this->insert_cache( [ 'request_uri' => '/wp/v2/posts/42' ] );

		$result = Caching::get_instance()->delete_cache_by_endpoint(
			'/wp/v2/posts',
			Caching::FLUSH_PARAMS
		);

		$this->assertFalse( $result );
		$this->assertNotExpired( $subpath );
	}

	// ---------- FLUSH_LOOSE ----------

	public function test_loose_matches_bare_path() {
		$bare = $this->insert_cache( [ 'request_uri' => '/wp/v2/posts' ] );

		$result = Caching::get_instance()->delete_cache_by_endpoint(
			'/wp/v2/posts',
			Caching::FLUSH_LOOSE
		);

		$this->assertTrue( $result );
		$this->assertExpired( $bare );
	}

	public function test_loose_matches_subpaths_and_query_strings() {
		$bare       = $this->insert_cache( [ 'request_uri' => '/wp/v2/posts' ] );
		$with_query = $this->insert_cache( [ 'request_uri' => '/wp/v2/posts?page=1' ] );
		$subpath    = $this->insert_cache( [ 'request_uri' => '/wp/v2/posts/42' ] );
		$unrelated  = $this->insert_cache( [ 'request_uri' => '/wp/v2/pages' ] );

		$result = Caching::get_instance()->delete_cache_by_endpoint(
			'/wp/v2/posts',
			Caching::FLUSH_LOOSE
		);

		$this->assertTrue( $result );
		$this->assertExpired( $bare );
		$this->assertExpired( $with_query );
		$this->assertExpired( $subpath );
		$this->assertNotExpired( $unrelated );
	}

	// ---------- Common behavior ----------

	public function test_default_strictness_is_strict() {
		$exact   = $this->insert_cache( [ 'request_uri' => '/wp/v2/posts' ] );
		$subpath = $this->insert_cache( [ 'request_uri' => '/wp/v2/posts/42' ] );

		Caching::get_instance()->delete_cache_by_endpoint( '/wp/v2/posts' );

		$this->assertExpired( $exact );
		$this->assertNotExpired( $subpath );
	}

	public function test_force_true_sets_deleted_flag() {
		$cache_id = $this->insert_cache( [ 'request_uri' => '/wp/v2/posts' ] );

		Caching::get_instance()->delete_cache_by_endpoint(
			'/wp/v2/posts',
			Caching::FLUSH_STRICT,
			true
		);

		$this->assertSame( '1', $this->column_value( $cache_id, 'deleted' ) );
	}

	public function test_force_false_preserves_existing_deleted_flag() {
		// A non-force flush should not resurrect a previously soft-deleted cache row.
		$cache_id = $this->insert_cache(
			[
				'request_uri' => '/wp/v2/posts',
				'deleted'     => 1,
			]
		);

		Caching::get_instance()->delete_cache_by_endpoint( '/wp/v2/posts' );

		$this->assertSame( '1', $this->column_value( $cache_id, 'deleted' ) );
	}

	public function test_returns_false_when_no_rows_match() {
		$this->insert_cache( [ 'request_uri' => '/wp/v2/pages' ] );

		$result = Caching::get_instance()->delete_cache_by_endpoint(
			'/wp/v2/posts',
			Caching::FLUSH_STRICT
		);

		$this->assertFalse( $result );
	}

	public function test_invalid_strictness_returns_wp_error() {
		$cache_id = $this->insert_cache( [ 'request_uri' => '/wp/v2/posts' ] );

		$result = Caching::get_instance()->delete_cache_by_endpoint(
			'/wp/v2/posts',
			'not-a-real-strictness'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wp_rest_cache_invalid_strictness', $result->get_error_code() );
		$this->assertNotExpired( $cache_id );
	}
}
