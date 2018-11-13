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
class WP_Rest_Cache_Api {

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

	private $request_uri;

	private $cache_key;

	private $response_headers = array(
        'Content-Type' => 'application/json; charset=UTF-8',
        'X-WP-cached-call' => 'served-cache',
        'X-Robots-Tag' => 'noindex',
        'X-Content-Type-Options' => 'nosniff',
		'Access-Control-Expose-Headers' => 'X-WP-Total, X-WP-TotalPages',
		'Access-Control-Allow-Headers' => 'Authorization, Content-Type'
    );

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    2018.1
	 */
	public function __construct() {
	}

	public function set_post_type_rest_controller( $args, $post_type ) {
		$restController = isset( $args['rest_controller_class'] ) ? $args['rest_controller_class'] : null;
		if ( ! $this->should_use_custom_class( $restController, 'post_type' ) ) {
			return $args;
		}

		if ( $restController == WP_REST_Attachments_Controller::class ) {
			$args['rest_controller_class'] = WP_Rest_Cache_Attachment_Controller::class;
		} else {
			$args['rest_controller_class'] = WP_Rest_Cache_Post_Controller::class;
		}

		return $args;
	}

	public function save_post( $post_id, WP_Post $post ) {

		$post_type = get_post_types( [ 'name' => $post->post_type ], 'objects' )[ $post->post_type ];
		if ( ! $this->should_use_custom_class( $post_type->rest_controller_class, 'post_type' )
		     || wp_is_post_revision( $post ) ) {
			return;
		}

		$controller = new WP_Rest_Cache_Post_Controller( $post->post_type );
		$controller->update_item_cache( $post );
	}

	public function delete_post( $post_id ) {
		$post = get_post( $post_id );
		if ( wp_is_post_revision( $post ) ) {
			return;
		}
		$post_type = get_post_types( [ 'name' => $post->post_type ], 'objects' )[0];
		if ( ! $this->should_use_custom_class( $post_type->rest_controller_class, 'post_type' ) ) {
			return;
		}

		$controller = new WP_Rest_Cache_Post_Controller( $post->post_type );
		$controller->delete_item_cache( $post );
	}

	public function set_taxonomy_rest_controller( $args, $taxonomy ) {
		$restController = isset( $args['rest_controller_class'] ) ? $args['rest_controller_class'] : null;
		if ( ! $this->should_use_custom_class( $restController, 'taxonomy' ) ) {
			return $args;
		}

		$args['rest_controller_class'] = WP_Rest_Cache_Term_Controller::class;

		return $args;
	}

	public function edited_terms( $term_id, $taxonomy ) {
		$term       = get_term( $term_id, $taxonomy );
		$tax_object = get_taxonomies( [ 'name' => $term->taxonomy ], 'objects' )[ $term->taxonomy ];
		if ( ! $this->should_use_custom_class( $tax_object->rest_controller_class, 'taxonomy' ) ) {
			return;
		}

		$controller = new WP_Rest_Cache_Term_Controller( $term->taxonomy );
		$controller->update_item_cache( $term );
	}

	public function delete_term( $term_id ) {
		$term       = get_term( $term_id );
		$tax_object = get_taxonomies( [ 'name' => $term->taxonomy ], 'objects' )[ $term->taxonomy ];
		if ( ! $this->should_use_custom_class( $tax_object->rest_controller_class, 'taxonomy' ) ) {
			return;
		}

		$controller = new WP_Rest_Cache_Term_Controller( $term->taxonomy );
		$controller->delete_item_cache( $term );
	}

	protected function should_use_custom_class( $class_name, $type ) {
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

	public static function clear_cache() {
		global $wpdb;

		// Remove all related post meta
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
			'_wp_rest_cache_entry'
		) );

