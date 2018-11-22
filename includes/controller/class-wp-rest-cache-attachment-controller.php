<?php

/**
 * REST Controller for Attachment caching.
 *
 * @link:       http://www.acato.nl
 * @since       2018.1
 *
 * @package     WP_Rest_Cache
 * @subpackage  WP_Rest_Cache/includes/controller
 */

/**
 * REST Controller for Attachment caching.
 *
 * @package     WP_Rest_Cache
 * @subpackage  WP_Rest_Cache/includes/controller
 * @author:     Richard Korthuis - Acato <richardkorthuis@acato.nl>
 */
class WP_Rest_Cache_Attachment_Controller extends WP_REST_Attachments_Controller {
    use WP_Rest_Cache_Controller_Trait;
}