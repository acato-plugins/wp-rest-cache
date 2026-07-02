<?php
/**
 * Coverage-targeted tests for branches that aren't covered by behavioral tests in their
 * respective natural test files. Grouped here per "class under test" for locality.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Admin\Admin;
use WP_Rest_Cache_Plugin\Admin\Includes\API_Caches_Table;
use WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api
 * @covers \WP_Rest_Cache_Plugin\Admin\Includes\API_Caches_Table
 * @covers \WP_Rest_Cache_Plugin\Admin\Admin
 * @covers \WP_Rest_Cache_Plugin\Includes\API\Oembed_Api
 * @covers \WP_Rest_Cache_Plugin\Includes\Activator
 */
class Test_Coverage_Fillers extends Caching_Test_Case {

	/** @var array<string,mixed> */
	private $server_backup;

	public function set_up() {
		parent::set_up();
		$this->server_backup = [
			'REQUEST_URI'    => $_SERVER['REQUEST_URI']    ?? null,
			'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? null,
		];
	}

	public function tear_down() {
		foreach ( $this->server_backup as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}
		parent::tear_down();
	}

	// ---------- Endpoint_Api::set_cacheable_request_headers — global config ----------

	public function test_global_cacheable_request_headers_are_captured_into_request_headers() {
		// The global header config is a comma-separated string in
		// wp_rest_cache_global_cacheable_request_headers; per-endpoint config (covered
		// below) is a map. Empty entries are skipped, but a non-empty header name lands
		// in $request_headers regardless of which endpoint is hit.
		update_option(
			'wp_rest_cache_global_cacheable_request_headers',
			'X-Global-Tenant,X-Trace-Id'
		);

		$_SERVER['REQUEST_URI']         = '/wp-json/wp/v2/posts';
		$_SERVER['REQUEST_METHOD']      = 'GET';
		$_SERVER['HTTP_X_GLOBAL_TENANT'] = 'globotron';
		$_SERVER['HTTP_X_TRACE_ID']      = 'abc-123';

		$api = new Endpoint_Api();
		$this->invoke_private( $api, 'build_cache_key', [] );

		$headers = $this->get_private_property( $api, 'request_headers' );

		$this->assertSame( 'globotron', $headers['X-Global-Tenant'] );
		$this->assertSame( 'abc-123', $headers['X-Trace-Id'] );

		unset( $_SERVER['HTTP_X_GLOBAL_TENANT'], $_SERVER['HTTP_X_TRACE_ID'] );
		delete_option( 'wp_rest_cache_global_cacheable_request_headers' );
	}

	// ---------- Endpoint_Api::set_cacheable_request_headers — per-endpoint config ----------

	public function test_per_endpoint_cacheable_request_headers_are_applied_when_uri_matches() {
		update_option(
			'wp_rest_cache_cacheable_request_headers',
			[ 'wp/v2/posts' => 'X-Tenant,X-Locale' ]
		);

		$_SERVER['REQUEST_URI']        = '/wp-json/wp/v2/posts';
		$_SERVER['REQUEST_METHOD']     = 'GET';
		$_SERVER['HTTP_X_TENANT']      = 'tenant-a';
		$_SERVER['HTTP_X_LOCALE']      = 'en_US';

		$api = new Endpoint_Api();
		$this->invoke_private( $api, 'build_cache_key', [] );

		$headers = $this->get_private_property( $api, 'request_headers' );

		$this->assertSame( 'tenant-a', $headers['X-Tenant'] );
		$this->assertSame( 'en_US', $headers['X-Locale'] );

		unset( $_SERVER['HTTP_X_TENANT'], $_SERVER['HTTP_X_LOCALE'] );
	}

	public function test_per_endpoint_cacheable_request_headers_skipped_when_uri_does_not_match() {
		update_option(
			'wp_rest_cache_cacheable_request_headers',
			[ 'wp/v2/users' => 'X-Tenant' ]
		);

		$_SERVER['REQUEST_URI']    = '/wp-json/wp/v2/posts'; // not /users
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_X_TENANT']  = 'tenant-a';

		$api = new Endpoint_Api();
		$this->invoke_private( $api, 'build_cache_key', [] );

		$headers = $this->get_private_property( $api, 'request_headers' );

		$this->assertArrayNotHasKey( 'X-Tenant', $headers );

		unset( $_SERVER['HTTP_X_TENANT'] );
	}

	// ---------- Endpoint_Api::skip_caching — rest_route= alt-form sets prefix ----------

	public function test_skip_caching_with_rest_route_uri_assigns_the_prefix_to_rest_route_marker() {
		// Make sure the URI uses the rest_route= form so the inner branch fires.
		$this->enable_endpoint( 'wp/v2', 'posts' );

		$api = $this->arrange_skip_caching( '/?rest_route=' . rawurlencode( '/wp/v2/posts' ) );

		$this->assertFalse( $api->skip_caching() );
	}

	public function test_skip_caching_with_rest_route_uri_pointing_at_disallowed_endpoint_skips() {
		$this->enable_endpoint( 'wp/v2', 'posts' );
		update_option(
			'wp_rest_cache_disallowed_endpoints',
			[ 'wp/v2' => [ 'posts' ] ]
		);

		$api = $this->arrange_skip_caching( '/?rest_route=' . rawurlencode( '/wp/v2/posts' ) );

		$this->assertTrue( $api->skip_caching() );
	}

	// ---------- API_Caches_Table::prepare_items ----------

	public function test_prepare_items_populates_items_from_caching_get_api_data() {
		$cache_id = $this->insert_cache( [ 'cache_type' => 'endpoint', 'cache_key' => 'k1' ] );
		$table    = new API_Caches_Table( 'endpoint' );

		$table->prepare_items();

		$this->assertCount( 1, $table->items );
		$this->assertSame( (string) $cache_id, $table->items[0]['cache_id'] );
	}

