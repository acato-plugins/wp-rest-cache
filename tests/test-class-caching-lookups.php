<?php
/**
 * Tests for the simple lookup helpers on the Caching class.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching
 */
class Test_Caching_Lookups extends Caching_Test_Case {

	public function tear_down() {
		unset( $_POST['s'], $_GET['s'], $_GET['orderby'], $_GET['order'] );
		parent::tear_down();
	}

	public function test_get_cache_row_id_returns_id_for_existing_cache_key() {
		$cache_id = $this->insert_cache( [ 'cache_key' => 'known-key' ] );

		$result = $this->invoke_private(
			Caching::get_instance(),
			'get_cache_row_id',
			[ 'known-key' ]
		);

		$this->assertSame( $cache_id, (int) $result );
	}

	public function test_get_cache_row_id_returns_null_for_unknown_cache_key() {
		$result = $this->invoke_private(
			Caching::get_instance(),
			'get_cache_row_id',
			[ 'does-not-exist' ]
		);

		$this->assertNull( $result );
	}

	public function test_get_cache_expiration_returns_stored_expiration_for_existing_key() {
		$this->insert_cache(
			[
				'cache_key'  => 'with-expiration',
				'expiration' => '2030-06-15 12:34:56',
			]
		);

		$result = $this->invoke_private(
			Caching::get_instance(),
			'get_cache_expiration',
			[ 'with-expiration' ]
		);

		$this->assertSame( '2030-06-15 12:34:56', $result );
	}

	public function test_get_cache_expiration_returns_null_for_unknown_key() {
		$result = $this->invoke_private(
			Caching::get_instance(),
			'get_cache_expiration',
			[ 'does-not-exist' ]
		);

		$this->assertNull( $result );
	}

	public function test_get_record_count_returns_zero_when_no_rows() {
		$this->assertSame( 0, Caching::get_instance()->get_record_count( 'endpoint' ) );
	}

	public function test_get_record_count_counts_only_matching_api_type() {
		$this->insert_cache( [ 'cache_type' => 'endpoint' ] );
		$this->insert_cache( [ 'cache_type' => 'endpoint' ] );
		$this->insert_cache( [ 'cache_type' => 'item' ] );

		$this->assertSame( 2, Caching::get_instance()->get_record_count( 'endpoint' ) );
	}

	public function test_get_record_count_excludes_soft_deleted_rows() {
		$this->insert_cache( [ 'cache_type' => 'endpoint', 'deleted' => 0 ] );
		$this->insert_cache( [ 'cache_type' => 'endpoint', 'deleted' => 1 ] );

		$this->assertSame( 1, Caching::get_instance()->get_record_count( 'endpoint' ) );
	}

	public function test_get_record_count_honors_search_param_against_request_uri_or_object_type() {
		$this->insert_cache( [ 'request_uri' => '/wp-json/wp/v2/posts', 'object_type' => 'post' ] );
		$this->insert_cache( [ 'request_uri' => '/wp-json/wp/v2/pages', 'object_type' => 'page' ] );
		$this->insert_cache( [ 'request_uri' => '/wp-json/wp/v2/users', 'object_type' => 'user' ] );

		$_GET['s'] = 'posts';
		$this->assertSame( 1, Caching::get_instance()->get_record_count( 'endpoint' ) );

		$_GET['s'] = 'page';
		$this->assertSame( 1, Caching::get_instance()->get_record_count( 'endpoint' ) );

		$_GET['s'] = 'nothing-matches';
		$this->assertSame( 0, Caching::get_instance()->get_record_count( 'endpoint' ) );
	}

	public function test_get_api_data_returns_only_matching_api_type_and_excludes_deleted() {
		$endpoint_id = $this->insert_cache( [ 'cache_type' => 'endpoint' ] );
		$this->insert_cache( [ 'cache_type' => 'endpoint', 'deleted' => 1 ] );
		$this->insert_cache( [ 'cache_type' => 'item' ] );

		$results = Caching::get_instance()->get_api_data( 'endpoint', 10, 1 );

		$this->assertCount( 1, $results );
		$this->assertSame( (string) $endpoint_id, $results[0]['cache_id'] );
	}

	public function test_get_api_data_default_order_is_cache_id_desc() {
		$first  = $this->insert_cache();
		$second = $this->insert_cache();
		$third  = $this->insert_cache();

		$results = Caching::get_instance()->get_api_data( 'endpoint', 10, 1 );

		$ids = array_map( 'intval', array_column( $results, 'cache_id' ) );
		$this->assertSame( [ $third, $second, $first ], $ids );
	}

