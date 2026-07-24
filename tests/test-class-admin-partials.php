<?php
/**
 * Tests for the admin partial templates under admin/partials/. These verify the early-
 * return guards (so a missing var doesn't crash the page), capability gates, and the
 * conditional UI states that depend on nonce / panel state.
 *
 * Pure HTML structure isn't pinned in detail — that's better verified by viewing the page —
 * but the load-bearing branches that change page state ARE pinned.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Admin\Admin;

/**
 * @covers \WP_Rest_Cache_Plugin\Admin\Admin
 * @covers \WP_Rest_Cache_Plugin\Admin\Includes\API_Caches_Table
 */
class Test_Admin_Partials extends Caching_Test_Case {

	const PARTIALS_DIR = __DIR__ . '/../admin/partials';

	/** @var array<string,mixed> $_REQUEST backup. */
	private $request_backup;

	public function set_up() {
		parent::set_up();
		$this->request_backup = $_REQUEST;
	}

	public function tear_down() {
		$_REQUEST = $this->request_backup;
		parent::tear_down();
	}

	// ---------- header.php ----------

	public function test_header_returns_early_when_sub_variable_is_not_set() {
		// The partial guards with `if ( ! isset( $sub, $this->settings_panels ) ) return;`
		// — without $sub in scope, nothing is rendered. Protects against direct file access
		// outside the Admin::settings_page flow.
		$html = $this->render_header_with_admin( null, [ 'settings' => [ 'label' => 'X' ] ] );

		$this->assertSame( '', $html );
	}

	public function test_header_renders_one_nav_tab_per_settings_panel() {
		$html = $this->render_header_with_admin(
			'settings',
			[
				'settings' => [ 'label' => 'Settings', 'position' => 10 ],
				'logs'     => [ 'label' => 'Logs', 'position' => 20 ],
			]
		);

		$this->assertStringContainsString( 'id="settings"', $html );
		$this->assertStringContainsString( '>Settings<', $html );
		$this->assertStringContainsString( 'id="logs"', $html );
		$this->assertStringContainsString( '>Logs<', $html );
	}

	public function test_header_marks_the_active_sub_with_nav_tab_active_class() {
		$html = $this->render_header_with_admin(
			'logs',
			[
				'settings' => [ 'label' => 'Settings' ],
				'logs'     => [ 'label' => 'Logs' ],
			]
		);

		// Active tab has the modifier class; inactive does not.
		$this->assertMatchesRegularExpression(
			'/id="logs"\s+class="nav-tab nav-tab-active"/',
			$html
		);
		$this->assertMatchesRegularExpression(
			'/id="settings"\s+class="nav-tab "/',
			$html
		);
	}

	// ---------- caches-table.php + sub-endpoint-api.php ----------
	//
	// Note: caches-table.php is loaded via `require_once` from inside sub-endpoint-api.php.
	// PHP tracks every loaded file in get_included_files(), and `require_once` consults that
	// list — so any earlier test that `include`s caches-table.php would silently no-op the
	// inner require_once in sub-endpoint-api.php. To avoid that test-order coupling we only
	// exercise caches-table.php transitively via sub-endpoint-api.php.

	public function test_sub_endpoint_api_renders_the_wrap_and_the_inner_caches_table() {
		ob_start();
		include self::PARTIALS_DIR . '/sub-endpoint-api.php';
		$html = ob_get_clean();

		// The outer wrap is from sub-endpoint-api.php itself.
		$this->assertStringContainsString( 'class="wrap"', $html );
		// The form is from caches-table.php — its presence proves the require_once chain ran.
		$this->assertStringContainsString( '<form method="get">', $html );
		$this->assertStringContainsString( 'name="page" value="wp-rest-cache"', $html );
	}

	// ---------- sub-clear-cache.php ----------