		// Remove all relater term meta
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->termmeta} WHERE meta_key = %s",
			'_wp_rest_cache_entry'
		) );

		// Remove all cache entries
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			'%wp_rest_cache_%'
		) );
	}

	public function build_request_uri(){

		$request_uri    = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL );
		$uri_parts      = parse_url($request_uri);
		$request_path   = rtrim( $uri_parts['path'], '/');

		if( isset( $uri_parts['query'] ) && !empty( $uri_parts['query'] ) ){
			parse_str( $uri_parts['query'], $params );
			ksort($params);
			$request_path.= '?' . http_build_query($params);
		}

		$this->request_uri = $request_path;
		$this->cache_key = 'wp_rest_cache_' . md5( $this->request_uri );

		return $request_path;
	}


	public function save_cache_headers( $served, WP_HTTP_Response $result, WP_REST_Request $request, WP_REST_Server $server ){

	    $headers = $result->get_headers();
	    if( isset($headers) && !empty($headers) ){
	        foreach ( $headers as $key => $value ){
                $this->response_headers[$key] = $value;
            }
        }

    }

	public function save_cache( $result, WP_REST_Server $server, WP_REST_Request $request ) {

		// Only Avoid cache if not 200
		if( !empty( $result ) && is_array( $result ) && isset( $result['data']['status'] ) && (int) $result['data']['status'] !== 200 ){
			return $result;
		}

		// Encode the json result
		$data = array(
		    'data' => $result,
            'headers' => $this->response_headers
        );
		$last_error = json_last_error();

		// No errors? Lets save!
		if ( $last_error === JSON_ERROR_NONE  ) {
			set_transient( $this->cache_key, $data );
			$this->save_cache_relations( $result, $this->cache_key );
		}

		return $result;
	}

	public function skip_caching(){

		// Don't run if we are calling to cache the request (see later in the code)
		if ( isset( $_GET['wp-rest-cache'] ) && (string) $_GET['wp-rest-cache'] === '1' ) {
			return true;
		}

		// Only cache GET-requests
		if ( 'GET' !== filter_input( INPUT_SERVER, 'REQUEST_METHOD', FILTER_SANITIZE_STRING ) ) {
			return true;
		}

		// Make sure we only apply to allowed api calls
		$rest_prefix = sprintf('/%s/', get_option( 'wp_rest_cache_rest_prefix', 'wp-json' ) );
		if ( strpos( $this->request_uri, $rest_prefix ) === false ) {
			return true;
		}

		$allowed_endpoints = get_option( 'wp_rest_cache_allowed_endpoints', [] );

		$allowed_endpoint = false;
		foreach ( $allowed_endpoints as $namespace => $endpoints ) {
			foreach ( $endpoints as $endpoint ) {
				if ( strpos( $this->request_uri, $rest_prefix . $namespace . '/' . $endpoint ) !== false ) {
					$allowed_endpoint = true;
					break 2;
				}
			}
		}

		if ( !$allowed_endpoint ) {
			return true;
		}

		// We dont skip
		return false;

	}

	/**
	 * Check if the current call is a REST API call, if so check if it has already been cached, otherwise cache it.
	 * Inspired by https://stackoverflow.com/a/36438831
	 */
	public function get_api_cache() {

		$this->build_request_uri();

		if( $this->skip_caching() ) {
			return;
		}

		$cache = get_transient( $this->cache_key );

		if( $cache !== false ){

            // We want the data to be json
            $data       = wp_json_encode( $cache['data'] );

            $last_error = json_last_error();

            if( $last_error === JSON_ERROR_NONE ){

                foreach ( $cache['headers'] as $key => $value ){
                    $header = sprintf('%s: %s', $key, $value );
                    header( $header );
                }

                echo $data;
                exit;
            }

		}

        // catch the headers after serving
        add_filter( 'rest_pre_serve_request', [$this, 'save_cache_headers' ], 9999, 4 );

		// catch the result after serving
		add_filter( 'rest_pre_echo_response', [$this, 'save_cache' ], 1000, 3 );

	}

	/**
	 * Save all cache relations for the current cache. This is done so caches can be cleared when a relation is updated.
	 *
	 * @param array $json The REST API result containing all objects
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
