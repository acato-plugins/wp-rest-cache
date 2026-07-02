<?php
/**
 * Tests for updated_meta (private) and its public wrappers (updated_post_meta,
 * updated_term_meta, updated_user_meta, updated_comment_meta).
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching
 */
class Test_Caching_Updated_Meta extends Caching_Test_Case {

	/** @var array<int, array{0:int,1:string}> Captured [object_id, object_type] from delete_related_caches. */
	private $delete_related_calls = [];

	public function set_up() {
		parent::set_up();

		add_action(
			'wp_rest_cache/pre_delete_related_caches',
			function ( $id, $object_type ) {
				$this->delete_related_calls[] = [ $id, $object_type ];
			},
			10,
			2
		);
	}

	public function test_default_behavior_does_not_flush_caches() {
		Caching::get_instance()->updated_post_meta( 1, 42, 'meta_key', 'value' );

		$this->assertSame( [], $this->delete_related_calls );
	}

	public function test_general_filter_returning_true_triggers_flush() {
		add_filter( 'wp_rest_cache/flush_on_meta_update', '__return_true' );
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );

		Caching::get_instance()->updated_post_meta( 1, $post_id, 'meta_key', 'value' );

		$this->assertSame( [ [ $post_id, 'post' ] ], $this->delete_related_calls );
	}

	public function test_general_filter_returning_non_strict_truthy_does_not_flush() {
		add_filter(
			'wp_rest_cache/flush_on_meta_update',
			fn() => 1
		);
		$post_id = self::factory()->post->create();

		Caching::get_instance()->updated_post_meta( 1, $post_id, 'meta_key', 'value' );

		$this->assertSame( [], $this->delete_related_calls );
	}

	public function test_general_filter_receives_full_arg_set() {
		$captured = [];
		add_filter(
			'wp_rest_cache/flush_on_meta_update',
			function ( ...$args ) use ( &$captured ) {
				$captured = $args;
				return false;
			},
			10,
			6
		);

		Caching::get_instance()->updated_post_meta( 17, 42, 'my_key', 'my_value' );

		$this->assertSame(
			[ false, 'post', 17, 42, 'my_key', 'my_value' ],
			$captured
		);
	}

	public function test_specific_filter_overrides_general_default_to_enable_flush() {
		add_filter(
			'wp_rest_cache/flush_on_meta_update/post/special_key',
			'__return_true'
		);
		$post_id = self::factory()->post->create();

		Caching::get_instance()->updated_post_meta( 1, $post_id, 'special_key', 'value' );

		$this->assertSame( [ [ $post_id, 'post' ] ], $this->delete_related_calls );
	}

	public function test_specific_filter_overrides_general_filter_to_disable_flush() {
		add_filter( 'wp_rest_cache/flush_on_meta_update', '__return_true' );
		add_filter(
			'wp_rest_cache/flush_on_meta_update/post/quiet_key',
			'__return_false'
		);
		$post_id = self::factory()->post->create();

		Caching::get_instance()->updated_post_meta( 1, $post_id, 'quiet_key', 'value' );

		$this->assertSame( [], $this->delete_related_calls );
	}

	public function test_specific_filter_is_keyed_by_meta_type_and_meta_key() {
		$fired_for_a = false;
		add_filter(
			'wp_rest_cache/flush_on_meta_update/post/key_a',
			function ( $flush ) use ( &$fired_for_a ) {
				$fired_for_a = true;
				return $flush;
			}
		);

		$post_id = self::factory()->post->create();
		Caching::get_instance()->updated_post_meta( 1, $post_id, 'key_b', 'value' );

		$this->assertFalse( $fired_for_a );
	}

	public function test_specific_filter_receives_meta_id_object_id_and_value() {
		$captured = [];
		add_filter(
			'wp_rest_cache/flush_on_meta_update/post/test_key',
			function ( ...$args ) use ( &$captured ) {
				$captured = $args;
				return false;
			},
			10,
			4
		);

		Caching::get_instance()->updated_post_meta( 17, 42, 'test_key', 'value' );

		$this->assertSame( [ false, 17, 42, 'value' ], $captured );
	}

	public function test_subtype_for_post_meta_resolves_to_post_type() {
		add_filter( 'wp_rest_cache/flush_on_meta_update', '__return_true' );
		register_post_type( 'wprc_custom', [ 'public' => true ] );
		$post_id = self::factory()->post->create( [ 'post_type' => 'wprc_custom' ] );

		Caching::get_instance()->updated_post_meta( 1, $post_id, 'k', 'v' );

		$this->assertSame( [ [ $post_id, 'wprc_custom' ] ], $this->delete_related_calls );

		unregister_post_type( 'wprc_custom' );
	}

	public function test_subtype_for_term_meta_resolves_to_taxonomy() {
		add_filter( 'wp_rest_cache/flush_on_meta_update', '__return_true' );
		$term_id = self::factory()->category->create();

		Caching::get_instance()->updated_term_meta( 1, $term_id, 'k', 'v' );

		$this->assertSame( [ [ $term_id, 'category' ] ], $this->delete_related_calls );
	}

	public function test_subtype_for_user_meta_resolves_to_user() {
		add_filter( 'wp_rest_cache/flush_on_meta_update', '__return_true' );
		$user_id = self::factory()->user->create();

		Caching::get_instance()->updated_user_meta( 1, $user_id, 'k', 'v' );

		$this->assertSame( [ [ $user_id, 'user' ] ], $this->delete_related_calls );
	}

	public function test_subtype_for_comment_meta_resolves_to_comment() {
		add_filter( 'wp_rest_cache/flush_on_meta_update', '__return_true' );
		$post_id    = self::factory()->post->create();
		$comment_id = self::factory()->comment->create( [ 'comment_post_ID' => $post_id ] );

		Caching::get_instance()->updated_comment_meta( 1, $comment_id, 'k', 'v' );

		$this->assertSame( [ [ $comment_id, 'comment' ] ], $this->delete_related_calls );
	}
}
