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
class WP_Rest_Cache_Endpoint_Api {

    private $request_uri;

    private $cache_key;

    private $response_headers = array(
        'Content-Type'                  => 'application/json; charset=UTF-8',
        'X-WP-cached-call'              => 'served-cache',
        'X-Robots-Tag'                  => 'noindex',
        'X-Content-Type-Options'        => 'nosniff',
        'Access-Control-Expose-Headers' => 'X-WP-Total, X-WP-TotalPages',
        'Access-Control-Allow-Headers'  => 'Authorization, Content-Type'
    );

    /**
     * Initialize the class and set its properties.
     *
     * @since    2018.1
     */
    public function __construct() {
    }

    public function save_post( $post_id, WP_Post $post ) {
        $this->update_item_cache( $post );
    }

    public function delete_post( $post_id ) {
        $post = get_post( $post_id );
        if ( wp_is_post_revision( $post ) ) {
            return;
        }

        $this->delete_item_cache( $post );
    }

    public function edited_terms( $term_id, $taxonomy ) {
        $term = get_term( $term_id, $taxonomy );
        $this->update_item_cache( $term );
    }

    public function delete_term( $term_id ) {
        $term = get_term( $term_id );
        $this->delete_item_cache( $term );
    }

    public function build_request_uri() {
        $request_uri  = filter_input( INPUT_SERVER, 'REQUEST_URI', FILTER_SANITIZE_URL );
        $uri_parts    = parse_url( $request_uri );
        $request_path = rtrim( $uri_parts['path'], '/' );

        if ( isset( $uri_parts['query'] ) && ! empty( $uri_parts['query'] ) ) {
            parse_str( $uri_parts['query'], $params );
            ksort( $params );
            $request_path .= '?' . http_build_query( $params );
        }

        $this->request_uri = $request_path;
        $this->cache_key   = 'wp_rest_cache_' . md5( $this->request_uri );

        return $request_path;
    }


    public function save_cache_headers( $served, WP_HTTP_Response $result, WP_REST_Request $request, WP_REST_Server $server ) {
        $headers = $result->get_headers();

        if ( isset( $headers ) && ! empty( $headers ) ) {
            foreach ( $headers as $key => $value ) {
                $this->response_headers[ $key ] = $value;
            }
        }

    }

    public function save_cache( $result, WP_REST_Server $server, WP_REST_Request $request ) {
        // Only Avoid cache if not 200
        if ( ! empty( $result ) && is_array( $result ) && isset( $result['data']['status'] ) && (int) $result['data']['status'] !== 200 ) {
            return $result;
        }

        // Encode the json result
        $data       = array(
            'data'    => $result,
            'headers' => $this->response_headers
        );
        $last_error = json_last_error();

        // No errors? Lets save!
        if ( $last_error === JSON_ERROR_NONE ) {
            set_transient( $this->cache_key, $data, WP_Rest_Cache::get_timeout() );
            $this->save_cache_relations( $result, $this->cache_key );
        }

        return $result;
    }

    public function skip_caching() {
        // Don't run if we are calling to cache the request (see later in the code)
        if ( isset( $_GET['wp-rest-cache'] ) && (string) $_GET['wp-rest-cache'] === '1' ) {
            return true;
        }

        // Only cache GET-requests
        if ( 'GET' !== filter_input( INPUT_SERVER, 'REQUEST_METHOD', FILTER_SANITIZE_STRING ) ) {
            return true;
        }

        // Make sure we only apply to allowed api calls
        $rest_prefix = sprintf( '/%s/', get_option( 'wp_rest_cache_rest_prefix', 'wp-json' ) );
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

        if ( ! $allowed_endpoint ) {
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

        if ( $this->skip_caching() ) {
            return;
        }

        $cache = get_transient( $this->cache_key );

        if ( $cache !== false ) {
            // We want the data to be json
            $data       = wp_json_encode( $cache['data'] );
            $last_error = json_last_error();

            if ( $last_error === JSON_ERROR_NONE ) {
                foreach ( $cache['headers'] as $key => $value ) {
                    $header = sprintf( '%s: %s', $key, $value );
                    header( $header );
                }

                echo $data;
                exit;
            }
        }

        // catch the headers after serving
        add_filter( 'rest_pre_serve_request', [ $this, 'save_cache_headers' ], 9999, 4 );

        // catch the result after serving
        add_filter( 'rest_pre_echo_response', [ $this, 'save_cache' ], 1000, 3 );
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

    /**
     * @param WP_Term|WP_Post $item
     */
    public function update_item_cache( $item ) {
        $this->delete_related_caches( $item );
    }

    /**
     * @param WP_Term|WP_Post $item
     */
    public function delete_item_cache( $item ) {
        $this->delete_related_caches( $item );
    }

    /**
     * Delete all related caches for the item that has just been updated.
     *
     * @param WP_Post|WP_Term $item The item that has been updated.
     */
    private function delete_related_caches( $item ) {
        switch ( get_class( $item ) ) {
            case WP_Post::class:
                $this->delete_post_related_caches( $item );
                break;
            case WP_Term::class:
                $this->delete_term_related_caches( $item );
                break;
        }
    }

    /**
     * Delete all related caches for the selected post.
     *
     * @param WP_Post $post The post item that has been updated.
     */
    private function delete_post_related_caches( $post ) {
        $related_caches = get_post_meta( $post->ID, '_wp_rest_cache_entry' );
        foreach ( $related_caches as $related_cache ) {
            delete_transient( $related_cache );
            $this->delete_cache_related_posts_meta( $related_cache );
        }
        delete_post_meta( $post->ID, '_wp_rest_cache_entry' );
    }

    /**
     * Delete all related caches for the selected term.
     *
     * @param WP_Term $term The term item that has been updated.
     */
    private function delete_term_related_caches( $term ) {
        $related_caches = get_term_meta( $term->term_id, '_wp_rest_cache_entry' );
        foreach ( $related_caches as $related_cache ) {
            delete_transient( $related_cache );
            $this->delete_cache_related_terms_meta( $related_cache );
        }
        delete_term_meta( $term->term_id, '_wp_rest_cache_entry' );
    }

    /**
     * Delete all relations from posts for a cache that has been deleted.
     *
     * @param string $related_cache The cache key for a cache that has just been deleted.
     */
    private function delete_cache_related_posts_meta( $related_cache ) {
        $posts = get_posts( [
            'meta_key'    => '_wp_rest_cache_entry',
            'meta_value'  => $related_cache,
            'nopaging'    => true,
            'post_status' => 'any',
            'fields'      => 'ids'
        ] );

        foreach ( $posts as $post ) {
            delete_post_meta( $post, '_wp_rest_cache_entry', $related_cache );
        }
    }

    /**
     * Delete all relations from terms for a cache that has been deleted.
     *
     * @param string $related_cache The cache key for a cache that has just been deleted.
     */
    private function delete_cache_related_terms_meta( $related_cache ) {
        $terms = get_terms( [
            'meta_key'   => '_wp_rest_cache_entry',
            'meta_value' => $related_cache,
            'hide_empty' => false,
            'fields'     => 'ids'
        ] );

        foreach ( $terms as $term ) {
            delete_term_meta( $term, '_wp_rest_cache_entry', $related_cache );
        }
    }
}
