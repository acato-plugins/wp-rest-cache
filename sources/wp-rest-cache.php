<?php
/**
 * The plugin Must-Use file
 *
 * @link:             http://www.acato.nl
 * @since             2018.2.0
 * @package           WP_Rest_Cache
 *
 * @wordpress-plugin
 * Plugin Name:       WP REST Cache - Must-Use Plugin
 * Plugin URI:        http://www.acato.nl
 * Description:       This is the Must-Use version of the WP REST Cache plugin. Deactivating that plugin will remove this Must-Use plugin.
 * Version:           2018.2.1
 * Author:            Richard Korthuis - Acato
 * Author URI:        http://www.acato.nl
 * Text Domain:       wp-rest-cache
 * Domain Path:       /languages
 */

include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
if(is_plugin_active('wp-rest-cache/wp-rest-cache.php')) {
    require_once WP_PLUGIN_DIR . '/wp-rest-cache/wp-rest-cache.php';

    $api = new WP_Rest_Cache_Endpoint_Api();
    $api->get_api_cache();
}