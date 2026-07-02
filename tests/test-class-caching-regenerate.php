<?php
/**
 * Tests for the cache regeneration cron path: should_regenerate, get_regenerate_interval,
 * get_regenerate_number, and regenerate_expired_caches.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\Caching\Caching
 */
class Test_Caching_Regenerate extends Caching_Test_Case {

	/** @var array<int, array{0:string,1:array<string,mixed>}> */
	private $http_calls = [];

	public function set_up() {
		parent::set_up();

		$this->http_calls = [];

		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$this->http_calls[] = [ $url, $args ];
				return [
					'headers'  => [],
					'body'     => '',
					'response' => [ 'code' => 200, 'message' => 'OK' ],
					'cookies'  => [],
					'filename' => null,
				];
			},
			10,
			3
		);
	}

	public function test_should_regenerate_defaults_to_false() {
		$this->assertFalse( Caching::get_instance()->should_regenerate() );
	}

	public function test_should_regenerate_returns_true_when_option_is_one() {
		update_option( 'wp_rest_cache_regenerate', '1' );

		$this->assertTrue( Caching::get_instance()->should_regenerate() );
	}

	public function test_should_regenerate_returns_false_when_option_is_zero() {
		update_option( 'wp_rest_cache_regenerate', '0' );

		$this->assertFalse( Caching::get_instance()->should_regenerate() );
	}

	public function test_get_regenerate_interval_defaults_to_twicedaily() {
		$this->assertSame( 'twicedaily', Caching::get_instance()->get_regenerate_interval() );
	}

	public function test_get_regenerate_interval_honors_option_override() {
		update_option( 'wp_rest_cache_regenerate_interval', 'hourly' );

		$this->assertSame( 'hourly', Caching::get_instance()->get_regenerate_interval() );
	}

	public function test_get_regenerate_number_defaults_to_ten() {
		$this->assertSame( 10, (int) Caching::get_instance()->get_regenerate_number() );
	}

	public function test_get_regenerate_number_honors_option_override() {
		update_option( 'wp_rest_cache_regenerate_number', 25 );

		$this->assertSame( 25, (int) Caching::get_instance()->get_regenerate_number() );
	}

	public function test_regenerates_rows_with_flushed_sentinel_expiration() {
		$this->seed_cache_needing_regen( '/wp-json/wp/v2/posts/1', $this->flushed_sentinel(), true );

		Caching::get_instance()->regenerate_expired_caches();

		$this->assertCount( 1, $this->http_calls );
		$this->assertStringContainsString( '/wp-json/wp/v2/posts/1', $this->http_calls[0][0] );
	}

	public function test_regenerates_rows_with_missing_transient_even_when_expiration_is_future() {
		$this->seed_cache_needing_regen( '/wp-json/wp/v2/posts/2', '2099-01-01 00:00:00', false );

		Caching::get_instance()->regenerate_expired_caches();

		$this->assertCount( 1, $this->http_calls );
		$this->assertStringContainsString( '/wp-json/wp/v2/posts/2', $this->http_calls[0][0] );
	}

	public function test_does_not_regenerate_rows_still_warm_in_transients() {
		$this->seed_cache_needing_regen( '/wp-json/wp/v2/posts/3', '2099-01-01 00:00:00', true );

		Caching::get_instance()->regenerate_expired_caches();

		$this->assertSame( [], $this->http_calls );
	}

	public function test_regenerates_in_descending_cache_hits_order() {
		$this->seed_cache_needing_regen( '/uri/cold', $this->flushed_sentinel(), false, 1, 'cold' );
		$this->seed_cache_needing_regen( '/uri/hot', $this->flushed_sentinel(), false, 100, 'hot' );
		$this->seed_cache_needing_regen( '/uri/warm', $this->flushed_sentinel(), false, 10, 'warm' );

		Caching::get_instance()->regenerate_expired_caches();

		$paths = array_map(
			fn( $call ) => parse_url( $call[0], PHP_URL_PATH ),
			$this->http_calls
		);
		$this->assertSame( [ '/uri/hot', '/uri/warm', '/uri/cold' ], $paths );
	}

	public function test_honors_regenerate_number_limit() {
		update_option( 'wp_rest_cache_regenerate_number', 2 );

		for ( $i = 0; $i < 5; $i++ ) {
			$this->seed_cache_needing_regen( "/uri/{$i}", $this->flushed_sentinel(), false, 5 - $i, "k{$i}" );
		}

		Caching::get_instance()->regenerate_expired_caches();

		$this->assertCount( 2, $this->http_calls );
	}

	public function test_skip_filter_returning_true_prevents_request_and_does_not_consume_quota() {
		update_option( 'wp_rest_cache_regenerate_number', 1 );

		$this->seed_cache_needing_regen( '/uri/skip-me', $this->flushed_sentinel(), false, 100, 'skip' );
		$this->seed_cache_needing_regen( '/uri/do-me', $this->flushed_sentinel(), false, 10, 'do' );

		add_filter(
			'wp_rest_cache/skip_cache_regeneration',
			function ( $skip, $result ) {
				return '/uri/skip-me' === $result['request_uri'] ? true : $skip;
			},
			10,
			2
		);

		Caching::get_instance()->regenerate_expired_caches();

		$this->assertCount( 1, $this->http_calls );
		$this->assertStringContainsString( '/uri/do-me', $this->http_calls[0][0] );
	}

	public function test_skip_filter_receives_the_cache_row_as_second_arg() {
		$this->seed_cache_needing_regen( '/uri/inspect', $this->flushed_sentinel(), false, 1, 'inspect' );

		$captured = null;
		add_filter(
			'wp_rest_cache/skip_cache_regeneration',
			function ( $skip, $result ) use ( &$captured ) {
				$captured = $result;
				return true;
			},
			10,
			2
		);

		Caching::get_instance()->regenerate_expired_caches();

		$this->assertIsArray( $captured );
		$this->assertSame( 'inspect', $captured['cache_key'] );
		$this->assertSame( '/uri/inspect', $captured['request_uri'] );
	}

	public function test_built_url_combines_home_url_and_request_uri() {
		$this->seed_cache_needing_regen( '/wp-json/wp/v2/posts', $this->flushed_sentinel(), false, 1, 'urltest' );

		Caching::get_instance()->regenerate_expired_caches();

		$this->assertCount( 1, $this->http_calls );
		$expected_url = \WP_Rest_Cache_Plugin\Includes\Util::get_home_url() . '/wp-json/wp/v2/posts';
		$this->assertSame( $expected_url, $this->http_calls[0][0] );
	}

	public function test_passes_stored_request_headers_to_wp_remote_get() {
		$headers_json = wp_json_encode( [ 'Authorization' => 'Bearer xyz', 'X-Custom' => 'foo' ] );

		$this->insert_cache(
			[
				'cache_key'       => 'headerstest',
				'cache_type'      => 'endpoint',
				'request_uri'     => '/uri/headers',
				'request_headers' => $headers_json,
				'expiration'      => $this->flushed_sentinel(),
				'cache_hits'      => 1,
			]
		);

		Caching::get_instance()->regenerate_expired_caches();

		$this->assertCount( 1, $this->http_calls );
		$this->assertSame(
			[ 'Authorization' => 'Bearer xyz', 'X-Custom' => 'foo' ],
			$this->http_calls[0][1]['headers']
		);
	}

	private function flushed_sentinel() {
		return date_i18n( 'Y-m-d H:i:s', 1 );
	}

	private function seed_cache_needing_regen( $uri, $expiration, $set_transient, $cache_hits = 1, $cache_key = null ) {
		$cache_key = $cache_key ?? md5( $uri . $cache_hits );

		$this->insert_cache(
			[
				'cache_key'   => $cache_key,
				'cache_type'  => 'endpoint',
				'request_uri' => $uri,
				'expiration'  => $expiration,
				'cache_hits'  => $cache_hits,
			]
		);

		if ( $set_transient ) {
			set_transient( Caching::get_instance()->transient_key( $cache_key ), 'payload', HOUR_IN_SECONDS );
		}
	}
}
