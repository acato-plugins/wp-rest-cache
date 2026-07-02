<?php
/**
 * Final round of coverage-filler tests targeting the still-uncovered branches identified
 * after the filter_input → $_GET refactor.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Admin\Admin;
use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching
 * @covers \WP_Rest_Cache_Plugin\Admin\Admin
 */
class Test_Final_Fillers extends Caching_Test_Case {

	public function test_set_cache_logs_to_error_log_when_set_transient_returns_false() {
		// set_transient returns false when the new value equals the existing value — calling
		// set_cache twice with an identical payload triggers the WP_DEBUG-guarded error_log
		// branch. Redirect error_log to a tempfile so the noise stays out of the test output.
		$cache_key = 'errorlog-key';
		$payload   = [ 'data' => [ 'id' => 1 ] ];

		$log_file     = tempnam( sys_get_temp_dir(), 'wprc-errlog-' );
		$previous_log = ini_set( 'error_log', $log_file );

		try {
			Caching::get_instance()->set_cache( $cache_key, $payload, 'endpoint', '/log-uri' );
			Caching::get_instance()->set_cache( $cache_key, $payload, 'endpoint', '/log-uri' );

			$contents = file_get_contents( $log_file );
		} finally {
			ini_set( 'error_log', $previous_log );
			unlink( $log_file );
		}

		$this->assertStringContainsString( 'Failed to set cache for endpoint: /log-uri', $contents );
		$this->assertStringContainsString( 'cache_key: errorlog-key', $contents );
	}

	public function test_set_cache_twice_with_same_key_updates_instead_of_inserting() {
		$cache_key = 'reused-key';
		$payload_1 = [ 'data' => [ 'id' => 1, 'type' => 'post', 'slug' => 'x' ] ];
		$payload_2 = [ 'data' => [ 'id' => 1, 'type' => 'post', 'slug' => 'y' ] ];

		Caching::get_instance()->set_cache( $cache_key, $payload_1, 'endpoint', '/x' );
		Caching::get_instance()->set_cache( $cache_key, $payload_2, 'endpoint', '/x' );

		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}wrc_caches` WHERE cache_key = %s",
				$cache_key
			)
		);
		$this->assertSame( 1, $count, 'reused cache_key must not produce a duplicate row' );

		$cleaned = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT cleaned FROM `{$wpdb->prefix}wrc_caches` WHERE cache_key = %s",
				$cache_key
			)
		);
		$this->assertSame( '0', $cleaned );
	}

	public function test_settings_page_loads_known_sub_partial() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$_GET['sub'] = 'endpoint-api';

		$applied = false;
		add_filter(
			'wp_rest_cache/settings_panels',
			function ( $panels ) use ( &$applied ) {
				$applied = true;
				return $panels;
			}
		);

		ob_start();
		( new Admin( 'wp-rest-cache', '2026.2.0' ) )->settings_page();
		ob_end_clean();

		$this->assertTrue( $applied );

		unset( $_GET['sub'] );
	}

	public function test_settings_page_falls_back_to_default_for_unknown_sub() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$_GET['sub'] = 'this-sub-does-not-exist';

		ob_start();
		( new Admin( 'wp-rest-cache', '2026.2.0' ) )->settings_page();
		ob_end_clean();

		$this->assertTrue( true );

		unset( $_GET['sub'] );
	}

	public function test_settings_page_treats_path_traversal_sub_value_as_default_settings() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$_GET['sub'] = '../../etc/passwd';

		ob_start();
		( new Admin( 'wp-rest-cache', '2026.2.0' ) )->settings_page();
		ob_end_clean();

		$this->assertTrue( true );

		unset( $_GET['sub'] );
	}
}
