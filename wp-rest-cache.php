<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link:       http://www.acato.nl
 * @since             2018.1
 * @package           WP_Rest_Cache
 *
 * @wordpress-plugin
 * Plugin Name:       WP REST Cache
 * Plugin URI:        http://www.acato.nl
 * Description:       Adds caching to the WP REST API
 * Version:           2018.2.0
 * Author:            Corne Guijt & Richard Korthuis - Acato
 * Author URI:        http://www.acato.nl
 * License:            GPL-2.0+
 * License URI:        http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:            wp-rest-cache
 * Domain Path:            /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-wp-rest-cache-activator.php
 */
function activate_WP_Rest_Cache() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-wp-rest-cache-activator.php';
	WP_Rest_Cache_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-wp-rest-cache-deactivator.php
 */
function deactivate_WP_Rest_Cache() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-wp-rest-cache-deactivator.php';
	WP_Rest_Cache_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_WP_Rest_Cache' );
register_deactivation_hook( __FILE__, 'deactivate_WP_Rest_Cache' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-wp-rest-cache.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    2018.1
 */
function run_WP_Rest_Cache() {

	$plugin = new WP_Rest_Cache();

}
run_WP_Rest_Cache();
