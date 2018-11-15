<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link:       http://www.acato.nl
 * @since       2018.1
 *
 * @package     WP_Rest_Cache
 * @subpackage  WP_Rest_Cache/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @package     WP_Rest_Cache
 * @subpackage  WP_Rest_Cache/includes
 * @author:     Richard Korthuis - Acato <richardkorthuis@acato.nl>
 */
class WP_Rest_Cache {

    /**
     * The unique identifier of this plugin.
     *
     * @access   protected
     * @var      string $plugin_name The string used to uniquely identify this plugin.
     */
    protected $plugin_name;

    /**
     * The current version of the plugin.
     *
     * @access   protected
     * @var      string $version The current version of the plugin.
     */
    protected $version;

    /**
     * Define the core functionality of the plugin.
     *
     * Set the plugin name and the plugin version that can be used throughout the plugin.
     * Load the dependencies, define the locale, and set the hooks for the admin area and
     * the public-facing side of the site.
     */
    public function __construct() {

        $this->plugin_name = 'wp-rest-cache';
        $this->version     = '2018.2.0';

        $this->load_dependencies();
        $this->set_locale();
        $this->define_admin_hooks();
        $this->define_api_hooks();

    }

    /**
     * Load the required dependencies for this plugin.
     *
     * Include the following files that make up the plugin:
     *
     * - WP_Rest_Cache_i18n. Defines internationalization functionality.
     * - WP_Rest_Cache_Admin. Defines all hooks for the admin area.
     */
    private function load_dependencies() {

        /**
         * The class responsible for defining internationalization functionality
         * of the plugin.
         */
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wp-rest-cache-i18n.php';

        /**
         * The class responsible for defining all actions that occur in the admin area.
         */
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-wp-rest-cache-admin.php';

        /**
         * The class responsible for defining all actions that occur for the REST Api.
         */
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/api/class-wp-rest-cache-item-api.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/api/class-wp-rest-cache-endpoint-api.php';

        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/controller/trait-wp-rest-cache-controller-trait.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/controller/class-wp-rest-cache-post-controller.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/controller/class-wp-rest-cache-attachment-controller.php';
        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/controller/class-wp-rest-cache-term-controller.php';

    }

    /**
     * Define the locale for this plugin for internationalization.
     *
     * Uses the WP_Rest_Cache_i18n class in order to set the domain and to register the hook
     * with WordPress.
     */
    private function set_locale() {

        $plugin_i18n = new WP_Rest_Cache_i18n();

        add_action( 'plugins_loaded', [ $plugin_i18n, 'load_plugin_textdomain' ] );

    }

    /**
     * Register all of the hooks related to the admin area functionality of the plugin.
     */
    private function define_admin_hooks() {

        $plugin_admin = new WP_Rest_Cache_Admin( $this->get_plugin_name(), $this->get_version() );

        add_action( 'admin_enqueue_scripts', array( $plugin_admin, 'enqueue_styles' ) );
        add_action( 'admin_enqueue_scripts', array( $plugin_admin, 'enqueue_scripts' ) );
        // create custom plugin settings menu
        add_action( 'admin_menu', [ $plugin_admin, 'create_menu' ] );
        add_action( 'admin_init', [ $plugin_admin, 'register_settings' ] );
        add_action( 'admin_init', [ $plugin_admin, 'check_muplugin_existence' ] );
        add_action( 'admin_init', [ $plugin_admin, 'handle_actions' ] );
        add_action( 'admin_notices', [ $plugin_admin, 'display_notices' ] );

        add_action( 'wp_before_admin_bar_render', [ $plugin_admin, 'admin_bar_item' ], 999 );

    }

    /**
     * Register all of the hooks related to the api functionality of the plugin.
     */
    private function define_api_hooks() {
        $endpoint_api = new WP_Rest_Cache_Endpoint_Api();

        add_action( 'save_post', [ $endpoint_api, 'save_post' ], 998, 2 );
        add_action( 'delete_post', [ $endpoint_api, 'delete_post' ] );
        add_action( 'edited_terms', [ $endpoint_api, 'edited_terms' ], 998, 2 );
        add_action( 'delete_term', [ $endpoint_api, 'delete_term' ] );

        add_action( 'init', [ $endpoint_api, 'save_options' ] );
        add_action( 'rest_api_init', [ $endpoint_api, 'save_options' ] );

        $item_api = new WP_Rest_Cache_Item_Api();
        add_filter( 'register_post_type_args', [ $item_api, 'set_post_type_rest_controller' ], 10, 2 );
        add_filter( 'register_taxonomy_args', [ $item_api, 'set_taxonomy_rest_controller' ], 10, 2 );

        add_action( 'save_post', [ $item_api, 'save_post' ], 999, 2 );
        add_action( 'delete_post', [ $item_api, 'delete_post' ] );
        add_action( 'edited_terms', [ $item_api, 'edited_terms' ], 999, 2 );
        add_action( 'delete_term', [ $item_api, 'delete_term' ] );
    }

    /**
     * Get the cache key for the current ID.
     *
     * @param   string|int $id The ID used for the cache key.
     *
     * @return  string The cache key.
     */
    public static function transient_key( $id ) {
        return 'wp_rest_cache_' . $id;
    }

    /**
     * Get the cache timeout as set in the plugin Settings.
     *
     * @return  int Timeout in seconds.
     */
    public static function get_timeout() {
        return get_option( 'wp_rest_cache_timeout' ) ? get_option( 'wp_rest_cache_timeout' ) : 0;
    }

    /**
     * The name of the plugin used to uniquely identify it within the context of
     * WordPress and to define internationalization functionality.
     *
     * @return    string    The name of the plugin.
     */
    public function get_plugin_name() {
        return $this->plugin_name;
    }

    /**
     * Retrieve the version number of the plugin.
     *
     * @return    string    The version number of the plugin.
     */
    public function get_version() {
        return $this->version;
    }
}