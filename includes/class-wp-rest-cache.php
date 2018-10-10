<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link:       http://www.acato.nl
 * @since      2018.1
 *
 * @package    WP_Rest_Cache
 * @subpackage WP_Rest_Cache/includes
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
 * @since      2018.1
 * @package    WP_Rest_Cache
 * @subpackage WP_Rest_Cache/includes
 * @author:       Richard Korthuis - Acato <richardkorthuis@acato.nl>
 */
class WP_Rest_Cache {

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    2018.1
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    2018.1
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    2018.1
	 */
	public function __construct() {

		$this->plugin_name = 'wp-rest-cache';
		$this->version = '2018.1';

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
	 * - WP_Rest_Cache_Loader. Orchestrates the hooks of the plugin.
	 * - WP_Rest_Cache_i18n. Defines internationalization functionality.
	 * - WP_Rest_Cache_Admin. Defines all hooks for the admin area.
	 * - WP_Rest_Cache_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    2018.1
	 * @access   private
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
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wp-rest-cache-api.php';

		/**
		 * The class responsible for defining all actions that occur for the REST Api.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-wp-rest-cache-post-controller.php';

	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the WP_Rest_Cache_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    2018.1
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new WP_Rest_Cache_i18n();

		add_action( 'plugins_loaded', [$plugin_i18n, 'load_plugin_textdomain'] );

	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    2018.1
	 * @access   private
	 */
	private function define_admin_hooks() {

		$plugin_admin = new WP_Rest_Cache_Admin( $this->get_plugin_name(), $this->get_version() );

		add_action( 'admin_enqueue_scripts', array($plugin_admin, 'enqueue_styles') );
		add_action( 'admin_enqueue_scripts', array($plugin_admin, 'enqueue_scripts') );
        // create custom plugin settings menu
        add_action('admin_menu', [$plugin_admin, 'create_menu']);

        add_action( 'save_post', [$plugin_admin, 'update_item'], 90, 2);
        add_action( 'delete_post', [$plugin_admin, 'delete_item']);
        add_action( 'wp_before_admin_bar_render', [$plugin_admin, 'admin_bar_item'], 999 );
	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    2018.1
	 * @access   private
	 */
	private function define_api_hooks() {

        $plugin_api = new WP_Rest_Cache_Api( $this->get_plugin_name(), $this->get_version() );
        add_filter( 'register_post_type_args', [$plugin_api, 'set_rest_controller'], 10, 2 );
//
//
//
//        //add_action( 'wp_enqueue_scripts', array( $plugin_api, 'enqueue_styles') );
//        //add_action( 'wp_enqueue_scripts', array( $plugin_api, 'enqueue_scripts') );
//        add_action( 'rest_prepare_post_type', array( $plugin_api, 'filter_vacancy_overview' ), 10, 1 );
//		add_action( 'init', array( 'WP_Rest_Cache_Posttpes', 'posttype_vacancy') );
//        add_action( 'init', array( 'WP_Rest_Cache_Posttpes', 'posttype_teammember') );
//		add_action( 'init', array( 'WP_Rest_Cache_Posttpes', 'taxonomy_workfield') );
//        add_action( 'init', array( 'WP_Rest_Cache_Posttpes', 'taxonomy_function') );
//		add_action( 'edit_tag_form_fields', array( 'WP_Rest_Cache_Posttpes', 'add_tax_wysiwyg_description'), 10, 2);
//        add_action ('init', array( 'WP_Rest_Cache_Posttpes', 'allow_html_tax_description'), 1000, 1);
//
//        // Api hooks
//		add_action( 'admin_init', array( 'WP_Rest_Cache_Sync', 'browser_run_sync'));
//        add_action( 'wp_version_check', array( 'WP_Rest_Cache_Sync', 'schedule' ) );
//        add_action( 'connexys_cron_sync', array( 'WP_Rest_Cache_Sync', 'run_sync' ) );
//
//        add_action( 'widgets_init', function(){
//            register_widget( 'WP_Widget_Function' );
//            register_widget( 'WP_Widget_Teammemberquote' );
//        });
	}

    /**
     * @param WP_Post|int $post
     * @return string
     */
    public static function transient_key($post)
    {
        $id = $post instanceof WP_Post ? $post->ID : $post;

        return 'wp_rest_cache_' . $id;
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     2018.1
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     2018.1
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

}
