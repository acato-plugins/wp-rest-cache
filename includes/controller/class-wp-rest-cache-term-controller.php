<?php
/**
 * REST Controller for (Custom) Term caching.
 *
 * @link:       http://www.acato.nl
 * @since       2018.1
 *
 * @package     WP_Rest_Cache
 * @subpackage  WP_Rest_Cache/includes/controller
 */

/**
 * REST Controller for (Custom) Term caching.
 *
 * @package     WP_Rest_Cache
 * @subpackage  WP_Rest_Cache/includes/api
 * @author:     Richard Korthuis - Acato <richardkorthuis@acato.nl>
 */
class WP_Rest_Cache_Term_Controller extends WP_REST_Terms_Controller {
    use WP_Rest_Cache_Controller_Trait;
}