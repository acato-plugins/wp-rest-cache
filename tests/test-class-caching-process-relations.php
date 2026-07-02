<?php
/**
 * Tests for the relation-extraction shape detection in process_recursive_cache_relations
 * and the taxonomy-specific process_taxonomy_relations branch.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching
 */
class Test_Caching_Process_Relations extends Caching_Test_Case {

	/** @var int */
	private $cache_id;

	public function set_up() {
		parent::set_up();
		$this->cache_id = $this->insert_cache();
	}

	public function test_non_array_input_is_ignored() {
		$this->run_recursive( null );
		$this->run_recursive( 'not-an-array' );
		$this->run_recursive( 42 );

		$this->assertSame( [], $this->relations() );
	}

	public function test_record_with_none_of_the_recognized_shapes_creates_nothing() {
		$this->run_recursive( [ 'foo' => 'bar', 'count' => 3 ] );

		$this->assertSame( [], $this->relations() );
	}

	public function test_post_type_shape_creates_relation_with_post_type_as_object_type() {
		$this->run_recursive( [ 'id' => 5, 'post_type' => 'page' ] );

		$this->assertRelations( [ [ '5', 'page' ] ] );
	}

	public function test_post_type_shape_wins_over_type_shape_when_both_present() {
		$this->run_recursive( [ 'id' => 5, 'post_type' => 'page', 'type' => 'post', 'slug' => 'x' ] );

		$this->assertRelations( [ [ '5', 'page' ] ] );
	}

	public function test_taxonomy_shape_with_id_name_slug_creates_relation_using_id() {
		$this->run_recursive(
			[
				'id'       => 7,
				'name'     => 'Category Name',
				'slug'     => 'category-name',
				'taxonomy' => 'category',
			]
		);

		$this->assertRelations( [ [ '7', 'category' ] ] );
	}

	public function test_taxonomy_shape_falls_back_to_term_id_when_id_missing() {
		$this->run_recursive(
			[
				'term_id'  => 9,
				'taxonomy' => 'post_tag',
			]
		);

		$this->assertRelations( [ [ '9', 'post_tag' ] ] );
	}

	public function test_taxonomy_shape_with_neither_id_nor_term_id_creates_nothing() {
		$this->run_recursive( [ 'taxonomy' => 'category', 'slug' => 'x' ] );

		$this->assertSame( [], $this->relations() );
	}

	public function test_rest_item_with_slug_creates_relation() {
		$this->run_recursive( [ 'id' => 1, 'type' => 'post', 'slug' => 'hello-world' ] );

		$this->assertRelations( [ [ '1', 'post' ] ] );
	}

	public function test_rest_item_with_status_creates_relation() {
		$this->run_recursive( [ 'id' => 1, 'type' => 'post', 'status' => 'publish' ] );

		$this->assertRelations( [ [ '1', 'post' ] ] );
	}

	public function test_rest_item_without_slug_or_status_creates_nothing() {
		$this->run_recursive( [ 'id' => 1, 'type' => 'post' ] );

		$this->assertSame( [], $this->relations() );
	}

	public function test_user_via_links_collection_creates_user_relation() {
		$this->run_recursive(
			[
				'id'     => 42,
				'slug'   => 'jane',
				'_links' => [
					'collection' => [
						[ 'href' => 'https://example.test/wp-json/wp/v2/users' ],
					],
				],
			]
		);

		$this->assertRelations( [ [ '42', 'user' ] ] );
	}

	public function test_links_collection_to_non_users_endpoint_creates_nothing() {
		$this->run_recursive(
			[
				'id'     => 42,
				'slug'   => 'jane',
				'_links' => [
					'collection' => [
						[ 'href' => 'https://example.test/wp-json/wp/v2/pages' ],
					],
				],
			]
		);

		$this->assertSame( [], $this->relations() );
	}

	public function test_comment_record_creates_relation_to_parent_post_type() {
		$page_id = self::factory()->post->create( [ 'post_type' => 'page' ] );

		$this->run_recursive( [ 'type' => 'comment', 'post' => $page_id ] );

		$this->assertRelations( [ [ (string) $page_id, 'page' ] ] );
	}

	public function test_comment_with_nonexistent_post_creates_nothing() {
		$this->run_recursive( [ 'type' => 'comment', 'post' => 999999 ] );

		$this->assertSame( [], $this->relations() );
	}

	public function test_comment_branch_runs_independently_of_first_elseif_chain() {
		$post_id = self::factory()->post->create( [ 'post_type' => 'post' ] );

		$this->run_recursive(
			[
				'id'   => 17,
				'type' => 'comment',
				'slug' => 'a-comment',
				'post' => $post_id,
			]
		);

		$this->assertRelations(
			[
				[ '17', 'comment' ],
				[ (string) $post_id, 'post' ],
			]
		);
	}

	public function test_recurses_into_nested_arrays() {
		$this->run_recursive(
			[
				'meta' => [
					'related' => [ 'id' => 3, 'post_type' => 'attachment' ],
				],
			]
		);

		$this->assertRelations( [ [ '3', 'attachment' ] ] );
	}

	public function test_recurses_into_a_list_of_items() {
		$this->run_recursive(
			[
				'data' => [
					[ 'id' => 1, 'type' => 'post', 'slug' => 'a' ],
					[ 'id' => 2, 'type' => 'post', 'slug' => 'b' ],
					[ 'id' => 3, 'type' => 'post', 'slug' => 'c' ],
				],
			]
		);

		$this->assertRelations(
			[
				[ '1', 'post' ],
				[ '2', 'post' ],
				[ '3', 'post' ],
			]
		);
	}

	public function test_top_level_keys_are_normalized_to_lowercase() {
		$this->run_recursive( [ 'ID' => 5, 'Post_Type' => 'page' ] );

		$this->assertRelations( [ [ '5', 'page' ] ] );
	}

	private function run_recursive( $record ) {
		$this->invoke_private(
			Caching::get_instance(),
			'process_recursive_cache_relations',
			[ $this->cache_id, $record ]
		);
	}

	private function relations() {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT object_id, object_type FROM `{$wpdb->prefix}wrc_relations` WHERE cache_id = %d ORDER BY object_type ASC, object_id ASC",
				$this->cache_id
			),
			ARRAY_A
		);
		return array_map(
			static fn( $row ) => [ $row['object_id'], $row['object_type'] ],
			$rows
		);
	}

	private function assertRelations( array $expected ) {
		$normalize = static function ( $pairs ) {
			$copy = $pairs;
			sort( $copy );
			return $copy;
		};
		$this->assertSame( $normalize( $expected ), $normalize( $this->relations() ) );
	}
}
