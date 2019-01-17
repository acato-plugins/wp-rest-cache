<?php

namespace WP_Rest_Cache_Plugin\Admin\Includes;

if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once( ABSPATH . 'wp-admin/includes/class-wp-list-table.php' );
}

class API_Caches_Table extends \WP_List_Table {
    const ITEMS_PER_PAGE = 5;
    private static $api_type;

    public function __construct( $api_type ) {
        if ( ! in_array( $api_type, [ 'item', 'endpoint' ], true ) ) {
            throw new \Exception(
                sprintf(
                /* translators: %s: api-type */
                    __( 'Invalid API type: %s', 'wp-rest-cache' ),
                    $api_type
                )
            );
        }

        self::$api_type = $api_type;
        switch ( $api_type ) {
            case 'item':
                $args = [
                    'singular' => __( 'Item API Cache', 'wp-rest-cache' ),
                    'plural'   => __( 'Item API Caches', 'wp-rest-cache' ),
                    'ajax'     => false
                ];
                break;
            case 'endpoint':
                $args = [
                    'singular' => __( 'Endpoint API Cache', 'wp-rest-cache' ),
                    'plural'   => __( 'Endpoint API Caches', 'wp-rest-cache' ),
                    'ajax'     => false,
                ];
                break;
        }
        parent::__construct( $args );
    }

    public static function get_caches( $per_page = self::ITEMS_PER_PAGE, $page_number = 1 ) {
        return \WP_Rest_Cache_Plugin\Includes\Caching\Caching::get_instance()->get_api_data( self::$api_type, $per_page, $page_number );
    }

    public static function clear_cache( $cache_key, $force = false ) {
        \WP_Rest_Cache_Plugin\Includes\Caching\Caching::get_instance()->delete_cache( $cache_key, $force );
    }

    public static function record_count() {
        return \WP_Rest_Cache_Plugin\Includes\Caching\Caching::get_instance()->get_record_count( self::$api_type );
    }

    public function no_items() {
        esc_html_e( 'No caches available', 'wp-rest-cache' );
    }

    public function column_cache_key( $item ) {
        $page         = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_STRING );
        $sub          = filter_input( INPUT_GET, 'sub', FILTER_SANITIZE_STRING );
        $flush_nonce  = wp_create_nonce( 'wp_rest_cache_flush_cache' );
        $delete_nonce = wp_create_nonce( 'wp_rest_cache_delete_cache' );
        $title        = sprintf(
            '<strong><a href="?page=%s&sub=%s&cache_key=%s">%s</a></strong>',
            esc_attr( $page ),
            'cache-details',
            esc_attr( $item['cache_key'] ),
            $item['cache_key']
        );

        $actions                  = [];
        $actions['cache-details'] = sprintf(
            '<a href="?page=%s&sub=%s&cache_key=%s">%s</a>',
            esc_attr( $page ),
            'cache-details',
            esc_attr( $item['cache_key'] ),
            __( 'Details', 'wp-rest-cache' )
        );
        if ( $item['is_active'] ) {
            $actions['flush'] = sprintf(
                '<a href="?page=%s&sub=%s&action=%s&cache_key=%s&wp_rest_cache_nonce=%s">%s</a>',
                esc_attr( $page ),
                esc_attr( $sub ),
                'flush',
                esc_attr( $item['cache_key'] ),
                $flush_nonce,
                __( 'Flush cache', 'wp-rest-cache' )
            );
        }
        $actions['delete'] = sprintf(
            '<a href="?page=%s&sub=%s&action=%s&cache_key=%s&wp_rest_cache_nonce=%s">%s</a>',
            esc_attr( $page ),
            esc_attr( $sub ),
            'delete',
            esc_attr( $item['cache_key'] ),
            $delete_nonce,
            __( 'Delete cache record', 'wp-rest-cache' )
        );

