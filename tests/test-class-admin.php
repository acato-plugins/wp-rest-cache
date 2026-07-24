<?php
/**
 * Tests for the Admin class — settings sanitization, screen options, notices, cron
 * (un)scheduling on settings changes, and plugin activation/deactivation hooks.
 *
 * UI-only methods (enqueue_styles/scripts, settings_page output, admin_bar_item, etc.)
 * aren't covered here — they depend on get_current_screen / global $wp_admin_bar / template
 * includes, none of which are meaningful in CLI.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Admin\Admin;
use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Admin\Admin
 */
class Test_Admin extends Caching_Test_Case {

	const CRON_HOOK = 'wp_rest_cache_regenerate_cron';

	/** @var Admin */
	private $admin;

	public function set_up() {
		parent::set_up();
		$this->admin = new Admin( 'wp-rest-cache', '2026.2.0' );
		delete_option( 'wp_rest_cache_admin_notices' );
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function tear_down() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		parent::tear_down();
	}

	// ---------- sanitize_timeout ----------

	public function test_sanitize_timeout_clamps_zero_to_minimum_of_one() {
		$this->assertSame( 1, $this->admin->sanitize_timeout( 0 ) );
	}

	public function test_sanitize_timeout_passes_positive_integers_through() {
		$this->assertSame( 7, $this->admin->sanitize_timeout( 7 ) );
	}

	public function test_sanitize_timeout_coerces_string_input_then_clamps() {
		// absint() takes the absolute value first, so '-5' becomes 5 — the clamp to 1 only
		// kicks in for 0 / non-numeric input.
		$this->assertSame( 5, $this->admin->sanitize_timeout( '-5' ) );
		$this->assertSame( 12, $this->admin->sanitize_timeout( '12' ) );
		$this->assertSame( 1, $this->admin->sanitize_timeout( 'not-a-number' ) );
	}

	// ---------- sanitize_timeout_interval ----------

	public function test_sanitize_timeout_interval_passes_recognized_constants_through() {
		$this->assertSame( HOUR_IN_SECONDS, $this->admin->sanitize_timeout_interval( HOUR_IN_SECONDS ) );
		$this->assertSame( DAY_IN_SECONDS, $this->admin->sanitize_timeout_interval( DAY_IN_SECONDS ) );
	}

	public function test_sanitize_timeout_interval_falls_back_to_year_for_unrecognized() {
		$this->assertSame( YEAR_IN_SECONDS, $this->admin->sanitize_timeout_interval( 12345 ) );
		$this->assertSame( YEAR_IN_SECONDS, $this->admin->sanitize_timeout_interval( 0 ) );
	}

	// ---------- sanitize_checkbox ----------

	public function test_sanitize_checkbox_returns_one_only_for_string_one() {
		$this->assertSame( '1', $this->admin->sanitize_checkbox( '1' ) );
		// Strict `=== '1'` — anything else, including int 1 or 'on', returns ''.
		$this->assertSame( '', $this->admin->sanitize_checkbox( 1 ) );
		$this->assertSame( '', $this->admin->sanitize_checkbox( 'on' ) );
		$this->assertSame( '', $this->admin->sanitize_checkbox( '' ) );
		$this->assertSame( '', $this->admin->sanitize_checkbox( null ) );
	}

	// ---------- sanitize_regenerate_interval ----------

	public function test_sanitize_regenerate_interval_passes_known_schedule_through() {
		$this->assertSame( 'hourly', $this->admin->sanitize_regenerate_interval( 'hourly' ) );
		$this->assertSame( 'daily', $this->admin->sanitize_regenerate_interval( 'daily' ) );
	}

	public function test_sanitize_regenerate_interval_falls_back_to_twicedaily_for_unknown() {
		$this->assertSame( 'twicedaily', $this->admin->sanitize_regenerate_interval( 'not-a-schedule' ) );
		$this->assertSame( 'twicedaily', $this->admin->sanitize_regenerate_interval( '' ) );
	}

	// ---------- sanitize_regenerate_number ----------