	public function test_get_api_data_paginates_via_per_page_and_page_number() {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->insert_cache();
		}

		$page_1 = Caching::get_instance()->get_api_data( 'endpoint', 2, 1 );
		$page_2 = Caching::get_instance()->get_api_data( 'endpoint', 2, 2 );
		$page_3 = Caching::get_instance()->get_api_data( 'endpoint', 2, 3 );

		$this->assertCount( 2, $page_1 );
		$this->assertCount( 2, $page_2 );
		$this->assertCount( 1, $page_3 );

		$all_ids = array_merge(
			array_column( $page_1, 'cache_id' ),
			array_column( $page_2, 'cache_id' ),
			array_column( $page_3, 'cache_id' )
		);
		$this->assertCount( 5, array_unique( $all_ids ) );
	}

	public function test_get_api_data_renders_active_cache_with_transient_present() {
		$cache_key = 'active-key';
		$this->insert_cache(
			[
				'cache_key'  => $cache_key,
				'expiration' => '2099-01-01 00:00:00',
			]
		);
		set_transient( Caching::get_instance()->transient_key( $cache_key ), 'payload', HOUR_IN_SECONDS );

		$results = Caching::get_instance()->get_api_data( 'endpoint', 10, 1 );

		$this->assertTrue( $results[0]['is_active'] );
		$this->assertSame( '2099-01-01 00:00:00', $results[0]['expiration'] );
	}

	public function test_get_api_data_renders_expiration_label_for_flushed_row() {
		$this->insert_cache(
			[
				'cache_key'  => 'flushed-key',
				'expiration' => gmdate( 'Y-m-d H:i:s', 1 ),
			]
		);

		$results = Caching::get_instance()->get_api_data( 'endpoint', 10, 1 );

		$this->assertFalse( $results[0]['is_active'] );
		$this->assertSame( 'Flushed', $results[0]['expiration'] );
	}

	public function test_get_api_data_renders_expiration_label_for_expired_row() {
		$this->insert_cache(
			[
				'cache_key'  => 'expired-key',
				'expiration' => '2000-01-01 00:00:00',
			]
		);

		$results = Caching::get_instance()->get_api_data( 'endpoint', 10, 1 );

		$this->assertFalse( $results[0]['is_active'] );
		$this->assertSame( 'Expired', $results[0]['expiration'] );
	}

	public function test_get_api_data_renders_unlimited_label_for_zero_expiration() {
		$this->insert_cache(
			[
				'cache_key'  => 'unlimited-key',
				'expiration' => '1970-01-01 00:00:00',
			]
		);
		set_transient( Caching::get_instance()->transient_key( 'unlimited-key' ), 'payload', 0 );

		$results = Caching::get_instance()->get_api_data( 'endpoint', 10, 1 );

		$this->assertSame( 'Unlimited', $results[0]['expiration'] );
	}

	public function test_get_api_data_honors_orderby_get_param_with_whitelist() {
		$this->insert_cache( [ 'cache_key' => 'aaa', 'request_uri' => '/zzz' ] );
		$this->insert_cache( [ 'cache_key' => 'bbb', 'request_uri' => '/yyy' ] );
		$this->insert_cache( [ 'cache_key' => 'ccc', 'request_uri' => '/xxx' ] );

		$_GET['orderby'] = 'cache_key';
		$_GET['order']   = 'asc';

		$results = Caching::get_instance()->get_api_data( 'endpoint', 10, 1 );

		$keys = array_column( $results, 'cache_key' );
		$this->assertSame( [ 'aaa', 'bbb', 'ccc' ], $keys );
	}

	public function test_get_api_data_ignores_orderby_outside_whitelist() {
		$first  = $this->insert_cache();
		$second = $this->insert_cache();

		$_GET['orderby'] = 'not_a_real_column';
		$_GET['order']   = 'asc';

		$results = Caching::get_instance()->get_api_data( 'endpoint', 10, 1 );

		$ids = array_map( 'intval', array_column( $results, 'cache_id' ) );
		$this->assertSame( [ $second, $first ], $ids );
	}

	public function test_get_api_data_applies_table_data_filter() {
		$this->insert_cache( [ 'cache_key' => 'filtered' ] );

		add_filter(
			'wp_rest_cache/api_caches_table_data',
			function ( $results, $api_type ) {
				foreach ( $results as &$result ) {
					$result['custom_field'] = "from-filter-{$api_type}";
				}
				return $results;
			},
			10,
			2
		);

		$results = Caching::get_instance()->get_api_data( 'endpoint', 10, 1 );

		$this->assertSame( 'from-filter-endpoint', $results[0]['custom_field'] );
	}
}
