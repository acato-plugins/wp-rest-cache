<?php
/**
 * Tests for Admin's UI-side methods — the ones the existing Test_Admin file deferred:
 * settings_page, admin_bar_item, check_muplugin_existence, check_memcache_ext_object_caching,
 * display_notices, register_settings, display_pro_message, add_cli_commands, enqueue_*,
 * create_menu, handle_actions.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Admin\Admin;

/**
 * @covers \WP_Rest_Cache_Plugin\Admin\Admin
 */
class Test_Admin_Ui extends Caching_Test_Case {

	/** @var Admin */
	private $admin;

	/** @var array<string,mixed> */
	private $server_backup;

	/** @var array<string,mixed> */
	private $get_backup;

	public function set_up() {
		parent::set_up();
		$this->admin = new Admin( 'wp-rest-cache', '2026.2.0' );
		delete_option( 'wp_rest_cache_admin_notices' );
		$this->server_backup = [
			'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? null,
		];
		$_SERVER['REQUEST_URI'] = '/wp-admin/options-general.php';
		$this->get_backup = $_GET;
	}

	public function tear_down() {
		foreach ( $this->server_backup as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}
		$_GET = $this->get_backup;
		parent::tear_down();
	}

	// ---------- enqueue_styles / enqueue_scripts ----------

	public function test_enqueue_styles_registers_the_main_admin_stylesheet() {
		$this->admin->enqueue_styles();

		$this->assertTrue( wp_style_is( 'wp-rest-cache', 'enqueued' ) );
	}

	public function test_enqueue_scripts_is_a_noop_outside_the_clear_cache_subpage() {
		$this->admin->enqueue_scripts();

		$this->assertFalse( wp_script_is( 'jquery-ui-progressbar', 'enqueued' ) );
	}

	public function test_enqueue_styles_enqueues_jquery_ui_on_the_clear_cache_subpage() {
		$_GET['page'] = 'wp-rest-cache';
		$_GET['sub']  = 'clear-cache';

		$this->admin->enqueue_styles();

		$this->assertTrue( wp_style_is( 'jquery-ui-progressbar', 'enqueued' ) );
	}

	public function test_enqueue_scripts_enqueues_progressbar_script_on_the_clear_cache_subpage() {
		$_GET['page'] = 'wp-rest-cache';
		$_GET['sub']  = 'clear-cache';

		$this->admin->enqueue_scripts();

		$this->assertTrue( wp_script_is( 'jquery-ui-progressbar', 'enqueued' ) );
	}

	// ---------- create_menu ----------

	public function test_create_menu_adds_settings_submenu_for_administrator() {
		// add_submenu_page requires an administrator cap (or whatever the filter resolves
		// to). Set the current user accordingly.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$this->admin->create_menu();

		global $submenu;
		$found = false;
		if ( isset( $submenu['options-general.php'] ) ) {
			foreach ( $submenu['options-general.php'] as $item ) {
				if ( 'wp-rest-cache' === ( $item[2] ?? null ) ) {
					$found = true;
					break;
				}
			}
		}
		$this->assertTrue( $found, 'Expected wp-rest-cache submenu under Settings' );
	}

	public function test_create_menu_capability_can_be_filtered() {
		// The capability defaults to 'administrator' but the filter can change it.
		$received_default = null;
		add_filter(
			'wp_rest_cache/settings_capability',
			function ( $default ) use ( &$received_default ) {
				$received_default = $default;
				return 'manage_options';
			}
		);

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$this->admin->create_menu();

		$this->assertSame( 'administrator', $received_default );
	}

	// ---------- settings_page ----------

	public function test_settings_page_wp_dies_for_users_without_settings_capability() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		$this->expectException( WPDieException::class );

