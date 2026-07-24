<?php
/**
 * Tests for the timeout helpers: get_timeout, get_timeout_interval, get_memcache_used,
 * and the wp_rest_cache/timeout per-cache override filter.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching
 */
class Test_Caching_Timeout extends Caching_Test_Case {

	public function test_get_timeout_interval_defaults_to_one_year() {
		$this->assertSame( YEAR_IN_SECONDS, Caching::get_instance()->get_timeout_interval() );
	}

	public function test_get_timeout_interval_honors_option_override() {
		update_option( 'wp_rest_cache_timeout_interval', HOUR_IN_SECONDS );

		$this->assertSame( HOUR_IN_SECONDS, (int) Caching::get_instance()->get_timeout_interval() );
	}

	public function test_get_memcache_used_defaults_to_false() {
		$this->assertFalse( Caching::get_instance()->get_memcache_used() );
	}

	public function test_get_memcache_used_is_true_when_option_is_one() {
		update_option( 'wp_rest_cache_memcache_used', '1' );

		$this->assertTrue( Caching::get_instance()->get_memcache_used() );
	}

	public function test_get_memcache_used_is_false_when_option_is_zero() {
		update_option( 'wp_rest_cache_memcache_used', '0' );

		$this->assertFalse( Caching::get_instance()->get_memcache_used() );
	}

	public function test_get_timeout_uncalculated_returns_raw_option_value() {
		update_option( 'wp_rest_cache_timeout', 3 );
		update_option( 'wp_rest_cache_timeout_interval', HOUR_IN_SECONDS );

		$this->assertEquals( 3, Caching::get_instance()->get_timeout( false ) );
	}

	public function test_get_timeout_default_calculated_multiplies_by_interval() {
		update_option( 'wp_rest_cache_timeout', 3 );
		update_option( 'wp_rest_cache_timeout_interval', HOUR_IN_SECONDS );

		$this->assertSame( 3 * HOUR_IN_SECONDS, Caching::get_instance()->get_timeout() );
	}

	public function test_get_timeout_uses_default_of_one_when_option_unset() {
		delete_option( 'wp_rest_cache_timeout' );
		delete_option( 'wp_rest_cache_timeout_interval' );

		$this->assertSame( YEAR_IN_SECONDS, Caching::get_instance()->get_timeout() );
	}

	public function test_get_timeout_adds_current_time_when_memcache_in_use() {
		update_option( 'wp_rest_cache_timeout', 1 );
		update_option( 'wp_rest_cache_timeout_interval', HOUR_IN_SECONDS );
		update_option( 'wp_rest_cache_memcache_used', '1' );

		$before  = time();
		$timeout = Caching::get_instance()->get_timeout();
		$after   = time();

		$this->assertGreaterThanOrEqual( $before + HOUR_IN_SECONDS, $timeout );
		$this->assertLessThanOrEqual( $after + HOUR_IN_SECONDS, $timeout );
	}

	public function test_get_timeout_does_not_add_current_time_when_memcache_disabled() {
		update_option( 'wp_rest_cache_timeout', 1 );
		update_option( 'wp_rest_cache_timeout_interval', HOUR_IN_SECONDS );
		update_option( 'wp_rest_cache_memcache_used', '0' );

		$this->assertSame( HOUR_IN_SECONDS, Caching::get_instance()->get_timeout() );
	}

	public function test_per_cache_filter_only_runs_when_options_are_provided() {
		$ran = false;
		add_filter(
			'wp_rest_cache/timeout',
			function ( $timeout ) use ( &$ran ) {
				$ran = true;
				return $timeout;
			}
		);

		Caching::get_instance()->get_timeout( true, [] );

		$this->assertFalse( $ran );
	}

	public function test_per_cache_filter_runs_when_options_are_provided() {
		$ran = false;
		add_filter(
			'wp_rest_cache/timeout',
			function ( $timeout ) use ( &$ran ) {
				$ran = true;
				return $timeout;
			}
		);

		Caching::get_instance()->get_timeout( true, [ 'uri' => '/x' ] );

		$this->assertTrue( $ran );
	}

	public function test_per_cache_filter_receives_timeout_and_options_args() {
		$captured = null;
		add_filter(
			'wp_rest_cache/timeout',
			function ( $timeout, $options ) use ( &$captured ) {
				$captured = [ $timeout, $options ];
				return $timeout;
			},
			10,
			2
		);

		update_option( 'wp_rest_cache_timeout', 2 );
		update_option( 'wp_rest_cache_timeout_interval', HOUR_IN_SECONDS );

		$options = [
			'uri'             => '/wp-json/wp/v2/posts',
			'object_type'     => 'post',
			'request_headers' => [],
			'request_method'  => 'GET',
		];
		Caching::get_instance()->get_timeout( true, $options );

		$this->assertNotNull( $captured );
		$this->assertSame( 2 * HOUR_IN_SECONDS, $captured[0] );
		$this->assertSame( $options, $captured[1] );
	}

	public function test_per_cache_filter_return_value_is_used_as_final_timeout() {
		add_filter(
			'wp_rest_cache/timeout',
			fn( $timeout ) => 42
		);

		$this->assertSame(
			42,
			Caching::get_instance()->get_timeout( true, [ 'uri' => '/x' ] )
		);
	}
}
