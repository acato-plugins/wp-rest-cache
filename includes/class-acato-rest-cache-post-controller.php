<?php

class Acato_Rest_Cache_Post_Controller extends WP_REST_Posts_Controller
{
    /**
     * Prepares a single post output for response.
     *
     * @since 4.7.0
     *
     * @param WP_Post         $post    Post object.
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response object.
     */
    public function prepare_item_for_response( $post, $request )
    {
        $key = Acato_Rest_Cache::transient_key($post);

        if(($value = get_transient($key)) == false){
            $value = $this->get_data($post, $request);
            set_transient($key, $value);
        }

        return $value;
    }

    public function get_data($post, $request)
    {
        return parent::prepare_item_for_response($post, $request);
    }
}
