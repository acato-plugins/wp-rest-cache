<?php

class WP_Rest_Cache_Term_Controller extends WP_REST_Terms_Controller
{
    use WP_Rest_Cache_Has_Caching;

    public function enrich_data(WP_Term $term, WP_REST_Response $response)
    {
        if(!class_exists('ACF_To_REST_API_ACF_API')){
            return $response;
        }

        $acfController = new ACF_To_REST_API_ACF_API($term->name, 'ACF_To_REST_API_Terms_Controller');
        $fields = $acfController->get_fields($term->term_id);

        $response->data['acf'] = $fields['acf'];
        return $response;
    }
}
