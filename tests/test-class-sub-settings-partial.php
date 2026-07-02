<?php
/**
 * Tests for admin/partials/sub-settings.php — the settings tab form.
 *
 * Focus is on the conditional rendering and the current-value reflection (so a regression
 * that swaps two options or forgets `selected()` shows up immediately). Pure HTML structure
 * isn't pinned in detail.
 *
 * Constant-disabling branches (WP_REST_CACHE_TIMEOUT etc.) need process isolation to test
 * and are documented as gaps.
 *
 * @package WP_Rest_Cache_Plugin
 */

/**
 * @covers \WP_Rest_Cache_Plugin\Admin\Admin
 */
class Test_Sub_Settings_Partial extends Caching_Test_Case {

	const PARTIAL = __DIR__ . '/../admin/partials/sub-settings.php';

	private function render() {
		ob_start();
		include self::PARTIAL;
		return ob_get_clean();
	}

	// ---------- Form skeleton ----------

	public function test_form_posts_to_options_php_and_includes_settings_fields_nonce() {
		$html = $this->render();

		$this->assertStringContainsString( '<form method="post" action="options.php">', $html );
		// settings_fields() emits single-quoted attributes for option_page (`'wp-rest-cache-settings'`)
		// plus a wp_nonce_field. Use a quote-agnostic match.
		$this->assertMatchesRegularExpression(
			'/name=["\']option_page["\']\s+value=["\']wp-rest-cache-settings["\']/',
			$html
		);
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
	}

	public function test_form_has_a_submit_button() {
		$html = $this->render();

		// `submit_button()` emits an <input class="button button-primary"> by default.
		$this->assertStringContainsString( 'class="button button-primary"', $html );
		$this->assertStringContainsString( 'name="submit"', $html );
	}

	// ---------- Current-value reflection ----------

	public function test_timeout_input_reflects_stored_option_value() {
		update_option( 'wp_rest_cache_timeout', 7 );

		$html = $this->render();

		// The timeout input has name="wp_rest_cache_timeout" and the current value as its value="…".
		$this->assertMatchesRegularExpression(
			'/name="wp_rest_cache_timeout"[^>]*value="7"/',
			$html
		);
	}

	public function test_timeout_interval_dropdown_marks_stored_value_as_selected() {
		update_option( 'wp_rest_cache_timeout_interval', HOUR_IN_SECONDS );

		$html = $this->render();

		// Find the Hour(s) <option> and confirm `selected` is on it.
		$pattern = '/<option value="' . preg_quote( (string) HOUR_IN_SECONDS, '/' ) . '"\s+selected/';
		$this->assertMatchesRegularExpression( $pattern, $html );
	}

	public function test_regenerate_checkbox_is_checked_when_setting_is_enabled() {
		update_option( 'wp_rest_cache_regenerate', '1' );

		$html = $this->render();

		// `name="wp_rest_cache_regenerate"` checkbox should carry `checked="checked"`.
		$this->assertMatchesRegularExpression(
			'/name="wp_rest_cache_regenerate"[^>]*checked="checked"/',
			$html
		);
	}

	public function test_regenerate_checkbox_is_unchecked_when_setting_is_disabled() {
		update_option( 'wp_rest_cache_regenerate', '0' );

		$html = $this->render();

		$this->assertDoesNotMatchRegularExpression(
			'/name="wp_rest_cache_regenerate"[^>]*checked="checked"/',
			$html
		);
	}

	public function test_regenerate_interval_dropdown_marks_stored_schedule_as_selected() {
		update_option( 'wp_rest_cache_regenerate_interval', 'hourly' );

		$html = $this->render();

		// The dropdown iterates wp_get_schedules(); the 'hourly' option must end up selected.
		$this->assertMatchesRegularExpression(
			'/<option value="hourly"\s+selected/',
			$html
		);
	}

	public function test_regenerate_number_input_reflects_stored_value() {
		update_option( 'wp_rest_cache_regenerate_number', 42 );

		$html = $this->render();

		$this->assertMatchesRegularExpression(
			'/name="wp_rest_cache_regenerate_number"[^>]*value="42"/',
			$html
		);
	}

	public function test_global_cacheable_request_headers_input_reflects_stored_value() {
		update_option(
			'wp_rest_cache_global_cacheable_request_headers',
			'Authorization,X-Custom'
		);

		$html = $this->render();

		$this->assertMatchesRegularExpression(
			'/name="wp_rest_cache_global_cacheable_request_headers"[^>]*value="Authorization,X-Custom"/',
			$html
		);
	}

	// ---------- Memcache row visibility ----------

	public function test_memcache_row_is_not_rendered_when_external_object_cache_is_off() {
		// In a default test env `wp_using_ext_object_cache()` is false, so the row's outer
		// condition fails and the row never appears. The setting name is unique enough to
		// search for.
		wp_using_ext_object_cache( false );

		$html = $this->render();

		$this->assertStringNotContainsString( 'name="wp_rest_cache_memcache_used"', $html );
	}

	public function test_memcache_row_is_not_rendered_without_a_memcache_class_present() {
		// Pin the AND-guard: even with ext_object_cache enabled, the row needs `Memcache` or
		// `Memcached` class to exist. In the test env neither is loaded, so still no row.
		wp_using_ext_object_cache( true );

		$html = $this->render();

		$this->assertStringNotContainsString( 'name="wp_rest_cache_memcache_used"', $html );

		// Restore default so we don't pollute downstream tests.
		wp_using_ext_object_cache( false );
	}

	// ---------- Sidebar (Pro / support panels) ----------

	public function test_sidebar_renders_pro_upgrade_panel() {
		$html = $this->render();

		$this->assertStringContainsString( 'Upgrade to Pro', $html );
		$this->assertStringContainsString( 'plugins.acato.nl', $html );
	}

	public function test_sidebar_renders_support_panel_link_to_wordpress_org() {
		$html = $this->render();

		$this->assertStringContainsString( 'Need Help', $html );
		$this->assertStringContainsString( 'wordpress.org/support/plugin/wp-rest-cache/', $html );
	}
}
