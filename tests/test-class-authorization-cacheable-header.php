<?php
/**
 * Tests for caching requests with credentials per set of credentials, by making `Authorization` a cacheable request
 * header (also when the web server handles HTTP Basic authentication), and masking the stored credentials in the
 * wp-admin.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Admin\Includes\API_Caches_Table;
use WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api;
use WP_Rest_Cache_Plugin\Includes\Util;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api
 * @covers \WP_Rest_Cache_Plugin\Includes\Util::mask_request_headers
 */
class Test_Authorization_Cacheable_Header extends Caching_Test_Case {

	/**
	 * The $_SERVER keys these tests change, to restore them afterwards.
	 *
	 * @var array<string,mixed>
	 */
	private $server_backup = [];

	/**
	 * Back up $_SERVER and make `acf/v3/options` a cacheable endpoint.
	 */
	public function set_up() {
		parent::set_up();

		foreach ( [ 'REQUEST_METHOD', 'REQUEST_URI', 'HTTP_AUTHORIZATION', 'PHP_AUTH_USER', 'PHP_AUTH_PW' ] as $key ) {
			$this->server_backup[ $key ] = $_SERVER[ $key ] ?? null;
		}

		update_option( 'wp_rest_cache_allowed_endpoints', [ 'acf/v3' => [ 'options' ] ] );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['REQUEST_URI']    = '/wp-json/acf/v3/options/options';
		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
	}

	/**
	 * Restore $_SERVER.
	 */
	public function tear_down() {
		foreach ( $this->server_backup as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}
		parent::tear_down();
	}

	/**
	 * A request with an Authorization header is skipped, unless Authorization is a cacheable request header.
	 */
	public function test_authorization_header_is_cached_when_it_is_a_cacheable_header() {
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( 'front:end' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		$this->assertTrue( $this->arrange()->skip_caching(), 'Without the workaround the request is skipped' );

		update_option( 'wp_rest_cache_global_cacheable_request_headers', 'Authorization' );

		$this->assertFalse( $this->arrange()->skip_caching(), 'With Authorization as cacheable header the request is cached' );
	}

	/**
	 * When the web server handles Basic auth (only PHP_AUTH_USER and PHP_AUTH_PW reach PHP), the request is skipped,
	 * unless Authorization is a cacheable request header: then the header is rebuilt from the credentials.
	 */
	public function test_server_side_basic_auth_is_cached_when_authorization_is_a_cacheable_header() {
		$_SERVER['PHP_AUTH_USER'] = 'front';
		$_SERVER['PHP_AUTH_PW']   = 'end';

		$this->assertTrue( $this->arrange()->skip_caching(), 'Without the workaround the request is skipped' );

		update_option( 'wp_rest_cache_global_cacheable_request_headers', 'Authorization' );
		$api = $this->arrange();

		$this->assertFalse( $api->skip_caching(), 'With Authorization as cacheable header the request is cached' );
		$this->assertSame(
			[ 'Authorization' => 'Basic ' . base64_encode( 'front:end' ) ], // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			$this->get_private_property( $api, 'request_headers' )
		);
	}

	/**
	 * Server-side Basic auth gives every set of credentials its own cache, the same as an Authorization header.
	 */
	public function test_server_side_basic_auth_gets_a_cache_per_set_of_credentials() {
		update_option( 'wp_rest_cache_global_cacheable_request_headers', 'Authorization' );

		$_SERVER['PHP_AUTH_USER'] = 'front';
		$_SERVER['PHP_AUTH_PW']   = 'end';
		$server_side              = $this->get_private_property( $this->arrange(), 'cache_key' );

		$_SERVER['PHP_AUTH_PW'] = 'other';
		$other_password         = $this->get_private_property( $this->arrange(), 'cache_key' );

		unset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] );
		$_SERVER['HTTP_AUTHORIZATION'] = 'Basic ' . base64_encode( 'front:end' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$header                        = $this->get_private_property( $this->arrange(), 'cache_key' );

		$this->assertNotSame( $server_side, $other_password, 'Other credentials should get another cache' );
		$this->assertSame( $server_side, $header, 'The same credentials should share a cache, however they are sent' );
	}

	/**
	 * Credentials in Authorization headers are masked, keeping only the scheme; other headers are left alone.
	 */
	public function test_mask_request_headers() {
		$this->assertSame(
			[
				'Authorization'       => 'Basic ********',
				'proxy-authorization' => '********',
				'Accept-Language'     => 'nl',
				'AUTHORIZATION'       => '',
			],
			Util::mask_request_headers(
				[
					'Authorization'       => 'Basic ZnJvbnQ6ZW5k',
					'proxy-authorization' => 'secret-token',
					'Accept-Language'     => 'nl',
					'AUTHORIZATION'       => '',
				]
			)
		);
	}

	/**
	 * The caches overview shows the stored request headers with masked credentials.
	 */
	public function test_caches_overview_masks_the_stored_authorization_header() {
		$table = new API_Caches_Table( 'endpoint' );

		$output = $table->column_default( [ 'request_headers' => wp_json_encode( [ 'Authorization' => 'Basic ZnJvbnQ6ZW5k' ] ) ], 'request_headers' );

		$this->assertStringNotContainsString( 'ZnJvbnQ6ZW5k', $output );
		$this->assertStringContainsString( 'Basic ********', $output );
	}

	/**
	 * Build an Endpoint_Api for the current $_SERVER, including its cache key and cacheable request headers.
	 *
	 * @return Endpoint_Api
	 */
	private function arrange() {
		$api = new Endpoint_Api();
		$this->invoke_private( $api, 'build_cache_key', [] );

		return $api;
	}
}
