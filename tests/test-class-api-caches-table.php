<?php
/**
 * Tests for API_Caches_Table — the WP_List_Table subclass that powers the admin
 * "API Caches" view.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Admin\Includes\API_Caches_Table;

/**
 * @covers \WP_Rest_Cache_Plugin\Admin\Includes\API_Caches_Table
 */
class Test_Api_Caches_Table extends Caching_Test_Case {

	/** @var API_Caches_Table */
	private $table;

	/** @var array<string,mixed> $_GET/$_REQUEST backup. */
	private $request_backup = [];

	public function set_up() {
		parent::set_up();
		$this->table          = new API_Caches_Table( 'endpoint' );
		$this->request_backup = [
			'_GET'     => $_GET,
			'_REQUEST' => $_REQUEST,
		];
	}

	public function tear_down() {
		$_GET     = $this->request_backup['_GET'];
		$_REQUEST = $this->request_backup['_REQUEST'];
		parent::tear_down();
	}

	// ---------- Constructor ----------

	public function test_constructor_with_invalid_api_type_throws() {
		$this->expectException( \Exception::class );
		$this->expectExceptionMessage( 'Invalid API type' );

		new API_Caches_Table( 'item' );
	}

	public function test_constructor_with_endpoint_api_type_succeeds() {
		$table = new API_Caches_Table( 'endpoint' );

		$this->assertInstanceOf( API_Caches_Table::class, $table );
	}

	// ---------- Static delegates over Caching ----------

	public function test_record_count_delegates_to_caching_get_record_count() {
		$this->insert_cache( [ 'cache_type' => 'endpoint' ] );
		$this->insert_cache( [ 'cache_type' => 'endpoint' ] );
		$this->insert_cache( [ 'cache_type' => 'item' ] ); // wrong api_type, excluded

		$this->assertSame( 2, API_Caches_Table::record_count() );
	}

	public function test_get_caches_delegates_to_caching_get_api_data() {
		$cache_id = $this->insert_cache( [ 'cache_type' => 'endpoint', 'cache_key' => 'k1' ] );

		$rows = API_Caches_Table::get_caches( 10, 1 );

		$this->assertCount( 1, $rows );
		$this->assertSame( (string) $cache_id, $rows[0]['cache_id'] );
	}

