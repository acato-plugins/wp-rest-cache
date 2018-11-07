<?php

require_once WP_PLUGIN_DIR . '/wp-rest-cache/wp-rest-cache.php';

$api = new WP_Rest_Cache_Api();
$api->get_api_cache();