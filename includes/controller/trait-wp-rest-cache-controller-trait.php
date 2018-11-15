<?php

/**
 * Trait for the REST Controller extensions.
 *
 * @link:       http://www.acato.nl
 * @since       2018.1
 *
 * @package     WP_Rest_Cache
 * @subpackage  WP_Rest_Cache/includes/controller
 */

/**
 * Trait for the REST Controller extensions.
 *
 * @package     WP_Rest_Cache
 * @subpackage  WP_Rest_Cache/includes/api
 * @author:     Richard Korthuis - Acato <richardkorthuis@acato.nl>
 */
trait WP_Rest_Cache_Controller_Trait {
    /**
     * Constructor.
     *
     * @param string $item Post type or taxonomy key.
     */
    public function __construct( $item ) {
        parent::__construct( $item );

        $allowed_endpoints = get_option( 'wp_rest_cache_allowed_endpoints', [] );
        if ( ! isset( $allowed_endpoints[ $this->namespace ] ) || ! in_array( $this->rest_base, $allowed_endpoints[ $this->namespace ] ) ) {
            $allowed_endpoints[ $this->namespace ][] = $this->rest_base;
            update_option( 'wp_rest_cache_allowed_endpoints', $allowed_endpoints );
        }
    }

    /**
     * Prepares a single post output for response.
     *
     * @param WP_Post|WP_Term $item Post/Term object.
     * @param WP_REST_Request $request Request object.
     *
     * @return WP_REST_Response Response object.
     */
    public function prepare_item_for_response( $item, $request ) {
        $key   = $this->transient_key( $item );
        $value = get_transient( $key );

        if ( empty( $value )
             || $request['context'] !== 'view' ) {
            $value = $this->get_data( $item, $request );
            if ( isset( $value->data )
                 && ! empty( $value->data )
                 && $request['context'] === 'view' ) {
                set_transient( $key, $value, WP_Rest_Cache::get_timeout() );
            }
        }

        return $value;
    }

    /**
     * Get the data as it would have been served without caching.
     *
     * @param   WP_Post|WP_Term $item Post/Term object
     * @param   WP_REST_Request $request Request object.
     *
     * @return  WP_REST_Response Response object.
     */
    public function get_data( $item, $request ) {
        return parent::prepare_item_for_response( $item, $request );
    }

    /**
     * Update the item cache by calling it's single REST endpoint.
     *
     * @param   WP_Term|WP_Post $item The object for which the cache should be updated.
     */
    public function update_item_cache( $item ) {
        delete_transient( $this->transient_key( $item ) );

        $url = home_url() . '/' . rest_get_url_prefix() . '/' . $this->namespace . '/' . $this->rest_base . '/';
        switch ( get_class( $item ) ) {
            case WP_Post::class:
                $url .= $item->ID;
                break;
            case WP_Term::class:
                $url .= $item->term_id;
                break;
            default:
                return;
        }
        $request = wp_remote_get( $url, [ 'timeout' => 10, 'sslverify' => false ] );
    }

    /**
     * Delete the cache for the current item.
     *
     * @param   WP_Term|WP_Post $item The item for which the cache should be deleted.
     */
    public function delete_item_cache( $item ) {
        delete_transient( $this->transient_key( $item ) );
    }

    /**
     * Get the cache key for the current item.
     *
     * @param   WP_Term|WP_Post $item The item for which the cache key should be returned.
     *
     * @return  string Cache key.
     */
    protected function transient_key( $item ) {
        return WP_Rest_Cache::transient_key( $this->get_id( $item ) );
    }

    /**
     * Get the cache key item ID.
     *
     * @param   WP_Post|WP_Term $item The item for which the ID should be returned.
     *
     * @return  int|string Item ID.
     */
    protected function get_id( $item ) {
        switch ( get_class( $item ) ) {
            case WP_Post::class:
                return $item->ID;
            case WP_Term::class:
                return 'taxonomy_' . $item->term_id;
            default:
                return $item;
        }
    }
}