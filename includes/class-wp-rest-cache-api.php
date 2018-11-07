<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link:       http://www.acato.nl
 * @since      2018.1
 *
 * @package    WP_Rest_Cache
 * @subpackage WP_Rest_Cache/includes
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    WP_Rest_Cache
 * @subpackage WP_Rest_Cache/includes
 * @author:       Richard Korthuis - Acato <richardkorthuis@acato.nl>
 */
class WP_Rest_Cache_Api
{

    /**
     * The ID of this plugin.
     *
     * @since    2018.1
     * @access   private
     * @var      string $plugin_name The ID of this plugin.
     */
    private $plugin_name;

    /**
     * The version of this plugin.
     *
     * @since    2018.1
     * @access   private
     * @var      string $version The current version of this plugin.
     */
    private $version;

    /**
     * Initialize the class and set its properties.
     *
     * @since    2018.1
     */
    public function __construct()
    {
    }

    public function set_post_type_rest_controller($args, $post_type)
    {
        $restController = isset($args['rest_controller_class']) ? $args['rest_controller_class'] : null;
        if(!$this->should_use_custom_class($restController, 'post_type')){
            return $args;
        }

        if($restController == WP_REST_Attachments_Controller::class) {
            $args['rest_controller_class'] = WP_Rest_Cache_Attachment_Controller::class;
        }
        else {
            $args['rest_controller_class'] = WP_Rest_Cache_Post_Controller::class;
        }

        return $args;
    }

    public function save_post($post_id, WP_Post $post)
    {
        $post_type = get_post_types(['name' => $post->post_type], 'objects')[$post->post_type];
        if(!$this->should_use_custom_class($post_type->rest_controller_class, 'post_type')
        || wp_is_post_revision($post)) {
            return;
        }

        $controller = new WP_Rest_Cache_Post_Controller($post->post_type);
        $controller->update_item_cache($post);
    }

    public function delete_post($post_id)
    {
        $post = get_post($post_id);
        if(wp_is_post_revision($post)) {
            return;
        }
        $post_type = get_post_types(['name' => $post->post_type], 'objects')[0];
        if(!$this->should_use_custom_class($post_type->rest_controller_class, 'post_type')) {
            return;
        }

        $controller = new WP_Rest_Cache_Post_Controller($post->post_type);
        $controller->delete_item_cache($post);
    }

    public function set_taxonomy_rest_controller($args, $taxonomy)
    {
        $restController = isset($args['rest_controller_class']) ? $args['rest_controller_class'] : null;
        if(!$this->should_use_custom_class($restController, 'taxonomy')){
            return $args;
        }

        $args['rest_controller_class'] = WP_Rest_Cache_Term_Controller::class;

        return $args;
    }

    public function edited_terms($term_id, $taxonomy)
    {
        $term = get_term($term_id, $taxonomy);
        $tax_object = get_taxonomies(['name' => $term->taxonomy], 'objects')[$term->taxonomy];
        if(!$this->should_use_custom_class($tax_object->rest_controller_class, 'taxonomy')) {
            return;
        }

        $controller = new WP_Rest_Cache_Term_Controller($term->taxonomy);
        $controller->update_item_cache($term);
    }

    public function delete_term($term_id)
    {
        $term = get_term($term_id);
        $tax_object = get_taxonomies(['name' => $term->taxonomy], 'objects')[$term->taxonomy];
        if(!$this->should_use_custom_class($tax_object->rest_controller_class, 'taxonomy')) {
            return;
        }

        $controller = new WP_Rest_Cache_Term_Controller($term->taxonomy);
        $controller->delete_item_cache($term);
    }

    protected function should_use_custom_class($class_name, $type)
    {
        if ( is_null( $class_name ) ) {
            return true;
        }
        switch ( $type ) {
            case 'taxonomy':
                return $class_name == WP_REST_Terms_Controller::class
                       || $class_name == WP_Rest_Cache_Term_Controller::class;
            case 'post_type':
            default:
                return $class_name == WP_REST_Posts_Controller::class
                       || $class_name == WP_Rest_Cache_Post_Controller::class
                       || $class_name == WP_REST_Attachments_Controller::class
                       || $class_name == WP_Rest_Cache_Attachment_Controller::class;
        }
    }

