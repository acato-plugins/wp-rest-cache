<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link:       http://www.acato.nl
 * @since      2018.1
 *
 * @package    WP_Rest_Cache
 * @subpackage WP_Rest_Cache/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    WP_Rest_Cache
 * @subpackage WP_Rest_Cache/admin
 * @author:       Richard Korthuis - Acato <richardkorthuis@acato.nl>
 */
class WP_Rest_Cache_Admin
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

    /**
     * Register the stylesheets for the admin area.
     *
     * @since    2018.1
     */
    public function enqueue_styles()
    {

        /**
         * This function is provided for demonstration purposes only.
         *
         * An instance of this class should be passed to the run() function
         * defined in WP_Rest_Cache_Loader as all of the hooks are defined
         * in that particular class.
         *
         * The WP_Rest_Cache_Loader will then create the relationship
         * between the defined hooks and the functions defined in this
         * class.
         */

        wp_enqueue_style($this->plugin_name, plugin_dir_url(__FILE__) . 'css/wp-rest-cache-admin.css', [], $this->version, 'all');

    }

    /**
     * Register the JavaScript for the admin area.
     *
     * @since    2018.1
     */
    public function enqueue_scripts()
    {

        /**
         * This function is provided for demonstration purposes only.
         *
         * An instance of this class should be passed to the run() function
         * defined in WP_Rest_Cache_Loader as all of the hooks are defined
         * in that particular class.
         *
         * The WP_Rest_Cache_Loader will then create the relationship
         * between the defined hooks and the functions defined in this
         * class.
         */

        wp_enqueue_script($this->plugin_name, plugin_dir_url(__FILE__) . 'js/wp-rest-cache-admin.js', ['jquery'], $this->version, false);

    }


    public function create_menu()
    {
        //create new top-level menu
        add_submenu_page('options-general.php', 'WP REST Cache', 'WP REST Cache', 'administrator', 'wp-rest-cache', [$this, 'settings_page']);

        //call register settings function
        add_action('admin_init', [$this, 'register_settings']);
    }


    public function register_settings()
    {
        // placeholder
    }

    public function settings_page()
    {
        $this->handle_actions();
        require_once(__DIR__ . '/partials/settings-page.php');
    }

    public function update_item($post_id, WP_Post $post)
    {
        $controller = new WP_Rest_Cache_Post_Controller($post->post_type);
        $data = $controller->get_data($post, new WP_REST_Request());

        set_transient(WP_Rest_Cache::transient_key($post), $data);
    }

    public function delete_item($post_id)
    {
        delete_transient(WP_Rest_Cache::transient_key($post_id));
    }

    // Add Toolbar Menus
    function admin_bar_item()
    {
        global $wp_admin_bar;

        $args = [
            'id' => 'wp-rest-cache-clear',
            'title' => __('Clear rest cache', 'scato-rest-cache'),
            'href' => self::empty_cache_url(),
        ];

        $wp_admin_bar->add_menu($args);
    }

    public static function empty_cache_url()
    {
        return wp_nonce_url(admin_url('options-general.php?page=wp-rest-cache&clear=1'), 'rest_cache_options', 'rest_cache_nonce');
    }

    protected function handle_actions()
    {
        $notice = NULL;

        if (isset($_REQUEST['rest_cache_nonce']) && wp_verify_nonce($_REQUEST['rest_cache_nonce'], 'rest_cache_options')) {
            if (isset($_GET['clear']) && 1 == $_GET['clear']) {
                if (WP_Rest_Cache_Api::clear_cache()) {
                    $this->add_notice('success', 'The cache has been successfully cleared');
                } else {
                    $this->add_notice('error', 'There were 0 items cached');
                }
            }
        }
    }

    protected function add_notice($type, $message)
    {
        // @todo at least show something!
    }

}
