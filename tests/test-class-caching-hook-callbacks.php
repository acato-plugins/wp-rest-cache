<?php
/**
 * Tests for the thin WP-hook dispatch wrappers on the Caching class.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching
 */
class Test_Caching_Hook_Callbacks extends Caching_Test_Case {

	const POST_TYPE_NO_COMMENTS = 'wprc_no_comments';

	/** @var array<int, array{0:mixed,1:string}> */
	private $delete_related_calls = [];

	/** @var array<int, string> */
	private $delete_object_type_calls = [];

	public function set_up() {
		parent::set_up();

		register_post_type( self::POST_TYPE_NO_COMMENTS, [ 'supports' => [ 'title' ] ] );

		add_action(
			'wp_rest_cache/pre_delete_related_caches',
			function ( $id, $object_type ) {
				$this->delete_related_calls[] = [ $id, $object_type ];
			},
			10,
			2
		);
		add_action(
			'wp_rest_cache/pre_delete_object_type_caches',
			function ( $object_type ) {
				$this->delete_object_type_calls[] = $object_type;
			}
		);
	}

	public function tear_down() {
		unregister_post_type( self::POST_TYPE_NO_COMMENTS );
		parent::tear_down();
	}

	public function test_save_post_on_update_flushes_related_caches_for_post() {
		$this->reset_captures();

		Caching::get_instance()->save_post(
			42,
			(object) [ 'post_type' => 'post', 'post_status' => 'publish' ],
			true
		);

		$this->assertSame( [ [ 42, 'post' ] ], $this->delete_related_calls );
		$this->assertSame( [], $this->delete_object_type_calls );
	}

	public function test_save_post_on_create_flushes_object_type_caches_for_post_type() {
		$this->reset_captures();

		Caching::get_instance()->save_post(
			42,
			(object) [ 'post_type' => 'post', 'post_status' => 'publish' ],
			false
		);

		$this->assertSame( [], $this->delete_related_calls );
		$this->assertSame( [ 'post' ], $this->delete_object_type_calls );
	}

	public function test_save_post_on_auto_draft_is_noop() {
		$this->reset_captures();

		Caching::get_instance()->save_post(
			42,
			(object) [ 'post_type' => 'post', 'post_status' => 'auto-draft' ],
			true
		);

		$this->assertSame( [], $this->delete_related_calls );
		$this->assertSame( [], $this->delete_object_type_calls );
	}

	public function test_delete_post_flushes_related_caches_with_force_single_delete() {
		$post_id  = self::factory()->post->create( [ 'post_type' => 'post' ] );
		$cache_id = $this->insert_cache(
			[
				'object_type' => 'post',
				'is_single'   => 1,
			]
		);
		$this->insert_relation( $cache_id, (string) $post_id, 'post' );

		$this->reset_captures();

		Caching::get_instance()->delete_post( $post_id );

		$this->assertSame( [ [ $post_id, 'post' ] ], $this->delete_related_calls );
		$this->assertSame( '1', $this->column_value( $cache_id, 'deleted' ) );
	}

	public function test_delete_post_with_comments_support_also_flushes_comment_object_type() {
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );

		$this->reset_captures();
		Caching::get_instance()->delete_post( $post_id );

