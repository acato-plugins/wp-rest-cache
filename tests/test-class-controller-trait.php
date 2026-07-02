<?php
/**
 * Tests for the Controller_Trait used by Post_Controller, Attachment_Controller, and
 * Term_Controller. The trait is the actual caching wiring on the REST handler side: it
 * registers each (namespace, rest_base) pair into the `wp_rest_cache_item_allowed_endpoints`
 * option the first time a controller is instantiated for that combination.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Controller\Attachment_Controller;
use WP_Rest_Cache_Plugin\Includes\Controller\Post_Controller;
use WP_Rest_Cache_Plugin\Includes\Controller\Term_Controller;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Controller\Controller_Trait
 * @covers \WP_Rest_Cache_Plugin\Includes\Controller\Post_Controller
 * @covers \WP_Rest_Cache_Plugin\Includes\Controller\Attachment_Controller
 * @covers \WP_Rest_Cache_Plugin\Includes\Controller\Term_Controller
 */
class Test_Controller_Trait extends Caching_Test_Case {

	const OPTION = 'wp_rest_cache_item_allowed_endpoints';

	public function set_up() {
		parent::set_up();
		delete_option( self::OPTION );
	}

	// ---------- First-instantiation registration ----------

	public function test_post_controller_registers_posts_endpoint_under_wp_v2() {
		new Post_Controller( 'post' );

		$this->assertSame(
			[ 'wp/v2' => [ 'posts' ] ],
			get_option( self::OPTION )
		);
	}

	public function test_attachment_controller_registers_media_endpoint_not_attachment() {
		// `WP_REST_Attachments_Controller::$rest_base` is 'media', not 'attachment' — easy
		// to confuse if you read the post-type slug instead of the controller's rest_base.
		new Attachment_Controller( 'attachment' );

		$this->assertSame(
			[ 'wp/v2' => [ 'media' ] ],
			get_option( self::OPTION )
		);
	}

	public function test_term_controller_for_category_registers_categories_endpoint() {
		new Term_Controller( 'category' );

		$this->assertSame(
			[ 'wp/v2' => [ 'categories' ] ],
			get_option( self::OPTION )
		);
	}

	public function test_term_controller_for_post_tag_registers_tags_endpoint() {
		new Term_Controller( 'post_tag' );

		$this->assertSame(
			[ 'wp/v2' => [ 'tags' ] ],
			get_option( self::OPTION )
		);
	}

	// ---------- Idempotency ----------

	public function test_second_instantiation_with_same_post_type_does_not_duplicate_entry() {
		new Post_Controller( 'post' );
		new Post_Controller( 'post' );
		new Post_Controller( 'post' );

		$this->assertSame(
			[ 'wp/v2' => [ 'posts' ] ],
			get_option( self::OPTION )
		);
	}

	// ---------- Accumulation ----------

	public function test_multiple_post_types_each_get_their_own_entry_under_wp_v2() {
		new Post_Controller( 'post' );
		new Post_Controller( 'page' );

		$option = get_option( self::OPTION );
		$this->assertArrayHasKey( 'wp/v2', $option );
		$this->assertEqualsCanonicalizing( [ 'posts', 'pages' ], $option['wp/v2'] );
	}

	public function test_post_and_term_controllers_share_the_wp_v2_namespace_in_the_option() {
		new Post_Controller( 'post' );
		new Term_Controller( 'category' );

		$option = get_option( self::OPTION );
		$this->assertEqualsCanonicalizing(
			[ 'posts', 'categories' ],
			$option['wp/v2']
		);
	}

	// ---------- Pre-existing state ----------

	public function test_pre_existing_unrelated_namespace_entries_are_preserved() {
		update_option(
			self::OPTION,
			[ 'custom/v1' => [ 'widgets' ] ]
		);

		new Post_Controller( 'post' );

		$this->assertSame(
			[
				'custom/v1' => [ 'widgets' ],
				'wp/v2'     => [ 'posts' ],
			],
			get_option( self::OPTION )
		);
	}

	public function test_pre_existing_entry_for_the_same_pair_is_not_re_added() {
		update_option(
			self::OPTION,
			[ 'wp/v2' => [ 'posts' ] ]
		);

		new Post_Controller( 'post' );

		$option = get_option( self::OPTION );
		$this->assertCount( 1, $option['wp/v2'] );
		$this->assertSame( 'posts', $option['wp/v2'][0] );
	}

	// ---------- Custom post type ----------

	public function test_custom_post_type_with_custom_rest_base_is_registered_with_that_rest_base() {
		register_post_type(
			'wprc_custom_pt',
			[
				'public'       => true,
				'show_in_rest' => true,
				'rest_base'    => 'wprc-things',
			]
		);

		new Post_Controller( 'wprc_custom_pt' );

		$this->assertSame(
			[ 'wp/v2' => [ 'wprc-things' ] ],
			get_option( self::OPTION )
		);

		unregister_post_type( 'wprc_custom_pt' );
	}
}