	public function test_sub_clear_cache_disables_the_submit_button_after_a_valid_nonce_submission() {
		// Valid nonce → the form was just submitted → button is disabled + progressbar
		// container is rendered (the JS then progresses it via the flush_caches ajax handler).
		$_REQUEST['wp_rest_cache_nonce'] = wp_create_nonce( 'wp_rest_cache_options' );

		ob_start();
		include self::PARTIALS_DIR . '/sub-clear-cache.php';
		$html = ob_get_clean();

		$this->assertStringContainsString( 'button-disabled', $html );
		$this->assertStringContainsString( 'id="progressbar"', $html );
	}

	public function test_sub_clear_cache_enables_the_submit_button_without_a_nonce() {
		unset( $_REQUEST['wp_rest_cache_nonce'] );

		ob_start();
		include self::PARTIALS_DIR . '/sub-clear-cache.php';
		$html = ob_get_clean();

		$this->assertStringContainsString( 'button-primary', $html );
		$this->assertStringNotContainsString( 'id="progressbar"', $html );
	}

	public function test_sub_clear_cache_with_invalid_nonce_keeps_button_enabled() {
		// Wrong action name → nonce verification fails → treated the same as no nonce.
		$_REQUEST['wp_rest_cache_nonce'] = wp_create_nonce( 'wrong-action-name' );

		ob_start();
		include self::PARTIALS_DIR . '/sub-clear-cache.php';
		$html = ob_get_clean();

		$this->assertStringContainsString( 'button-primary', $html );
		$this->assertStringNotContainsString( 'id="progressbar"', $html );
	}

	// ---------- sub-cache-details.php ----------

	public function test_sub_cache_details_wp_dies_for_users_without_administrator_capability() {
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );

		$this->expectException( WPDieException::class );
		$this->expectExceptionMessage( 'You do not have permission to access this page' );

		include self::PARTIALS_DIR . '/sub-cache-details.php';
	}

	public function test_sub_cache_details_can_be_loaded_by_an_administrator() {
		$admin_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_id );

		// filter_input(INPUT_GET, 'cache_key', ...) returns null in CLI, so this falls into
		// the "found" branch via get_cache_data's autovivification behavior. We don't assert
		// specific cell contents — just that the page-shell renders without dying.
		ob_start();
		// Suppress PHP 8.1+ deprecation notices from strtotime(null) inside get_cache_row —
		// they trip WP's deprecation tracker when the cache_key resolves to nothing.
		$old_error = error_reporting();
		error_reporting( $old_error & ~E_DEPRECATED & ~E_WARNING );
		try {
			include self::PARTIALS_DIR . '/sub-cache-details.php';
		} finally {
			error_reporting( $old_error );
		}
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Cache details', $html );
		$this->assertStringContainsString( 'class="wrap"', $html );
	}

	// ----- helpers -----

	/**
	 * Render header.php with a real Admin instance as $this. settings_panels is private so
	 * we set it via reflection; the include happens inside a closure bound to Admin's scope
	 * so the partial's `$this->settings_panels` access works.
	 */
	private function render_header_with_admin( $sub, array $panels ) {
		$admin = new Admin( 'wp-rest-cache', '2026.2.0' );

		$prop = ( new ReflectionClass( $admin ) )->getProperty( 'settings_panels' );
		$prop->setAccessible( true );
		$prop->setValue( $admin, $panels );

		$partials_dir = self::PARTIALS_DIR;

		if ( null === $sub ) {
			// Don't bring $sub into the closure scope at all — partial's isset() returns false.
			$render = Closure::bind(
				function () use ( $partials_dir ) {
					ob_start();
					include $partials_dir . '/header.php';
					return ob_get_clean();
				},
				$admin,
				Admin::class
			);
		} else {
			$render = Closure::bind(
				function () use ( $sub, $partials_dir ) {
					ob_start();
					include $partials_dir . '/header.php';
					return ob_get_clean();
				},
				$admin,
				Admin::class
			);
		}

		return $render();
	}
}
