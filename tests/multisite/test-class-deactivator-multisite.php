<?php
/**
 * Multisite-only tests for the Deactivator — covers the branch that walks every other site
 * in the network to decide whether the MU plugin file can be removed.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Deactivator;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Deactivator
 * @group multisite
 */
class Test_Deactivator_Multisite extends Caching_Test_Case {

	private $mu_plugin_file;

	public function set_up() {
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite-only test — run via `composer test-multisite`.' );
		}
		parent::set_up();
		$this->mu_plugin_file = WPMU_PLUGIN_DIR . '/wp-rest-cache.php';
	}

	public function tear_down() {
		if ( file_exists( $this->mu_plugin_file ) ) {
			unlink( $this->mu_plugin_file );
		}
		parent::tear_down();
	}

	public function test_deactivate_removes_mu_plugin_when_plugin_inactive_on_every_other_site() {
		// Create a second site with NO plugins active. Default `active_plugins` is empty.
		self::factory()->blog->create();
		$this->seed_mu_plugin_file();

		Deactivator::deactivate();

		$this->assertFileDoesNotExist( $this->mu_plugin_file );
	}

	public function test_deactivate_keeps_mu_plugin_when_plugin_is_active_on_another_site() {
		// Create a second site, then activate the plugin in that site's active_plugins option.
		$other_site = self::factory()->blog->create();

		switch_to_blog( $other_site );
		update_option( 'active_plugins', [ 'wp-rest-cache/wp-rest-cache.php' ] );
		restore_current_blog();

		$this->seed_mu_plugin_file();

		Deactivator::deactivate();

		$this->assertFileExists(
			$this->mu_plugin_file,
			'MU plugin must survive deactivation when another site still has the plugin active'
		);
	}

	public function test_deactivate_skips_the_current_blog_when_iterating_other_sites() {
		// The current blog is excluded from the loop — this is what allows "the plugin is
		// being deactivated *here*" to actually take effect.
		self::factory()->blog->create();

		// Activate the plugin only on the CURRENT blog. The loop should never see it.
		update_option( 'active_plugins', [ 'wp-rest-cache/wp-rest-cache.php' ] );

		$this->seed_mu_plugin_file();

		Deactivator::deactivate();

		$this->assertFileDoesNotExist( $this->mu_plugin_file );
	}

	public function test_deactivate_walks_every_other_site_in_the_network() {
		self::factory()->blog->create();
		self::factory()->blog->create();
		self::factory()->blog->create();

		// switch_blog fires AFTER the global blog_id has been updated, so inside the closure
		// `get_current_blog_id()` already reflects $new_blog_id. Capture the starting blog
		// out here and compare against that instead.
		$starting_blog = (int) get_current_blog_id();

		$visited = [];
		add_action(
			'switch_blog',
			function ( $new_blog_id ) use ( &$visited, $starting_blog ) {
				if ( (int) $new_blog_id !== $starting_blog ) {
					$visited[] = (int) $new_blog_id;
				}
			}
		);

		$this->seed_mu_plugin_file();

		Deactivator::deactivate();

		$this->assertGreaterThanOrEqual(
			3,
			count( array_unique( $visited ) ),
			'Expected the loop to visit every other site at least once (current blog excluded)'
		);
		$this->assertNotContains(
			$starting_blog,
			$visited,
			'The current blog must be skipped — its is_plugin_active state is irrelevant'
		);
	}

	private function seed_mu_plugin_file() {
		if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {
			mkdir( WPMU_PLUGIN_DIR, 0777, true );
		}
		file_put_contents( $this->mu_plugin_file, "<?php // seeded by Test_Deactivator_Multisite\n" );
	}
}
