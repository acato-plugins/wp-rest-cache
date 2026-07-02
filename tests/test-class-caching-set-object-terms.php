<?php
/**
 * Tests for set_object_terms (the WP set_object_terms hook callback) and the private
 * get_all_term_ancestors helper.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching
 */
class Test_Caching_Set_Object_Terms extends Caching_Test_Case {

	/** @var array<int, array{0: array<int,int>, 1: string}> Captured [ids, taxonomy] from the batch flush. */
	private $batch_calls = [];

	public function set_up() {
		parent::set_up();

		add_action(
			'wp_rest_cache/pre_delete_related_caches_batch',
			function ( $ids, $taxonomy ) {
				$this->batch_calls[] = [ array_map( 'intval', $ids ), $taxonomy ];
			},
			10,
			2
		);
	}

	public function test_filter_returning_false_skips_flush_entirely() {
		add_filter( 'wp_rest_cache/flush_on_set_terms', '__return_false' );

		$post_id = self::factory()->post->create();
		$tag_id  = self::factory()->tag->create();

		$this->batch_calls = [];
		wp_set_object_terms( $post_id, [ $tag_id ], 'post_tag' );

		$this->assertSame( [], $this->batch_calls );

		remove_filter( 'wp_rest_cache/flush_on_set_terms', '__return_false' );
	}

	public function test_no_change_in_terms_skips_flush() {
		$post_id = self::factory()->post->create();
		$tag_id  = self::factory()->tag->create();
		wp_set_object_terms( $post_id, [ $tag_id ], 'post_tag' );

		$this->batch_calls = [];

		wp_set_object_terms( $post_id, [ $tag_id ], 'post_tag' );

		$this->assertSame( [], $this->batch_calls );
	}

	public function test_assigning_a_new_term_triggers_batch_flush_for_added_term() {
		$post_id = self::factory()->post->create();
		$tag_id  = self::factory()->tag->create();

		$this->batch_calls = [];
		wp_set_object_terms( $post_id, [ $tag_id ], 'post_tag' );

		$this->assertSame( [ [ [ $tag_id ], 'post_tag' ] ], $this->batch_calls );
	}

	public function test_append_only_flushes_newly_added_terms() {
		$post_id = self::factory()->post->create();
		$tag_a   = self::factory()->tag->create();
		$tag_b   = self::factory()->tag->create();
		wp_set_object_terms( $post_id, [ $tag_a ], 'post_tag' );

		$this->batch_calls = [];
		wp_set_object_terms( $post_id, [ $tag_b ], 'post_tag', true );

		$this->assertSame( [ [ [ $tag_b ], 'post_tag' ] ], $this->batch_calls );
	}

	public function test_replace_flushes_both_added_and_removed_terms() {
		$post_id = self::factory()->post->create();
		$tag_a   = self::factory()->tag->create();
		$tag_b   = self::factory()->tag->create();
		wp_set_object_terms( $post_id, [ $tag_a ], 'post_tag' );

		$this->batch_calls = [];
		wp_set_object_terms( $post_id, [ $tag_b ], 'post_tag' );

		$this->assertCount( 1, $this->batch_calls );
		[ $ids, $taxonomy ] = $this->batch_calls[0];
		$this->assertEqualsCanonicalizing( [ $tag_a, $tag_b ], $ids );
		$this->assertSame( 'post_tag', $taxonomy );
	}

	public function test_flat_taxonomy_does_not_expand_to_ancestors() {
		$post_id = self::factory()->post->create();
		$tag_id  = self::factory()->tag->create();

		$this->batch_calls = [];
		wp_set_object_terms( $post_id, [ $tag_id ], 'post_tag' );

		[ $ids ] = $this->batch_calls[0];
		$this->assertSame( [ $tag_id ], $ids );
	}

	public function test_hierarchical_taxonomy_expands_to_include_ancestors() {
		$grandparent = self::factory()->category->create();
		$parent      = self::factory()->category->create( [ 'parent' => $grandparent ] );
		$child       = self::factory()->category->create( [ 'parent' => $parent ] );
		$post_id     = self::factory()->post->create();

		// Factory posts auto-get Uncategorized; clear so only $child is involved.
		wp_set_object_terms( $post_id, [], 'category' );

		$this->batch_calls = [];
		wp_set_object_terms( $post_id, [ $child ], 'category' );

		$this->assertCount( 1, $this->batch_calls );
		[ $ids, $taxonomy ] = $this->batch_calls[0];
		$this->assertEqualsCanonicalizing(
			[ $child, $parent, $grandparent ],
			$ids
		);
		$this->assertSame( 'category', $taxonomy );
	}

