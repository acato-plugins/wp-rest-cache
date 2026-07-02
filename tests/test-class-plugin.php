<?php
/**
 * Tests for the Plugin bootstrap class — verifies the hook wiring stays correct.
 *
 * The plugin is loaded once by tests/bootstrap.php, which runs `new Plugin()` and registers
 * every callback via the three `define_*_hooks` private methods. These tests inspect the
 * resulting $wp_filter state — i.e. they verify production wiring at test time without
 * re-instantiating Plugin (which would double-register hooks).
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Admin\Admin;
use WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api;
use WP_Rest_Cache_Plugin\Includes\API\Item_Api;
use WP_Rest_Cache_Plugin\Includes\API\Oembed_Api;
use WP_Rest_Cache_Plugin\Includes\Caching\Caching;
use WP_Rest_Cache_Plugin\Includes\Plugin;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Plugin
 */
class Test_Plugin extends Caching_Test_Case {

	// ---------- Identity getters ----------

	public function test_get_plugin_name_returns_wp_rest_cache() {
		$plugin = new Plugin();

		$this->assertSame( 'wp-rest-cache', $plugin->get_plugin_name() );
	}

	public function test_get_version_returns_a_calver_string() {
		$plugin = new Plugin();

		// Pinning the shape (YYYY.X.Y) rather than the exact value — exact value will drift
		// on each release and isn't load-bearing for the test.
		$this->assertMatchesRegularExpression(
			'/^\d{4}\.\d+\.\d+$/',
			$plugin->get_version()
		);
	}

	// ---------- Caching singleton hooks ----------
	// The Caching class is a singleton, so we have a stable reference and can use has_action
	// directly. The other classes don't expose their instance, so we use the
	// assert_action_registered helper for those.

	public function test_init_runs_update_database_structure() {
		$this->assertNotFalse(
			has_action( 'init', [ Caching::get_instance(), 'update_database_structure' ] )
		);
	}

	public function test_save_post_is_hooked_at_priority_999() {
		$this->assertSame(
			999,
			has_action( 'save_post', [ Caching::get_instance(), 'save_post' ] )
		);
	}

	public function test_delete_post_is_hooked() {
		$this->assertNotFalse(
			has_action( 'delete_post', [ Caching::get_instance(), 'delete_post' ] )
		);
	}

	public function test_transition_post_status_is_hooked() {
		$this->assertNotFalse(
			has_action( 'transition_post_status', [ Caching::get_instance(), 'transition_post_status' ] )
		);
	}

	public function test_set_object_terms_is_hooked() {
		$this->assertNotFalse(
			has_action( 'set_object_terms', [ Caching::get_instance(), 'set_object_terms' ] )
		);
	}

	public function test_cleanup_cron_event_runs_cleanup_deleted_caches() {
		$this->assertNotFalse(
			has_action( 'wp_rest_cache_cleanup_deleted_caches', [ Caching::get_instance(), 'cleanup_deleted_caches' ] )
		);
	}

	public function test_regenerate_cron_event_runs_regenerate_expired_caches() {
		$this->assertNotFalse(
			has_action( 'wp_rest_cache_regenerate_cron', [ Caching::get_instance(), 'regenerate_expired_caches' ] )
		);
	}

	// ---------- API hooks ----------

	public function test_item_api_swaps_post_type_rest_controller() {
		$this->assert_filter_registered(
			'register_post_type_args',
			Item_Api::class,
			'set_post_type_rest_controller'
		);
	}

	public function test_item_api_swaps_taxonomy_rest_controller() {
		$this->assert_filter_registered(
			'register_taxonomy_args',
			Item_Api::class,
			'set_taxonomy_rest_controller'
		);
	}

	public function test_endpoint_api_saves_options_on_init_and_rest_api_init() {
		// save_options runs on both hooks — first one wins, but having both means the
		// option side-effects run regardless of which fires first.
		$this->assert_filter_registered( 'init', Endpoint_Api::class, 'save_options' );
		$this->assert_filter_registered( 'rest_api_init', Endpoint_Api::class, 'save_options' );
	}

	public function test_oembed_api_adds_its_endpoint_to_the_allowed_list() {
		$this->assert_filter_registered(
			'wp_rest_cache/allowed_endpoints',
			Oembed_Api::class,
			'add_oembed_endpoint'
		);
	}

	public function test_object_type_determination_has_both_endpoint_and_oembed_handlers() {
		// Two listeners on the same filter — endpoint_api goes first, oembed_api fills in
		// when endpoint_api returns 'unknown'.
		$this->assert_filter_registered(
			'wp_rest_cache/determine_object_type',
			Endpoint_Api::class,
			'determine_object_type'
		);
		$this->assert_filter_registered(
			'wp_rest_cache/determine_object_type',
			Oembed_Api::class,
			'determine_object_type'
		);
	}

	public function test_oembed_api_processes_cache_relations() {
		$this->assert_filter_registered(
			'wp_rest_cache/process_cache_relations',
			Oembed_Api::class,
			'process_cache_relations'
		);
	}

	// ---------- Admin hooks ----------

	public function test_admin_creates_settings_menu() {
		$this->assert_filter_registered( 'admin_menu', Admin::class, 'create_menu' );
	}

	public function test_admin_registers_settings_on_admin_init() {
		$this->assert_filter_registered( 'admin_init', Admin::class, 'register_settings' );
	}

	public function test_admin_ajax_flush_caches_handler_is_registered() {
		$this->assert_filter_registered( 'wp_ajax_flush_caches', Admin::class, 'flush_caches' );
	}

	public function test_admin_cli_command_registration_is_hooked_on_cli_init() {
		$this->assert_filter_registered( 'cli_init', Admin::class, 'add_cli_commands' );
	}

	// ----- helpers -----

	/**
	 * Assert that *some* callback on $hook is `[<instance-of $class>, $method]`. WP indexes
	 * object-method callbacks by spl_object_hash, so without access to the actual instance
	 * registered by Plugin we can't use has_action() directly — we walk $wp_filter instead.
	 */
	private function assert_filter_registered( $hook, $class, $method ) {
		global $wp_filter;

		$this->assertArrayHasKey( $hook, $wp_filter, "No callbacks registered for {$hook}" );

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$fn = $callback['function'];
				if ( is_array( $fn ) && is_object( $fn[0] ) && $fn[0] instanceof $class && $fn[1] === $method ) {
					$this->addToAssertionCount( 1 );
					return;
				}
			}
		}

		$this->fail( "Expected an instance-callback {$class}::{$method} on hook {$hook}, none found" );
	}
}
