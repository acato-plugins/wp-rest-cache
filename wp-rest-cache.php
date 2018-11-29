<?php

/**
 * The plugin bootstrap file
 *
 * @link:           http://www.acato.nl
 * @since           2018.1
 * @package         WP_Rest_Cache_Plugin
 *
 * @wordpress-plugin
 * Plugin Name:     WP REST Cache
 * Plugin URI:      http://www.acato.nl
 * Description:     Adds caching to the WP REST API
 * Version:         2018.4.0
 * Author:          Corne Guijt & Richard Korthuis - Acato
 * Author URI:      http://www.acato.nl
 * Text Domain:     wp-rest-cache
 * Domain Path:     /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Autoload classes related to this plugin.
 *
 * @param string $class_name The requested class
 */
function wp_rest_cache_autoloader( $class_name ) {
    $file_path = explode( '\\', $class_name );
    if ( isset( $file_path[0] ) && $file_path[0] == 'WP_Rest_Cache_Plugin' ) {
        $file_name = strtolower( $file_path[ count( $file_path ) - 1 ] );
        unset( $file_path[ count( $file_path ) - 1 ] );
        unset( $file_path[0] );
        $subdir = '';
        if ( count( $file_path ) ) {
            $subdir = strtolower( implode( DIRECTORY_SEPARATOR, $file_path ) );
        }
        $subdir .= DIRECTORY_SEPARATOR;

        $file_name       = str_ireplace( '_', '-', $file_name );
        $file_name_parts = explode( '-', $file_name );

        switch ( $file_name_parts[ count( $file_name_parts ) - 1 ] ) {
            case 'trait':
            case 'interface':
                $type = $file_name_parts[ count( $file_name_parts ) - 1 ];
                unset( $file_name_parts[ count( $file_name_parts ) - 1 ] );
                $file_name = $type . '-' . implode( '-', $file_name_parts ) . '.php';
                break;
            default:
                $file_name = 'class-' . $file_name . '.php';
        }

        require_once plugin_dir_path( __FILE__ ) . $subdir . $file_name;
    } else if ( $class_name == 'WP_Rest_Cache_Endpoint_Api' ) {
        require_once plugin_dir_path( __FILE__ ) . DIRECTORY_SEPARATOR . 'deprecated' . DIRECTORY_SEPARATOR . 'class-wp-rest-cache-endpoint-api.php';
    }
}

spl_autoload_register( 'wp_rest_cache_autoloader' );

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-wp-rest-cache-activator.php
 */
function activate_WP_Rest_Cache() {
    \WP_Rest_Cache_Plugin\Includes\Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-wp-rest-cache-deactivator.php
 */
function deactivate_WP_Rest_Cache() {
    \WP_Rest_Cache_Plugin\Includes\Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_WP_Rest_Cache' );
register_deactivation_hook( __FILE__, 'deactivate_WP_Rest_Cache' );

/**
 * Begins execution of the plugin.
 */
function run_WP_Rest_Cache() {

    $plugin = new \WP_Rest_Cache_Plugin\Includes\Plugin();

}

run_WP_Rest_Cache();