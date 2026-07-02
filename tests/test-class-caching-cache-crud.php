<?php
/**
 * Tests for set_cache / get_cache: the core round-trip of the Caching class.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching::set_cache
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching::get_cache
 */
class Test_Caching_Cache_Crud extends Caching_Test_Case {

	const CACHE_KEY = '0123456789abcdef0123456789abcdef';
	const URI       = '/wp-json/wp/v2/posts/42';

	public function set_up() {
		parent::set_up();

		// Reset the singleton's mutable is_single flag — it leaks between tests otherwise.
		$caching    = Caching::get_instance();
		$reflection = new ReflectionClass( $caching );
		if ( $reflection->hasProperty( 'is_single' ) ) {
			$prop = $reflection->getProperty( 'is_single' );
			$prop->setAccessible( true );
			$prop->setValue( $caching, true );
		}
	}

	public function tear_down() {
		delete_transient( Caching::get_instance()->transient_key( self::CACHE_KEY ) );
		parent::tear_down();
	}

	public function test_set_cache_then_get_cache_round_trips_value() {
		$value = $this->single_post_payload( 42 );

		Caching::get_instance()->set_cache( self::CACHE_KEY, $value, 'endpoint', self::URI );

		$retrieved = Caching::get_instance()->get_cache( self::CACHE_KEY );

		// register_endpoint_cache forces $data['data'] through json_decode-as-array, so the
		// retrieved value's data array won't be object-typed even if we passed it as such.
		// Compare structurally.
		$this->assertSame( $value['data']['id'], $retrieved['data']['id'] );
		$this->assertSame( $value['data']['type'], $retrieved['data']['type'] );
	}

	public function test_set_cache_creates_caches_row_with_correct_metadata() {
		global $wpdb;

		Caching::get_instance()->set_cache(
			self::CACHE_KEY,
			$this->single_post_payload( 42 ),
			'endpoint',
			self::URI
		);

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT cache_key, cache_type, request_uri, object_type, is_single FROM `{$wpdb->prefix}wrc_caches` WHERE cache_key = %s",
				self::CACHE_KEY
			),
			ARRAY_A
		);

		$this->assertNotNull( $row );
		$this->assertSame( self::CACHE_KEY, $row['cache_key'] );
		$this->assertSame( 'endpoint', $row['cache_type'] );
		$this->assertSame( self::URI, $row['request_uri'] );
		$this->assertSame( 'post', $row['object_type'] );
		$this->assertSame( '1', $row['is_single'] );
	}

	public function test_set_cache_creates_relation_row() {
		global $wpdb;

		Caching::get_instance()->set_cache(
			self::CACHE_KEY,
			$this->single_post_payload( 42 ),
			'endpoint',
			self::URI
		);

		$relations = $wpdb->get_results(
			"SELECT object_id, object_type FROM `{$wpdb->prefix}wrc_relations`",
			ARRAY_A
		);

		$this->assertCount( 1, $relations );
		$this->assertSame( '42', $relations[0]['object_id'] );
		$this->assertSame( 'post', $relations[0]['object_type'] );
	}

	public function test_set_cache_with_non_endpoint_type_is_noop() {
		global $wpdb;

		// _deprecated_argument fires from set_cache when $type != 'endpoint'; WP_UnitTestCase
		// fails the test unless we declare we expect it.
		$this->setExpectedDeprecated( 'set_cache' );

		Caching::get_instance()->set_cache(
			self::CACHE_KEY,
			$this->single_post_payload( 42 ),
			'item', // Anything other than 'endpoint' should bail.
			self::URI
		);

		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `{$wpdb->prefix}wrc_caches`"
		);
		$this->assertSame( 0, $count );
		$this->assertFalse( get_transient( Caching::get_instance()->transient_key( self::CACHE_KEY ) ) );
	}

	public function test_get_cache_returns_false_for_expired_row() {
		// Seed the cache, then mark its row as expired by setting expiration to epoch+1s.
		Caching::get_instance()->set_cache(
			self::CACHE_KEY,
			$this->single_post_payload( 42 ),
			'endpoint',
			self::URI
		);

		global $wpdb;
		$wpdb->update(
			"{$wpdb->prefix}wrc_caches",
			[ 'expiration' => gmdate( 'Y-m-d H:i:s', 1 ) ],
			[ 'cache_key' => self::CACHE_KEY ]
		);

		$this->assertFalse( Caching::get_instance()->get_cache( self::CACHE_KEY ) );
	}

	public function test_get_cache_returns_false_when_transient_is_missing() {
		Caching::get_instance()->set_cache(
			self::CACHE_KEY,
			$this->single_post_payload( 42 ),
			'endpoint',
			self::URI
		);
		delete_transient( Caching::get_instance()->transient_key( self::CACHE_KEY ) );

		$this->assertFalse( Caching::get_instance()->get_cache( self::CACHE_KEY ) );
	}

	public function test_get_cache_increments_hit_counter() {
		global $wpdb;

		Caching::get_instance()->set_cache(
			self::CACHE_KEY,
			$this->single_post_payload( 42 ),
			'endpoint',
			self::URI
		);

		$initial_hits = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT cache_hits FROM `{$wpdb->prefix}wrc_caches` WHERE cache_key = %s",
				self::CACHE_KEY
			)
		);

		Caching::get_instance()->get_cache( self::CACHE_KEY );
		Caching::get_instance()->get_cache( self::CACHE_KEY );

		$final_hits = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT cache_hits FROM `{$wpdb->prefix}wrc_caches` WHERE cache_key = %s",
				self::CACHE_KEY
			)
		);

		$this->assertSame( $initial_hits + 2, $final_hits );
	}

	public function test_collection_payload_marks_cache_as_non_single_and_creates_one_relation_per_item() {
		global $wpdb;

		// `process_recursive_cache_relations` only creates a relation row when each item has
		// `id`, `type`, AND (`slug` OR `status`).
		$value = [
			'data'    => [
				[ 'id' => 1, 'type' => 'post', 'slug' => 'one' ],
				[ 'id' => 2, 'type' => 'post', 'slug' => 'two' ],
				[ 'id' => 3, 'type' => 'post', 'slug' => 'three' ],
			],
			'headers' => [],
		];

		Caching::get_instance()->set_cache( self::CACHE_KEY, $value, 'endpoint', self::URI );

		$is_single = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT is_single FROM `{$wpdb->prefix}wrc_caches` WHERE cache_key = %s",
				self::CACHE_KEY
			)
		);
		$this->assertSame( '0', $is_single );

		$relation_ids = $wpdb->get_col(
			"SELECT object_id FROM `{$wpdb->prefix}wrc_relations` ORDER BY object_id ASC"
		);
		$this->assertSame( [ '1', '2', '3' ], $relation_ids );
	}

	/**
	 * A payload shaped like a single WP REST item response: array with a `data` key whose
	 * value carries `id` + `type`. This is what triggers `is_single = true` and
	 * `object_type = 'post'` in determine_object_type().
	 */
	private function single_post_payload( $id ) {
		return [
			'data'    => [
				'id'   => $id,
				'type' => 'post',
				'slug' => "post-{$id}",
			],
			'headers' => [],
		];
	}
}
