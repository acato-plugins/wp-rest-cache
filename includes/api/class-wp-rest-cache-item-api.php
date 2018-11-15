<?php

/**
 * API for item caching.
 *
 * @link:       http://www.acato.nl
 * @since       2018.2
 *
 * @package     WP_Rest_Cache
 * @subpackage  WP_Rest_Cache/includes/api
 */

/**
 * API for item caching.
 *
 * Caches single items (result of prepare_item_for_response) and handles the update if single items are updated.
 *
 * @package     WP_Rest_Cache
 * @subpackage  WP_Rest_Cache/includes/api
 * @author:     Richard Korthuis - Acato <richardkorthuis@acato.nl>
 */
class WP_Rest_Cache_Item_Api {

    /**
     * Initialize the class and set its properties.
     */
    public function __construct() {
    }

    /**
     * Hook into the registering of a post type and replace the REST Controller with an extension (if allowed).
     *
     * @param array $args Array of arguments for registering a post type.
     * @param string $post_type Post type key.
     *
     * @return array Array of arguments for registering a post type.
     */
    public function set_post_type_rest_controller( $args, $post_type ) {
        $restController = isset( $args['rest_controller_class'] ) ? $args['rest_controller_class'] : null;
        if ( ! $this->should_use_custom_class( $restController, 'post_type' ) ) {
            return $args;
        }

        if ( $restController == WP_REST_Attachments_Controller::class ) {
            $args['rest_controller_class'] = WP_Rest_Cache_Attachment_Controller::class;
        } else {
            $args['rest_controller_class'] = WP_Rest_Cache_Post_Controller::class;
        }

        return $args;
    }

    /**
     * Fired upon post update (WordPress hook 'save_post'). Make sure the item cache is updated.
     *
     * @param   int $post_id The ID of the post that is being updated.
     * @param   WP_Post $post The post object of the post that is being updated.
     */
    public function save_post( $post_id, WP_Post $post ) {

        $post_type = get_post_types( [ 'name' => $post->post_type ], 'objects' )[ $post->post_type ];
        if ( ! $this->should_use_custom_class( $post_type->rest_controller_class, 'post_type' )
             || wp_is_post_revision( $post ) ) {
            return;
        }

        $controller = new WP_Rest_Cache_Post_Controller( $post->post_type );
        $controller->update_item_cache( $post );
    }

    /**
     * Fired upon post deletion (WordPress hook 'delete_post'). Make sure the item cache is deleted.
     *
     * @param   int $post_id The ID of the post that is being deleted.
     */
    public function delete_post( $post_id ) {
        $post = get_post( $post_id );
        if ( wp_is_post_revision( $post ) ) {
            return;
        }
        $post_type = get_post_types( [ 'name' => $post->post_type ], 'objects' )[0];
        if ( ! $this->should_use_custom_class( $post_type->rest_controller_class, 'post_type' ) ) {
            return;
        }

        $controller = new WP_Rest_Cache_Post_Controller( $post->post_type );
        $controller->delete_item_cache( $post );
    }

    /**
     * Hook into the registering of a taxonomy and replace the REST Controller with an extension (if allowed).
     *
     * @param array $args Array of arguments for registering a taxonomy.
     * @param string $taxonomy Taxonomy key.
     *
     * @return array Array of arguments for registering a taxonomy.
     */
    public function set_taxonomy_rest_controller( $args, $taxonomy ) {
        $restController = isset( $args['rest_controller_class'] ) ? $args['rest_controller_class'] : null;
        if ( ! $this->should_use_custom_class( $restController, 'taxonomy' ) ) {
            return $args;
        }

        $args['rest_controller_class'] = WP_Rest_Cache_Term_Controller::class;

        return $args;
    }

    /**
     * Fired upon term update (WordPress hook 'edited_term'). Make sure the item cache is updated.
     *
     * @param   int $term_id The term_id of the term that is being updated.
     * @param   string $taxonomy The taxonomy of the term that is being updated.
     */
    public function edited_terms( $term_id, $taxonomy ) {
        $term       = get_term( $term_id, $taxonomy );
        $tax_object = get_taxonomies( [ 'name' => $term->taxonomy ], 'objects' )[ $term->taxonomy ];
        if ( ! $this->should_use_custom_class( $tax_object->rest_controller_class, 'taxonomy' ) ) {
            return;
        }

        $controller = new WP_Rest_Cache_Term_Controller( $term->taxonomy );
        $controller->update_item_cache( $term );
    }

    /**
     * Fired upon term deletion (WordPress hook 'delete_term'). Make sure the item cache is deleted.
     *
     * @param   int $term_id The term_id of the term that is being deleted.
     */
    public function delete_term( $term_id ) {
        $term       = get_term( $term_id );
        $tax_object = get_taxonomies( [ 'name' => $term->taxonomy ], 'objects' )[ $term->taxonomy ];
        if ( ! $this->should_use_custom_class( $tax_object->rest_controller_class, 'taxonomy' ) ) {
            return;
        }

        $controller = new WP_Rest_Cache_Term_Controller( $term->taxonomy );
        $controller->delete_item_cache( $term );
    }

    /**
     * Check if we can use an extension of the current REST Controller.
     *
     * @param   string $class_name Class name of the current REST Controller
     * @param   string $type Type of the object (taxonomy|post_type).
     *
     * @return  bool True if a custom REST Controller can be used.
     */
    protected function should_use_custom_class( $class_name, $type ) {
        if ( is_null( $class_name ) ) {
            return true;
        }
        switch ( $type ) {
            case 'taxonomy':
                return $class_name == WP_REST_Terms_Controller::class
                       || $class_name == WP_Rest_Cache_Term_Controller::class;
            case 'post_type':
            default:
                return $class_name == WP_REST_Posts_Controller::class
                       || $class_name == WP_Rest_Cache_Post_Controller::class
                       || $class_name == WP_REST_Attachments_Controller::class
                       || $class_name == WP_Rest_Cache_Attachment_Controller::class;
        }
    }

    /**
     * Clear all caches.
     *
     * @TODO: Do not use queries to determine which caches to clear and to clear them.
     */
    public static function clear_cache() {
        global $wpdb;

        // Remove all related post meta
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
            '_wp_rest_cache_entry'
        ) );

        // Remove all relater term meta
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->termmeta} WHERE meta_key = %s",
            '_wp_rest_cache_entry'
        ) );

        // Remove all cache entries
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '%wp_rest_cache_%'
        ) );
    }
}