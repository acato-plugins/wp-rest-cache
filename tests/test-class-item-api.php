<?php
/**
 * Tests for Item_Api — the register_post_type_args / register_taxonomy_args filter that
 * swaps in the plugin's caching REST controllers for core ones.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\API\Item_Api;
use WP_Rest_Cache_Plugin\Includes\Controller\Attachment_Controller;
use WP_Rest_Cache_Plugin\Includes\Controller\Post_Controller;
use WP_Rest_Cache_Plugin\Includes\Controller\Term_Controller;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\API\Item_Api
 */
class Test_Item_Api extends Caching_Test_Case {

	/** @var Item_Api */
	private $api;

	public function set_up() {
		parent::set_up();
		$this->api = new Item_Api();
	}

	// ---------- set_post_type_rest_controller ----------

	public function test_post_type_with_no_controller_set_gets_custom_post_controller() {
		$args = [ 'public' => true ];

		$result = $this->api->set_post_type_rest_controller( $args );

		$this->assertSame( Post_Controller::class, $result['rest_controller_class'] );
	}

	public function test_core_posts_controller_is_swapped_for_custom_post_controller() {
		$args = [ 'rest_controller_class' => WP_REST_Posts_Controller::class ];

		$result = $this->api->set_post_type_rest_controller( $args );

		$this->assertSame( Post_Controller::class, $result['rest_controller_class'] );
	}

	public function test_core_attachments_controller_is_swapped_for_custom_attachment_controller() {
		$args = [ 'rest_controller_class' => WP_REST_Attachments_Controller::class ];

		$result = $this->api->set_post_type_rest_controller( $args );

		$this->assertSame( Attachment_Controller::class, $result['rest_controller_class'] );
	}

	public function test_already_custom_post_controller_remains_post_controller() {
		// Idempotent: running the filter on already-replaced args doesn't break the assignment.
		$args = [ 'rest_controller_class' => Post_Controller::class ];

		$result = $this->api->set_post_type_rest_controller( $args );

		$this->assertSame( Post_Controller::class, $result['rest_controller_class'] );
	}

	public function test_already_custom_attachment_controller_remains_attachment_controller() {
		// Idempotent for re-registrations that already carry the plugin's custom controller.
		$args = [ 'rest_controller_class' => Attachment_Controller::class ];

		$result = $this->api->set_post_type_rest_controller( $args );

		$this->assertSame( Attachment_Controller::class, $result['rest_controller_class'] );
	}

	public function test_foreign_rest_controller_is_left_alone() {
		// A third-party controller (e.g., WooCommerce's) should pass through unchanged.
		$args = [ 'rest_controller_class' => 'WC_REST_Products_Controller' ];

		$result = $this->api->set_post_type_rest_controller( $args );

		$this->assertSame( 'WC_REST_Products_Controller', $result['rest_controller_class'] );
	}

	public function test_post_type_args_are_returned_unchanged_apart_from_controller_swap() {
		$args = [
			'public'                => true,
			'show_in_rest'          => true,
			'rest_base'             => 'my-things',
			'rest_controller_class' => WP_REST_Posts_Controller::class,
			'supports'              => [ 'title', 'editor' ],
		];

		$result = $this->api->set_post_type_rest_controller( $args );

		// Only rest_controller_class changes; everything else is preserved.
		$expected = $args;
		$expected['rest_controller_class'] = Post_Controller::class;
		$this->assertSame( $expected, $result );
	}

	// ---------- set_taxonomy_rest_controller ----------

	public function test_taxonomy_with_no_controller_set_gets_custom_term_controller() {
		$args = [ 'public' => true ];

		$result = $this->api->set_taxonomy_rest_controller( $args );

		$this->assertSame( Term_Controller::class, $result['rest_controller_class'] );
	}

	public function test_core_terms_controller_is_swapped_for_custom_term_controller() {
		$args = [ 'rest_controller_class' => WP_REST_Terms_Controller::class ];

		$result = $this->api->set_taxonomy_rest_controller( $args );

		$this->assertSame( Term_Controller::class, $result['rest_controller_class'] );
	}

	public function test_already_custom_term_controller_remains_term_controller() {
		$args = [ 'rest_controller_class' => Term_Controller::class ];

		$result = $this->api->set_taxonomy_rest_controller( $args );

		$this->assertSame( Term_Controller::class, $result['rest_controller_class'] );
	}

	public function test_foreign_taxonomy_controller_is_left_alone() {
		$args = [ 'rest_controller_class' => 'My_Custom_Tax_Controller' ];

		$result = $this->api->set_taxonomy_rest_controller( $args );

		$this->assertSame( 'My_Custom_Tax_Controller', $result['rest_controller_class'] );
	}

	// ---------- should_use_custom_class (protected, via reflection for the edge cases) ----------

	public function test_should_use_custom_class_returns_true_for_null_input_regardless_of_type() {
		$this->assertTrue( $this->should_use_custom_class( null, 'post_type' ) );
		$this->assertTrue( $this->should_use_custom_class( null, 'taxonomy' ) );
	}

	public function test_should_use_custom_class_default_branch_treats_unknown_type_like_post_type() {
		// The switch's default case falls through to the post_type allowlist — a quirky safety
		// net that means an unknown $type with a posts-flavored class still says yes.
		$this->assertTrue(
			$this->should_use_custom_class( WP_REST_Posts_Controller::class, 'something_unknown' )
		);
		$this->assertFalse(
			$this->should_use_custom_class( WP_REST_Terms_Controller::class, 'something_unknown' )
		);
	}

	private function should_use_custom_class( $class_name, $type ) {
		return $this->invoke_private( $this->api, 'should_use_custom_class', [ $class_name, $type ] );
	}
}
