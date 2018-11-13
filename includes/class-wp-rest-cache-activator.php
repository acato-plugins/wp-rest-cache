<?php

/**
 * Fired during plugin activation
 *
 * @link:      http://www.acato.nl
 * @since      2018.1
 *
 * @package    WP_Rest_Cache
 * @subpackage WP_Rest_Cache/includes
 */

/**
 * Fired during plugin activation.
 *
 * This class defines all code necessary to run during the plugin's activation.
 *
 * @since      2018.1
 * @package    WP_Rest_Cache
 * @subpackage WP_Rest_Cache/includes
 * @author:    Richard Korthuis - Acato <richardkorthuis@acato.nl>
 */
class WP_Rest_Cache_Activator {

    /**
     * Activate the plugin. Copy mu-plugin to correct directory.
     */
    public static function activate() {
        if ( ! get_option( 'wp_rest_cache_allowed_endpoints' ) ) {
            add_option( 'wp_rest_cache_allowed_endpoints', [], '', false );
        }
        if ( ! get_option( 'wp_rest_cache_rest_prefix' ) ) {
            add_option( 'wp_rest_cache_rest_prefix', rest_get_url_prefix(), '', false );
        }

        $access_type = get_filesystem_method();
        if ( $access_type !== 'direct' ) {
            return;
        }
        $request_uri = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL );
        $url         = get_home_url() . $request_uri;
        $creds       = request_filesystem_credentials( $url );
        if ( ! WP_Filesystem( $creds ) ) {
            return;
        }
        global $wp_filesystem;

        if ( ! $wp_filesystem->is_dir( WPMU_PLUGIN_DIR ) ) {
            $wp_filesystem->mkdir( WPMU_PLUGIN_DIR );
        }

        $source = plugin_dir_path( __DIR__ ) . 'sources/wp-rest-cache.php';
        $target = WPMU_PLUGIN_DIR . '/wp-rest-cache.php';
        $wp_filesystem->copy( $source, $target );
    }
}