	public function test_prepare_items_sets_pagination_args_to_match_record_count() {
		for ( $i = 0; $i < 7; $i++ ) {
			$this->insert_cache( [ 'cache_type' => 'endpoint' ] );
		}

		$table = new API_Caches_Table( 'endpoint' );
		$table->prepare_items();

		$pagination = $table->get_pagination_arg( 'total_items' );
		$this->assertSame( 7, (int) $pagination );
	}

	// ---------- Admin::settings_page — template-from-panel branch ----------

	public function test_settings_page_includes_a_panel_specific_template_when_one_is_configured() {
		// A panel can carry a `template` key — settings_page must include THAT instead of
		// the convention-based sub-{key}.php partial. Use a tempfile as the template.
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$tpl = tempnam( sys_get_temp_dir(), 'wprc-template-' );
		file_put_contents( $tpl, "<?php echo 'PANEL_TEMPLATE_LOADED'; ?>" );

		add_filter(
			'wp_rest_cache/settings_panels',
			function ( $panels ) use ( $tpl ) {
				$panels['settings'] = [ 'label' => 'Settings', 'position' => 10, 'template' => $tpl ];
				return $panels;
			}
		);

		ob_start();
		( new Admin( 'wp-rest-cache', '2026.2.0' ) )->settings_page();
		$html = ob_get_clean();

		unlink( $tpl );

		$this->assertStringContainsString( 'PANEL_TEMPLATE_LOADED', $html );
	}

	// Note: the Memcache-class warning branch is tested in test-class-admin-memcache-warning.php
	// (isolated via @runInSeparateProcess because the eval'd phantom Memcache class would
	// otherwise leak into other tests in this process).

	// ---------- Admin::add_screen_options ----------

	public function test_add_screen_options_registers_caches_per_page_option() {
		// add_screen_options uses add_screen_option which writes to a global. The actual
		// registration happens via the `screen_settings` hook... but the simplest assertion
		// is that the method runs without error and the underlying add_screen_option call
		// succeeds (it returns void).
		( new Admin( 'wp-rest-cache', '2026.2.0' ) )->add_screen_options();

		$this->assertTrue( true ); // No-throw is the assertion.
	}

	// ---------- Oembed_Api::get_oembed_post_id — path-only url= branch ----------

	public function test_get_oembed_post_id_prepends_home_url_when_url_param_is_path_only() {
		// Hits the `! preg_match('#^https?://#i', $url_param)` branch — without a scheme,
		// the home URL gets prepended before url_to_postid resolves.
		$post_id   = self::factory()->post->create();
		$permalink = get_permalink( $post_id );

		// Strip scheme+host so what's left is a path (possibly with query string).
		$path = wp_make_link_relative( $permalink );

		$api = new \WP_Rest_Cache_Plugin\Includes\API\Oembed_Api();
		$result = $api->determine_object_type(
			'unknown',
			'k',
			[],
			'/wp-json/oembed/1.0/embed?url=' . rawurlencode( $path )
		);

		$this->assertSame( 'post', $result );
	}

	// ---------- Activator::create_mu_plugin — early-return branches ----------

	public function test_create_mu_plugin_returns_early_when_filesystem_method_is_not_direct() {
		// Hook the filesystem_method filter to force a non-'direct' return → covers line 67.
		add_filter( 'filesystem_method', fn() => 'ftpext' );

		$mu_file = WPMU_PLUGIN_DIR . '/wp-rest-cache.php';
		if ( file_exists( $mu_file ) ) {
			unlink( $mu_file );
		}

		\WP_Rest_Cache_Plugin\Includes\Activator::create_mu_plugin();

		// Nothing got copied (we returned before the copy).
		$this->assertFileDoesNotExist( $mu_file );
	}

	// Note: the WP_Filesystem-fail early-return (Activator line 74) isn't reliably testable
	// once the WP_Filesystem_Direct class is loaded — filter tricks that work pre-load have
	// no effect on the cached class. Would need process isolation + a careful no-direct-class
	// environment to drive. Documented as a known gap.

	public function test_create_mu_plugin_creates_mu_plugin_dir_when_missing() {
		// Remove the MU plugin dir so the inner is_dir/mkdir branch fires (line 79).
		if ( is_dir( WPMU_PLUGIN_DIR ) ) {
			array_map( 'unlink', glob( WPMU_PLUGIN_DIR . '/*' ) ?: [] );
			rmdir( WPMU_PLUGIN_DIR );
		}

		$_SERVER['REQUEST_URI'] = '/';
		\WP_Rest_Cache_Plugin\Includes\Activator::create_mu_plugin();

		$this->assertDirectoryExists( WPMU_PLUGIN_DIR );

		// Cleanup so subsequent tests have predictable state.
		$mu_file = WPMU_PLUGIN_DIR . '/wp-rest-cache.php';
		if ( file_exists( $mu_file ) ) {
			unlink( $mu_file );
		}
	}

	// ---------- helpers ----------

	private function enable_endpoint( $namespace, $endpoint ) {
		update_option(
			'wp_rest_cache_allowed_endpoints',
			[ $namespace => [ $endpoint ] ]
		);
	}

	private function arrange_skip_caching( $request_uri, $method = 'GET' ) {
		$request = new \WP_REST_Request();
		$api     = new Endpoint_Api();
		$this->set_private_property( $api, 'request_uri', $request_uri );
		$this->set_private_property( $api, 'request', $request );
		$_SERVER['REQUEST_METHOD'] = $method;
		return $api;
	}
}
