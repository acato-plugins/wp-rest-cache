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

    public function set_rest_controller($args, $name)
    {
        $restController = isset($args['rest_controller_class']) ? $args['rest_controller_class'] : null;
        if(!$this->should_use_custom_class($restController, $name)){
            return $args;
        }

        $args['rest_controller_class'] = WP_Rest_Cache_Post_Controller::class;

        return $args;

    }

    protected function should_use_custom_class($className, $postType)
    {
        return $className == WP_REST_Posts_Controller::class;
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
