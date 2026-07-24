<?php
/**
 * Tests for delete_cache (single-row delete) and clear_caches (sweep).
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching
 */
class Test_Caching_Delete_Clear extends Caching_Test_Case {

	public function test_delete_cache_force_false_soft_deletes_row_and_removes_relations_and_transient() {
		$cache_key = 'soft-delete-key';
		$cache_id  = $this->insert_cache( [ 'cache_key' => $cache_key ] );
		$this->insert_relation( $cache_id, '42', 'post' );
		set_transient( Caching::get_instance()->transient_key( $cache_key ), 'cached-payload', HOUR_IN_SECONDS );

		Caching::get_instance()->delete_cache( $cache_key );

		$this->assertTrue( $this->cache_row_exists( $cache_id ) );
		$this->assertExpired( $cache_id );
		$this->assertSame( '1', $this->column_value( $cache_id, 'cleaned' ) );
		$this->assertSame( 0, $this->relation_count_for( $cache_id ) );
		$this->assertFalse( get_transient( Caching::get_instance()->transient_key( $cache_key ) ) );
	}

	public function test_delete_cache_force_false_preserves_existing_deleted_flag() {
		$cache_key = 'previously-deleted';
		$cache_id  = $this->insert_cache(
			[
				'cache_key' => $cache_key,
				'deleted'   => 1,
			]
		);

		Caching::get_instance()->delete_cache( $cache_key );

		$this->assertSame( '1', $this->column_value( $cache_id, 'deleted' ) );
	}

	public function test_delete_cache_force_true_hard_deletes_row_and_relations_and_transient() {
		$cache_key = 'hard-delete-key';
		$cache_id  = $this->insert_cache( [ 'cache_key' => $cache_key ] );
		$this->insert_relation( $cache_id, '42', 'post' );
		set_transient( Caching::get_instance()->transient_key( $cache_key ), 'cached-payload', HOUR_IN_SECONDS );

		Caching::get_instance()->delete_cache( $cache_key, true );

		$this->assertFalse( $this->cache_row_exists( $cache_id ) );
		$this->assertSame( 0, $this->relation_count_for( $cache_id ) );
		$this->assertFalse( get_transient( Caching::get_instance()->transient_key( $cache_key ) ) );
	}

	public function test_delete_cache_with_unknown_key_is_a_table_noop() {
		$other = $this->insert_cache( [ 'cache_key' => 'other-key' ] );
		$this->insert_relation( $other, '1', 'post' );

		Caching::get_instance()->delete_cache( 'never-existed' );

		$this->assertTrue( $this->cache_row_exists( $other ) );
		$this->assertNotExpired( $other );
		$this->assertSame( 1, $this->relation_count_for( $other ) );
	}

	public function test_delete_cache_with_unknown_key_still_deletes_transient() {
		$cache_key = 'orphaned-key';
		set_transient( Caching::get_instance()->transient_key( $cache_key ), 'stale', HOUR_IN_SECONDS );

		Caching::get_instance()->delete_cache( $cache_key );

		$this->assertFalse( get_transient( Caching::get_instance()->transient_key( $cache_key ) ) );
	}

	public function test_delete_cache_with_no_existing_relations_still_succeeds() {
		$cache_key = 'no-relations';
		$cache_id  = $this->insert_cache( [ 'cache_key' => $cache_key ] );

		Caching::get_instance()->delete_cache( $cache_key );

		$this->assertTrue( $this->cache_row_exists( $cache_id ) );
		$this->assertExpired( $cache_id );
	}

	public function test_clear_caches_returns_false_when_no_caches_exist() {
		$this->assertFalse( Caching::get_instance()->clear_caches() );
	}

	public function test_clear_caches_returns_true_when_at_least_one_cache_exists() {
		$this->insert_cache( [ 'cache_key' => 'key-1' ] );

		$this->assertTrue( Caching::get_instance()->clear_caches() );
	}

	public function test_clear_caches_force_false_soft_deletes_every_cache() {
		$ids = [];
		foreach ( [ 'a', 'b', 'c' ] as $key ) {
			$cache_id = $this->insert_cache( [ 'cache_key' => $key ] );
			$this->insert_relation( $cache_id, '1', 'post' );
			set_transient( Caching::get_instance()->transient_key( $key ), 'payload', HOUR_IN_SECONDS );
			$ids[] = $cache_id;
		}

		Caching::get_instance()->clear_caches();

		foreach ( $ids as $cache_id ) {
			$this->assertTrue( $this->cache_row_exists( $cache_id ) );
			$this->assertExpired( $cache_id );
			$this->assertSame( 0, $this->relation_count_for( $cache_id ) );
		}
		foreach ( [ 'a', 'b', 'c' ] as $key ) {
			$this->assertFalse( get_transient( Caching::get_instance()->transient_key( $key ) ) );
		}
	}

	public function test_clear_caches_force_true_hard_deletes_every_cache() {
		$ids = [];
		foreach ( [ 'a', 'b', 'c' ] as $key ) {
			$ids[] = $this->insert_cache( [ 'cache_key' => $key ] );
		}

		Caching::get_instance()->clear_caches( true );

		foreach ( $ids as $cache_id ) {
			$this->assertFalse( $this->cache_row_exists( $cache_id ) );
		}
	}

	private function cache_row_exists( $cache_id ) {
		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}wrc_caches` WHERE cache_id = %d",
				$cache_id
			)
		);
		return 1 === $count;
	}

	private function relation_count_for( $cache_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}wrc_relations` WHERE cache_id = %d",
				$cache_id
			)
		);
	}
}
