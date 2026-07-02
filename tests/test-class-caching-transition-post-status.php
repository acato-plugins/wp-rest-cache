<?php
/**
 * Tests for transition_post_status: which post-status transitions trigger which
 * cache flushes.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching::transition_post_status
 */
class Test_Caching_Transition_Post_Status extends Caching_Test_Case {

	const POST_TYPE_WITH_COMMENTS = 'wprc_with_comments';
	const POST_TYPE_NO_COMMENTS   = 'wprc_no_comments';

	/** @var array<int, string> Object types passed to delete_object_type_caches via the pre-action. */
	private $flush_calls = [];

	public function set_up() {
		parent::set_up();

		register_post_type(
			self::POST_TYPE_WITH_COMMENTS,
			[ 'supports' => [ 'title', 'comments' ] ]
		);
		register_post_type(
			self::POST_TYPE_NO_COMMENTS,
			[ 'supports' => [ 'title' ] ]
		);

		$this->flush_calls = [];
		add_action(
			'wp_rest_cache/pre_delete_object_type_caches',
			function ( $object_type ) {
				$this->flush_calls[] = $object_type;
			}
		);
	}

	public function tear_down() {
		unregister_post_type( self::POST_TYPE_WITH_COMMENTS );
		unregister_post_type( self::POST_TYPE_NO_COMMENTS );
		parent::tear_down();
	}

	public function test_no_op_when_new_status_equals_old_status() {
		Caching::get_instance()->transition_post_status(
			'publish',
			'publish',
			$this->stub_post( self::POST_TYPE_WITH_COMMENTS )
		);

		$this->assertSame( [], $this->flush_calls );
	}

	public function test_no_op_when_neither_status_is_publish() {
		Caching::get_instance()->transition_post_status(
			'draft',
			'pending',
			$this->stub_post( self::POST_TYPE_WITH_COMMENTS )
		);

		$this->assertSame( [], $this->flush_calls );
	}

	public function test_no_op_when_transitioning_between_non_publish_states() {
		Caching::get_instance()->transition_post_status(
			'trash',
			'draft',
			$this->stub_post( self::POST_TYPE_WITH_COMMENTS )
		);

		$this->assertSame( [], $this->flush_calls );
	}

	public function test_publishing_flushes_non_single_caches_for_post_type() {
		Caching::get_instance()->transition_post_status(
			'publish',
			'draft',
			$this->stub_post( self::POST_TYPE_WITH_COMMENTS )
		);

		$this->assertSame( [ self::POST_TYPE_WITH_COMMENTS ], $this->flush_calls );
	}

	public function test_publishing_does_not_flush_comment_caches_even_when_post_type_supports_comments() {
		// Pin the asymmetry: comment caches are flushed only on UNPUBLISH, not on publish.
		Caching::get_instance()->transition_post_status(
			'publish',
			'draft',
			$this->stub_post( self::POST_TYPE_WITH_COMMENTS )
		);

		$this->assertNotContains( 'comment', $this->flush_calls );
	}

	public function test_unpublishing_post_type_without_comments_flushes_post_type_only() {
		Caching::get_instance()->transition_post_status(
			'draft',
			'publish',
			$this->stub_post( self::POST_TYPE_NO_COMMENTS )
		);

		$this->assertSame( [ self::POST_TYPE_NO_COMMENTS ], $this->flush_calls );
	}

	public function test_unpublishing_post_type_with_comments_also_flushes_comment_caches() {
		Caching::get_instance()->transition_post_status(
			'draft',
			'publish',
			$this->stub_post( self::POST_TYPE_WITH_COMMENTS )
		);

		$this->assertSame(
			[ self::POST_TYPE_WITH_COMMENTS, 'comment' ],
			$this->flush_calls
		);
	}

	public function test_unpublishing_to_trash_still_triggers_flush() {
		Caching::get_instance()->transition_post_status(
			'trash',
			'publish',
			$this->stub_post( self::POST_TYPE_WITH_COMMENTS )
		);

		$this->assertSame(
			[ self::POST_TYPE_WITH_COMMENTS, 'comment' ],
			$this->flush_calls
		);
	}

	private function stub_post( $post_type ) {
		return (object) [ 'post_type' => $post_type ];
	}
}
