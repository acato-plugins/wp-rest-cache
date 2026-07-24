<?php
/**
 * Tests for delete_object_type_caches.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching::delete_object_type_caches
 */
class Test_Caching_Delete_Object_Type extends Caching_Test_Case {

	public function test_flushes_only_non_single_caches_of_matching_object_type() {
		$post_collection = $this->insert_cache( [ 'object_type' => 'post', 'is_single' => 0 ] );
		$post_single     = $this->insert_cache( [ 'object_type' => 'post', 'is_single' => 1 ] );

		$affected = Caching::get_instance()->delete_object_type_caches( 'post' );

		$this->assertSame( 1, (int) $affected );
		$this->assertExpired( $post_collection );
		$this->assertNotExpired( $post_single );
	}

	public function test_does_not_touch_caches_of_different_object_type() {
		$post_collection = $this->insert_cache( [ 'object_type' => 'post', 'is_single' => 0 ] );
		$page_collection = $this->insert_cache( [ 'object_type' => 'page', 'is_single' => 0 ] );

		$affected = Caching::get_instance()->delete_object_type_caches( 'post' );

		$this->assertSame( 1, (int) $affected );
		$this->assertExpired( $post_collection );
		$this->assertNotExpired( $page_collection );
	}

	public function test_default_does_not_mark_deleted_flag() {
		$cache_id = $this->insert_cache( [ 'object_type' => 'post', 'is_single' => 0 ] );

		Caching::get_instance()->delete_object_type_caches( 'post' );

		$this->assertSame( '0', $this->column_value( $cache_id, 'deleted' ) );
		$this->assertExpired( $cache_id );
	}

	public function test_delete_true_marks_deleted_flag() {
		$cache_id = $this->insert_cache( [ 'object_type' => 'post', 'is_single' => 0 ] );

		Caching::get_instance()->delete_object_type_caches( 'post', true );

		$this->assertSame( '1', $this->column_value( $cache_id, 'deleted' ) );
		$this->assertExpired( $cache_id );
	}

	public function test_no_matching_caches_returns_zero_and_touches_nothing() {
		$post_single = $this->insert_cache( [ 'object_type' => 'post', 'is_single' => 1 ] );
		$page_coll   = $this->insert_cache( [ 'object_type' => 'page', 'is_single' => 0 ] );

		$affected = Caching::get_instance()->delete_object_type_caches( 'product' );

		$this->assertSame( 0, (int) $affected );
		$this->assertNotExpired( $post_single );
		$this->assertNotExpired( $page_coll );
	}

	public function test_returns_count_of_matched_rows() {
		$this->insert_cache( [ 'object_type' => 'post', 'is_single' => 0 ] );
		$this->insert_cache( [ 'object_type' => 'post', 'is_single' => 0 ] );
		$this->insert_cache( [ 'object_type' => 'post', 'is_single' => 0 ] );
		$this->insert_cache( [ 'object_type' => 'post', 'is_single' => 1 ] ); // single — excluded

		$affected = Caching::get_instance()->delete_object_type_caches( 'post' );

		$this->assertSame( 3, (int) $affected );
	}

	public function test_fires_pre_action_with_object_type() {
		$captured = [];
		add_action(
			'wp_rest_cache/pre_delete_object_type_caches',
			function ( $object_type ) use ( &$captured ) {
				$captured[] = $object_type;
			}
		);

		Caching::get_instance()->delete_object_type_caches( 'post' );

		$this->assertSame( [ 'post' ], $captured );
	}

	public function test_pre_action_fires_even_when_no_rows_match() {
		$captured = [];
		add_action(
			'wp_rest_cache/pre_delete_object_type_caches',
			function ( $object_type ) use ( &$captured ) {
				$captured[] = $object_type;
			}
		);

		$affected = Caching::get_instance()->delete_object_type_caches( 'post' );

		$this->assertSame( 0, (int) $affected );
		$this->assertSame( [ 'post' ], $captured );
	}
}
