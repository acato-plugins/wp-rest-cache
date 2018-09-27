<?php

/**
 * Fired during plugin deactivation
 *
 * @link:       http://www.acato.nl
 * @since      1.0.0
 *
 * @package    Acato_Rest_Cache
 * @subpackage Acato_Rest_Cache/includes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    Acato_Rest_Cache
 * @subpackage Acato_Rest_Cache/includes
 * @author:       Richard Korthuis - Acato <richardkorthuis@acato.nl>
 */
class Acato_Rest_Cache_Deactivator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function deactivate() {
        Acato_Rest_Cache_Api::clear_cache();
	}

}
