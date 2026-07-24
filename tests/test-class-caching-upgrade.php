<?php
/**
 * Tests for the database schema upgrade routines in the Caching class.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching::update_database_structure
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching::upgrade_2026_1_0
 */
class Test_Caching_Upgrade extends WP_UnitTestCase {

	/**
	 * Re-create the plugin tables in their pre-2026.1.0 shape so each test starts from a known state.
	 */
	public function set_up() {
		parent::set_up();

		global $wpdb;
		$caches_table    = "{$wpdb->prefix}wrc_caches";
		$relations_table = "{$wpdb->prefix}wrc_relations";

		$wpdb->query( "DROP TABLE IF EXISTS `{$relations_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS `{$caches_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

		// Pre-2026.1.0 schema: VARCHAR(191) on object_id/object_type, prefix-length `object` index.
		$wpdb->query(
			"CREATE TABLE `{$caches_table}` (
				`cache_id` BIGINT(20) NOT NULL AUTO_INCREMENT,
				`cache_key` VARCHAR(181) NOT NULL,
				`cache_type` VARCHAR(10) NOT NULL,
				`request_uri` LONGTEXT NOT NULL,
				`request_headers` LONGTEXT NOT NULL,
				`request_method` VARCHAR(10) NOT NULL,
				`object_type` VARCHAR(191) NOT NULL,
				`cache_hits` BIGINT(20) NOT NULL,
				`is_single` TINYINT(1) NOT NULL,
				`expiration` DATETIME NOT NULL,
				`deleted` TINYINT(1) DEFAULT 0,
				`cleaned` TINYINT(1) DEFAULT 0,
				PRIMARY KEY (`cache_id`),
				UNIQUE KEY `cache_key` (`cache_key`),
				KEY `cache_type` (`cache_type`),
				KEY `non_single_caches` (`cache_type`, `object_type`, `is_single`)
			)"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

		$wpdb->query(
			"CREATE TABLE `{$relations_table}` (
				`cache_id` BIGINT(20) NOT NULL,
				`object_id` VARCHAR(191) NOT NULL,
				`object_type` VARCHAR(191) NOT NULL,
				PRIMARY KEY (`cache_id`, `object_id`, `object_type`),
				KEY `cache_id` (`cache_id`),
				KEY `object` (`object_id`(100), `object_type`(100))
			)"
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery

		update_option( 'wp_rest_cache_database_version', '2025.1.0', false );
	}

	/**
	 * The DROP/CREATE TABLE statements in set_up are DDL and break the WP_UnitTestCase
	 * transaction wrapper, so explicitly clear out any rows we left behind to keep the next
	 * test's state predictable.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( "DELETE FROM `{$wpdb->prefix}wrc_relations`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM `{$wpdb->prefix}wrc_caches`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		parent::tear_down();
	}

	/**
	 * Running the upgrade from 2025.1.0 should shrink the over-sized columns, drop the
	 * prefix-length `object` index, keep the composite PRIMARY KEY, and preserve existing rows.
	 */
	public function test_upgrade_from_2025_1_0_shrinks_columns_and_preserves_data() {
		global $wpdb;

		$caches_table    = "{$wpdb->prefix}wrc_caches";
		$relations_table = "{$wpdb->prefix}wrc_relations";

		// Seed one cache + one relation so we can prove data survives the migration.
		$wpdb->insert(
			$caches_table,
			[
				'cache_key'       => str_repeat( 'a', 32 ),
				'cache_type'      => 'endpoint',
				'request_uri'     => '/wp-json/wp/v2/posts',
				'request_headers' => '',
				'request_method'  => 'GET',
				'object_type'     => 'post',
				'cache_hits'      => 0,
				'is_single'       => 0,
				'expiration'      => '2099-01-01 00:00:00',
			]
		);
		$cache_id = (int) $wpdb->insert_id;

		$wpdb->insert(
			$relations_table,
			[
				'cache_id'    => $cache_id,
				'object_id'   => '42',
				'object_type' => 'post',
			]
		);

		Caching::get_instance()->update_database_structure();

		$this->assertSame( '2026.1.0', get_option( 'wp_rest_cache_database_version' ) );

		$relations_object_id   = $this->column_type( $relations_table, 'object_id' );
		$relations_object_type = $this->column_type( $relations_table, 'object_type' );
		$caches_object_type    = $this->column_type( $caches_table, 'object_type' );

		$this->assertSame( 'varchar(100)', $relations_object_id );
		$this->assertSame( 'varchar(50)', $relations_object_type );
		$this->assertSame( 'varchar(50)', $caches_object_type );

		$this->assertSame(
			[ 'cache_id', 'object_id', 'object_type' ],
			$this->primary_key_columns( $relations_table )
		);

		$object_index = $this->index_columns( $relations_table, 'object' );
		$this->assertSame( [ 'object_id', 'object_type' ], array_column( $object_index, 'Column_name' ) );
		$this->assertSame( [ null, null ], array_column( $object_index, 'Sub_part' ), 'Prefix lengths should be gone' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT object_id, object_type FROM `{$relations_table}` WHERE cache_id = %d",
				$cache_id
			),
			ARRAY_A
		);
		$this->assertSame( [ 'object_id' => '42', 'object_type' => 'post' ], $row );
	}

	private function column_type( $table, $column ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( "SHOW COLUMNS FROM `{$table}` WHERE Field = '{$column}'", ARRAY_A );
		return strtolower( $row['Type'] );
	}

	private function primary_key_columns( $table ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A );
		usort( $rows, fn( $a, $b ) => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'] );
		return array_column( $rows, 'Column_name' );
	}

	private function index_columns( $table, $key_name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( "SHOW KEYS FROM `{$table}` WHERE Key_name = '{$key_name}'", ARRAY_A );
		usort( $rows, fn( $a, $b ) => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'] );
		return $rows;
	}
}
