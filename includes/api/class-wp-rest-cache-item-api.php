<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link:       http://www.acato.nl
 * @since      2018.1
 *
 * @package    WP_Rest_Cache
 * @subpackage WP_Rest_Cache/includes
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    WP_Rest_Cache
 * @subpackage WP_Rest_Cache/includes
 * @author:       Richard Korthuis - Acato <richardkorthuis@acato.nl>
 */
class WP_Rest_Cache_Item_Api {

    /**
     * Initialize the class and set its properties.
     *
     * @since    2018.1
     */
    public function __construct() {
    }

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

    public function save_post( $post_id, WP_Post $post ) {

        $post_type = get_post_types( [ 'name' => $post->post_type ], 'objects' )[ $post->post_type ];
        if ( ! $this->should_use_custom_class( $post_type->rest_controller_class, 'post_type' )
             || wp_is_post_revision( $post ) ) {
            return;
        }

        $controller = new WP_Rest_Cache_Post_Controller( $post->post_type );
        $controller->update_item_cache( $post );
    }

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

    public function set_taxonomy_rest_controller( $args, $taxonomy ) {
        $restController = isset( $args['rest_controller_class'] ) ? $args['rest_controller_class'] : null;
        if ( ! $this->should_use_custom_class( $restController, 'taxonomy' ) ) {
            return $args;
        }

        $args['rest_controller_class'] = WP_Rest_Cache_Term_Controller::class;

        return $args;
    }

    public function edited_terms( $term_id, $taxonomy ) {
        $term       = get_term( $term_id, $taxonomy );
        $tax_object = get_taxonomies( [ 'name' => $term->taxonomy ], 'objects' )[ $term->taxonomy ];
        if ( ! $this->should_use_custom_class( $tax_object->rest_controller_class, 'taxonomy' ) ) {
            return;
        }

        $controller = new WP_Rest_Cache_Term_Controller( $term->taxonomy );
        $controller->update_item_cache( $term );
    }

    public function delete_term( $term_id ) {
        $term       = get_term( $term_id );
        $tax_object = get_taxonomies( [ 'name' => $term->taxonomy ], 'objects' )[ $term->taxonomy ];
        if ( ! $this->should_use_custom_class( $tax_object->rest_controller_class, 'taxonomy' ) ) {
            return;
        }

        $controller = new WP_Rest_Cache_Term_Controller( $term->taxonomy );
        $controller->delete_item_cache( $term );
    }

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
