<?php
/**
 * Tests for the Deactivator — clears every cache (hard delete) and removes the MU plugin
 * file the Activator copied into wp-content/mu-plugins.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Deactivator;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Deactivator
 */
class Test_Deactivator extends Caching_Test_Case {

	private $mu_plugin_file;

	public function set_up() {
		parent::set_up();
		$this->mu_plugin_file = WPMU_PLUGIN_DIR . '/wp-rest-cache.php';
	}

	public function tear_down() {
		// Clean up whatever the test wrote to the mu-plugins dir (real filesystem side effect
		// — WP_UnitTestCase's transactional rollback only covers the DB).
		if ( file_exists( $this->mu_plugin_file ) ) {
			unlink( $this->mu_plugin_file );
		}
		parent::tear_down();
	}

	// ---------- Cache clearing ----------

	public function test_deactivate_hard_deletes_every_cache_row() {
		$this->insert_cache();
		$this->insert_cache();
		$this->insert_cache();

		Deactivator::deactivate();

		$this->assertSame( 0, $this->count_cache_rows(), 'all cache rows must be gone after deactivate' );
	}

	public function test_deactivate_passes_force_true_to_clear_caches_so_rows_are_removed_not_just_flushed() {
		// Distinguish hard-delete (rows gone) from soft-delete (rows expired but still present).
		$cache_id = $this->insert_cache();

		Deactivator::deactivate();

		global $wpdb;
		$still_there = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}wrc_caches` WHERE cache_id = %d",
				$cache_id
			)
		);
		$this->assertSame( 0, $still_there );
	}

	public function test_deactivate_clears_caches_even_when_mu_plugin_is_absent() {
		// The two side-effects are independent: missing MU file shouldn't prevent the
		// clear_caches sweep from running.
		$this->insert_cache();
		if ( file_exists( $this->mu_plugin_file ) ) {
			unlink( $this->mu_plugin_file );
		}

		Deactivator::deactivate();

		$this->assertSame( 0, $this->count_cache_rows() );
	}

	// ---------- MU plugin removal ----------

	public function test_deactivate_removes_the_mu_plugin_file_when_present() {
		$this->seed_mu_plugin_file();
		$this->assertFileExists( $this->mu_plugin_file, 'precondition: MU plugin file was seeded' );

		Deactivator::deactivate();

		$this->assertFileDoesNotExist( $this->mu_plugin_file );
	}

	public function test_deactivate_is_a_noop_for_the_mu_plugin_file_when_already_absent() {
		if ( file_exists( $this->mu_plugin_file ) ) {
			unlink( $this->mu_plugin_file );
		}

		// Should not error / warn even though there's nothing to delete.
		Deactivator::deactivate();

		$this->assertFileDoesNotExist( $this->mu_plugin_file );
	}

	// ----- helpers -----

	private function seed_mu_plugin_file() {
		if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {
			mkdir( WPMU_PLUGIN_DIR, 0777, true );
		}
		file_put_contents( $this->mu_plugin_file, "<?php // seeded by Test_Deactivator\n" );
	}

	private function count_cache_rows() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}wrc_caches`" );
	}
}
