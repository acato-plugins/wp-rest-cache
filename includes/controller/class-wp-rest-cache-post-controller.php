<?php

class WP_Rest_Cache_Post_Controller extends WP_REST_Posts_Controller
{
    use WP_Rest_Cache_Has_Caching;

    public function enrich_data(WP_Post $post, WP_REST_Response $response)
    {
        if(!class_exists('ACF_To_REST_API_ACF_API')){
            return $response;
        }

        $acfController = new ACF_To_REST_API_ACF_API($post->post_type);
        $fields = $acfController->get_fields($post->ID);

        $response->data['acf'] = $fields['acf'];
        return $response;
    }
}
