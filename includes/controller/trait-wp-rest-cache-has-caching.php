<?php

/**
 * Trait WP_Rest_Cache_Has_Caching
 */
trait WP_Rest_Cache_Has_Caching
{
    /**
     * Constructor.
     *
     * @param string $item Post type or taxonomy key.
     */
    public function __construct($item) {
        parent::__construct($item);

        $allowed_endpoints = get_option('wp_rest_cache_allowed_endpoints', []);
        if(!isset($allowed_endpoints[$this->namespace]) || !in_array($this->rest_base, $allowed_endpoints[$this->namespace])) {
            $allowed_endpoints[$this->namespace][] = $this->rest_base;
            update_option('wp_rest_cache_allowed_endpoints', $allowed_endpoints);
        }
    }

    /**
     * Prepares a single post output for response.
     *
     * @param WP_Post|WP_Term $item Post object.
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response object.
     */
    public function prepare_item_for_response($item, $request)
    {
        $key = $this->transient_key($item);
        //delete_transient($this->transient_key($item));
        $value = get_transient($key);

        if ( $value === false || $value === '' ) {
            $value = $this->get_data($item, $request);
            if( isset( $value->data ) && (string) $value->data !== '' ) set_transient($key, $value->data, WP_Rest_Cache::get_timeout());
        }

        return $value;
    }

    /**
     * @param WP_Post|WP_Term $item
     * @param WP_REST_Request $request
     * @return WP_REST_Response
     */
    public function get_data($item, $request)
    {
        return parent::prepare_item_for_response($item, $request);
    }

    /**
     * @param WP_Term|WP_Post $item
     */
    public function update_item_cache($item)
    {
        $url = site_url() . '/wp-json/wp/v2/posts/' . $item->ID;
        $request = wp_remote_get( $url );
        $this->delete_related_caches($item);
    }

    /**
     * @param WP_Term|WP_Post $item
     */
    public function delete_item_cache($item)
    {
        delete_transient($this->transient_key($item));
        $this->delete_related_caches($item);
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

    /**
     * @param WP_Term|WP_Post $post
     * @return string
     */
    protected function transient_key($post)
    {
        return WP_Rest_Cache::transient_key($this->get_id($post));
    }

    /**
     * @param WP_Post|WP_Term $post
     * @return int|string
     */
    protected function get_id($post)
    {
        switch(get_class($post)){
            case WP_Post::class:
                return $post->ID;
            case WP_Term::class:
                return 'taxonomy_' . $post->term_id;
            default:
                return $post;
        }
    }
}