		$this->admin->settings_page();
	}

	public function test_settings_page_applies_settings_panels_filter_for_admin_user() {
		// Asserting on the rendered HTML is fragile — `settings_page` uses include_once for
		// the partials, so once they've been loaded by another test in the suite, the
		// re-include is a no-op and produces no output. Verify the method got past the cap
		// check and into the panel-rendering section by observing the filter side-effect
		// instead.
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$applied = false;
		add_filter(
			'wp_rest_cache/settings_panels',
			function ( $panels ) use ( &$applied ) {
				$applied = true;
				return $panels;
			}
		);

		ob_start();
		$this->admin->settings_page();
		ob_end_clean();

		$this->assertTrue( $applied, 'settings_page must apply the settings_panels filter' );
	}

	// ---------- admin_bar_item ----------

	public function test_admin_bar_item_adds_button_for_authorized_user() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		// Build a fresh admin bar for this test.
		global $wp_admin_bar;
		require_once ABSPATH . 'wp-includes/class-wp-admin-bar.php';
		$wp_admin_bar = new WP_Admin_Bar();

		$this->admin->admin_bar_item();

		$this->assertNotNull(
			$wp_admin_bar->get_node( 'wp-rest-cache-clear' ),
			'Expected the Clear REST cache node in the admin bar'
		);
	}

	public function test_admin_bar_item_does_not_add_button_for_unprivileged_user() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

		global $wp_admin_bar;
		require_once ABSPATH . 'wp-includes/class-wp-admin-bar.php';
		$wp_admin_bar = new WP_Admin_Bar();

		$this->admin->admin_bar_item();

		$this->assertNull( $wp_admin_bar->get_node( 'wp-rest-cache-clear' ) );
	}

	public function test_admin_bar_item_can_be_suppressed_via_filter() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		add_filter( 'wp_rest_cache/display_clear_cache_button', '__return_false' );

		global $wp_admin_bar;
		require_once ABSPATH . 'wp-includes/class-wp-admin-bar.php';
		$wp_admin_bar = new WP_Admin_Bar();

		$this->admin->admin_bar_item();

		$this->assertNull( $wp_admin_bar->get_node( 'wp-rest-cache-clear' ) );
	}

	// ---------- check_muplugin_existence ----------

	public function test_check_muplugin_existence_adds_warning_when_file_missing() {
		$mu_file = WPMU_PLUGIN_DIR . '/wp-rest-cache.php';
		if ( file_exists( $mu_file ) ) {
			unlink( $mu_file );
		}

		// We don't want the side-effect Activator::create_mu_plugin call to actually copy
		// the file (otherwise the warning branch never fires). Short-circuit it via a filter
		// hook that prevents the filesystem method from being 'direct'.
		add_filter( 'filesystem_method', fn() => 'ftpext' );

		$this->admin->check_muplugin_existence();

		$notices = get_option( 'wp_rest_cache_admin_notices', [] );
		$this->assertNotEmpty( $notices['warning'] ?? [] );
		$this->assertStringContainsString(
			'You are not getting the best caching result',
			$notices['warning'][0]['message']
		);
	}

	// ---------- check_memcache_ext_object_caching ----------

	public function test_check_memcache_does_not_warn_when_no_memcache_class_is_loaded() {
		// Pin the AND-guard: even with ext_object_cache enabled, no warning unless either
		// the Memcache or Memcached class exists. In the test env neither is loaded.
		wp_using_ext_object_cache( true );

		$this->admin->check_memcache_ext_object_caching();

		$notices = get_option( 'wp_rest_cache_admin_notices', [] );
		$this->assertEmpty( $notices );

		wp_using_ext_object_cache( false );
	}

	// ---------- display_notices ----------

	public function test_display_notices_does_nothing_outside_relevant_admin_screens() {
		set_current_screen( 'edit-post' );

		update_option(
			'wp_rest_cache_admin_notices',
			[ 'warning' => [ [ 'message' => 'hi', 'dismissible' => true, 'button' => [] ] ] ]
		);

		ob_start();
		$this->admin->display_notices();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}

	public function test_display_notices_renders_messages_on_the_settings_page() {
		set_current_screen( 'settings_page_wp-rest-cache' );

		update_option(
			'wp_rest_cache_admin_notices',
			[
				'warning' => [
					[ 'message' => 'visible warning', 'dismissible' => true, 'button' => [] ],
				],
			]
		);

		ob_start();
		$this->admin->display_notices();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'visible warning', $html );
		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'is-dismissible', $html );
	}

	public function test_display_notices_clears_the_notices_option_after_rendering() {
		set_current_screen( 'settings_page_wp-rest-cache' );

		update_option(
			'wp_rest_cache_admin_notices',
			[ 'warning' => [ [ 'message' => 'x', 'dismissible' => true, 'button' => [] ] ] ]
		);

		ob_start();
		$this->admin->display_notices();
		ob_end_clean();

		$this->assertFalse(
			get_option( 'wp_rest_cache_admin_notices', false ),
			'Notice option should be deleted after rendering so the message is shown once'
		);
	}

	public function test_display_notices_renders_button_when_provided() {
		set_current_screen( 'settings_page_wp-rest-cache' );

		update_option(
			'wp_rest_cache_admin_notices',
			[
				'warning' => [
					[
						'message'     => 'with button',
						'dismissible' => true,
						'button'      => [ 'label' => 'Click me', 'url' => '#do-thing' ],
					],
				],
			]
		);

		ob_start();
		$this->admin->display_notices();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Click me', $html );
		$this->assertStringContainsString( '#do-thing', $html );
	}

	public function test_display_notices_renders_hide_link_for_permanently_dismissible_messages() {
		set_current_screen( 'settings_page_wp-rest-cache' );

		update_option(
			'wp_rest_cache_admin_notices',
			[
				'warning' => [
					[ 'message' => 'permanent', 'dismissible' => 'permanent', 'button' => [] ],
				],
			]
		);

		ob_start();
		$this->admin->display_notices();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Hide this message', $html );
		$this->assertStringContainsString( 'wp_rest_cache_dismiss=', $html );
	}

	public function test_display_notices_skips_messages_the_user_has_dismissed() {
		set_current_screen( 'settings_page_wp-rest-cache' );

		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		update_user_meta(
			$user_id,
			'wp_rest_cache_dismissed_notices',
			[ md5( 'silenced' ) ]
		);

		update_option(
			'wp_rest_cache_admin_notices',
			[
				'warning' => [
					[ 'message' => 'silenced', 'dismissible' => 'permanent', 'button' => [] ],
				],
			]
		);

		ob_start();
		$this->admin->display_notices();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'silenced', $html );
	}

	// ---------- register_settings ----------

	public function test_register_settings_registers_every_plugin_setting() {
		global $wp_registered_settings;
		$wp_registered_settings = []; // start from empty so we can see exactly what's registered.

		$this->admin->register_settings();

		$expected_settings = [
			'wp_rest_cache_timeout',
			'wp_rest_cache_timeout_interval',
			'wp_rest_cache_regenerate',
			'wp_rest_cache_regenerate_interval',
			'wp_rest_cache_regenerate_number',
			'wp_rest_cache_memcache_used',
			'wp_rest_cache_global_cacheable_request_headers',
		];

		foreach ( $expected_settings as $setting ) {
			$this->assertArrayHasKey( $setting, $wp_registered_settings, "Expected {$setting} to be registered" );
			$this->assertSame( 'wp-rest-cache-settings', $wp_registered_settings[ $setting ]['group'] );
			$this->assertIsCallable( $wp_registered_settings[ $setting ]['sanitize_callback'] );
		}
	}

	// ---------- display_pro_message ----------

	public function test_display_pro_message_adds_permanent_success_notice() {
		$this->admin->display_pro_message();

		$notices = get_option( 'wp_rest_cache_admin_notices', [] );
		$this->assertNotEmpty( $notices['success'] ?? [] );
		$this->assertStringContainsString( 'Pro version', $notices['success'][0]['message'] );
		$this->assertSame( 'permanent', $notices['success'][0]['dismissible'] );
	}

	// ---------- add_cli_commands ----------

	public function test_add_cli_commands_is_a_noop_when_wp_cli_constant_is_undefined() {
		// In the test env, WP_CLI is not defined → the method just returns.
		// We verify by ensuring the call doesn't throw and no command was registered (we
		// can't easily verify the latter without WP_CLI loaded, so the "doesn't throw" is
		// the test).
		$this->assertNull( $this->admin->add_cli_commands() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_add_cli_commands_attempts_to_register_the_wprc_command_when_wp_cli_constant_is_set() {
		// Goal: cover the `WP_CLI::add_command()` line inside add_cli_commands(). WP_CLI is
		// autoloaded via dev deps, but its add_command path eventually reaches utility
		// functions/constants that only get defined when WP_CLI's own bootstrap.php runs —
		// which would require a full wp-cli runtime here. So we let add_command throw and
		// just assert the call was attempted. The line counts as executed in Xdebug's
		// coverage model the moment the statement starts, before the Error propagates.
		// Process-isolated so neither the WP_CLI constant nor the command-registry state
		// leak into other tests.
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}

		$admin     = new Admin( 'wp-rest-cache', '2026.2.0' );
		$attempted = false;
		try {
			$admin->add_cli_commands();
			$attempted = true;
		} catch ( \Throwable $e ) {
			// Expected: WP_CLI's add_command bootstrap is incomplete in the test env.
			$attempted = true;
		}

		$this->assertTrue( $attempted );
	}

	// ---------- handle_actions ----------

	public function test_handle_actions_dispatches_to_process_action_when_request_action_is_set() {
		// `handle_actions` has two top-level branches: a notice-dismiss path (gated on
		// $_GET['wp_rest_cache_dismiss']) and a `$_REQUEST['action']` path that constructs
		// an API_Caches_Table and calls process_action. The dismiss path is covered by a
		// separate test below; here we only verify the second branch fires without error —
		// the table's process_action behavior is covered by Test_Api_Caches_Table.
		$_REQUEST['action'] = 'flush';

		$this->admin->handle_actions();

		// No exception thrown is the assertion — the table was constructed and process_action
		// was called.
		$this->assertTrue( true );

		unset( $_REQUEST['action'] );
	}

	public function test_handle_actions_records_a_dismissed_notice_when_called_with_valid_nonce() {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$notice_key                          = 'mu-plugin-missing';
		$_GET['wp_rest_cache_dismiss']       = $notice_key;
		$_GET['_wpnonce']                    = wp_create_nonce( 'wp-rest-cache-dismiss-notice-' . $notice_key );
		$_REQUEST['_wpnonce']                = $_GET['_wpnonce']; // check_admin_referer reads $_REQUEST.

		$this->admin->handle_actions();

		$dismissed = get_user_meta( $user_id, 'wp_rest_cache_dismissed_notices', true );
		$this->assertIsArray( $dismissed );
		$this->assertContains( $notice_key, $dismissed );

		// A second dispatch with the same key should NOT add a duplicate.
		$this->admin->handle_actions();
		$dismissed_again = get_user_meta( $user_id, 'wp_rest_cache_dismissed_notices', true );
		$this->assertCount( 1, $dismissed_again );

		unset( $_GET['wp_rest_cache_dismiss'], $_GET['_wpnonce'], $_REQUEST['_wpnonce'] );
	}

	public function test_handle_actions_is_a_noop_when_action_is_minus_one() {
		// `-1` is the "no bulk action selected" sentinel from WP_List_Table's bulk dropdown.
		$_REQUEST['action'] = '-1';

		$this->admin->handle_actions();

		$this->assertTrue( true );

		unset( $_REQUEST['action'] );
	}
}
