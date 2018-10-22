<?php

class WP_Rest_Cache_Term_Controller extends WP_REST_Terms_Controller
{
    /**
     * Prepares a single post output for response.
     *
     * @param WP_Term $term Term object.
     * @param WP_REST_Request $request Request object.
     * @return WP_REST_Response Response object.
     */
    public function prepare_item_for_response($term, $request)
    {
        $key = $this->transient_key($term);

        if (($value = get_transient($key)) == false) {
            $value = $this->get_data($term, $request);
            set_transient($key, $value, WP_Rest_Cache::get_timeout());
        }

        return $value;
    }

    public function get_data($term, $request)
    {
        return parent::prepare_item_for_response($term, $request);
    }

    public function update_item_cache($term)
    {
        $data = $this->get_data($term, new WP_REST_Request());

        set_transient($this->transient_key($term), $data, WP_Rest_Cache::get_timeout());
    }

    public function delete_item_cache($term)
    {
        delete_transient($this->transient_key($term));
    }

    private function transient_key($term)
    {
        $id = $term instanceof WP_Term ? $term->term_id : $term;
        return WP_Rest_Cache::transient_key('taxonomy_' . $id);
    }
}
