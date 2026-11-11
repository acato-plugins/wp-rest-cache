<?php
/**
 * Util functions for usage throughout the plugin.
 *
 * @link: https://www.acato.nl
 *
 * @package    WP_Rest_Cache_Plugin
 * @subpackage WP_Rest_Cache_Plugin/Includes
 */

namespace WP_Rest_Cache_Plugin\Includes;

/**
 * Util functions for usage throughout the plugin.
 *
 * This class define util functions that can be used throughout the plugin.
 *
 * @package    WP_Rest_Cache_Plugin
 * @subpackage WP_Rest_Cache_Plugin/Includes
 * @author     Richard Korthuis - Acato <richardkorthuis@acato.nl>
 */
class Util {

	/**
	 * Get the home url, without converting it to the current language.
	 *
	 * @return string The home url.
	 */
	public static function get_home_url() {
		add_filter( 'wpml_skip_convert_url_string', '__return_true' );
		$home_url = get_home_url();
		remove_filter( 'wpml_skip_convert_url_string', '__return_true' );
		return $home_url;
	}

	/**
	 * Mask the credentials in request headers before they are displayed.
	 *
	 * When `Authorization` is a cacheable request header, its value (e.g. Basic auth credentials or a token) is stored
	 * with the cache, because regeneration has to replay the request with it. It is never shown in full in the
	 * wp-admin: only the authentication scheme (e.g. `Basic`) is kept.
	 *
	 * @param array<string,mixed> $headers The request headers, header name => value.
	 *
	 * @return array<string,mixed> The request headers, with credentials masked.
	 */
	public static function mask_request_headers( $headers ) {
		if ( ! is_array( $headers ) ) {
			return $headers;
		}

		foreach ( $headers as $name => $value ) {
			$normalized = strtolower( str_replace( '-', '_', trim( (string) $name ) ) );
			if ( ! in_array( $normalized, [ 'authorization', 'proxy_authorization' ], true ) || ! is_string( $value ) || '' === $value ) {
				continue;
			}

			$parts            = explode( ' ', trim( $value ), 2 );
			$headers[ $name ] = ( 2 === count( $parts ) ? $parts[0] . ' ' : '' ) . '********';
		}

		return $headers;
	}
}
