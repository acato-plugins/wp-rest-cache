<?php

/**
 * Trait WP_Rest_Cache_Has_Caching
 */
trait WP_Rest_Cache_Has_Caching
{
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

        if (($value = get_transient($key)) == false) {
            $value = $this->get_data($item, $request);
            set_transient($key, $value, WP_Rest_Cache::get_timeout());
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
        $data = $this->get_data($item, new WP_REST_Request());
        if(method_exists($this, 'enrich_data')){
            $data = $this->enrich_data($item, $data);
        }

        set_transient($this->transient_key($item), $data, WP_Rest_Cache::get_timeout());
    }

    /**
     * @param WP_Term|WP_Post $item
     */
    public function delete_item_cache($item)
    {
        delete_transient($this->transient_key($item));
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
