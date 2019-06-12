<?php
/**
 * API for item caching.
 *
 * @link: http://www.acato.nl
 * @since 2018.2
 *
 * @package    WP_Rest_Cache_Plugin
 * @subpackage WP_Rest_Cache_Plugin/Includes/API
 */

namespace WP_Rest_Cache_Plugin\Includes\API;

/**
 * API for item caching.
 *
 * Caches single items (result of prepare_item_for_response) and handles the update if single items are updated.
 *
 * @package    WP_Rest_Cache_Plugin
 * @subpackage WP_Rest_Cache_Plugin/Includes/API
 * @author:    Richard Korthuis - Acato <richardkorthuis@acato.nl>
 */
class Item_Api {


	/**
	 * Initialize the class and set its properties.
	 */
	public function __construct() {
	}

	/**
	 * Hook into the registering of a post type and replace the REST Controller with an extension (if allowed).
	 *
	 * @param array  $args      Array of arguments for registering a post type.
	 * @param string $post_type Post type key.
	 *
	 * @return array Array of arguments for registering a post type.
	 */
	public function set_post_type_rest_controller( $args, $post_type ) {
		$rest_controller = isset( $args['rest_controller_class'] ) ? $args['rest_controller_class'] : null;
		if ( ! $this->should_use_custom_class( $rest_controller, 'post_type' ) ) {
			return $args;
		}

		if ( \WP_REST_Attachments_Controller::class === $rest_controller ) {
			$args['rest_controller_class'] = \WP_Rest_Cache_Plugin\Includes\Controller\Attachment_Controller::class;
		} else {
			$args['rest_controller_class'] = \WP_Rest_Cache_Plugin\Includes\Controller\Post_Controller::class;
		}

		return $args;
	}

	/**
	 * Fired upon post update (WordPress hook 'save_post'). Make sure the item cache is updated.
	 *
	 * @param int      $post_id The ID of the post that is being updated.
	 * @param \WP_Post $post    The post object of the post that is being updated.
	 * @param bool     $update  True if it is an updated post, false if it is a new post.
	 */
	public function save_post( $post_id, \WP_Post $post, $update ) {
		$post_type = get_post_types( [ 'name' => $post->post_type ], 'objects' )[ $post->post_type ];
		if ( ! $this->should_use_custom_class( $post_type->rest_controller_class, 'post_type' )
			|| wp_is_post_revision( $post )
		) {
			return;
		}

		$controller = new \WP_Rest_Cache_Plugin\Includes\Controller\Post_Controller( $post->post_type );
		$controller->update_item_cache( $post );
	}

	/**
	 * Hook into the registering of a taxonomy and replace the REST Controller with an extension (if allowed).
	 *
	 * @param array  $args     Array of arguments for registering a taxonomy.
	 * @param string $taxonomy Taxonomy key.
	 *
	 * @return array Array of arguments for registering a taxonomy.
	 */
	public function set_taxonomy_rest_controller( $args, $taxonomy ) {
		$rest_controller = isset( $args['rest_controller_class'] ) ? $args['rest_controller_class'] : null;
		if ( ! $this->should_use_custom_class( $rest_controller, 'taxonomy' ) ) {
			return $args;
		}

		$args['rest_controller_class'] = \WP_Rest_Cache_Plugin\Includes\Controller\Term_Controller::class;

		return $args;
	}

	/**
	 * Fired upon term creation / update (WordPress hooks 'created_term' and 'edited_term'). Make sure the item cache
	 * is updated.
	 *
	 * @param int    $term_id  The term_id of the term that is being updated.
	 * @param int    $tt_id    The term taxonomy id.
	 * @param string $taxonomy The taxonomy of the term that is being updated.
	 */
	public function edited_term( $term_id, $tt_id, $taxonomy ) {
		$term       = get_term( $term_id, $taxonomy );
		$tax_object = get_taxonomies( [ 'name' => $term->taxonomy ], 'objects' )[ $term->taxonomy ];
		if ( ! $this->should_use_custom_class( $tax_object->rest_controller_class, 'taxonomy' ) ) {
			return;
		}

		$controller = new \WP_Rest_Cache_Plugin\Includes\Controller\Term_Controller( $term->taxonomy );
		$controller->update_item_cache( $term );
	}

	/**
	 * Check if we can use an extension of the current REST Controller.
	 *
	 * @param string $class_name Class name of the current REST Controller.
	 * @param string $type       Type of the object (taxonomy|post_type).
	 *
	 * @return bool True if a custom REST Controller can be used.
	 */
	protected function should_use_custom_class( $class_name, $type ) {
		if ( is_null( $class_name ) ) {
			return true;
		}
		switch ( $type ) {
			case 'taxonomy':
				return \WP_REST_Terms_Controller::class === $class_name
					|| \WP_Rest_Cache_Plugin\Includes\Controller\Term_Controller::class === $class_name;
			case 'post_type':
			default:
				return \WP_REST_Posts_Controller::class === $class_name
					|| \WP_Rest_Cache_Plugin\Includes\Controller\Post_Controller::class === $class_name
					|| \WP_REST_Attachments_Controller::class === $class_name
					|| \WP_Rest_Cache_Plugin\Includes\Controller\Attachment_Controller::class === $class_name;
		}
	}
}
