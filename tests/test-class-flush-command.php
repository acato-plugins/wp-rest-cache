<?php
/**
 * Tests for the WP-CLI `wp wp-rest-cache flush` command.
 *
 * @package WP_Rest_Cache_Plugin
 */

use WP_Rest_Cache_Plugin\Includes\CLI\Flush_Command;

// WP_CLI's namespaced utility functions (WP_CLI\Utils\get_flag_value) aren't autoloaded by
// composer (no "files" autoload entry). Require the file explicitly so Flush_Command::flush
// can call get_flag_value() at runtime.
require_once dirname( __DIR__ ) . '/vendor/wp-cli/wp-cli/php/utils.php';

/**
 * @covers \WP_Rest_Cache_Plugin\Includes\CLI\Flush_Command
 */
class Test_Flush_Command extends Caching_Test_Case {

	/** @var Flush_Command */
	private $command;

	/** @var \WP_CLI\Loggers\Execution */
	private $logger;

	public function set_up() {
		parent::set_up();
		$this->command = new Flush_Command();

		// Capture WP_CLI's stdout/stderr writes instead of letting them go to the terminal,
		// and make WP_CLI::error throw instead of `exit(1)`. The latter requires reflection
		// because $capture_exit is a private static.
		$this->logger = new \WP_CLI\Loggers\Execution();
		\WP_CLI::set_logger( $this->logger );
		$this->set_wp_cli_capture_exit( true );
	}

	public function tear_down() {
		$this->set_wp_cli_capture_exit( false );
		parent::tear_down();
	}

	// ---------- No object_type: full sweep ----------

	public function test_flush_with_no_args_flushes_every_cache() {
		$a = $this->insert_cache( [ 'object_type' => 'post' ] );
		$b = $this->insert_cache( [ 'object_type' => 'page' ] );

		$this->command->flush( [], [] );

		$this->assertExpired( $a );
		$this->assertExpired( $b );
	}

	public function test_flush_with_explicit_all_arg_also_runs_full_sweep() {
		$cache_id = $this->insert_cache();

		$this->command->flush( [ 'all' ], [] );

		$this->assertExpired( $cache_id );
	}

	public function test_flush_with_delete_flag_marks_every_cache_deleted() {
		$cache_id = $this->insert_cache();

		$this->command->flush( [], [ 'delete' => true ] );

		$this->assertSame( '1', $this->column_value( $cache_id, 'deleted' ) );
	}

	// ---------- With object_type but no --related: per-type sweep ----------

	public function test_flush_with_object_type_targets_only_that_type() {
		// `delete_object_type_caches` flushes non-single caches matching object_type.
		$post_cache = $this->insert_cache( [ 'object_type' => 'post', 'is_single' => 0 ] );
		$page_cache = $this->insert_cache( [ 'object_type' => 'page', 'is_single' => 0 ] );

		$this->command->flush( [ 'post' ], [] );

		$this->assertExpired( $post_cache );
		$this->assertNotExpired( $page_cache );
	}

	public function test_flush_with_object_type_and_delete_marks_them_deleted_not_just_flushed() {
		$cache_id = $this->insert_cache( [ 'object_type' => 'post', 'is_single' => 0 ] );

		$this->command->flush( [ 'post' ], [ 'delete' => true ] );

		$this->assertSame( '1', $this->column_value( $cache_id, 'deleted' ) );
	}

	// ---------- With object_type AND --related: per-object relation flush ----------

	public function test_flush_with_object_type_and_related_flushes_only_that_object_relation() {
		$linked_cache   = $this->insert_cache( [ 'object_type' => 'post' ] );
		$unrelated      = $this->insert_cache( [ 'object_type' => 'post' ] );
		$this->insert_relation( $linked_cache, '42', 'post' );
		$this->insert_relation( $unrelated, '99', 'post' );

		$this->command->flush( [ 'post' ], [ 'related' => 42 ] );

		$this->assertExpired( $linked_cache );
		$this->assertNotExpired( $unrelated );
	}

	// ---------- Invalid combination ----------

	public function test_flush_with_related_but_no_object_type_aborts_with_wp_cli_error() {
		$untouched = $this->insert_cache();

		$this->expectException( \WP_CLI\ExitException::class );

		try {
			$this->command->flush( [], [ 'related' => 42 ] );
		} finally {
			// Verify the error fired before any DB side-effect.
			$this->assertNotExpired( $untouched );
			// And that the user actually got the error message.
			$this->assertStringContainsString(
				'--related is only allowed when an object type is given',
				$this->logger->stderr
			);
		}
	}

	// ---------- Success-message verbs ----------

	public function test_success_message_uses_flushed_verb_when_delete_flag_is_absent() {
		$this->insert_cache();

		$this->command->flush( [], [] );

		$this->assertStringContainsString( 'Flushed', $this->logger->stdout );
		$this->assertStringNotContainsString( 'Deleted ', $this->logger->stdout );
	}

	public function test_success_message_uses_deleted_verb_when_delete_flag_is_set() {
		$this->insert_cache();

		$this->command->flush( [], [ 'delete' => true ] );

		$this->assertStringContainsString( 'Deleted', $this->logger->stdout );
	}

	public function test_success_message_includes_count_of_affected_caches() {
		$this->insert_cache();
		$this->insert_cache();
		$this->insert_cache();

		$this->command->flush( [], [] );

		// The count is from delete_all_caches() — three rows touched.
		$this->assertStringContainsString( '3 caches', $this->logger->stdout );
	}

	// ----- helpers -----

	/**
	 * WP_CLI::$capture_exit is a private static — toggle it via reflection so WP_CLI::error
	 * throws WP_CLI\ExitException instead of calling exit(1) inside the test process.
	 */
	private function set_wp_cli_capture_exit( $value ) {
		$prop = ( new ReflectionClass( 'WP_CLI' ) )->getProperty( 'capture_exit' );
		$prop->setAccessible( true );
		$prop->setValue( null, $value );
	}
}