	public function test_clear_cache_delegates_to_caching_delete_cache_with_force_flag() {
		$cache_id = $this->insert_cache( [ 'cache_key' => 'to-delete' ] );

		API_Caches_Table::clear_cache( 'to-delete', true );

		// $force=true → hard delete (row gone).
		global $wpdb;
		$still_there = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}wrc_caches` WHERE cache_id = %d",
				$cache_id
			)
		);
		$this->assertSame( 0, $still_there );
	}

	// ---------- Column generators ----------

	public function test_no_items_emits_the_translated_message() {
		ob_start();
		$this->table->no_items();
		$output = ob_get_clean();

		$this->assertSame( 'No caches available', $output );
	}

	public function test_column_cache_key_renders_link_to_cache_details_with_key() {
		$item = $this->fake_item( [ 'cache_key' => 'abc123', 'is_active' => true ] );

		$html = $this->table->column_cache_key( $item );

		$this->assertStringContainsString( 'sub=cache-details', $html );
		$this->assertStringContainsString( 'cache_key=abc123', $html );
		$this->assertStringContainsString( '>abc123<', $html );
	}

	public function test_column_cache_key_includes_flush_action_when_item_is_active() {
		$item = $this->fake_item( [ 'is_active' => true ] );

		$html = $this->table->column_cache_key( $item );

		$this->assertStringContainsString( 'action=flush', $html );
		$this->assertStringContainsString( 'action=delete', $html );
	}

	public function test_column_cache_key_omits_flush_action_when_item_is_inactive() {
		// Pin the asymmetry: an already-expired cache has no "flush" action — only details
		// and delete — because there's nothing live to flush.
		$item = $this->fake_item( [ 'is_active' => false ] );

		$html = $this->table->column_cache_key( $item );

		$this->assertStringNotContainsString( 'action=flush', $html );
		$this->assertStringContainsString( 'action=delete', $html );
	}

	public function test_column_is_active_renders_green_yes_dashicon_for_active_caches() {
		$html = $this->table->column_is_active( $this->fake_item( [ 'is_active' => true ] ) );

		$this->assertStringContainsString( 'dashicons-yes', $html );
		$this->assertStringContainsString( 'color:green', $html );
	}

	public function test_column_is_active_renders_red_no_dashicon_for_inactive_caches() {
		$html = $this->table->column_is_active( $this->fake_item( [ 'is_active' => false ] ) );

		$this->assertStringContainsString( 'dashicons-no', $html );
		$this->assertStringContainsString( 'color:red', $html );
	}

	public function test_column_cb_renders_checkbox_with_cache_key_value() {
		$item = $this->fake_item( [ 'cache_key' => 'xyz' ] );

		$html = $this->table->column_cb( $item );

		$this->assertStringContainsString( 'name="bulk-flush[]"', $html );
		$this->assertStringContainsString( 'value="xyz"', $html );
	}

	public function test_column_default_escapes_and_returns_the_named_column_value() {
		$item = $this->fake_item( [ 'request_uri' => '/wp-json/wp/v2/<script>' ] );

		$html = $this->table->column_default( $item, 'request_uri' );

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( '&lt;script&gt;', $html );
	}

	public function test_column_default_returns_empty_string_for_missing_column() {
		$html = $this->table->column_default( $this->fake_item(), 'no-such-column' );

		$this->assertSame( '', $html );
	}

	public function test_column_default_applies_custom_column_output_filter() {
		add_filter(
			'wp_rest_cache/api_caches_table_column_output',
			fn( $output, $item, $column ) => "custom:{$column}:" . ( $item['cache_key'] ?? '' ),
			10,
			3
		);

		$html = $this->table->column_default(
			$this->fake_item( [ 'cache_key' => 'k1' ] ),
			'some-extra-column'
		);

		$this->assertSame( 'custom:some-extra-column:k1', $html );
	}

	// ---------- Column / sort / bulk-action lists ----------

	public function test_get_columns_includes_the_core_set() {
		$columns = $this->table->get_columns();

		foreach ( [ 'cb', 'cache_key', 'request_uri', 'object_type', 'expiration', 'cache_hits', 'is_active' ] as $expected ) {
			$this->assertArrayHasKey( $expected, $columns );
		}
	}

	public function test_get_columns_applies_table_columns_filter() {
		add_filter(
			'wp_rest_cache/api_caches_table_columns',
			fn( $columns ) => array_merge( $columns, [ 'tenant' => 'Tenant' ] )
		);

		$columns = $this->table->get_columns();

		$this->assertArrayHasKey( 'tenant', $columns );
		$this->assertSame( 'Tenant', $columns['tenant'] );
	}

	public function test_get_sortable_columns_marks_each_sortable_column() {
		$sortable = $this->table->get_sortable_columns();

		foreach ( [ 'cache_key', 'request_uri', 'request_method', 'object_type', 'expiration', 'cache_hits' ] as $col ) {
			$this->assertArrayHasKey( $col, $sortable );
		}
		// `request_headers` and the checkbox column aren't sortable.
		$this->assertArrayNotHasKey( 'request_headers', $sortable );
		$this->assertArrayNotHasKey( 'cb', $sortable );
	}

	public function test_get_bulk_actions_offers_flush_and_delete() {
		$actions = $this->table->get_bulk_actions();

		$this->assertArrayHasKey( 'bulk-flush', $actions );
		$this->assertArrayHasKey( 'bulk-delete', $actions );
	}

	// ---------- Action dispatch ----------

	public function test_process_action_with_no_request_action_is_a_noop() {
		// $_REQUEST['action'] absent → current_action() returns false → switch falls through.
		// Just verify no error / no redirect was attempted.
		$redirect_called = false;
		add_filter(
			'wp_redirect',
			function () use ( &$redirect_called ) {
				$redirect_called = true;
				return false;
			}
		);

		$this->table->process_action();

		$this->assertFalse( $redirect_called );
	}

	public function test_process_action_with_unknown_action_is_a_noop() {
		$_REQUEST['action'] = 'not-a-handled-action';

		$redirect_called = false;
		add_filter(
			'wp_redirect',
			function () use ( &$redirect_called ) {
				$redirect_called = true;
				return false;
			}
		);

		$this->table->process_action();

		$this->assertFalse( $redirect_called );
	}

	public function test_process_action_with_flush_action_but_no_nonce_does_not_redirect() {
		// Single-action branch returns early on missing nonce — verify the redirect doesn't
		// fire (i.e. we never reached the clear_cache + wp_safe_redirect tail).
		$_REQUEST['action'] = 'flush';
		unset( $_GET['wp_rest_cache_nonce'] );

		$redirect_called = false;
		add_filter(
			'wp_redirect',
			function () use ( &$redirect_called ) {
				$redirect_called = true;
				return false;
			}
		);

		$this->table->process_action();

		$this->assertFalse( $redirect_called );
	}

	public function test_process_action_with_flush_action_and_valid_nonce_redirects_to_endpoint_view() {
		$_REQUEST['action']            = 'flush';
		$_GET['wp_rest_cache_nonce']   = wp_create_nonce( 'wp_rest_cache_flush_cache' );

		$redirect_target = null;
		add_filter(
			'wp_redirect',
			function ( $location ) use ( &$redirect_target ) {
				$redirect_target = $location;
				return false; // suppress actual header() call
			}
		);

		$this->table->process_action();

		$this->assertNotNull( $redirect_target );
		$this->assertStringContainsString( 'page=wp-rest-cache', $redirect_target );
		$this->assertStringContainsString( 'sub=endpoint-api', $redirect_target );
	}

	public function test_process_action_with_bulk_flush_action_but_no_nonce_does_not_redirect() {
		$_REQUEST['action'] = 'bulk-flush';
		unset( $_GET['_wpnonce'] );

		$redirect_called = false;
		add_filter(
			'wp_redirect',
			function () use ( &$redirect_called ) {
				$redirect_called = true;
				return false;
			}
		);

		$this->table->process_action();

		$this->assertFalse( $redirect_called );
	}

	public function test_process_action_with_bulk_flush_and_valid_nonce_clears_each_selected_cache_and_redirects() {
		$cache_id_a = $this->insert_cache( [ 'cache_type' => 'endpoint', 'cache_key' => 'bulk-a' ] );
		$cache_id_b = $this->insert_cache( [ 'cache_type' => 'endpoint', 'cache_key' => 'bulk-b' ] );

		$_REQUEST['action']  = 'bulk-flush';
		$_GET['_wpnonce']    = wp_create_nonce( 'bulk-' . sanitize_key( __( 'Endpoint API Caches', 'wp-rest-cache' ) ) );
		$_GET['bulk-flush']  = [ 'bulk-a', 'bulk-b' ];

		$redirect_target = null;
		add_filter(
			'wp_redirect',
			function ( $location ) use ( &$redirect_target ) {
				$redirect_target = $location;
				return false;
			}
		);

		$this->table->process_action();

		$this->assertNotNull( $redirect_target );
		$this->assertStringContainsString( 'sub=endpoint-api', $redirect_target );

		// 'bulk-flush' is the non-delete variant — caches get cleared (expiration=1) but
		// not deleted. Both selected rows should now carry the sentinel expiration.
		$this->assertSame( '1970-01-01 00:00:01', $this->column_value( $cache_id_a, 'expiration' ) );
		$this->assertSame( '1970-01-01 00:00:01', $this->column_value( $cache_id_b, 'expiration' ) );
	}

	public function test_process_action_with_bulk_action_and_valid_nonce_but_no_selected_caches_is_a_noop_redirect() {
		// Covers the `: []` fallback when $_GET['bulk-flush'] is missing — the loop body is
		// skipped entirely and we just redirect.
		$_REQUEST['action'] = 'bulk-flush';
		$_GET['_wpnonce']   = wp_create_nonce( 'bulk-' . sanitize_key( __( 'Endpoint API Caches', 'wp-rest-cache' ) ) );
		unset( $_GET['bulk-flush'] );

		$redirect_target = null;
		add_filter(
			'wp_redirect',
			function ( $location ) use ( &$redirect_target ) {
				$redirect_target = $location;
				return false;
			}
		);

		$this->table->process_action();

		$this->assertNotNull( $redirect_target );
		$this->assertStringContainsString( 'sub=endpoint-api', $redirect_target );
	}

	public function test_process_action_with_bulk_delete_and_valid_nonce_hard_deletes_each_selected_row() {
		// bulk-delete forwards to clear_cache($key, true) → delete_cache($key, true), which
		// hard-deletes the row from wrc_caches (it's the destructive "remove statistics
		// too" path, not the soft `deleted=1` flag).
		$cache_id = $this->insert_cache( [ 'cache_type' => 'endpoint', 'cache_key' => 'bulk-del' ] );

		$_REQUEST['action']  = 'bulk-delete';
		$_GET['_wpnonce']    = wp_create_nonce( 'bulk-' . sanitize_key( __( 'Endpoint API Caches', 'wp-rest-cache' ) ) );
		$_GET['bulk-flush']  = [ 'bulk-del' ];

		add_filter( 'wp_redirect', '__return_false' );

		$this->table->process_action();

		$this->assertNull( $this->column_value( $cache_id, 'cache_key' ), 'row should be hard-deleted' );
	}

	// ----- helpers -----

	private function fake_item( array $overrides = [] ) {
		return array_merge(
			[
				'cache_id'        => 1,
				'cache_key'       => 'fakekey',
				'cache_type'      => 'endpoint',
				'request_uri'     => '/wp-json/x',
				'request_headers' => '',
				'request_method'  => 'GET',
				'object_type'     => 'post',
				'cache_hits'      => '0',
				'is_single'       => '1',
				'expiration'      => '2099-01-01 00:00:00',
				'is_active'       => true,
				'deleted'         => '0',
				'cleaned'         => '0',
			],
			$overrides
		);
	}
}
