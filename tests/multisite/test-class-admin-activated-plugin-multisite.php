<?php
/**
 * Multisite-only tests for Admin::activated_plugin — covers the network_wide branch that
 * special-cases Wordfence activation across every site in the network.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Admin\Admin;

/**
 * @covers \WP_Rest_Cache_Plugin\Admin\Admin::activated_plugin
 * @group multisite
 */
class Test_Admin_Activated_Plugin_Multisite extends Caching_Test_Case {

	/** @var Admin */
	private $admin;

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test — run via `composer test-multisite`.' );
		}
		parent::set_up();
		$this->admin = new Admin( 'wp-rest-cache', '2026.2.0' );
	}

	public function test_network_wide_wordfence_activation_visits_every_site_in_the_network() {
		// Three extra sites + the main one = four sites total. Each should be visited so the
		// per-site users-endpoint flush runs.
		self::factory()->blog->create();
		self::factory()->blog->create();
		self::factory()->blog->create();

		$expected_site_ids = array_map( 'intval', get_sites( [ 'fields' => 'ids' ] ) );

		$visited = [];
		add_action(
			'switch_blog',
			function ( $new_blog_id, $prev_blog_id ) use ( &$visited ) {
				$visited[] = (int) $new_blog_id;
			},
			10,
			2
		);

		$this->admin->activated_plugin( 'wordfence/wordfence.php', /* network_wide */ true );

		// The forward "switch_to_blog" direction should have visited every site.
		foreach ( $expected_site_ids as $site_id ) {
			$this->assertContains(
				$site_id,
				$visited,
				"Expected switch_to_blog({$site_id}) during network-wide wordfence activation"
			);
		}
	}

	public function test_non_network_wide_wordfence_activation_does_not_switch_blogs() {
		self::factory()->blog->create();
		self::factory()->blog->create();

		$current = get_current_blog_id();
		$switched_to_other = false;
		add_action(
			'switch_blog',
			function ( $new_blog_id ) use ( &$switched_to_other, $current ) {
				if ( (int) $new_blog_id !== (int) $current ) {
					$switched_to_other = true;
				}
			}
		);

		$this->admin->activated_plugin( 'wordfence/wordfence.php', /* network_wide */ false );

		$this->assertFalse(
			$switched_to_other,
			'Single-site activation must not iterate other blogs in the network'
		);
	}

	public function test_network_wide_activation_for_non_wordfence_plugin_does_not_iterate_blogs() {
		// The per-site loop is gated on the plugin slug being wordfence specifically. Other
		// plugins go down the "show a notice" branch regardless of network_wide.
		self::factory()->blog->create();

		$switched_to_other = false;
		add_action(
			'switch_blog',
			function ( $new_blog_id ) use ( &$switched_to_other ) {
				if ( (int) $new_blog_id !== (int) get_current_blog_id() ) {
					$switched_to_other = true;
				}
			}
		);

		$this->admin->activated_plugin( 'some-other/plugin.php', /* network_wide */ true );

		$this->assertFalse( $switched_to_other );

		// And the user-facing notice was added instead (the non-wordfence else branch).
		$notices = get_option( 'wp_rest_cache_admin_notices', [] );
		$this->assertNotEmpty( $notices['warning'] ?? [] );
	}
}
