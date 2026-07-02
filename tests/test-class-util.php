<?php
/**
 * Tests for the Util helper class — currently just get_home_url(), which exists to bypass
 * WPML's language-prefix conversion when computing the cache-base URL.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Util;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Util
 */
class Test_Util extends Caching_Test_Case {

	public function test_get_home_url_returns_the_wordpress_home_url() {
		// In a non-WPML environment Util::get_home_url() must match the bare get_home_url().
		$this->assertSame( get_home_url(), Util::get_home_url() );
	}

	public function test_get_home_url_applies_the_wpml_skip_convert_filter_during_the_call() {
		// Verify the WPML override is actually active *during* the underlying get_home_url()
		// call (and not just registered-then-removed). We piggyback on WP's own `home_url`
		// filter to peek at the wpml flag mid-flight.
		$skip_value_during_call = null;
		add_filter(
			'home_url',
			function ( $home_url ) use ( &$skip_value_during_call ) {
				$skip_value_during_call = apply_filters( 'wpml_skip_convert_url_string', false );
				return $home_url;
			}
		);

		Util::get_home_url();

		$this->assertTrue(
			$skip_value_during_call,
			'wpml_skip_convert_url_string should resolve to true while Util::get_home_url() runs'
		);
	}

	public function test_get_home_url_does_not_leak_the_skip_convert_filter_after_returning() {
		// Filter must be removed after the call so a later, unrelated WPML conversion isn't
		// inadvertently suppressed elsewhere in the request.
		$this->assertFalse(
			has_filter( 'wpml_skip_convert_url_string', '__return_true' ),
			'precondition: filter is not registered before the call'
		);

		Util::get_home_url();

		$this->assertFalse(
			has_filter( 'wpml_skip_convert_url_string', '__return_true' ),
			'filter must be removed after Util::get_home_url() returns'
		);
	}
}
