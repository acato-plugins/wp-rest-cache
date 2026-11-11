<?php
/**
 * Tests for counting REST requests that weren't cached because they carried credentials, and the admin notice that
 * is shown when there are many of them.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Admin\Admin;
use WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api;
use WP_Rest_Cache_Plugin\Includes\Caching\Credential_Skips;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Credential_Skips
 * @covers \WP_Rest_Cache_Plugin\Admin\Admin::check_credential_skips
 */
class Test_Credential_Skips extends Caching_Test_Case {

	/**
	 * Start every test without counts or notices, and count every skipped request (no sampling).
	 */
	public function set_up() {
		parent::set_up();
		Credential_Skips::get_instance()->reset();
		delete_option( 'wp_rest_cache_admin_notices' );
		add_filter( 'wp_rest_cache/credential_skips_sample_rate', fn() => 1 );
	}

	/**
	 * Log out, in case a test logged a user in.
	 */
	public function tear_down() {
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Every recorded skip is counted per reason, and the start of the period is stored.
	 */
	public function test_record_counts_per_reason() {
		$skips = Credential_Skips::get_instance();

		$skips->record( Credential_Skips::REASON_AUTHORIZATION );
		$skips->record( Credential_Skips::REASON_AUTHORIZATION );
		$skips->record( Credential_Skips::REASON_AUTHENTICATED_USER );

		$this->assertSame( 2, $skips->get_count( Credential_Skips::REASON_AUTHORIZATION ) );
		$this->assertSame( 1, $skips->get_count( Credential_Skips::REASON_AUTHENTICATED_USER ) );
		$this->assertArrayHasKey( 'since', $skips->get_counts() );
	}

	/**
	 * A sampled skip counts for the whole sample, so the total stays an estimate of all skipped requests.
	 */
	public function test_a_sampled_skip_counts_for_the_sample_rate() {
		add_filter( 'wp_rest_cache/credential_skips_sample_rate', fn() => 10, 20 );
		$skips = Credential_Skips::get_instance();

		for ( $i = 0; $i < 200; $i++ ) {
			$skips->record( Credential_Skips::REASON_AUTHORIZATION );
		}

		$count = $skips->get_count( Credential_Skips::REASON_AUTHORIZATION );
		$this->assertSame( 0, $count % 10, 'Each sampled skip should count for 10 requests' );
		$this->assertLessThan( 2000, $count, 'Not every skip should be written to the database' );
	}

	/**
	 * A sample rate of 0 disables counting.
	 */
	public function test_a_sample_rate_of_zero_disables_counting() {
		add_filter( 'wp_rest_cache/credential_skips_sample_rate', fn() => 0, 20 );

		Credential_Skips::get_instance()->record( Credential_Skips::REASON_AUTHORIZATION );

		$this->assertFalse( get_option( Credential_Skips::OPTION ) );
	}

	/**
	 * Counts from a previous period are ignored, and counting starts over.
	 */
	public function test_counts_from_a_previous_period_are_ignored() {
		update_option(
			Credential_Skips::OPTION,
			[
				'since'                                => time() - Credential_Skips::PERIOD - 1,
				Credential_Skips::REASON_AUTHORIZATION => 5000,
			],
			false
		);
		$skips = Credential_Skips::get_instance();

		$this->assertSame( 0, $skips->get_count( Credential_Skips::REASON_AUTHORIZATION ) );

		$skips->record( Credential_Skips::REASON_AUTHORIZATION );

		$this->assertSame( 1, $skips->get_count( Credential_Skips::REASON_AUTHORIZATION ) );
	}

	/**
	 * A cacheable request with an Authorization header is counted when it is skipped.
	 */
	public function test_skip_caching_counts_a_cacheable_request_with_an_authorization_header() {
		$api = $this->arrange( '/wp-json/wp/v2/posts' );
		$this->get_private_property( $api, 'request' )->set_header( 'authorization', 'Basic YWRtaW46c2VjcmV0' );

		$this->assertTrue( $api->skip_caching() );
		$this->assertSame( 1, Credential_Skips::get_instance()->get_count( Credential_Skips::REASON_AUTHORIZATION ) );
	}

	/**
	 * A request with an Authorization header to an endpoint that is never cached is not counted.
	 */
	public function test_skip_caching_does_not_count_a_request_that_would_not_be_cached_anyway() {
		$api = $this->arrange( '/wp-json/wp/v2/pages' );
		$this->get_private_property( $api, 'request' )->set_header( 'authorization', 'Basic YWRtaW46c2VjcmV0' );

		$this->assertTrue( $api->skip_caching() );
		$this->assertSame( 0, Credential_Skips::get_instance()->get_count( Credential_Skips::REASON_AUTHORIZATION ) );
	}

	/**
	 * A request without credentials is not counted.
	 */
	public function test_skip_caching_does_not_count_a_request_without_credentials() {
		$api = $this->arrange( '/wp-json/wp/v2/posts' );

		$this->assertFalse( $api->skip_caching() );
		$this->assertSame( [], Credential_Skips::get_instance()->get_counts() );
	}

	/**
	 * A response that isn't cached because it was generated for a logged-in user is counted.
	 */
	public function test_save_cache_counts_a_response_for_an_authenticated_user() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$api = $this->arrange( '/wp-json/wp/v2/posts' );

		$api->save_cache( [ [ 'id' => 1 ] ] );

		$this->assertSame( 1, Credential_Skips::get_instance()->get_count( Credential_Skips::REASON_AUTHENTICATED_USER ) );
	}