    public static function clear_cache()
    {
        global $wpdb;

        return $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '_transient_wp_rest_cache_%'
        ) );
    }

    /**
     * Check if the current call is a REST API call, if so check if it has already been cached, otherwise cache it.
     * Inspired by https://stackoverflow.com/a/36438831
     */
    public function get_api_cache() {
        // Don't run if we are calling to cache the request (see later in the code)
        if ( isset( $_GET['wp-rest-cache'] ) && $_GET['wp-rest-cache'] === '1' ) {
            return;
        }

        // Only cache GET-requests
        if ( 'GET' !== filter_input( INPUT_SERVER, 'REQUEST_METHOD', FILTER_SANITIZE_STRING ) ) {
            return;
        }

        // Make sure we only apply to allowed api calls
        $request_uri = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL );
        $rest_prefix = '/' . get_option('wp_rest_cache_rest_prefix', 'wp-json') . '/';
        if ( strpos( $request_uri, $rest_prefix ) === false ) {
            return;
        }

        $allowed_endpoints = get_option('wp_rest_cache_allowed_endpoints', []);

        $allowed_endpoint  = false;
        foreach ( $allowed_endpoints as $namespace => $endpoints ) {
            foreach ( $endpoints as $endpoint ) {
                if ( strpos( $request_uri, $rest_prefix . $namespace . '/' . $endpoint ) !== false ) {
                    $allowed_endpoint = true;
                    break 2;
                }
            }
        }

        if ( ! $allowed_endpoint ) {
            return;
        }

        //ok reasonably confident its a api call...

        $already_cached = true;
        $cache_key = 'wp_rest_cache_' . md5($request_uri);

        if ( ( $data = get_transient( $cache_key ) ) === false ) {
            $already_cached = false;
            $ch = curl_init();

            $request_uri = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL );
            list( $request_uri ) = explode( '?', $request_uri );
            $url = get_home_url() . $request_uri . '?' . http_build_query( array_merge( $_GET, [ 'wp-rest-cache' => 1 ] ) );
            $headers = [];

            curl_setopt( $ch, CURLOPT_URL, $url );
            curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
            curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 5 );
            curl_setopt( $ch, CURLOPT_ENCODING, '' );

            // https://stackoverflow.com/a/41135574
            curl_setopt( $ch, CURLOPT_HEADERFUNCTION,
                function($curl, $header) use (&$headers) {
                    $len = strlen($header);
                    $header = explode(':', $header, 2);
                    if (count($header) < 2) {
                        return $len;
                    }

                    $name = $header[0];
                    if(substr(strtolower($name), 0, 5) == 'x-wp-') {
                        if (!array_key_exists($name, $headers)) {
                            $headers[ $name ] = [ trim( $header[1] ) ];
                        } else {
                            $headers[ $name ][] = trim( $header[1] );
                        }
                    }
                    return $len;
                }
            );
            $json = curl_exec( $ch );
            $httpcode = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
            curl_close( $ch );

            $decoded = json_decode($json, true);
            if($httpcode == 200 && is_array($decoded)) {
                $data = [
                    'json' => $json,
                    'headers' => $headers
                ];
                set_transient( $cache_key, $data );
                $this->save_cache_relations($decoded, $cache_key);
            }
        }

        header('Content-Type: application/json; charset=UTF-8');
        header( 'X-WP-cached-call: ' . ($already_cached ? 'served-cache' : 'freshly-cached'));
        if(count($data['headers'])) {
            foreach ( $data['headers'] as $header => $values ) {
                foreach ( $values as $value ) {
                    header( $header . ': ' . $value );
                }
            }
        }
        echo $data['json'];
        exit;// we need nothing else from php exit
    }

    /**
     * Save all cache relations for the current cache. This is done so caches can be cleared when a relation is updated.
     *
     * @param array $json       The REST API result containing all objects
     * @param string $cache_key The cache key for the current cache
     */
    private function save_cache_relations( $json, $cache_key ) {
        if ( array_key_exists( 'id', $json ) ) {
            if ( array_key_exists( 'type', $json ) ) {
                $function = 'add_post_meta';
            } else if ( array_key_exists( 'taxonomy', $json ) ) {
                $function = 'add_term_meta';
            } else {
                return;
            }
            call_user_func_array( $function, [ $json['id'], '_wp_rest_cache_entry', $cache_key ] );
        } else {
            if ( count( $json ) ) {
                if ( array_key_exists( 'type', $json[0] ) ) {
                    $function = 'add_post_meta';
                } else if ( array_key_exists( 'taxonomy', $json[0] ) ) {
                    $function = 'add_term_meta';
                } else {
                    return;
                }
                foreach ( $json as $post_array ) {
                    call_user_func_array( $function, [ $post_array['id'], '_wp_rest_cache_entry', $cache_key ] );
                }
            }
        }
    }

    /**
     * Re-save the options if they have changed.
     */
    public function save_options() {
        $original_allowed_endpoints = get_option( 'wp_rest_cache_allowed_endpoints', [] );
        $allowed_endpoints          = apply_filters( 'wp_rest_cache/allowed_endpoints', $original_allowed_endpoints );
        if ( $original_allowed_endpoints != $allowed_endpoints ) {
            update_option( 'wp_rest_cache_allowed_endpoints', $allowed_endpoints );
        }

        $original_rest_prefix = get_option( 'wp_rest_cache_rest_prefix' );
        $rest_prefix          = rest_get_url_prefix();
        if ( $original_rest_prefix != $rest_prefix ) {
            update_option( 'wp_rest_cache_rest_prefix', $rest_prefix );
        }
    }
}
