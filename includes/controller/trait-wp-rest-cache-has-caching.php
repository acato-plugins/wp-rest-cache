<?php

/**
 * Trait WP_Rest_Cache_Has_Caching
 */
trait WP_Rest_Cache_Has_Caching {
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
     * @param WP_Post|WP_Term $item Post object.
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
     * @param WP_Post|WP_Term $item
     * @param WP_REST_Request $request
     *
     * @return WP_REST_Response
     */
    public function get_data( $item, $request ) {
        return parent::prepare_item_for_response( $item, $request );
    }

    /**
     * @param WP_Term|WP_Post $item
     */
    public function update_item_cache( $item ) {
        delete_transient( $this->transient_key( $item ) );

        $url     = home_url() . '/' . rest_get_url_prefix() . '/' . $this->namespace . '/' . $this->rest_base . '/';
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
     * @param WP_Term|WP_Post $item
     */
    public function delete_item_cache( $item ) {
        delete_transient( $this->transient_key( $item ) );
    }

    /**
     * @param WP_Term|WP_Post $post
     *
     * @return string
     */
    protected function transient_key( $post ) {
        return WP_Rest_Cache::transient_key( $this->get_id( $post ) );
    }

    /**
     * @param WP_Post|WP_Term $post
     *
     * @return int|string
     */
    protected function get_id( $post ) {
        switch ( get_class( $post ) ) {
            case WP_Post::class:
                return $post->ID;
            case WP_Term::class:
                return 'taxonomy_' . $post->term_id;
            default:
                return $post;
        }
    }
}
