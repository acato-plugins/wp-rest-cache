<?php
/**
 * Tests for Endpoint_Api::get_api_cache — the request-time orchestrator that decides
 * whether to skip caching, serve from cache (which ends with `exit;`), or register filters
 * to capture the upcoming response.
 *
 * The cache-hit path ends with `exit;`, which is normally awkward to test. We sidestep that
 * by throwing an exception from a filter that runs BEFORE `exit;` — the exception propagates
 * up through `apply_filters`, out of `get_api_cache`, and into the test, where we catch it.
 * No `@runInSeparateProcess` required, no ~6s subprocess bootstrap per test.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api;
use WP_Rest_Cache_Plugin\Includes\Caching\Caching;

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\API\Endpoint_Api::get_api_cache
 */
class Test_Endpoint_Api_Get_Api_Cache extends Caching_Test_Case {

	const FENCE_MESSAGE = 'TEST FENCE — short-circuit before exit';

	/** @var array<string,mixed> $_SERVER backup. */
	private $server_backup = [];

	public function set_up() {
		parent::set_up();
		$this->server_backup = [
			'REQUEST_URI'    => $_SERVER['REQUEST_URI']    ?? null,
			'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? null,
		];
		// save_cache reads http_response_code() = 200; already pinned to 200 in bootstrap.
		// Reset the relevant options so the get_api_cache flow has predictable state.
		update_option( 'wp_rest_cache_allowed_endpoints', [ 'wp/v2' => [ 'posts' ] ] );
		update_option( 'wp_rest_cache_allowed_request_methods', [ 'GET' ] );
		update_option( 'wp_rest_cache_rest_prefix', 'wp-json' );
	}

	public function tear_down() {
		// Remove the rest-serving filters get_api_cache adds on cache miss so they don't leak
		// into other tests (WP_UnitTestCase's hook restore handles new add_action calls, but
		// the cleanup is cheap and explicit).
		remove_all_filters( 'rest_pre_serve_request', 9999 );
		remove_all_filters( 'rest_pre_echo_response', 1000 );

		foreach ( $this->server_backup as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}
		parent::tear_down();
	}

	// ---------- Easy paths (no exit) ----------

	public function test_get_api_cache_short_circuits_when_skip_caching_returns_true() {
		// Force skip_caching to true via its top-of-tree filter.
		add_filter( 'wp_rest_cache/skip_caching', '__return_true' );

		// Set REQUEST_URI to something get_api_cache can build a key for; doesn't matter
		// since we'll bail before reading the cache.
		$_SERVER['REQUEST_URI']    = '/wp-json/wp/v2/posts/1';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$api = new Endpoint_Api();
		$api->get_api_cache();

		// Hooks that get_api_cache would add on cache miss are NOT registered.
		$this->assertFalse(
			has_filter( 'rest_pre_serve_request', [ $api, 'save_cache_headers' ] ),
			'save_cache_headers must not be hooked when skip_caching is true'
		);
		$this->assertFalse(
			has_filter( 'rest_pre_echo_response', [ $api, 'save_cache' ] ),
			'save_cache must not be hooked when skip_caching is true'
		);
	}

	public function test_get_api_cache_hooks_response_capture_filters_on_cache_miss() {
		// No cache row pre-seeded → get_cache returns false → fall through to hook adds.
		$_SERVER['REQUEST_URI']    = '/wp-json/wp/v2/posts';
		$_SERVER['REQUEST_METHOD'] = 'GET';

		$api = new Endpoint_Api();
		$api->get_api_cache();

		$this->assertSame(
			9999,
			has_filter( 'rest_pre_serve_request', [ $api, 'save_cache_headers' ] ),
			'save_cache_headers must be hooked at the documented priority 9999'
		);
		$this->assertSame(
			1000,
			has_filter( 'rest_pre_echo_response', [ $api, 'save_cache' ] ),
			'save_cache must be hooked at the documented priority 1000'
		);
	}

	// ---------- Cache hit (exit-bearing — sidestepped via throw-from-filter) ----------

	public function test_cache_hit_applies_filter_cache_output_filter_before_emitting() {
		$this->arrange_cache_hit( '/wp-json/wp/v2/posts/1' );

		// Throw from the filter — execution short-circuits before `echo` + `exit`.
		add_filter(
			'wp_rest_cache/filter_cache_output',
			function ( $data ) {
				throw new \DomainException( self::FENCE_MESSAGE . '|filter_cache_output' );
			}
		);

		$api = new Endpoint_Api();
		try {
			$api->get_api_cache();
			$this->fail( 'expected filter_cache_output to be reached before exit' );
		} catch ( \DomainException $e ) {
			$this->assertStringContainsString( 'filter_cache_output', $e->getMessage() );
		}
	}

