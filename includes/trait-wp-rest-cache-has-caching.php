<?php

trait WP_Rest_Cache_Has_Caching
{
    /**
     * Prepares a single post output for response.
     *
     * @param WP_Post $post Post object.
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response object.
     */
    public function prepare_item_for_response($post, $request)
    {
        $key = $this->transient_key($post);

        if (($value = get_transient($key)) == false) {
            $value = $this->get_data($post, $request);
            set_transient($key, $value, WP_Rest_Cache::get_timeout());
        }

        return $value;
    }

    public function get_data($post, $request)
    {
        return parent::prepare_item_for_response($post, $request);
    }

    public function update_item_cache(WP_Post $post)
    {
        $data = $this->get_data($post, new WP_REST_Request());

        set_transient($this->transient_key($post), $data, WP_Rest_Cache::get_timeout());
    }

    public function delete_item_cache(WP_Post $post)
    {
        delete_transient($this->transient_key($post));
    }

    protected function transient_key($post)
    {
        if(is_object($post)){
            if($post instanceof WP_Post){
                $id = $post->ID;
            } elseif( $post instanceof WP_Term) {
                $id = $post->term_id;
            } else {
                $id = $post;
            }
        } else {
            $id = $post;
        }
        $id = $post instanceof WP_Post ? $post->ID : $post;
        return WP_Rest_Cache::transient_key($id);
    }
}
