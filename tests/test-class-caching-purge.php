<?php
/**
 * Tests for the stale cache purge cron: schedule_purge_stale_caches, purge_stale_caches, and the
 * wp_rest_cache/purge_stale_caches, wp_rest_cache/stale_cache_grace_period and
 * wp_rest_cache/max_purge_caches filters.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching::schedule_purge_stale_caches
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching::purge_stale_caches
 */
class Test_Caching_Purge extends Caching_Test_Case {

	const CRON_HOOK = 'wp_rest_cache_purge_stale_caches';

	public function set_up() {
		parent::set_up();
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function tear_down() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		parent::tear_down();
	}

	// ---------- schedule_purge_stale_caches ----------

	public function test_schedule_purge_schedules_a_daily_event() {
		Caching::get_instance()->schedule_purge_stale_caches();

		$this->assertNotFalse( wp_next_scheduled( self::CRON_HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( self::CRON_HOOK ) );
	}

	public function test_schedule_purge_does_not_replace_an_already_scheduled_event() {
		$preset = time() + 1000;
		wp_schedule_event( $preset, 'daily', self::CRON_HOOK );

		Caching::get_instance()->schedule_purge_stale_caches();

		$this->assertSame( $preset, wp_next_scheduled( self::CRON_HOOK ) );
	}

	// ---------- purge_stale_caches ----------

	public function test_purges_caches_that_expired_longer_ago_than_the_grace_period() {
		$cache_id = $this->insert_cache( [ 'expiration' => $this->local_time( -2 * DAY_IN_SECONDS ) ] );
		$this->insert_relation( $cache_id, 1, 'post' );

		Caching::get_instance()->purge_stale_caches();

		$this->assertFalse( $this->cache_row_exists( $cache_id ) );
		$this->assertSame( 0, $this->relation_count_for( $cache_id ) );
	}

	public function test_keeps_caches_that_expired_within_the_grace_period() {
		$cache_id = $this->insert_cache( [ 'expiration' => $this->local_time( -HOUR_IN_SECONDS ) ] );
		$this->insert_relation( $cache_id, 1, 'post' );

		Caching::get_instance()->purge_stale_caches();

		$this->assertTrue( $this->cache_row_exists( $cache_id ) );
		$this->assertSame( 1, $this->relation_count_for( $cache_id ) );
	}

	public function test_keeps_caches_that_have_not_expired() {
		$cache_id = $this->insert_cache( [ 'expiration' => $this->local_time( DAY_IN_SECONDS ) ] );

		Caching::get_instance()->purge_stale_caches();

		$this->assertTrue( $this->cache_row_exists( $cache_id ) );
	}

	public function test_keeps_unlimited_caches() {
		$cache_id = $this->insert_cache( [ 'expiration' => date_i18n( 'Y-m-d H:i:s', 0 ) ] );

		Caching::get_instance()->purge_stale_caches();

		$this->assertTrue( $this->cache_row_exists( $cache_id ) );
	}

	public function test_purges_flushed_caches_that_have_been_cleaned() {
		$cache_id = $this->insert_cache(
			[
				'expiration' => $this->flushed_sentinel(),
				'cleaned'    => 1,
			]
		);

		Caching::get_instance()->purge_stale_caches();

		$this->assertFalse( $this->cache_row_exists( $cache_id ) );
	}

	public function test_keeps_flushed_caches_that_still_await_cleanup() {
		// The cleanup cron still has to delete the transient of this cache.
		$cache_id = $this->insert_cache(
			[
				'expiration' => $this->flushed_sentinel(),
				'cleaned'    => 0,
			]
		);

		Caching::get_instance()->purge_stale_caches();

		$this->assertTrue( $this->cache_row_exists( $cache_id ) );
	}

	public function test_does_nothing_when_regeneration_is_enabled() {
		update_option( 'wp_rest_cache_regenerate', '1' );

		$expired = $this->insert_cache( [ 'expiration' => $this->local_time( -2 * DAY_IN_SECONDS ) ] );
		$flushed = $this->insert_cache(
			[
				'expiration' => $this->flushed_sentinel(),
				'cleaned'    => 1,
			]
		);

		Caching::get_instance()->purge_stale_caches();

		$this->assertTrue( $this->cache_row_exists( $expired ) );
		$this->assertTrue( $this->cache_row_exists( $flushed ) );
	}

	public function test_purge_filter_overrides_the_regeneration_default() {
		update_option( 'wp_rest_cache_regenerate', '1' );
		add_filter( 'wp_rest_cache/purge_stale_caches', '__return_true' );

		$cache_id = $this->insert_cache( [ 'expiration' => $this->local_time( -2 * DAY_IN_SECONDS ) ] );

		Caching::get_instance()->purge_stale_caches();

		$this->assertFalse( $this->cache_row_exists( $cache_id ) );
	}

	public function test_purge_filter_can_disable_purging() {
		add_filter( 'wp_rest_cache/purge_stale_caches', '__return_false' );

		$cache_id = $this->insert_cache( [ 'expiration' => $this->local_time( -2 * DAY_IN_SECONDS ) ] );

		Caching::get_instance()->purge_stale_caches();

		$this->assertTrue( $this->cache_row_exists( $cache_id ) );
	}

	public function test_grace_period_filter_is_honored() {
		add_filter(
			'wp_rest_cache/stale_cache_grace_period',
			function () {
				return MINUTE_IN_SECONDS;
			}
		);

		$cache_id = $this->insert_cache( [ 'expiration' => $this->local_time( -HOUR_IN_SECONDS ) ] );

		Caching::get_instance()->purge_stale_caches();

		$this->assertFalse( $this->cache_row_exists( $cache_id ) );
	}

	public function test_full_batch_schedules_a_follow_up_run() {
		add_filter(
			'wp_rest_cache/max_purge_caches',
			function () {
				return 2;
			}
		);

		$cache_ids = [];
		for ( $i = 0; $i < 3; $i++ ) {
			$cache_ids[] = $this->insert_cache( [ 'expiration' => $this->local_time( -2 * DAY_IN_SECONDS ) ] );
		}

		$before = time();
		Caching::get_instance()->purge_stale_caches();

		$remaining = array_filter( $cache_ids, [ $this, 'cache_row_exists' ] );
		$this->assertCount( 1, $remaining );

		$next = wp_next_scheduled( self::CRON_HOOK );
		$this->assertNotFalse( $next );
		$this->assertLessThanOrEqual( $before + MINUTE_IN_SECONDS + 15, $next );
	}

	public function test_partial_batch_does_not_schedule_a_follow_up_run() {
		$this->insert_cache( [ 'expiration' => $this->local_time( -2 * DAY_IN_SECONDS ) ] );

		Caching::get_instance()->purge_stale_caches();

		$this->assertFalse( wp_next_scheduled( self::CRON_HOOK ) );
	}

	public function test_does_not_touch_relations_of_caches_that_are_kept() {
		$stale = $this->insert_cache( [ 'expiration' => $this->local_time( -2 * DAY_IN_SECONDS ) ] );
		$live  = $this->insert_cache();
		$this->insert_relation( $stale, 1, 'post' );
		$this->insert_relation( $live, 1, 'post' );

		Caching::get_instance()->purge_stale_caches();

		$this->assertSame( 0, $this->relation_count_for( $stale ) );
		$this->assertSame( 1, $this->relation_count_for( $live ) );
	}

	private function local_time( $offset ) {
		// The expiration column holds local time, see Caching::insert_cache_row().
		// phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		return date_i18n( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $offset );
	}

	private function flushed_sentinel() {
		return date_i18n( 'Y-m-d H:i:s', 1 );
	}

	public function cache_row_exists( $cache_id ) {
		global $wpdb;
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}wrc_caches` WHERE cache_id = %d",
				$cache_id
			)
		);
		return 1 === $count;
	}

	private function relation_count_for( $cache_id ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$wpdb->prefix}wrc_relations` WHERE cache_id = %d",
				$cache_id
			)
		);
	}
}