	public function test_cache_hit_passes_the_stored_data_through_filter_cache_output() {
		$this->arrange_cache_hit( '/wp-json/wp/v2/posts/1' );

		$captured = null;
		add_filter(
			'wp_rest_cache/filter_cache_output',
			function ( $data ) use ( &$captured ) {
				$captured = $data;
				throw new \DomainException( self::FENCE_MESSAGE );
			}
		);

		try {
			( new Endpoint_Api() )->get_api_cache();
		} catch ( \DomainException $e ) {
			// expected
		}

		// The filter receives the inner `data` array from the cached envelope (not the
		// wrapper, not the headers).
		$this->assertNotNull( $captured );
		$this->assertSame( 1, $captured['id'] );
		$this->assertSame( 'post', $captured['type'] );
	}

	public function test_cache_hit_consults_disable_cors_headers_filter() {
		$this->arrange_cache_hit( '/wp-json/wp/v2/posts/1' );

		$consulted = false;
		add_filter(
			'wp_rest_cache/disable_cors_headers',
			function ( $disable ) use ( &$consulted ) {
				$consulted = true;
				throw new \DomainException( self::FENCE_MESSAGE );
			}
		);

		try {
			( new Endpoint_Api() )->get_api_cache();
		} catch ( \DomainException $e ) {
			// expected
		}

		$this->assertTrue( $consulted );
	}

	public function test_cache_hit_applies_pre_output_headers_filter_with_cached_headers() {
		$this->arrange_cache_hit(
			'/wp-json/wp/v2/posts/1',
			[ 'X-Total' => '1', 'Content-Type' => 'application/json' ]
		);

		// Disable the CORS branch so we don't hit `header()` and trigger a "headers already
		// sent" warning before we reach pre_output_headers.
		add_filter( 'wp_rest_cache/disable_cors_headers', '__return_true' );

		$captured_headers = null;
		add_filter(
			'wp_rest_cache/pre_output_headers',
			function ( $headers, $uri ) use ( &$captured_headers ) {
				$captured_headers = $headers;
				throw new \DomainException( self::FENCE_MESSAGE );
			},
			10,
			2
		);

		try {
			( new Endpoint_Api() )->get_api_cache();
		} catch ( \DomainException $e ) {
			// expected
		}

		$this->assertIsArray( $captured_headers );
		$this->assertSame( '1', $captured_headers['X-Total'] );
		// The Endpoint_Api seeds a default `Content-Type: application/json; charset=UTF-8`
		// which gets preserved through save_cache_headers, then served back from cache.
		$this->assertArrayHasKey( 'Content-Type', $captured_headers );
	}

