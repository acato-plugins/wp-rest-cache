<?php
/**
 * Isolated test for the Admin::check_memcache_ext_object_caching warning branch — needs to
 * define a phantom `Memcache` class which would otherwise leak into other tests in the same
 * process. Kept in its own file so the @runInSeparateProcess overhead doesn't suppress
 * coverage attribution for unrelated tests in the same class.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Admin\Admin;

/**
 * @covers \WP_Rest_Cache_Plugin\Admin\Admin::check_memcache_ext_object_caching
 */
class Test_Admin_Memcache_Warning extends Caching_Test_Case {

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_check_memcache_adds_warning_when_memcache_class_is_loaded_but_setting_not_enabled() {
		eval( 'class Memcache {}' ); // phpcs:ignore Squiz.PHP.Eval.Discouraged

		wp_using_ext_object_cache( true );
		update_option( 'wp_rest_cache_memcache_used', '0' );

		( new Admin( 'wp-rest-cache', '2026.2.0' ) )->check_memcache_ext_object_caching();

		$notices = get_option( 'wp_rest_cache_admin_notices', [] );
		$this->assertNotEmpty( $notices['warning'] ?? [] );
		$this->assertStringContainsString( 'Memcache', $notices['warning'][0]['message'] );
	}
}
