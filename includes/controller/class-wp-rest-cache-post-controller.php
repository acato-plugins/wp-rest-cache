<?php
/**
 * REST Controller for (Custom) Post Type caching.
 *
 * @link:       http://www.acato.nl
 * @since       2018.1
 *
 * @package     WP_Rest_Cache
 * @subpackage  WP_Rest_Cache/includes/controller
 */

/**
 * REST Controller for (Custom) Post Type caching.
 *
 * @package     WP_Rest_Cache
 * @subpackage  WP_Rest_Cache/includes/controller
 * @author:     Richard Korthuis - Acato <richardkorthuis@acato.nl>
 */
class WP_Rest_Cache_Post_Controller extends WP_REST_Posts_Controller {
    use WP_Rest_Cache_Controller_Trait;
}