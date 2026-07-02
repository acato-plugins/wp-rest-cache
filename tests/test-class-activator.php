<?php
/**
 * Tests for the Activator — runs on plugin activation, seeds default options and copies
 * the MU plugin.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Activator;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Activator
 */
class Test_Activator extends Caching_Test_Case {

	/** @var array<int, string> Options seeded by activate() — clean these up between tests. */
	private $seeded_options = [
		'wp_rest_cache_allowed_endpoints',
		'wp_rest_cache_disallowed_endpoints',
		'wp_rest_cache_rest_prefix',
		'wp_rest_cache_cacheable_request_headers',
		'wp_rest_cache_allowed_request_methods',
		'wp_rest_cache_uncached_parameters',
		'wp_rest_cache_hit_recording',
	];

	public function set_up() {
		parent::set_up();
		foreach ( $this->seeded_options as $option ) {
			delete_option( $option );
		}
		// create_mu_plugin() reads $_SERVER['REQUEST_URI'] — provide a value so the filesystem
		// credentials call doesn't choke.
		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			$_SERVER['REQUEST_URI'] = '/';
		}
	}

	// ---------- Default option seeding ----------

	public function test_activate_seeds_empty_allowed_endpoints_when_option_is_missing() {
		Activator::activate();

		$this->assertSame( [], get_option( 'wp_rest_cache_allowed_endpoints' ) );
	}

	public function test_activate_seeds_empty_disallowed_endpoints_when_option_is_missing() {
		Activator::activate();

		$this->assertSame( [], get_option( 'wp_rest_cache_disallowed_endpoints' ) );
	}

	public function test_activate_seeds_rest_prefix_using_rest_get_url_prefix() {
		Activator::activate();

		// The default WP REST prefix is 'wp-json' but it can be filtered, so compare against
		// what WP itself reports rather than hardcoding the string.
		$this->assertSame(
			rest_get_url_prefix(),
			get_option( 'wp_rest_cache_rest_prefix' )
		);
	}

	public function test_activate_seeds_empty_cacheable_request_headers_when_option_is_missing() {
		Activator::activate();

		$this->assertSame( [], get_option( 'wp_rest_cache_cacheable_request_headers' ) );
	}

	public function test_activate_seeds_allowed_request_methods_with_get_only() {
		Activator::activate();

		$this->assertSame( [ 'GET' ], get_option( 'wp_rest_cache_allowed_request_methods' ) );
	}

	public function test_activate_seeds_empty_uncached_parameters_when_option_is_missing() {
		Activator::activate();

		$this->assertSame( [], get_option( 'wp_rest_cache_uncached_parameters' ) );
	}

	// ---------- Non-overwrite of existing options ----------

	public function test_activate_does_not_overwrite_existing_allowed_endpoints() {
		$existing = [ 'wp/v2' => [ 'posts' ] ];
		update_option( 'wp_rest_cache_allowed_endpoints', $existing );

		Activator::activate();

		$this->assertSame( $existing, get_option( 'wp_rest_cache_allowed_endpoints' ) );
	}

	public function test_activate_does_not_overwrite_existing_allowed_request_methods() {
		update_option( 'wp_rest_cache_allowed_request_methods', [ 'GET', 'POST' ] );

		Activator::activate();

		$this->assertSame(
			[ 'GET', 'POST' ],
			get_option( 'wp_rest_cache_allowed_request_methods' )
		);
	}

	public function test_activate_does_not_overwrite_existing_rest_prefix() {
		update_option( 'wp_rest_cache_rest_prefix', 'api' );

		Activator::activate();

		$this->assertSame( 'api', get_option( 'wp_rest_cache_rest_prefix' ) );
	}

	// ---------- hit_recording ----------

	public function test_activate_seeds_hit_recording_to_one_when_missing() {
		Activator::activate();

		$this->assertEquals( 1, get_option( 'wp_rest_cache_hit_recording' ) );
	}

	public function test_activate_does_not_overwrite_existing_hit_recording_value() {
		// A site that has explicitly disabled hit recording must not be flipped back on by a
		// re-activation.
		update_option( 'wp_rest_cache_hit_recording', 0 );

		Activator::activate();

		$this->assertEquals( 0, get_option( 'wp_rest_cache_hit_recording' ) );
	}
}