        return $title . $this->row_actions( $actions );
    }

    public function column_is_active( $item ) {
        if ( $item['is_active'] ) {
            return sprintf( '<span class="dashicons dashicons-yes" style="color:green" title="%s"></span>
                <span class="screen-reader-text">%s</span>',
                __( 'Cache is ready to be served.', 'wp-rest-cache' ),
                __( 'Cache is ready to be served.', 'wp-rest-cache' )
            );
        }

        return sprintf( '<span class="dashicons dashicons-no" style="color:red" title="%s"></span>
            <span class="screen-reader-text">%s</span>',
            __( 'Cache is expired or flushed.', 'wp-rest-cache' ),
            __( 'Cache is expired or flushed.', 'wp-rest-cache' )
        );
    }

    public function column_default( $item, $column_name ) {
        return $item[ $column_name ];
    }

    public function column_cb( $item ) {
        return sprintf(
            '<input type="checkbox" name="bulk-flush[]" value="%s" />', $item['cache_key']
        );
    }

    public function get_columns() {
        $columns = [
            'cb'          => '<input type="checkbox" />',
            'cache_key'   => __( 'Cache Key', 'wp-rest-cache' ),
            'request_uri' => __( 'Request URI', 'wp-rest-cache' ),
            'object_type' => __( 'Object Type', 'wp-rest-cache' ),
            'expiration'  => __( 'Expiration', 'wp-rest-cache' ),
            'cache_hits'  => __( '# Cache Hits', 'wp-rest-cache' ),
            'is_active'   => __( 'Active', 'wp-rest-cache' )
        ];

        return $columns;
    }

    public function get_sortable_columns() {
        $sortable_columns = [
            'cache_key'   => [ 'cache_key', false ],
            'request_uri' => [ 'request_uri', false ],
            'object_type' => [ 'object_type', false ],
            'expiration'  => [ 'expiration', true ],
            'cache_hits'  => [ 'cache_hits', true ]
        ];

        return $sortable_columns;
    }

    public function get_bulk_actions() {
        $actions = [
            'bulk-flush'  => __( 'Flush cache', 'wp-rest-cache' ),
            'bulk-delete' => __( 'Delete cache record', 'wp-rest-cache' )
        ];

        return $actions;
    }

    public function prepare_items() {
        $this->process_action();

        $columns               = $this->get_columns();
        $hidden                = [];
        $sortable              = $this->get_sortable_columns();
        $this->_column_headers = [ $columns, $hidden, $sortable ];

        $per_page     = $this->get_items_per_page( 'caches_per_page', self::ITEMS_PER_PAGE );
        $current_page = $this->get_pagenum();
        $total_items  = self::record_count();

        $this->set_pagination_args( [
            'total_items' => $total_items,
            'per_page'    => $per_page
        ] );

        $this->items = self::get_caches( $per_page, $current_page );
    }

    public function process_action() {
        switch ( $this->current_action() ) {
            case 'flush':
            case 'delete':
                $this->process_single_action( $this->current_action() );
                break;
            case 'bulk-flush':
            case 'bulk-delete':
                $this->process_bulk_action( $this->current_action() );
                break;
        }
    }

    private function process_single_action( $action ) {
        if ( ! isset( $_GET['wp_rest_cache_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['wp_rest_cache_nonce'] ), 'wp_rest_cache_' . $action . '_cache' ) ) {
            die( 'No naughty business please' );
        }
        $cache_key = filter_input( INPUT_GET, 'cache_key', FILTER_SANITIZE_STRING );
        self::clear_cache( $cache_key, ( $action === 'delete' ) );
    }

    private function process_bulk_action( $action ) {
        $caches = filter_input( INPUT_POST, 'bulk-flush', FILTER_SANITIZE_STRING, FILTER_REQUIRE_ARRAY );
        foreach ( $caches as $cache_key ) {
            self::clear_cache( $cache_key, ( $action === 'bulk-delete' ) );
        }
    }
}
