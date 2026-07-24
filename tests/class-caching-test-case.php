<?php
/**
 * Shared base test case for row-level tests against the wp-rest-cache plugin tables.
 *
 * Tests that exercise schema migrations directly (DROP/CREATE TABLE) should extend
 * WP_UnitTestCase instead — the DELETE-on-set_up here would fight with their DDL.
 *
 * @package WP_Rest_Cache_Plugin
 */

abstract class Caching_Test_Case extends WP_UnitTestCase {

	const FAR_FUTURE = '2099-01-01 00:00:00';

	public function set_up() {
		parent::set_up();
		$this->clean_plugin_tables();
	}

	protected function clean_plugin_tables() {
		global $wpdb;
		$wpdb->query( "DELETE FROM `{$wpdb->prefix}wrc_relations`" );
		$wpdb->query( "DELETE FROM `{$wpdb->prefix}wrc_caches`" );
	}

	/**
	 * Insert a row into wrc_caches. Pass an array of column => value overrides for the bits
	 * you care about; everything else gets a sensible default.
	 */
	protected function insert_cache( array $overrides = [] ) {
		global $wpdb;

		$defaults = [
			'cache_key'       => md5( wp_generate_uuid4() ),
			'cache_type'      => 'endpoint',
			'request_uri'     => '/wp-json/test/' . wp_generate_uuid4(),
			'request_headers' => '',
			'request_method'  => 'GET',
			'object_type'     => 'post',
			'cache_hits'      => 0,
			'is_single'       => 0,
			'expiration'      => self::FAR_FUTURE,
			'deleted'         => 0,
			'cleaned'         => 0,
		];

		$wpdb->insert( "{$wpdb->prefix}wrc_caches", array_merge( $defaults, $overrides ) );

		return (int) $wpdb->insert_id;
	}

	protected function insert_relation( $cache_id, $object_id, $object_type ) {
		global $wpdb;
		$wpdb->insert(
			"{$wpdb->prefix}wrc_relations",
			[
				'cache_id'    => $cache_id,
				'object_id'   => $object_id,
				'object_type' => $object_type,
			]
		);
	}

	protected function column_value( $cache_id, $column ) {
		global $wpdb;
		return $wpdb->get_var(
			$wpdb->prepare(
				"SELECT `{$column}` FROM `{$wpdb->prefix}wrc_caches` WHERE cache_id = %d",
				$cache_id
			)
		);
	}

	protected function assertExpired( $cache_id, $message = '' ) {
		// Production's expiry check (Caching::get_cache) is `1 === strtotime( $expiration )` —
		// any tz drift would also break production, not just this assertion.
		$expiration = $this->column_value( $cache_id, 'expiration' );
		$this->assertSame(
			1,
			strtotime( $expiration ),
			$message !== '' ? $message : "Expected cache row {$cache_id} to be marked expired (got {$expiration})"
		);
	}

	protected function assertNotExpired( $cache_id, $message = '' ) {
		$this->assertSame(
			self::FAR_FUTURE,
			$this->column_value( $cache_id, 'expiration' ),
			$message !== '' ? $message : "Expected cache row {$cache_id} to retain its original expiration"
		);
	}

	/**
	 * Call a private/protected method on $object with the given args. Used sparingly — the
	 * private branches of the Caching class encode meaningful contracts (e.g. relation-extraction
	 * shape detection) that are easier to enumerate directly than to drive via the public API.
	 */
	protected function invoke_private( $object, $method, array $args ) {
		$reflection = new ReflectionClass( $object );
		$m          = $reflection->getMethod( $method );
		$m->setAccessible( true );
		return $m->invokeArgs( $object, $args );
	}

	/**
	 * Read a private/protected property from $object — useful when the SUT writes derived
	 * state (e.g. Endpoint_Api's $request_uri / $cache_key) into private fields rather than
	 * returning it from the call.
	 */
	protected function get_private_property( $object, $property ) {
		$reflection = new ReflectionClass( $object );
		$p          = $reflection->getProperty( $property );
		$p->setAccessible( true );
		return $p->getValue( $object );
	}

	/**
	 * Write a private/protected property — useful when arranging state for a partial-flow test
	 * (e.g. setting Endpoint_Api's $cache_key before calling save_cache directly).
	 */
	protected function set_private_property( $object, $property, $value ) {
		$reflection = new ReflectionClass( $object );
		$p          = $reflection->getProperty( $property );
		$p->setAccessible( true );
		$p->setValue( $object, $value );
	}
}
