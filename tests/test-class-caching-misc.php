<?php
/**
 * Tests for the remaining Caching utilities: get_cache_data / get_cache_row,
 * insert_cache_relation, get_global_cacheable_request_headers, transient_key.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching
 */
class Test_Caching_Misc extends Caching_Test_Case {

	public function test_transient_key_prefixes_with_wp_rest_cache_underscore() {
		$this->assertSame(
			'wp_rest_cache_abc123',
			Caching::get_instance()->transient_key( 'abc123' )
		);
	}

	public function test_transient_key_accepts_integer_ids() {
		$this->assertSame(
			'wp_rest_cache_42',
			Caching::get_instance()->transient_key( 42 )
		);
	}

	public function test_global_cacheable_request_headers_defaults_to_empty_string() {
		delete_option( 'wp_rest_cache_global_cacheable_request_headers' );

		$this->assertSame(
			'',
			Caching::get_instance()->get_global_cacheable_request_headers()
		);
	}

	public function test_global_cacheable_request_headers_returns_option_value() {
		update_option(
			'wp_rest_cache_global_cacheable_request_headers',
			'Authorization,X-Custom'
		);

		$this->assertSame(
			'Authorization,X-Custom',
			Caching::get_instance()->get_global_cacheable_request_headers()
		);
	}

