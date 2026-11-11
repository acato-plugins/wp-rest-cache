<?php
/**
 * Tests for the 2026.x database upgrade paths: from 2026.3.1 (database version 2026.1.0), from 2026.4.0 - 2026.4.2
 * (database version 2026.4.0) and a fresh install.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching::update_database_structure
 */
class Test_Caching_Upgrade_2026 extends WP_UnitTestCase {

	const FAR_FUTURE = '2099-01-01 00:00:00';

	/**
	 * The caches table name.
	 *
	 * @var string
	 */
	private $caches_table;

	/**
	 * The relations table name.
	 *
	 * @var string
	 */
	private $relations_table;

	/**
	 * Re-create the plugin tables exactly as 2026.3.1 up to and including 2026.4.2 created them.
	 */
	public function set_up() {
		parent::set_up();

		global $wpdb;
		$this->caches_table    = "{$wpdb->prefix}wrc_caches";
		$this->relations_table = "{$wpdb->prefix}wrc_relations";

		$this->drop_tables();

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			"CREATE TABLE `{$this->caches_table}` (
				`cache_id` BIGINT(20) NOT NULL AUTO_INCREMENT,
				`cache_key` VARCHAR(181) NOT NULL,
				`cache_type` VARCHAR(10) NOT NULL,
				`request_uri` LONGTEXT NOT NULL,
				`request_headers` LONGTEXT NOT NULL,
				`request_method` VARCHAR(10) NOT NULL,
				`object_type` VARCHAR(50) NOT NULL,
				`cache_hits` BIGINT(20) NOT NULL,
				`is_single` TINYINT(1) NOT NULL,
				`expiration` DATETIME NOT NULL,
				`deleted` TINYINT(1) DEFAULT 0,
				`cleaned` TINYINT(1) DEFAULT 0,
				PRIMARY KEY (`cache_id`),
				UNIQUE INDEX `cache_key` (`cache_key`),
				KEY `cache_type` (`cache_type`),
				KEY `non_single_caches` (`cache_type`, `object_type`, `is_single`)
			)"
		);

		$wpdb->query(
			"CREATE TABLE `{$this->relations_table}` (
				`cache_id` BIGINT(20) NOT NULL,
				`object_id` VARCHAR(100) NOT NULL,
				`object_type` VARCHAR(50) NOT NULL,
				PRIMARY KEY (`cache_id`, `object_id`, `object_type`),
				KEY `cache_id` (`cache_id`),
				KEY `object` (`object_id`, `object_type`)
			)"
		);
		// phpcs:enable

		// A lock left behind by an earlier upgrade (see Caching_Test_Case::clean_plugin_tables()) would block this one.
		$wpdb->delete( $wpdb->options, [ 'option_name' => Caching::UPGRADE_LOCK_OPTION ] );
	}

	/**
	 * The DDL in set_up breaks the WP_UnitTestCase transaction wrapper, so clear out the rows explicitly and leave the
	 * database version current for the next test.
	 */
	public function tear_down() {
		global $wpdb;
		$wpdb->query( "DELETE FROM `{$this->relations_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM `{$this->caches_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		update_option( 'wp_rest_cache_database_version', Caching::DB_VERSION, false );
		parent::tear_down();
	}

	/**
	 * Upgrading a 2026.3.1 site keeps all keys and data, adds the `expiration` index without database errors and
	 * flushes all caches once (the 2026.4.0 security upgrade).
	 */
	public function test_upgrade_from_2026_3_1() {
		update_option( 'wp_rest_cache_database_version', '2026.1.0', false );
		$cache_id = $this->seed_cache_with_relation();

		$errors = $this->count_database_errors();
		Caching::get_instance()->update_database_structure();

		$this->assertSame( $errors, $this->count_database_errors(), 'The upgrade should not cause database errors' );
		$this->assertSame( Caching::DB_VERSION, get_option( 'wp_rest_cache_database_version' ) );
		$this->assert_current_schema();

		$this->assertSame( 1, strtotime( $this->column_value( $cache_id, 'expiration' ) ), 'The cache should be flushed' );
		$this->assertSame( '1', $this->column_value( $cache_id, 'deleted' ), 'The cache should be marked as deleted' );
	}

	/**
	 * Upgrading a 2026.4.0 - 2026.4.2 site adds the `expiration` index, but doesn't flush the caches a second time.
	 */
	public function test_upgrade_from_2026_4_0_does_not_flush_caches_again() {
		update_option( 'wp_rest_cache_database_version', '2026.4.0', false );
		$cache_id = $this->seed_cache_with_relation();

		$errors = $this->count_database_errors();
		Caching::get_instance()->update_database_structure();

		$this->assertSame( $errors, $this->count_database_errors(), 'The upgrade should not cause database errors' );
		$this->assertSame( Caching::DB_VERSION, get_option( 'wp_rest_cache_database_version' ) );
		$this->assert_current_schema();

		$this->assertSame( self::FAR_FUTURE, $this->column_value( $cache_id, 'expiration' ) );
		$this->assertSame( '0', $this->column_value( $cache_id, 'deleted' ) );
	}

	/**
	 * A fresh install creates both tables with all their keys.
	 */
	public function test_fresh_install_creates_the_tables() {
		$this->drop_tables();
		delete_option( 'wp_rest_cache_database_version' );

		$errors = $this->count_database_errors();
		Caching::get_instance()->update_database_structure();

		$this->assertSame( $errors, $this->count_database_errors(), 'The install should not cause database errors' );
		$this->assertSame( Caching::DB_VERSION, get_option( 'wp_rest_cache_database_version' ) );
		$this->assert_current_schema();
	}

	/**
	 * Assert that both tables have all keys of the current schema.
	 *
	 * @return void
	 */
	private function assert_current_schema() {
		$this->assertSame( [ 'cache_id' ], $this->index_columns( $this->caches_table, 'PRIMARY' ) );
		$this->assertSame( [ 'cache_key' ], $this->index_columns( $this->caches_table, 'cache_key' ) );
		$this->assertSame( [ 'cache_type' ], $this->index_columns( $this->caches_table, 'cache_type' ) );
		$this->assertSame( [ 'cache_type', 'object_type', 'is_single' ], $this->index_columns( $this->caches_table, 'non_single_caches' ) );
		$this->assertSame( [ 'expiration' ], $this->index_columns( $this->caches_table, 'expiration' ) );

		$this->assertSame( [ 'cache_id', 'object_id', 'object_type' ], $this->index_columns( $this->relations_table, 'PRIMARY' ) );
		$this->assertSame( [ 'cache_id' ], $this->index_columns( $this->relations_table, 'cache_id' ) );
		$this->assertSame( [ 'object_id', 'object_type' ], $this->index_columns( $this->relations_table, 'object' ) );
	}

	/**
	 * Insert a valid cache with one relation, and check that both survive the upgrade.
	 *
	 * @return int The ID of the inserted cache.
	 */
	private function seed_cache_with_relation() {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->caches_table,
			[
				'cache_key'       => md5( 'upgrade-2026' ),
				'cache_type'      => 'endpoint',
				'request_uri'     => '/wp-json/wp/v2/posts',
				'request_headers' => '',
				'request_method'  => 'GET',
				'object_type'     => 'post',
				'cache_hits'      => 5,
				'is_single'       => 0,
				'expiration'      => self::FAR_FUTURE,
			]
		);
		$cache_id = (int) $wpdb->insert_id;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$this->relations_table,
			[
				'cache_id'    => $cache_id,
				'object_id'   => '42',
				'object_type' => 'post',
			]
		);

		return $cache_id;
	}

	/**
	 * Get a column value of a cache row.
	 *
	 * @param int    $cache_id The ID of the cache.
	 * @param string $column   The column name.
	 *
	 * @return string|null The column value.
	 */
	private function column_value( $cache_id, $column ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_var( $wpdb->prepare( "SELECT `{$column}` FROM `{$this->caches_table}` WHERE cache_id = %d", $cache_id ) );
	}

	/**
	 * Get the columns of an index, in index order.
	 *
	 * @param string $table    The table name.
	 * @param string $key_name The index name.
	 *
	 * @return array<int,string> The column names.
	 */
	private function index_columns( $table, $key_name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SHOW KEYS FROM `{$table}` WHERE Key_name = %s", $key_name ), ARRAY_A );
		usort( $rows, fn( $a, $b ) => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'] );
		return array_column( $rows, 'Column_name' );
	}

	/**
	 * Count the database errors wpdb has recorded so far.
	 *
	 * @return int The number of database errors.
	 */
	private function count_database_errors() {
		global $EZSQL_ERROR; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase -- wpdb's global.
		return is_array( $EZSQL_ERROR ) ? count( $EZSQL_ERROR ) : 0; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase
	}

	/**
	 * Drop both plugin tables.
	 *
	 * @return void
	 */
	private function drop_tables() {
		global $wpdb;
		$wpdb->query( "DROP TABLE IF EXISTS `{$this->relations_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DROP TABLE IF EXISTS `{$this->caches_table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}
}