	public function test_get_ancestors_with_empty_input_returns_empty_array() {
		$this->assertSame( [], $this->ancestors_for( [], 'category' ) );
	}

	public function test_get_ancestors_for_root_term_returns_no_ancestors() {
		$term_id = self::factory()->category->create();

		$this->assertSame( [], $this->ancestors_for( [ $term_id ], 'category' ) );
	}

	public function test_get_ancestors_returns_direct_parent() {
		$parent_id = self::factory()->category->create();
		$child_id  = self::factory()->category->create( [ 'parent' => $parent_id ] );

		$this->assertSame( [ $parent_id ], $this->ancestors_for( [ $child_id ], 'category' ) );
	}

	public function test_get_ancestors_walks_the_full_parent_chain() {
		$gp = self::factory()->category->create();
		$p  = self::factory()->category->create( [ 'parent' => $gp ] );
		$c  = self::factory()->category->create( [ 'parent' => $p ] );

		$ancestors = $this->ancestors_for( [ $c ], 'category' );

		$this->assertEqualsCanonicalizing( [ $gp, $p ], $ancestors );
	}

	public function test_get_ancestors_dedupes_when_two_terms_share_an_ancestor() {
		$gp = self::factory()->category->create();
		$p  = self::factory()->category->create( [ 'parent' => $gp ] );
		$c1 = self::factory()->category->create( [ 'parent' => $p ] );
		$c2 = self::factory()->category->create( [ 'parent' => $p ] );

		$ancestors = $this->ancestors_for( [ $c1, $c2 ], 'category' );

		$this->assertEqualsCanonicalizing( [ $gp, $p ], $ancestors );
		$this->assertCount( 2, $ancestors );
	}

	public function test_get_ancestors_does_not_cross_taxonomy_boundaries() {
		register_taxonomy( 'wprc_other', 'post', [ 'hierarchical' => true ] );

		$cat_parent = self::factory()->category->create();
		$cat_child  = self::factory()->category->create( [ 'parent' => $cat_parent ] );

		$other_parent = wp_insert_term( 'other-parent', 'wprc_other' );
		$other_child  = wp_insert_term(
			'other-child',
			'wprc_other',
			[ 'parent' => $other_parent['term_id'] ]
		);

		$this->assertNotInstanceOf( WP_Error::class, $other_parent );
		$this->assertNotInstanceOf( WP_Error::class, $other_child );

		$ancestors = $this->ancestors_for( [ $cat_child ], 'category' );

		$this->assertSame( [ $cat_parent ], $ancestors );

		unregister_taxonomy( 'wprc_other' );
	}

	public function test_append_mode_with_no_newly_added_tt_ids_returns_without_flush() {
		// In append mode, only newly-added tt_ids are considered "affected". When the new
		// tt_ids are a strict subset of the old set (i.e. only removals via the diff, no
		// adds), affected_tt_ids is empty and the method must return without invoking the
		// batch flush. We call set_object_terms directly so we can craft that arg shape.
		// Create the post first because factory()->post->create assigns the default
		// category, which itself triggers set_object_terms and would pollute batch_calls.
		$post_id           = self::factory()->post->create();
		$this->batch_calls = [];

		Caching::get_instance()->set_object_terms(
			$post_id,
			[],
			[ 1 ],        // new tt_ids
			'category',
			true,         // append
			[ 1, 2 ]      // old tt_ids — strict superset of new
		);

		$this->assertSame( [], $this->batch_calls );
	}

	public function test_unknown_taxonomy_short_circuits_without_flush() {
		// get_terms returns WP_Error for an unregistered taxonomy. The is_wp_error guard
		// must catch it and return without invoking the batch flush.
		$post_id           = self::factory()->post->create();
		$this->batch_calls = [];

		Caching::get_instance()->set_object_terms(
			$post_id,
			[],
			[ 1 ],
			'wprc_definitely_not_a_taxonomy',
			false,
			[]
		);

		$this->assertSame( [], $this->batch_calls );
	}

	private function ancestors_for( $term_ids, $taxonomy ) {
		return $this->invoke_private(
			Caching::get_instance(),
			'get_all_term_ancestors',
			[ $term_ids, $taxonomy ]
		);
	}
}
