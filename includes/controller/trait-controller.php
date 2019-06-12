<?php
/**
 * Trait for the REST Controller extensions.
 *
 * @link: http://www.acato.nl
 * @since 2018.1
 *
 * @package    WP_Rest_Cache_Plugin
 * @subpackage WP_Rest_Cache_Plugin/Includes/Controller
 */

namespace WP_Rest_Cache_Plugin\Includes\Controller;

/**
 * Trait for the REST Controller extensions.
 *
 * @package    WP_Rest_Cache_Plugin
 * @subpackage WP_Rest_Cache_Plugin/Includes/Controller
 * @author:    Richard Korthuis - Acato <richardkorthuis@acato.nl>
 */
trait Controller_Trait {

	/**
	 * Constructor.
	 *
	 * @param string $item Post type or taxonomy key.
	 */
	public function __construct( $item ) {
		parent::__construct( $item );

		$allowed_endpoints = get_option( 'wp_rest_cache_item_allowed_endpoints', [] );
		if ( ! isset( $allowed_endpoints[ $this->namespace ] ) || ! in_array( $this->rest_base, $allowed_endpoints[ $this->namespace ], true ) ) {
			$allowed_endpoints[ $this->namespace ][] = $this->rest_base;
			update_option( 'wp_rest_cache_item_allowed_endpoints', $allowed_endpoints );
		}
	}

	/**
	 * Prepares a single post output for response.
	 *
	 * @param \WP_Post|\WP_Term $item    Post/Term object.
	 * @param \WP_REST_Request  $request Request object.
	 *
	 * @return \WP_REST_Response Response object.
	 */
	public function prepare_item_for_response( $item, $request ) {
		$key     = $this->get_id( $item );
		$caching = \WP_Rest_Cache_Plugin\Includes\Caching\Caching::get_instance();
		$value   = $caching->get_cache( $key );

		if ( empty( $value ) || 'view' !== $request['context'] ) {
			$value = $this->get_data( $item, $request );
			if ( isset( $value->data ) && ! empty( $value->data ) && 'view' === $request['context'] ) {
				$object_type = isset( $this->post_type ) ? $this->post_type : ( isset( $this->taxonomy ) ? $this->taxonomy : '' );
				$caching->set_cache( $key, $value, 'item', '', $object_type );
			}
		}

		return $value;
	}

	/**
	 * Get the data as it would have been served without caching.
	 *
	 * @param \WP_Post|\WP_Term $item    Post/Term object.
	 * @param \WP_REST_Request  $request Request object.
	 *
	 * @return \WP_REST_Response Response object.
	 */
	public function get_data( $item, $request ) {
		return parent::prepare_item_for_response( $item, $request );
	}

	/**
	 * Update the item cache by calling it's single REST endpoint.
	 *
	 * @param \WP_Term|\WP_Post $item The object for which the cache should be updated.
	 */
	public function update_item_cache( $item ) {
		$url = home_url() . '/' . rest_get_url_prefix() . '/' . $this->namespace . '/' . $this->rest_base . '/';
		switch ( get_class( $item ) ) {
			case \WP_Post::class:
				$url .= $item->ID;
				break;
			case \WP_Term::class:
				$url .= $item->term_id;
				break;
			default:
				return;
		}
		wp_remote_get(
			$url,
			[
				'timeout'   => 10,
				'sslverify' => false,
			]
		);
	}

	/**
	 * Get the cache key item ID.
	 *
	 * @param \WP_Post|\WP_Term $item The item for which the ID should be returned.
	 *
	 * @return int|string Item ID.
	 */
	protected function get_id( $item ) {
		switch ( get_class( $item ) ) {
			case \WP_Post::class:
				return $item->ID;
			case \WP_Term::class:
				return 'taxonomy_' . $item->term_id;
			default:
				return $item;
		}
	}
}
