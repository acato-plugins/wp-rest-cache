<?php

/**
 * Fired during plugin deactivation
 *
 * @link:       http://www.acato.nl
 * @since      2018.1
 *
 * @package    WP_Rest_Cache
 * @subpackage WP_Rest_Cache/includes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      2018.1
 * @package    WP_Rest_Cache
 * @subpackage WP_Rest_Cache/includes
 * @author:       Richard Korthuis - Acato <richardkorthuis@acato.nl>
 */
class WP_Rest_Cache_Deactivator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    2018.1
	 */
	public static function deactivate() {
        WP_Rest_Cache_Api::clear_cache();
	}

}