		$this->assertSame( [ 'comment' ], $this->delete_object_type_calls );
	}

	public function test_delete_post_without_comments_support_does_not_flush_comment_object_type() {
		$post_id = self::factory()->post->create( [ 'post_type' => self::POST_TYPE_NO_COMMENTS ] );

		$this->reset_captures();
		Caching::get_instance()->delete_post( $post_id );

		$this->assertNotContains( 'comment', $this->delete_object_type_calls );
	}

	public function test_delete_post_on_revision_is_a_noop() {
		$parent_id   = self::factory()->post->create();
		$revision_id = wp_save_post_revision( $parent_id );
		$this->assertNotEmpty( $revision_id );

		$this->reset_captures();
		Caching::get_instance()->delete_post( $revision_id );

		$this->assertSame( [], $this->delete_related_calls );
		$this->assertSame( [], $this->delete_object_type_calls );
	}

	public function test_add_attachment_flushes_attachment_object_type() {
		$this->reset_captures();

		Caching::get_instance()->add_attachment();

		$this->assertSame( [ 'attachment' ], $this->delete_object_type_calls );
		$this->assertSame( [], $this->delete_related_calls );
	}

	public function test_edit_attachment_flushes_related_caches_for_attachment_id() {
		$this->reset_captures();

		Caching::get_instance()->edit_attachment( 99 );

		$this->assertSame( [ [ 99, 'attachment' ] ], $this->delete_related_calls );
		$this->assertSame( [], $this->delete_object_type_calls );
	}

	public function test_created_term_flushes_taxonomy_as_object_type() {
		$this->reset_captures();

		Caching::get_instance()->created_term( 7, 8, 'category' );

		$this->assertSame( [ 'category' ], $this->delete_object_type_calls );
		$this->assertSame( [], $this->delete_related_calls );
	}

	public function test_edited_term_flushes_related_caches_with_term_id_and_taxonomy() {
		$this->reset_captures();

		Caching::get_instance()->edited_term( 7, 8, 'category' );

		$this->assertSame( [ [ 7, 'category' ] ], $this->delete_related_calls );
		$this->assertSame( [], $this->delete_object_type_calls );
	}

	public function test_delete_term_flushes_related_caches_with_force_single_delete() {
		$cache_id = $this->insert_cache(
			[
				'object_type' => 'category',
				'is_single'   => 1,
			]
		);
		$this->insert_relation( $cache_id, '7', 'category' );

		$this->reset_captures();
		Caching::get_instance()->delete_term( 7, 8, 'category' );

		$this->assertSame( [ [ 7, 'category' ] ], $this->delete_related_calls );
		$this->assertSame( '1', $this->column_value( $cache_id, 'deleted' ) );
	}

	public function test_profile_update_flushes_related_caches_for_user() {
		$this->reset_captures();

		Caching::get_instance()->profile_update( 5 );

		$this->assertSame( [ [ 5, 'user' ] ], $this->delete_related_calls );
	}

	public function test_user_register_flushes_users_object_type() {
		$this->reset_captures();

		Caching::get_instance()->user_register();

		$this->assertSame( [ 'users' ], $this->delete_object_type_calls );
	}

	public function test_deleted_user_flushes_related_caches_with_force_single_delete() {
		$cache_id = $this->insert_cache(
			[
				'object_type' => 'user',
				'is_single'   => 1,
			]
		);
		$this->insert_relation( $cache_id, '5', 'user' );

		$this->reset_captures();
		Caching::get_instance()->deleted_user( 5 );

		$this->assertSame( [ [ 5, 'user' ] ], $this->delete_related_calls );
		$this->assertSame( '1', $this->column_value( $cache_id, 'deleted' ) );
	}

	public function test_delete_comment_related_caches_under_deleted_filter_forces_single_delete() {
		$cache_id = $this->insert_cache(
			[
				'object_type' => 'comment',
				'is_single'   => 1,
			]
		);
		$this->insert_relation( $cache_id, '11', 'comment' );

		$this->reset_captures();

		$this->call_under_current_filter(
			'deleted_comment',
			fn() => Caching::get_instance()->delete_comment_related_caches( 11 )
		);

		$this->assertSame( [ [ 11, 'comment' ] ], $this->delete_related_calls );
		$this->assertSame( '1', $this->column_value( $cache_id, 'deleted' ) );
	}

	public function test_delete_comment_related_caches_under_trashed_filter_does_not_force() {
		$cache_id = $this->insert_cache(
			[
				'object_type' => 'comment',
				'is_single'   => 1,
			]
		);
		$this->insert_relation( $cache_id, '11', 'comment' );

		$this->reset_captures();

		$this->call_under_current_filter(
			'trashed_comment',
			fn() => Caching::get_instance()->delete_comment_related_caches( 11 )
		);

		$this->assertSame( [ [ 11, 'comment' ] ], $this->delete_related_calls );
		$this->assertSame( '0', $this->column_value( $cache_id, 'deleted' ) );
	}

	public function test_delete_comment_type_related_caches_flushes_comment_object_type() {
		$this->reset_captures();

		Caching::get_instance()->delete_comment_type_related_caches();

		$this->assertSame( [ 'comment' ], $this->delete_object_type_calls );
	}

	private function reset_captures() {
		$this->delete_related_calls    = [];
		$this->delete_object_type_calls = [];
	}

	private function call_under_current_filter( $filter, callable $callback ) {
		global $wp_current_filter;
		$wp_current_filter[] = $filter;
		try {
			$callback();
		} finally {
			array_pop( $wp_current_filter );
		}
	}
}