	public function test_cache_hit_passes_request_uri_to_pre_output_headers_filter() {
		$uri = '/wp-json/wp/v2/posts/1';
		$this->arrange_cache_hit( $uri );

		add_filter( 'wp_rest_cache/disable_cors_headers', '__return_true' );

		$captured_uri = null;
		add_filter(
			'wp_rest_cache/pre_output_headers',
			function ( $headers, $passed_uri ) use ( &$captured_uri ) {
				$captured_uri = $passed_uri;
				throw new \DomainException( self::FENCE_MESSAGE );
			},
			10,
			2
		);

		try {
			( new Endpoint_Api() )->get_api_cache();
		} catch ( \DomainException $e ) {
			// expected
		}

		$this->assertSame( $uri, $captured_uri );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cache_hit_invokes_rest_send_cors_headers_when_disable_cors_filter_returns_false() {
		// Path: cache hit → JSON encode succeeds → disable_cors_headers defaults to false →
		// rest_send_cors_headers() runs (the uncovered line we're after) → pre_output_headers
		// filter throws to short-circuit before the foreach + echo + exit. Run in a separate
		// process because rest_send_cors_headers calls header(), which the shared PHPUnit
		// process can't service once output has been emitted.
		$this->arrange_cache_hit( '/wp-json/wp/v2/posts/1' );

		$cors_filter_called = false;
		add_filter(
			'wp_rest_cache/disable_cors_headers',
			function ( $disable ) use ( &$cors_filter_called ) {
				$cors_filter_called = true;
				return false; // explicit — force the rest_send_cors_headers branch
			}
		);

		// Throw from pre_output_headers — the filter fires AFTER rest_send_cors_headers, so
		// by the time we catch this, line 462 has been executed.
		add_filter(
			'wp_rest_cache/pre_output_headers',
			function ( $headers ) {
				throw new \DomainException( self::FENCE_MESSAGE );
			}
		);

		try {
			( new Endpoint_Api() )->get_api_cache();
			$this->fail( 'expected pre_output_headers fence to fire after rest_send_cors_headers' );
		} catch ( \DomainException $e ) {
			$this->assertStringContainsString( self::FENCE_MESSAGE, $e->getMessage() );
		}

		$this->assertTrue( $cors_filter_called );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cache_hit_iterates_and_emits_each_cached_header_through_native_header_call() {
		// Path: cache hit → JSON encode succeeds → disable_cors_headers=true (skip the CORS
		// branch since we're not testing that here) → pre_output_headers returns a generator
		// that yields one header, then throws on the second iteration. That lets us cover
		// the foreach body (sprintf + header()) for the yielded header before short-circuiting
		// just shy of `echo $data; exit;` on lines 482-483.
		$this->arrange_cache_hit(
			'/wp-json/wp/v2/posts/1',
			[ 'X-Total' => '7' ]
		);

		add_filter( 'wp_rest_cache/disable_cors_headers', '__return_true' );

		add_filter(
			'wp_rest_cache/pre_output_headers',
			function ( $headers ) {
				return ( function () {
					yield 'X-Total' => '7';
					throw new \DomainException( self::FENCE_MESSAGE );
				} )();
			}
		);

		try {
			( new Endpoint_Api() )->get_api_cache();
			$this->fail( 'expected the generator to throw after emitting the first header' );
		} catch ( \DomainException $e ) {
			$this->assertStringContainsString( self::FENCE_MESSAGE, $e->getMessage() );
		}
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_cache_hit_echoes_the_json_encoded_payload_just_before_exit() {
		// Path: full cache-hit pipeline, including the final `echo $data;` on line 482.
		// We intercept by registering an output handler with chunk_size=1 that throws as
		// soon as `echo` writes its first byte. That short-circuits before `exit;` on line
		// 483 (which would terminate the subprocess and lose the test result). All earlier
		// branches — filter_cache_output, disable_cors_headers default-false branch,
		// rest_send_cors_headers, pre_output_headers, the header-emit foreach — run for
		// real in this scenario.
		$this->arrange_cache_hit(
			'/wp-json/wp/v2/posts/1',
			[ 'X-Total' => '7' ]
		);

		// Catch the echo via an output handler that throws on first byte written. PHP 8
		// flushes whatever's already in the buffer to the parent stream when an output
		// handler throws, so we nest an outer buffer to swallow that leak — otherwise the
		// JSON payload prints in the middle of the PHPUnit progress dots.
		$base_level = ob_get_level();
		ob_start(); // outer: catches leaked output that escapes the throwing inner handler
		ob_start(
			function ( $chunk ) {
				if ( '' !== $chunk ) {
					throw new \DomainException( self::FENCE_MESSAGE . '|caught-echo' );
				}
				return $chunk;
			},
			1
		);

		try {
			( new Endpoint_Api() )->get_api_cache();
			$this->fail( 'expected ob_start handler to throw when get_api_cache echoes' );
		} catch ( \DomainException $e ) {
			$this->assertStringContainsString( 'caught-echo', $e->getMessage() );
		} finally {
			// Close only OUR buffers — leave PHPUnit's stack intact.
			while ( ob_get_level() > $base_level ) {
				try {
					ob_end_clean();
				} catch ( \Throwable $ignore ) {
					break;
				}
			}
		}
	}

	// ----- helpers -----

	/**
	 * Plant a cache hit for $uri with given response headers (optional). Sets up $_SERVER
	 * so a fresh Endpoint_Api will compute the same cache_key.
	 */
	private function arrange_cache_hit( $uri, array $extra_headers = [] ) {
		$_SERVER['REQUEST_URI']    = $uri;
		$_SERVER['REQUEST_METHOD'] = 'GET';

		// Compute the cache key the same way production will.
		$api = new Endpoint_Api();
		$this->invoke_private( $api, 'build_cache_key', [] );
		$cache_key = $this->get_private_property( $api, 'cache_key' );

		// Endpoint_Api::save_cache stores a `{data, headers}` envelope; mirror that shape
		// here so the cache-hit branch of get_api_cache finds the keys it expects.
		$cached_envelope = [
			'data'    => [ 'id' => 1, 'type' => 'post', 'slug' => 'hello-world' ],
			'headers' => array_merge(
				[ 'Content-Type' => 'application/json; charset=UTF-8' ],
				$extra_headers
			),
		];

		Caching::get_instance()->set_cache(
			$cache_key,
			$cached_envelope,
			'endpoint',
			$uri
		);

		// Sanity: the cache must actually be retrievable now.
		$this->assertNotFalse(
			Caching::get_instance()->get_cache( $cache_key ),
			'precondition: cache must be hit-able after arrange'
		);
	}
}