	public function test_sanitize_regenerate_number_clamps_to_minimum_of_one() {
		// absint() takes the absolute value, so -10 ends up as 10 (not clamped). The clamp
		// only kicks in when the absint result itself is below 1.
		$this->assertSame( 1, $this->admin->sanitize_regenerate_number( 0 ) );
		$this->assertSame( 10, $this->admin->sanitize_regenerate_number( -10 ) );
		$this->assertSame( 25, $this->admin->sanitize_regenerate_number( 25 ) );
	}

	// ---------- sanitize_cacheable_request_headers ----------

	public function test_sanitize_cacheable_request_headers_trims_and_filters_empty_segments() {
		$this->assertSame(
			'Authorization,X-Custom',
			$this->admin->sanitize_cacheable_request_headers( ' Authorization , X-Custom ' )
		);
	}

	public function test_sanitize_cacheable_request_headers_drops_empty_segments_between_commas() {
		$this->assertSame(
			'A,B',
			$this->admin->sanitize_cacheable_request_headers( 'A,,B' )
		);
	}

	public function test_sanitize_cacheable_request_headers_returns_empty_for_blank_input() {
		$this->assertSame( '', $this->admin->sanitize_cacheable_request_headers( '' ) );
	}

	// ---------- set_screen_option ----------

	public function test_set_screen_option_returns_user_value_for_caches_per_page() {
		$this->assertSame( 50, $this->admin->set_screen_option( 0, 'caches_per_page', 50 ) );
	}

	public function test_set_screen_option_passes_through_unrelated_option_values() {
		$this->assertSame( 20, $this->admin->set_screen_option( 20, 'unrelated_option', 99 ) );
	}

	// ---------- add_plugin_settings_link ----------

	public function test_add_plugin_settings_link_appends_a_settings_link() {
		$result = $this->admin->add_plugin_settings_link( [ '<a href="#deactivate">Deactivate</a>' ] );

		$this->assertCount( 2, $result );
		$this->assertStringContainsString( 'page=wp-rest-cache', $result[1] );
		$this->assertStringContainsString( 'Settings', $result[1] );
	}

	// ---------- empty_cache_url ----------

	public function test_empty_cache_url_contains_clear_cache_subpage_and_nonce() {
		$url = Admin::empty_cache_url();

		$this->assertStringContainsString( 'page=wp-rest-cache', $url );
		$this->assertStringContainsString( 'sub=clear-cache', $url );
		$this->assertStringContainsString( 'wp_rest_cache_nonce=', $url );
	}

	// ---------- filter_settings_panels ----------

	public function test_filter_settings_panels_merges_defaults_into_existing_panels() {
		$result = $this->admin->filter_settings_panels( [] );

		$this->assertArrayHasKey( 'settings', $result );
		$this->assertArrayHasKey( 'endpoint-api', $result );
		$this->assertArrayHasKey( 'clear-cache', $result );
		$this->assertSame( 10, $result['settings']['position'] );
		$this->assertSame( 20, $result['endpoint-api']['position'] );
		$this->assertSame( 30, $result['clear-cache']['position'] );
	}

	public function test_filter_settings_panels_preserves_existing_panel_entries() {
		$existing = [ 'custom-panel' => [ 'label' => 'Custom', 'position' => 5 ] ];

		$result = $this->admin->filter_settings_panels( $existing );

		$this->assertArrayHasKey( 'custom-panel', $result );
		$this->assertSame( 5, $result['custom-panel']['position'] );
	}

	// ---------- add_notice (protected — via reflection) ----------

	public function test_add_notice_creates_first_entry_for_a_type() {
		$this->invoke_private(
			$this->admin,
			'add_notice',
			[ 'warning', 'first message', true, [] ]
		);

		$notices = get_option( 'wp_rest_cache_admin_notices' );
		$this->assertSame( 'first message', $notices['warning'][0]['message'] );
		$this->assertTrue( $notices['warning'][0]['dismissible'] );
	}

	public function test_add_notice_dedupes_identical_messages_within_a_type() {
		$this->invoke_private( $this->admin, 'add_notice', [ 'warning', 'same message' ] );
		$this->invoke_private( $this->admin, 'add_notice', [ 'warning', 'same message' ] );

		$notices = get_option( 'wp_rest_cache_admin_notices' );
		$this->assertCount( 1, $notices['warning'] );
	}

