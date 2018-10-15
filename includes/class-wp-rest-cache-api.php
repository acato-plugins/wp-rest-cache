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
class WP_Rest_Cache_Api
{

    /**
     * The ID of this plugin.
     *
     * @since    2018.1
     * @access   private
     * @var      string $plugin_name The ID of this plugin.
     */
    private $plugin_name;

    /**
     * The version of this plugin.
     *
     * @since    2018.1
     * @access   private
     * @var      string $version The current version of this plugin.
     */
    private $version;

    /**
     * Initialize the class and set its properties.
     *
     * @since    2018.1
     * @param      string $plugin_name The name of this plugin.
     * @param      string $version The version of this plugin.
     */
    public function __construct($plugin_name, $version)
    {
        $this->plugin_name = $plugin_name;
        $this->version = $version;
    }

    public function set_post_type_rest_controller($args, $post_type)
    {
        $restController = isset($args['rest_controller_class']) ? $args['rest_controller_class'] : null;
        if(!$this->should_use_custom_class($restController, 'post_type')){
            return $args;
        }

        if($restController == WP_REST_Attachments_Controller::class) {
            $args['rest_controller_class'] = WP_Rest_Cache_Attachment_Controller::class;
        }
        else {
            $args['rest_controller_class'] = WP_Rest_Cache_Post_Controller::class;
        }

        return $args;
    }

    public function save_post($post_id, WP_Post $post)
    {
        $post_type = get_post_types(['name' => $post->post_type], 'objects')[$post->post_type];
        if(!$this->should_use_custom_class($post_type->rest_controller_class, 'post_type')) {
            return;
        }

        $controller = new WP_Rest_Cache_Post_Controller($post->post_type);
        $controller->update_item_cache($post);
    }

    public function delete_post($post_id)
    {
        $post = get_post($post_id);
        $post_type = get_post_types(['name' => $post->post_type], 'objects')[0];
        if(!$this->should_use_custom_class($post_type->rest_controller_class, 'post_type')) {
            return;
        }

        $controller = new WP_Rest_Cache_Post_Controller($post->post_type);
        $controller->delete_item_cache($post);
    }

    public function set_taxonomy_rest_controller($args, $taxonomy)
    {
        $restController = isset($args['rest_controller_class']) ? $args['rest_controller_class'] : null;
        if(!$this->should_use_custom_class($restController, 'taxonomy')){
            return $args;
        }

        $args['rest_controller_class'] = WP_Rest_Cache_Term_Controller::class;

        return $args;
    }

    public function edited_terms($term_id, $taxonomy)
    {
        $term = get_term($term_id, $taxonomy);
        $tax_object = get_taxonomies(['name' => $term->taxonomy], 'objects')[$term->taxonomy];
        if(!$this->should_use_custom_class($tax_object->rest_controller_class, 'taxonomy')) {
            return;
        }

        $controller = new WP_Rest_Cache_Term_Controller($term->taxonomy);
        $controller->update_item_cache($term);
    }

    public function delete_term($term_id)
    {
        $term = get_term($term_id);
        $tax_object = get_taxonomies(['name' => $term->taxonomy], 'objects')[$term->taxonomy];
        if(!$this->should_use_custom_class($tax_object->rest_controller_class, 'taxonomy')) {
            return;
        }

        $controller = new WP_Rest_Cache_Term_Controller($term->taxonomy);
        $controller->delete_item_cache($term);
    }

    protected function should_use_custom_class($class_name, $type)
    {
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

    public static function clear_cache()
    {
        global $wpdb;

        return $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '_transient_wp_rest_cache_%'
        ) );
    }

}
