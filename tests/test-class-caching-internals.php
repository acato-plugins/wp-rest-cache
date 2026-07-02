<?php
/**
 * Coverage-targeted tests for Caching's private internals: register_cache_hit,
 * insert_cache_row, update_cache_expiration, upgrade_2019_4_0, determine_object_type.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching
 */
class Test_Caching_Internals extends Caching_Test_Case {

	public function test_register_cache_hit_increments_the_cache_hits_column() {
		$cache_id = $this->insert_cache( [ 'cache_key' => 'hit-test', 'cache_hits' => 5 ] );

		$result = $this->invoke_private(
			Caching::get_instance(),
			'register_cache_hit',
			[ 'hit-test' ]
		);

		$this->assertSame( 1, (int) $result );
		$this->assertSame( '6', $this->column_value( $cache_id, 'cache_hits' ) );
	}

	public function test_register_cache_hit_returns_zero_for_unknown_cache_key() {
		$result = $this->invoke_private(
			Caching::get_instance(),
			'register_cache_hit',
			[ 'no-such-key' ]
		);

		$this->assertSame( 0, (int) $result );
	}

	public function test_insert_cache_row_writes_a_row_with_the_expected_columns() {
		$cache_id = $this->invoke_private(
			Caching::get_instance(),
			'insert_cache_row',
			[
				'fresh-key',
				'endpoint',
				'/wp-json/wp/v2/posts',
				'post',
				1,
				[ 'X-Custom' => 'value' ],
				'POST',
			]
		);

		$this->assertGreaterThan( 0, (int) $cache_id );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT cache_key, cache_type, request_uri, object_type, is_single,
				 request_method, request_headers, cache_hits FROM `{$wpdb->prefix}wrc_caches`
				 WHERE cache_id = %d",
				$cache_id
			),
			ARRAY_A
		);
		$this->assertSame( 'fresh-key', $row['cache_key'] );
		$this->assertSame( 'endpoint', $row['cache_type'] );
		$this->assertSame( '/wp-json/wp/v2/posts', $row['request_uri'] );
		$this->assertSame( 'post', $row['object_type'] );
		$this->assertSame( '1', $row['is_single'] );
		$this->assertSame( 'POST', $row['request_method'] );
		$this->assertSame( wp_json_encode( [ 'X-Custom' => 'value' ] ), $row['request_headers'] );
		$this->assertSame( '1', $row['cache_hits'] );
	}

	public function test_insert_cache_row_deprecates_non_endpoint_cache_type() {
		$this->setExpectedDeprecated( 'insert_cache_row' );

		$this->invoke_private(
			Caching::get_instance(),
			'insert_cache_row',
			[ 'k', 'item', '/uri', 'post', 1, [], 'GET' ]
		);
	}

	public function test_update_cache_expiration_renew_sets_future_expiration_and_clears_deleted_flag() {
		$cache_id = $this->insert_cache(
			[
				'expiration' => '2000-01-01 00:00:00',
				'deleted'    => 1,
			]
		);

		$this->invoke_private(
			Caching::get_instance(),
			'update_cache_expiration',
			[ $cache_id, null, false, [ 'uri' => '/x' ] ]
		);

		$this->assertSame( '0', $this->column_value( $cache_id, 'deleted' ) );
		$this->assertGreaterThan(
			strtotime( '2000-01-01 00:00:00' ),
			strtotime( $this->column_value( $cache_id, 'expiration' ) )
		);
	}

	public function test_update_cache_expiration_renew_adds_current_time_when_memcache_is_used() {
		update_option( 'wp_rest_cache_memcache_used', '1' );
		update_option( 'wp_rest_cache_timeout', 1 );
		update_option( 'wp_rest_cache_timeout_interval', HOUR_IN_SECONDS );

		$cache_id = $this->insert_cache( [ 'expiration' => '2000-01-01 00:00:00' ] );

		$before = time();
		$this->invoke_private(
			Caching::get_instance(),
			'update_cache_expiration',
			[ $cache_id, null, false, [] ]
		);
		$after = time();

		$expiration_ts = strtotime( $this->column_value( $cache_id, 'expiration' ) );
		$this->assertGreaterThanOrEqual( $before + HOUR_IN_SECONDS - 1, $expiration_ts );
		$this->assertLessThanOrEqual( $after + HOUR_IN_SECONDS + 1, $expiration_ts );
	}

	public function test_update_cache_expiration_explicit_expiration_does_not_touch_deleted_flag() {
		$cache_id = $this->insert_cache( [ 'deleted' => 1 ] );

		$this->invoke_private(
			Caching::get_instance(),
			'update_cache_expiration',
			[ $cache_id, '2099-01-01 00:00:00', true ]
		);

		$this->assertSame( '1', $this->column_value( $cache_id, 'deleted' ) );
		$this->assertSame( '1', $this->column_value( $cache_id, 'cleaned' ) );
	}

	public function test_update_database_structure_runs_upgrade_2019_4_0_for_pre_2019_4_0_versions() {
		$item_cache_id = $this->insert_cache( [ 'cache_type' => 'item' ] );
		update_option( 'wp_rest_cache_database_version', '2019.3.0' );

		Caching::get_instance()->update_database_structure();

		global $wpdb;
		$still_there = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}wrc_caches` WHERE cache_id = %d",
				$item_cache_id
			)
		);
		$this->assertSame( 0, $still_there );
	}

	public function test_determine_object_type_for_collection_with_type_returns_type() {
		$result = $this->invoke_private(
			Caching::get_instance(),
			'determine_object_type',
			[
				[
					'data' => [
						[ 'id' => 1, 'type' => 'post', 'slug' => 'a' ],
						[ 'id' => 2, 'type' => 'post', 'slug' => 'b' ],
					],
				],
			]
		);

		$this->assertSame( 'post', $result );
	}

	public function test_determine_object_type_for_collection_with_taxonomy_returns_taxonomy() {
		$result = $this->invoke_private(
			Caching::get_instance(),
			'determine_object_type',
			[
				[
					'data' => [
						[ 'id' => 1, 'taxonomy' => 'category', 'name' => 'cat' ],
						[ 'id' => 2, 'taxonomy' => 'category', 'name' => 'dog' ],
					],
				],
			]
		);

		$this->assertSame( 'category', $result );
	}

	public function test_determine_object_type_for_collection_with_no_type_field_returns_unknown() {
		$result = $this->invoke_private(
			Caching::get_instance(),
			'determine_object_type',
			[
				[
					'data' => [
						[ 'id' => 1, 'title' => 'no-type-here' ],
					],
				],
			]
		);

		$this->assertSame( 'unknown', $result );
	}

	public function test_determine_object_type_for_single_item_with_taxonomy_field_returns_taxonomy() {
		$result = $this->invoke_private(
			Caching::get_instance(),
			'determine_object_type',
			[
				[ 'data' => [ 'id' => 1, 'taxonomy' => 'post_tag' ] ],
			]
		);

		$this->assertSame( 'post_tag', $result );
	}
}