	public function test_add_notice_appends_distinct_messages_under_the_same_type() {
		$this->invoke_private( $this->admin, 'add_notice', [ 'warning', 'first' ] );
		$this->invoke_private( $this->admin, 'add_notice', [ 'warning', 'second' ] );

		$notices = get_option( 'wp_rest_cache_admin_notices' );
		$this->assertCount( 2, $notices['warning'] );
		$this->assertSame( [ 'first', 'second' ], array_column( $notices['warning'], 'message' ) );
	}

	// ---------- regenerate_updated ----------

	public function test_regenerate_updated_schedules_cron_when_value_is_one() {
		update_option( 'wp_rest_cache_regenerate_interval', 'hourly' );

		$this->admin->regenerate_updated( '0', '1' );

		$this->assertNotFalse( wp_next_scheduled( self::CRON_HOOK ) );
	}

	public function test_regenerate_updated_clears_cron_when_value_is_not_one() {
		// Pre-seed a scheduled event so we can verify the clear-side of the branch.
		wp_schedule_event( time() + 60, 'hourly', self::CRON_HOOK );
		$this->assertNotFalse( wp_next_scheduled( self::CRON_HOOK ), 'precondition: cron was scheduled' );

		$this->admin->regenerate_updated( '1', '0' );

		$this->assertFalse( wp_next_scheduled( self::CRON_HOOK ) );
	}

	// ---------- regenerate_interval_updated ----------

	public function test_regenerate_interval_updated_reschedules_when_regenerate_is_enabled() {
		update_option( 'wp_rest_cache_regenerate', '1' );
		wp_schedule_event( time() + 60, 'twicedaily', self::CRON_HOOK );

		$this->admin->regenerate_interval_updated( 'twicedaily', 'hourly' );

		// Cron is still scheduled (under the new interval).
		$this->assertNotFalse( wp_next_scheduled( self::CRON_HOOK ) );
	}

	public function test_regenerate_interval_updated_does_nothing_when_regenerate_is_disabled() {
		update_option( 'wp_rest_cache_regenerate', '0' );

		$this->admin->regenerate_interval_updated( 'twicedaily', 'hourly' );

		$this->assertFalse( wp_next_scheduled( self::CRON_HOOK ) );
	}

	// ---------- activated_plugin ----------

	public function test_activated_plugin_for_wordfence_flushes_users_endpoint_caches() {
		// Seed a cache for the users endpoint and an unrelated cache; only the users one
		// should be flushed by the wordfence special-case.
		$users_cache = $this->insert_cache( [ 'request_uri' => '/wp-json/wp/v2/users' ] );
		$posts_cache = $this->insert_cache( [ 'request_uri' => '/wp-json/wp/v2/posts' ] );

		$this->admin->activated_plugin( 'wordfence/wordfence.php', false );

		$this->assertExpired( $users_cache );
		$this->assertNotExpired( $posts_cache );
	}

	public function test_activated_plugin_for_other_plugins_adds_a_warning_notice() {
		$this->admin->activated_plugin( 'some-other/plugin.php', false );

		$notices = get_option( 'wp_rest_cache_admin_notices' );
		$this->assertNotEmpty( $notices['warning'] ?? [] );
		$this->assertStringContainsString(
			'A new plugin has been activated',
			$notices['warning'][0]['message']
		);
	}

	public function test_activated_plugin_for_wordfence_does_not_add_a_notice() {
		// The wordfence branch handles the flush directly; no user-facing notice needed.
		$this->insert_cache( [ 'request_uri' => '/wp-json/wp/v2/users' ] );

		$this->admin->activated_plugin( 'wordfence/wordfence.php', false );

		$this->assertEmpty( get_option( 'wp_rest_cache_admin_notices', [] ) );
	}

	// ---------- deactivated_plugin ----------

	public function test_deactivated_plugin_adds_a_warning_notice() {
		$this->admin->deactivated_plugin();

		$notices = get_option( 'wp_rest_cache_admin_notices' );
		$this->assertNotEmpty( $notices['warning'] ?? [] );
		$this->assertStringContainsString(
			'A plugin has been deactivated',
			$notices['warning'][0]['message']
		);
	}
}
