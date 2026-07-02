<?php
/**
 * Constant-override tests for the Caching settings getters. Each method short-circuits on
 * `defined( WP_REST_CACHE_* ) ? CONST : option`, and once a PHP constant is defined it sticks
 * for the rest of the process — so each test gets its own subprocess via
 * @runInSeparateProcess.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching
 */
class Test_Caching_Constants extends Caching_Test_Case {

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_timeout_uses_constant_when_defined() {
		define( 'WP_REST_CACHE_TIMEOUT', 42 );
		update_option( 'wp_rest_cache_timeout', 999 );

		$this->assertSame( 42, (int) Caching::get_instance()->get_timeout( false ) );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_timeout_interval_uses_constant_when_defined() {
		define( 'WP_REST_CACHE_TIMEOUT_INTERVAL', HOUR_IN_SECONDS );
		update_option( 'wp_rest_cache_timeout_interval', WEEK_IN_SECONDS );

		$this->assertSame( HOUR_IN_SECONDS, Caching::get_instance()->get_timeout_interval() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_memcache_used_uses_constant_when_defined() {
		define( 'WP_REST_CACHE_MEMCACHE_USED', true );
		update_option( 'wp_rest_cache_memcache_used', '0' );

		$this->assertTrue( Caching::get_instance()->get_memcache_used() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_should_regenerate_uses_constant_when_defined() {
		define( 'WP_REST_CACHE_REGENERATE', true );
		update_option( 'wp_rest_cache_regenerate', '0' );

		$this->assertTrue( Caching::get_instance()->should_regenerate() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_regenerate_interval_uses_constant_when_defined() {
		define( 'WP_REST_CACHE_REGENERATE_INTERVAL', 'hourly' );
		update_option( 'wp_rest_cache_regenerate_interval', 'daily' );

		$this->assertSame( 'hourly', Caching::get_instance()->get_regenerate_interval() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_regenerate_number_uses_constant_when_defined() {
		define( 'WP_REST_CACHE_REGENERATE_NUMBER', 50 );
		update_option( 'wp_rest_cache_regenerate_number', 999 );

		$this->assertSame( 50, (int) Caching::get_instance()->get_regenerate_number() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_get_global_cacheable_request_headers_uses_constant_when_defined() {
		define( 'WP_REST_CACHE_GLOBAL_CACHEABLE_REQUEST_HEADERS', 'X-Const-Header' );
		update_option( 'wp_rest_cache_global_cacheable_request_headers', 'X-Option-Header' );

		$this->assertSame(
			'X-Const-Header',
			Caching::get_instance()->get_global_cacheable_request_headers()
		);
	}
}
