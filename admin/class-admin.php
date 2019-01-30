<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link:       http://www.acato.nl
 * @since       2018.1
 *
 * @package     WP_Rest_Cache_Plugin
 * @subpackage  WP_Rest_Cache_Plugin/Admin
 */

namespace WP_Rest_Cache_Plugin\Admin;

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package     WP_Rest_Cache_Plugin
 * @subpackage  WP_Rest_Cache_Plugin/Admin
 * @author:     Richard Korthuis - Acato <richardkorthuis@acato.nl>
 */
class Admin {

    /**
     * The ID of this plugin.
     *
     * @access   private
     * @var      string $plugin_name The ID of this plugin.
     */
    private $plugin_name;

    /**
     * The version of this plugin.
     *
     * @access   private
     * @var      string $version The current version of this plugin.
     */
    private $version;

    /**
     * Notices to be displayed in the wp-admin.
     *
     * @access  private
     * @var     array $notices An array of notices to be displayed in the wp-admin.
     */
    private $notices;

    /**
     * Initialize the class and set its properties.
     *
     * @param   string $plugin_name The name of this plugin.
     * @param   string $version The version of this plugin.
     */
    public function __construct( $plugin_name, $version ) {
        $this->plugin_name = $plugin_name;
        $this->version     = $version;
        $this->notices     = [];
    }

    /**
     * Register the stylesheets for the admin area.
     */
    public function enqueue_styles() {
        wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/wp-rest-cache-admin.css', [], $this->version, 'all' );
    }

    /**
     * Add a new menu item under Settings.
     */
    public function create_menu() {
        $hook = add_submenu_page( 'options-general.php', 'WP REST Cache', 'WP REST Cache', 'administrator', 'wp-rest-cache', [
            $this,
            'settings_page'
        ] );

        add_action( "load-$hook", [ $this, 'add_screen_options' ] );
    }

    /**
     * Add screen options to the WP Admin
     */
    public function add_screen_options() {
        $args = [
            'label'   => __( 'Caches', 'wp-rest-cache' ),
            'default' => Includes\API_Caches_Table::ITEMS_PER_PAGE,
            'option'  => 'caches_per_page'
        ];
        add_screen_option( 'per_page', $args );
    }

    /**
     * Set the caches_per_pages screen option.
     *
     * @param   bool|int $option_value Screen option value. Default false to skip.
     * @param   string $option The option name.
     * @param   int $value The number of rows to use.
     *
     * @return int
     */
    public function set_screen_option( $option_value, $option, $value ) {
        if ( 'caches_per_page' === $option ) {
            return $value;
        }

        return $option_value;
    }

    /**
     * Add a settings link to the plugin on the Plugin admin screen.
     *
     * @param   array $links An array of plugin action links.
     *
     * @return  array An array of plugin action links.
     */
    public function add_plugin_settings_link( $links ) {
        $links[] = '<a href="' .
                   admin_url( 'options-general.php?page=wp-rest-cache' ) . '">' .
                   __( 'Settings', 'wp-rest-cache' ) . '</a>';

        return $links;
    }

    /**
     * Register plugin specific settings.
     */
    public function register_settings() {
        register_setting( 'wp-rest-cache-settings', 'wp_rest_cache_timeout' );
        register_setting( 'wp-rest-cache-settings', 'wp_rest_cache_timeout_interval' );
    }

    /**
     * Display the plugin settings page.
     */
    public function settings_page() {
        $sub = filter_input( INPUT_GET, 'sub', FILTER_SANITIZE_STRING );
        if ( ! strlen( $sub ) ) {
            $sub = 'settings';
        }
        require_once( __DIR__ . '/partials/header.php' );
        require_once( __DIR__ . '/partials/sub-' . $sub . '.php' );
    }

    /**
     * Add a 'Clear REST cache' button to the wp-admin top bar.
     */
    public function admin_bar_item() {
        global $wp_admin_bar;

        $args = [
            'id'    => 'wp-rest-cache-clear',
            'title' => '<span class="ab-icon"></span>' . __( 'Clear REST cache', 'wp-rest-cache' ),
            'href'  => self::empty_cache_url(),
        ];

        $wp_admin_bar->add_menu( $args );
    }

    /**
     * Create the url to empty the cache.
     *
     * @return  string The url to empty the cache.
     */
    public static function empty_cache_url() {
        return wp_nonce_url( admin_url( 'options-general.php?page=wp-rest-cache&clear=1' ), 'wp_rest_cache_options', 'wp_rest_cache_nonce' );
    }

    /**
     * Handle the correct actions. I.e. clear the cache if the clear cache url is visited.
     */
    public function handle_actions() {
        if ( isset( $_REQUEST['wp_rest_cache_nonce'] ) && wp_verify_nonce( sanitize_key( $_REQUEST['wp_rest_cache_nonce'] ), 'wp_rest_cache_options' ) ) {
            if ( isset( $_GET['clear'] ) && '1' === $_GET['clear'] ) {
                if ( \WP_Rest_Cache_Plugin\Includes\Caching\Caching::get_instance()->clear_caches() ) {
                    $this->add_notice( 'success', __( 'The cache has been successfully cleared', 'wp-rest-cache' ) );
                } else {
                    $this->add_notice( 'error', __( 'There were no items cached', 'wp-rest-cache' ) );
                }
            }
        }
    }

    /**
     * Add a notice to the wp-admin.
     *
     * @param   string $type The type of message (error|warning|success|info).
     * @param   string $message The message to display.
     * @param   bool $dismissible Should the message be dismissible?
     */
    protected function add_notice( $type, $message, $dismissible = true ) {
        $this->notices[ $type ][] = [ 'message' => $message, 'dismissible' => $dismissible ];
    }

    /**
     * Check if the MU plugin was created, if not display a warning.
     */
    public function check_muplugin_existence() {
        if ( ! file_exists( WPMU_PLUGIN_DIR . '/wp-rest-cache.php' ) ) {
            \WP_Rest_Cache_Plugin\Includes\Activator::create_mu_plugin();
            if ( ! file_exists( WPMU_PLUGIN_DIR . '/wp-rest-cache.php' ) ) {

                $from = '<code>' . substr(
                        plugin_dir_path( __DIR__ ) . 'sources/wp-rest-cache.php',
                        strpos( plugin_dir_path( __DIR__ ), '/wp-content/' )
                    ) . '</code>';
                $to   = '<code>' . substr(
                        WPMU_PLUGIN_DIR . '/wp-rest-cache.php',
                        strpos( WPMU_PLUGIN_DIR, '/wp-content/' )
                    ) . '</code>';

                $this->add_notice(
                    'warning',
                    sprintf(
                    /* translators: %1$s: source-directory, %2$s: target-directory */
                        __( 'You are not getting the best caching result! <br/>Please copy %1$s to %2$s', 'wp-rest-cache' ),
                        $from,
                        $to
                    ),
                    false );
            }
        }
    }

    /**
     * Display notices (if any) on the Admin dashboard
     */
    public function display_notices() {
        if ( count( $this->notices ) ) {
            foreach ( $this->notices as $type => $messages ) {
                foreach ( $messages as $message ) {
                    ?>
                    <div
                        class="notice notice-<?php echo esc_attr( $type ); ?> <?php echo $message['dismissible'] ? 'is-dismissible' : ''; ?>">
                        <p><strong>WP REST Cache:</strong> <?php echo esc_html( $message['message'] ); ?></p>
                    </div>
                    <?php
                }
            }
        }
    }
}
