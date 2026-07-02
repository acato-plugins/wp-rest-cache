<?php
/**
 * Tests for delete_related_caches and the private delete_related_caches_batch.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching
 */
class Test_Caching_Delete_Related extends Caching_Test_Case {

	public function test_flushes_cache_linked_to_matching_object() {
		[ $linked_id, $unrelated_id ] = $this->seed_two_caches_one_linked_to_post_42();

		$affected = Caching::get_instance()->delete_related_caches( 42, 'post' );

		$this->assertSame( 1, (int) $affected );
		$this->assertExpired( $linked_id );
		$this->assertNotExpired( $unrelated_id );
	}

	public function test_does_not_mark_deleted_when_force_single_delete_is_false() {
		[ $linked_id ] = $this->seed_two_caches_one_linked_to_post_42();

		Caching::get_instance()->delete_related_caches( 42, 'post' );

		$this->assertSame( '0', $this->column_value( $linked_id, 'deleted' ) );
	}

	public function test_force_single_delete_marks_single_caches_deleted_but_leaves_collections() {
		$single_id     = $this->insert_cache( [ 'is_single' => 1 ] );
		$collection_id = $this->insert_cache( [ 'is_single' => 0 ] );
		$this->insert_relation( $single_id, '42', 'post' );
		$this->insert_relation( $collection_id, '42', 'post' );

		Caching::get_instance()->delete_related_caches( 42, 'post', true );

		$this->assertSame( '1', $this->column_value( $single_id, 'deleted' ) );
		$this->assertSame( '0', $this->column_value( $collection_id, 'deleted' ) );
		$this->assertExpired( $single_id );
		$this->assertExpired( $collection_id );
	}

	public function test_delete_true_marks_all_matching_caches_deleted_regardless_of_is_single() {
		$single_id     = $this->insert_cache( [ 'is_single' => 1 ] );
		$collection_id = $this->insert_cache( [ 'is_single' => 0 ] );
		$this->insert_relation( $single_id, '42', 'post' );
		$this->insert_relation( $collection_id, '42', 'post' );

		Caching::get_instance()->delete_related_caches( 42, 'post', false, true );

		$this->assertSame( '1', $this->column_value( $single_id, 'deleted' ) );
		$this->assertSame( '1', $this->column_value( $collection_id, 'deleted' ) );
	}

	public function test_no_matching_relation_returns_zero_and_touches_nothing() {
		[ $linked_id, $unrelated_id ] = $this->seed_two_caches_one_linked_to_post_42();

		$affected = Caching::get_instance()->delete_related_caches( 999, 'post' );

		$this->assertSame( 0, (int) $affected );
		$this->assertNotExpired( $linked_id );
		$this->assertNotExpired( $unrelated_id );
	}

	public function test_fires_pre_delete_related_caches_action_with_args() {
		$captured = [];
		add_action(
			'wp_rest_cache/pre_delete_related_caches',
			function ( $id, $object_type ) use ( &$captured ) {
				$captured[] = [ $id, $object_type ];
			},
			10,
			2
		);

		Caching::get_instance()->delete_related_caches( 42, 'post' );

		$this->assertSame( [ [ 42, 'post' ] ], $captured );
	}

	public function test_batch_flushes_all_matching_caches_in_single_query() {
		$post_1_cache = $this->insert_cache();
		$post_2_cache = $this->insert_cache();
		$post_3_cache = $this->insert_cache();
		$other_cache  = $this->insert_cache();

		$this->insert_relation( $post_1_cache, '1', 'post' );
		$this->insert_relation( $post_2_cache, '2', 'post' );
		$this->insert_relation( $post_3_cache, '3', 'post' );
		$this->insert_relation( $other_cache, '999', 'post' );

		$affected = $this->invoke_private(
			Caching::get_instance(),
			'delete_related_caches_batch',
			[ [ 1, 2, 3 ], 'post' ]
		);

		$this->assertSame( 3, (int) $affected );
		$this->assertExpired( $post_1_cache );
		$this->assertExpired( $post_2_cache );
		$this->assertExpired( $post_3_cache );
		$this->assertNotExpired( $other_cache );
	}

	public function test_batch_with_empty_ids_returns_zero_and_does_no_query() {
		$cache_id = $this->insert_cache();
		$this->insert_relation( $cache_id, '1', 'post' );

		$affected = $this->invoke_private(
			Caching::get_instance(),
			'delete_related_caches_batch',
			[ [], 'post' ]
		);

		$this->assertSame( 0, (int) $affected );
		$this->assertNotExpired( $cache_id );
	}

	public function test_batch_does_not_cross_contaminate_across_object_types() {
		$post_cache = $this->insert_cache();
		$term_cache = $this->insert_cache();

		$this->insert_relation( $post_cache, '1', 'post' );
		$this->insert_relation( $term_cache, '1', 'category' );

		$affected = $this->invoke_private(
			Caching::get_instance(),
			'delete_related_caches_batch',
			[ [ 1 ], 'post' ]
		);

		$this->assertSame( 1, (int) $affected );
		$this->assertExpired( $post_cache );
		$this->assertNotExpired( $term_cache );
	}

	public function test_batch_fires_pre_action() {
		$captured = [];
		add_action(
			'wp_rest_cache/pre_delete_related_caches_batch',
			function ( $ids, $object_type ) use ( &$captured ) {
				$captured[] = [ $ids, $object_type ];
			},
			10,
			2
		);

		$this->invoke_private(
			Caching::get_instance(),
			'delete_related_caches_batch',
			[ [ 1, 2, 3 ], 'post' ]
		);

		$this->assertSame( [ [ [ 1, 2, 3 ], 'post' ] ], $captured );
	}

	private function seed_two_caches_one_linked_to_post_42() {
		$linked_id    = $this->insert_cache();
		$unrelated_id = $this->insert_cache();
		$this->insert_relation( $linked_id, '42', 'post' );
		$this->insert_relation( $unrelated_id, '99', 'post' );
		return [ $linked_id, $unrelated_id ];
	}
}
