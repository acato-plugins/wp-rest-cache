<?php
/**
 * Tests for the lock that makes sure only one request at a time runs the database upgrade.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching::update_database_structure
 */
class Test_Caching_Upgrade_Lock extends Caching_Test_Case {

	/**
	 * Remove the lock and restore the current database version, so other tests start from a clean state.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->delete( $wpdb->options, [ 'option_name' => Caching::UPGRADE_LOCK_OPTION ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		update_option( 'wp_rest_cache_database_version', Caching::DB_VERSION, false );
		parent::tear_down();
	}

	/**
	 * An up-to-date database neither takes the lock nor runs an upgrade.
	 */
	public function test_does_nothing_when_the_database_is_up_to_date() {
		update_option( 'wp_rest_cache_database_version', Caching::DB_VERSION, false );
		$cache_id = $this->insert_cache();

		Caching::get_instance()->update_database_structure();

		$this->assertNull( $this->lock_value() );
		$this->assertNotExpired( $cache_id );
	}

	/**
	 * While another request holds a fresh lock, this request skips the upgrade and leaves the lock alone.
	 */
	public function test_skips_the_upgrade_while_another_request_holds_the_lock() {
		update_option( 'wp_rest_cache_database_version', '2026.1.0', false );
		$this->insert_lock( time() );
		$cache_id = $this->insert_cache();

		Caching::get_instance()->update_database_structure();

		$this->assertSame( '2026.1.0', $this->database_version() );
		$this->assertNotExpired( $cache_id, 'The 2026.4.0 upgrade should not have run' );
		$this->assertNotNull( $this->lock_value(), 'The lock of the other request should be left alone' );
	}

	/**
	 * A lock older than the timeout is taken over and the upgrade runs.
	 */
	public function test_takes_over_a_stale_lock() {
		update_option( 'wp_rest_cache_database_version', '2026.1.0', false );
		$this->insert_lock( time() - Caching::UPGRADE_LOCK_TIMEOUT - 1 );

		Caching::get_instance()->update_database_structure();

		$this->assertSame( Caching::DB_VERSION, $this->database_version() );
		$this->assertNull( $this->lock_value() );
	}

	/**
	 * The lock is released once the upgrade has finished.
	 */
	public function test_releases_the_lock_after_the_upgrade() {
		update_option( 'wp_rest_cache_database_version', '2026.1.0', false );

		Caching::get_instance()->update_database_structure();

		$this->assertSame( Caching::DB_VERSION, $this->database_version() );
		$this->assertNull( $this->lock_value() );
	}

	/**
	 * The version is re-read from the database after acquiring the lock, so a stale cached version doesn't rerun upgrades.
	 */
	public function test_does_not_repeat_an_upgrade_another_request_just_finished() {
		global $wpdb;

		// This request read the old version (cached), but another request finished the upgrade in the meantime.
		update_option( 'wp_rest_cache_database_version', '2026.1.0', false );
		$wpdb->update( $wpdb->options, [ 'option_value' => Caching::DB_VERSION ], [ 'option_name' => 'wp_rest_cache_database_version' ] ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$this->assertSame( '2026.1.0', get_option( 'wp_rest_cache_database_version' ), 'Precondition: the option is cached' );

		$cache_id = $this->insert_cache();

		Caching::get_instance()->update_database_structure();

		$this->assertNotExpired( $cache_id, 'The 2026.4.0 upgrade should not have run twice' );
		$this->assertNull( $this->lock_value() );
	}

	/**
	 * The upgrade adds the `expiration` index to the caches table.
	 */
	public function test_adds_the_expiration_index() {
		global $wpdb;

		update_option( 'wp_rest_cache_database_version', '2026.1.0', false );

		Caching::get_instance()->update_database_structure();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$index = $wpdb->get_results( "SHOW KEYS FROM `{$wpdb->prefix}wrc_caches` WHERE Key_name = 'expiration'", ARRAY_A );
		$this->assertSame( [ 'expiration' ], array_column( $index, 'Column_name' ) );
	}

	/**
	 * Insert the upgrade lock as if another request holds it.
	 *
	 * @param int $time The time the lock was taken.
	 *
	 * @return void
	 */
	private function insert_lock( $time ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->options,
			[
				'option_name'  => Caching::UPGRADE_LOCK_OPTION,
				'option_value' => (string) $time,
				'autoload'     => 'no',
			]
		);
	}

	/**
	 * Get the lock value straight from the database.
	 *
	 * @return string|null The time the lock was taken, or null if there is no lock.
	 */
	private function lock_value() {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", Caching::UPGRADE_LOCK_OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Get the database version straight from the database, bypassing the option cache.
	 *
	 * @return string|null The database version.
	 */
	private function database_version() {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'wp_rest_cache_database_version' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
