<?php
/**
 * Tests for delete_all_caches and the wp_rest_cache/filtered_cache_keys filter.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching
 */
class Test_Caching_Delete_All extends Caching_Test_Case {

	const CRON_HOOK = 'wp_rest_cache_cleanup_deleted_caches';

	public function set_up() {
		parent::set_up();
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function tear_down() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		parent::tear_down();
	}

	public function test_delete_false_marks_all_rows_expired_and_preserves_existing_deleted_flag() {
		$a = $this->insert_cache( [ 'object_type' => 'post', 'deleted' => 0 ] );
		$b = $this->insert_cache( [ 'object_type' => 'post', 'deleted' => 1 ] );

		Caching::get_instance()->delete_all_caches( false );

		$this->assertExpired( $a );
		$this->assertExpired( $b );
		$this->assertSame( '0', $this->column_value( $a, 'deleted' ) );
		$this->assertSame( '1', $this->column_value( $b, 'deleted' ) );
	}

	public function test_delete_false_promotes_unknown_object_type_rows_to_deleted_one() {
		$known   = $this->insert_cache( [ 'object_type' => 'post', 'deleted' => 0 ] );
		$unknown = $this->insert_cache( [ 'object_type' => 'unknown', 'deleted' => 0 ] );

		Caching::get_instance()->delete_all_caches( false );

		$this->assertSame( '0', $this->column_value( $known, 'deleted' ) );
		$this->assertSame( '1', $this->column_value( $unknown, 'deleted' ) );
	}

	public function test_delete_true_marks_every_row_deleted() {
		$a = $this->insert_cache( [ 'object_type' => 'post', 'deleted' => 0 ] );
		$b = $this->insert_cache( [ 'object_type' => 'page', 'deleted' => 0 ] );

		Caching::get_instance()->delete_all_caches( true );

		$this->assertSame( '1', $this->column_value( $a, 'deleted' ) );
		$this->assertSame( '1', $this->column_value( $b, 'deleted' ) );
	}

	public function test_returns_count_of_affected_rows() {
		$this->insert_cache();
		$this->insert_cache();
		$this->insert_cache();

		$this->assertSame( 3, (int) Caching::get_instance()->delete_all_caches( false ) );
	}

	public function test_returns_zero_when_no_rows_exist() {
		$this->assertSame( 0, (int) Caching::get_instance()->delete_all_caches( false ) );
	}

	public function test_schedules_cleanup_cron_when_rows_were_affected() {
		$this->insert_cache();
		$this->assertFalse( wp_next_scheduled( self::CRON_HOOK ) );

		Caching::get_instance()->delete_all_caches( false );

		$this->assertNotFalse( wp_next_scheduled( self::CRON_HOOK ) );
	}

	public function test_does_not_schedule_cleanup_when_no_rows_affected() {
		Caching::get_instance()->delete_all_caches( false );

		$this->assertFalse( wp_next_scheduled( self::CRON_HOOK ) );
	}

	public function test_filter_returning_empty_array_returns_zero_and_touches_nothing() {
		$cache_id = $this->insert_cache( [ 'cache_key' => md5( 'a' ) ] );

		add_filter( 'wp_rest_cache/filtered_cache_keys', '__return_empty_array' );

		$affected = Caching::get_instance()->delete_all_caches( false, 'some_filter_id' );

		$this->assertSame( 0, (int) $affected );
		$this->assertNotExpired( $cache_id );
	}

	public function test_filter_returning_valid_md5_keys_scopes_update_to_those_rows() {
		$key_a = md5( 'a' );
		$key_b = md5( 'b' );
		$key_c = md5( 'c' );

		$row_a = $this->insert_cache( [ 'cache_key' => $key_a ] );
		$row_b = $this->insert_cache( [ 'cache_key' => $key_b ] );
		$row_c = $this->insert_cache( [ 'cache_key' => $key_c ] );

		add_filter(
			'wp_rest_cache/filtered_cache_keys',
			fn() => [ $key_a, $key_c ]
		);

		$affected = Caching::get_instance()->delete_all_caches( false, 'my_filter' );

		$this->assertSame( 2, (int) $affected );
		$this->assertExpired( $row_a );
		$this->assertNotExpired( $row_b );
		$this->assertExpired( $row_c );
	}

	public function test_filter_drops_keys_that_are_not_32_hex_chars() {
		$valid_key = md5( 'real' );
		$cache_id  = $this->insert_cache( [ 'cache_key' => $valid_key ] );

		add_filter(
			'wp_rest_cache/filtered_cache_keys',
			fn() => [
				$valid_key,
				'too-short',
				'NOT-HEX-AT-ALL-NOT-HEX-AT-ALL!!',
				str_repeat( 'g', 32 ),
				'1234567890abcdef1234567890abcdef1',
				'SQL INJECTION; DROP TABLE wp_caches; --',
			]
		);

		$affected = Caching::get_instance()->delete_all_caches( false, 'mixed_filter' );

		$this->assertSame( 1, (int) $affected );
		$this->assertExpired( $cache_id );
	}

	public function test_filter_returning_only_invalid_keys_returns_zero_without_querying() {
		$cache_id = $this->insert_cache( [ 'cache_key' => md5( 'real' ) ] );

		add_filter(
			'wp_rest_cache/filtered_cache_keys',
			fn() => [ 'short', 'also-invalid' ]
		);

		$affected = Caching::get_instance()->delete_all_caches( false, 'all_bad' );

		$this->assertSame( 0, (int) $affected );
		$this->assertNotExpired( $cache_id );
	}

	public function test_filter_receives_initial_empty_array_and_the_filter_identifier() {
		$captured = null;
		add_filter(
			'wp_rest_cache/filtered_cache_keys',
			function ( $keys, $cache_filter ) use ( &$captured ) {
				$captured = [ $keys, $cache_filter ];
				return $keys;
			},
			10,
			2
		);

		Caching::get_instance()->delete_all_caches( false, 'my_custom_identifier' );

		$this->assertSame( [ [], 'my_custom_identifier' ], $captured );
	}

	public function test_filter_is_not_consulted_when_cache_filter_is_false() {
		$ran = false;
		add_filter(
			'wp_rest_cache/filtered_cache_keys',
			function ( $keys ) use ( &$ran ) {
				$ran = true;
				return $keys;
			}
		);

		Caching::get_instance()->delete_all_caches( false );

		$this->assertFalse( $ran );
	}
}