	public function test_insert_cache_relation_writes_a_row() {
		$cache_id = $this->insert_cache();

		Caching::get_instance()->insert_cache_relation( $cache_id, 42, 'post' );

		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT object_id, object_type FROM `{$wpdb->prefix}wrc_relations` WHERE cache_id = %d",
				$cache_id
			),
			ARRAY_A
		);
		$this->assertSame( [ 'object_id' => '42', 'object_type' => 'post' ], $row );
	}

	public function test_insert_cache_relation_uses_replace_so_duplicates_collapse_into_one_row() {
		$cache_id = $this->insert_cache();

		Caching::get_instance()->insert_cache_relation( $cache_id, 42, 'post' );
		Caching::get_instance()->insert_cache_relation( $cache_id, 42, 'post' );
		Caching::get_instance()->insert_cache_relation( $cache_id, 42, 'post' );

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}wrc_relations` WHERE cache_id = %d",
				$cache_id
			)
		);
		$this->assertSame( 1, $count );
	}

	public function test_insert_cache_relation_short_circuits_when_object_id_is_array() {
		$cache_id = $this->insert_cache();

		Caching::get_instance()->insert_cache_relation( $cache_id, [ 1, 2, 3 ], 'post' );

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}wrc_relations` WHERE cache_id = %d",
				$cache_id
			)
		);
		$this->assertSame( 0, $count );
	}

	public function test_insert_cache_relation_short_circuits_when_object_type_is_array() {
		$cache_id = $this->insert_cache();

		Caching::get_instance()->insert_cache_relation( $cache_id, 42, [ 'post' ] );

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}wrc_relations` WHERE cache_id = %d",
				$cache_id
			)
		);
		$this->assertSame( 0, $count );
	}

	public function test_insert_cache_relation_fires_insert_action_with_args() {
		$cache_id = $this->insert_cache();

		$captured = null;
		add_action(
			'wp_rest_cache/insert_cache_relation',
			function ( $cid, $oid, $otype ) use ( &$captured ) {
				$captured = [ $cid, $oid, $otype ];
			},
			10,
			3
		);

		Caching::get_instance()->insert_cache_relation( $cache_id, 42, 'post' );

		$this->assertSame( [ $cache_id, 42, 'post' ], $captured );
	}

	public function test_get_cache_data_returns_active_payload_when_transient_present() {
		$cache_key = 'active';
		$this->insert_cache(
			[
				'cache_key'  => $cache_key,
				'expiration' => '2099-01-01 00:00:00',
			]
		);
		set_transient(
			Caching::get_instance()->transient_key( $cache_key ),
			[ 'data' => [ 'id' => 1, 'type' => 'post' ] ],
			HOUR_IN_SECONDS
		);

		$result = Caching::get_instance()->get_cache_data( $cache_key );

		$this->assertNotNull( $result );
		$this->assertTrue( $result['row']['is_active'] );
		$this->assertSame( '2099-01-01 00:00:00', $result['row']['expiration'] );
		$this->assertSame( 1, $result['data']['data']['id'] );
		$this->assertSame( 'post', $result['data']['data']['type'] );
	}

	public function test_get_cache_data_returns_inactive_with_false_data_when_transient_missing() {
		$cache_key = 'no-transient';
		$this->insert_cache(
			[
				'cache_key'  => $cache_key,
				'expiration' => '2099-01-01 00:00:00',
			]
		);

		$result = Caching::get_instance()->get_cache_data( $cache_key );

		$this->assertFalse( $result['row']['is_active'] );
		// `false` survives the json_decode(wp_json_encode(...)) round-trip ("false" → false).
		$this->assertFalse( $result['data'] );
	}

	public function test_get_cache_data_renders_flushed_label_when_row_uses_sentinel_expiration() {
		$cache_key = 'flushed';
		$this->insert_cache(
			[
				'cache_key'  => $cache_key,
				'expiration' => date_i18n( 'Y-m-d H:i:s', 1 ),
			]
		);

		$result = Caching::get_instance()->get_cache_data( $cache_key );

		$this->assertFalse( $result['row']['is_active'] );
		$this->assertSame( 'Flushed', $result['row']['expiration'] );
	}

	public function test_get_cache_data_renders_expired_label_when_transient_missing_and_not_flushed() {
		$cache_key = 'expired-natural';
		$this->insert_cache(
			[
				'cache_key'  => $cache_key,
				'expiration' => '2000-01-01 00:00:00',
			]
		);

		$result = Caching::get_instance()->get_cache_data( $cache_key );

		$this->assertSame( 'Expired', $result['row']['expiration'] );
	}

	public function test_get_cache_data_renders_unlimited_label_for_active_zero_expiration() {
		$cache_key = 'unlimited';
		$this->insert_cache(
			[
				'cache_key'  => $cache_key,
				'expiration' => '1970-01-01 00:00:00',
			]
		);
		set_transient(
			Caching::get_instance()->transient_key( $cache_key ),
			[ 'data' => [] ],
			0
		);

		$result = Caching::get_instance()->get_cache_data( $cache_key );

		$this->assertTrue( $result['row']['is_active'] );
		$this->assertSame( 'Unlimited', $result['row']['expiration'] );
	}

	public function test_get_cache_data_returns_null_for_unknown_key() {
		$result = Caching::get_instance()->get_cache_data( 'does-not-exist' );

		$this->assertNull( $result );
	}

	public function test_get_cache_returns_false_when_transient_exists_but_cache_row_does_not() {
		// Edge case from production: a transient sticks around (e.g. after a manual cache
		// table reset) without a matching row in wrc_caches. register_cache_hit then has
		// nothing to update and returns 0, which get_cache must treat as a miss.
		$cache_key = 'orphaned-transient-key';
		set_transient(
			Caching::get_instance()->transient_key( $cache_key ),
			[ 'data' => 'should-be-ignored' ],
			60
		);

		$result = Caching::get_instance()->get_cache( $cache_key );

		$this->assertFalse( $result );

		delete_transient( Caching::get_instance()->transient_key( $cache_key ) );
	}

	public function test_get_instance_constructs_and_returns_a_new_singleton_when_none_exists() {
		// Most tests use the singleton that bootstrapped early; the "first call" branch in
		// get_instance() is therefore not naturally exercised. Reset the static via
		// reflection and call get_instance() so the `new Caching()` path (plus the
		// constructor body that initializes db_table_caches/db_table_relations) executes.
		$original_instance = Caching::get_instance();

		$reflection = new ReflectionClass( Caching::class );
		$prop       = $reflection->getProperty( 'instance' );
		$prop->setAccessible( true );
		$prop->setValue( null, null );

		try {
			$rebuilt = Caching::get_instance();

			$this->assertInstanceOf( Caching::class, $rebuilt );
			$this->assertNotSame(
				$original_instance,
				$rebuilt,
				'Resetting the static then calling get_instance must produce a fresh singleton'
			);

			// The constructor pulls $wpdb->prefix into db_table_caches — verify it was set
			// so we know the constructor body ran (not just the property declaration).
			global $wpdb;
			$expected = $wpdb->prefix . Caching::TABLE_CACHES;
			$ref      = new ReflectionProperty( $rebuilt, 'db_table_caches' );
			$ref->setAccessible( true );
			$this->assertSame( $expected, $ref->getValue( $rebuilt ) );
		} finally {
			// Restore so subsequent tests see the original instance + its captured state.
			$prop->setValue( null, $original_instance );
		}
	}
}