	/**
	 * Above the threshold, a permanently dismissible warning with a link to the settings is added.
	 */
	public function test_notice_is_added_above_the_threshold() {
		$this->log_in_administrator();
		$this->set_count( Credential_Skips::REASON_AUTHORIZATION, 1000 );

		$this->admin()->check_credential_skips();

		$notices = get_option( 'wp_rest_cache_admin_notices', [] );
		$this->assertCount( 1, $notices['warning'] ?? [] );
		$this->assertStringContainsString( 'Authorization', $notices['warning'][0]['message'] );
		$this->assertSame( 'permanent', $notices['warning'][0]['dismissible'] );
		$this->assertStringContainsString( 'sub=settings', $notices['warning'][0]['button']['url'] );
	}

	/**
	 * The authenticated user notice mentions the filter to cache these responses.
	 */
	public function test_notice_for_authenticated_users_mentions_the_filter() {
		$this->log_in_administrator();
		$this->set_count( Credential_Skips::REASON_AUTHENTICATED_USER, 1000 );

		$this->admin()->check_credential_skips();

		$notices = get_option( 'wp_rest_cache_admin_notices', [] );
		$this->assertCount( 1, $notices['warning'] ?? [] );
		$this->assertStringContainsString( 'wp_rest_cache/cache_authenticated_response', $notices['warning'][0]['message'] );
	}

	/**
	 * Below the threshold no notice is added.
	 */
	public function test_no_notice_below_the_threshold() {
		$this->log_in_administrator();
		$this->set_count( Credential_Skips::REASON_AUTHORIZATION, 999 );

		$this->admin()->check_credential_skips();

		$this->assertSame( [], get_option( 'wp_rest_cache_admin_notices', [] ) );
	}

	/**
	 * The threshold can be changed with a filter.
	 */
	public function test_threshold_can_be_filtered() {
		$this->log_in_administrator();
		$this->set_count( Credential_Skips::REASON_AUTHORIZATION, 50 );
		add_filter( 'wp_rest_cache/credential_skips_notice_threshold', fn() => 50 );

		$this->admin()->check_credential_skips();

		$this->assertCount( 1, get_option( 'wp_rest_cache_admin_notices', [] )['warning'] ?? [] );
	}

	/**
	 * Users who may not change the plugin settings don't get the notice.
	 */
	public function test_no_notice_for_users_who_may_not_change_the_settings() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'editor' ] ) );
		$this->set_count( Credential_Skips::REASON_AUTHORIZATION, 5000 );

		$this->admin()->check_credential_skips();

		$this->assertSame( [], get_option( 'wp_rest_cache_admin_notices', [] ) );
	}

	/**
	 * Deactivating the plugin removes the counts.
	 */
	public function test_deactivation_removes_the_counts() {
		$this->set_count( Credential_Skips::REASON_AUTHORIZATION, 5000 );

		\WP_Rest_Cache_Plugin\Includes\Deactivator::deactivate();

		$this->assertFalse( get_option( Credential_Skips::OPTION ) );
	}

	/**
	 * Build an Endpoint_Api for a GET request to the given URI, with only `wp/v2/posts` as a cacheable endpoint.
	 *
	 * @param string $request_uri The requested URI.
	 *
	 * @return Endpoint_Api
	 */
	private function arrange( $request_uri ) {
		update_option( 'wp_rest_cache_allowed_endpoints', [ 'wp/v2' => [ 'posts' ] ] );

		$api = new Endpoint_Api();
		$this->set_private_property( $api, 'request_uri', $request_uri );
		$this->set_private_property( $api, 'request', new \WP_REST_Request() );

		$_SERVER['REQUEST_METHOD'] = 'GET';

		return $api;
	}

	/**
	 * Store a count for one reason in the current period.
	 *
	 * @param string $reason The skip reason.
	 * @param int    $count  The count.
	 *
	 * @return void
	 */
	private function set_count( $reason, $count ) {
		update_option(
			Credential_Skips::OPTION,
			[
				'since'  => time(),
				$reason  => $count,
			],
			false
		);
	}

	/**
	 * Log in as an administrator.
	 *
	 * @return void
	 */
	private function log_in_administrator() {
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/**
	 * Get a new Admin instance.
	 *
	 * @return Admin
	 */
	private function admin() {
		return new Admin( 'wp-rest-cache', '2026.4.3' );
	}
}
