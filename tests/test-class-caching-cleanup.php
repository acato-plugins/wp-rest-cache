<?php
/**
 * Tests for the cleanup cron pipeline: schedule_cleanup, cleanup_deleted_caches, and the
 * wp_rest_cache/delete_caches_immediately and wp_rest_cache/max_cleanup_caches filters.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching::schedule_cleanup
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching::cleanup_deleted_caches
 */
class Test_Caching_Cleanup extends Caching_Test_Case {

	const CRON_HOOK = 'wp_rest_cache_cleanup_deleted_caches';

	public function set_up() {
		parent::set_up();
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function tear_down() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		parent::tear_down();
	}

	public function test_schedule_cleanup_schedules_cron_about_five_minutes_out() {
		$before = time();
		$this->invoke_private( Caching::get_instance(), 'schedule_cleanup', [] );

		$next = wp_next_scheduled( self::CRON_HOOK );
		$this->assertNotFalse( $next );
		$this->assertGreaterThanOrEqual( $before + 5 * MINUTE_IN_SECONDS - 15, $next );
		$this->assertLessThanOrEqual( $before + 5 * MINUTE_IN_SECONDS + 15, $next );
	}

	public function test_schedule_cleanup_does_not_replace_an_already_pending_event() {
		$preset = time() + 1000;
		wp_schedule_single_event( $preset, self::CRON_HOOK );
		$this->assertSame( $preset, wp_next_scheduled( self::CRON_HOOK ) );

		$this->invoke_private( Caching::get_instance(), 'schedule_cleanup', [] );

		$this->assertSame( $preset, wp_next_scheduled( self::CRON_HOOK ) );
	}

	public function test_schedule_cleanup_with_immediate_filter_runs_synchronously_and_does_not_schedule() {
		add_filter( 'wp_rest_cache/delete_caches_immediately', '__return_true' );

		$cache_id = $this->insert_cache(
			[
				'cache_key'  => 'eligible',
				'expiration' => $this->flushed_sentinel(),
				'cleaned'    => 0,
				'deleted'    => 1,
			]
		);

		$this->invoke_private( Caching::get_instance(), 'schedule_cleanup', [] );

		$this->assertFalse( $this->cache_row_exists( $cache_id ) );
		$this->assertFalse( wp_next_scheduled( self::CRON_HOOK ) );
	}

	public function test_cleanup_processes_only_flushed_and_uncleaned_rows() {
		$eligible = $this->insert_cache(
			[
				'cache_key'  => 'eligible',
				'expiration' => $this->flushed_sentinel(),
				'cleaned'    => 0,
				'deleted'    => 0,
			]
		);
		$already_cleaned = $this->insert_cache(
			[
				'cache_key'  => 'already-cleaned',
				'expiration' => $this->flushed_sentinel(),
				'cleaned'    => 1,
				'deleted'    => 0,
			]
		);
		$not_flushed = $this->insert_cache(
			[
				'cache_key'  => 'not-flushed',
				'expiration' => '2099-01-01 00:00:00',
				'cleaned'    => 0,
				'deleted'    => 0,
			]
		);

		Caching::get_instance()->cleanup_deleted_caches();

		$this->assertSame( '1', $this->column_value( $eligible, 'cleaned' ) );
		$this->assertSame( '1', $this->column_value( $already_cleaned, 'cleaned' ) );
		$this->assertSame( '0', $this->column_value( $not_flushed, 'cleaned' ) );
		$this->assertNotExpired( $not_flushed );
	}

	public function test_cleanup_with_deleted_one_hard_deletes_row() {
		$cache_id = $this->insert_cache(
			[
				'cache_key'  => 'hard',
				'expiration' => $this->flushed_sentinel(),
				'cleaned'    => 0,
				'deleted'    => 1,
			]
		);

		Caching::get_instance()->cleanup_deleted_caches();

		$this->assertFalse( $this->cache_row_exists( $cache_id ) );
	}

	public function test_cleanup_with_deleted_zero_soft_deletes_row_and_marks_cleaned() {
		$cache_id = $this->insert_cache(
			[
				'cache_key'  => 'soft',
				'expiration' => $this->flushed_sentinel(),
				'cleaned'    => 0,
				'deleted'    => 0,
			]
		);

		Caching::get_instance()->cleanup_deleted_caches();

		$this->assertTrue( $this->cache_row_exists( $cache_id ) );
		$this->assertSame( '1', $this->column_value( $cache_id, 'cleaned' ) );
	}

	public function test_cleanup_fires_deleted_caches_action_with_processed_rows() {
		// Seed two rows: WP's do_action() unwraps a single-element array of objects (legacy
		// PHP4 `array( &$this )` support), so we'd see a stdClass instead of an array if we
		// only seeded one. With two, the contract documented by @param array $caches holds.
		foreach ( [ 'a', 'b' ] as $key ) {
			$this->insert_cache(
				[
					'cache_key'  => $key,
					'expiration' => $this->flushed_sentinel(),
					'cleaned'    => 0,
					'deleted'    => 1,
				]
			);
		}

		$captured = null;
		add_action(
			'wp_rest_cache/deleted_caches',
			function ( $caches ) use ( &$captured ) {
				$captured = $caches;
			}
		);

		Caching::get_instance()->cleanup_deleted_caches();

		$this->assertIsArray( $captured );
		$this->assertCount( 2, $captured );
		$keys = array_column( array_map( fn( $c ) => (array) $c, $captured ), 'cache_key' );
		$this->assertEqualsCanonicalizing( [ 'a', 'b' ], $keys );
	}

	public function test_cleanup_honors_max_cleanup_caches_filter_limit() {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->insert_cache(
				[
					'cache_key'  => "k{$i}",
					'expiration' => $this->flushed_sentinel(),
					'cleaned'    => 0,
					'deleted'    => 0,
				]
			);
		}

		add_filter( 'wp_rest_cache/max_cleanup_caches', fn() => 2 );

		Caching::get_instance()->cleanup_deleted_caches();

		global $wpdb;
		$cleaned_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$wpdb->prefix}wrc_caches` WHERE cleaned = 1"
		);
		$this->assertSame( 2, $cleaned_count );
	}

	public function test_cleanup_reschedules_itself_when_unprocessed_rows_remain() {
		for ( $i = 0; $i < 3; $i++ ) {
			$this->insert_cache(
				[
					'cache_key'  => "k{$i}",
					'expiration' => $this->flushed_sentinel(),
					'cleaned'    => 0,
					'deleted'    => 0,
				]
			);
		}
		add_filter( 'wp_rest_cache/max_cleanup_caches', fn() => 2 );

		$this->assertFalse( wp_next_scheduled( self::CRON_HOOK ) );

		Caching::get_instance()->cleanup_deleted_caches();

		$this->assertNotFalse( wp_next_scheduled( self::CRON_HOOK ) );
	}

	public function test_cleanup_does_not_reschedule_when_no_unprocessed_rows_remain() {
		$this->insert_cache(
			[
				'cache_key'  => 'only',
				'expiration' => $this->flushed_sentinel(),
				'cleaned'    => 0,
				'deleted'    => 0,
			]
		);

		$this->assertFalse( wp_next_scheduled( self::CRON_HOOK ) );

		Caching::get_instance()->cleanup_deleted_caches();

		$this->assertFalse( wp_next_scheduled( self::CRON_HOOK ) );
	}

	private function flushed_sentinel() {
		return date_i18n( 'Y-m-d H:i:s', 1 );
	}

	private function cache_row_exists( $cache_id ) {
		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}wrc_caches` WHERE cache_id = %d",
				$cache_id
			)
		);
		return 1 === $count;
	}
}
