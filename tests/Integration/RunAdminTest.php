<?php
/**
 * Pipeline::run_admin() on its own, with a stub AdminAction: who may run it, what each check refuses, and that every refusal is
 * stored as blocked and nothing runs. The stub keeps these tests on the pipeline's own checks, not on UndoEntry's.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\PostReader;
use Steadhand\Log\DbLogger;
use Steadhand\Log\Entry;
use Steadhand\Log\Schema;
use Steadhand\Pipeline\Pipeline;
use Steadhand\Roles;

/**
 * Covers Pipeline::run_admin() with the stub: the caller rule, the input rule, the object rule, the throw cases and the logging.
 */
final class RunAdminTest extends PostWriteTestCase {

	/** The fixed refusal for a caller who is not an administrator without an agent role. */
	private const ADMIN_ONLY = 'Only an administrator who does not hold the Steadhand agent role may do this. Nothing was changed.';

	/**
	 * The filters this file adds, removed in tearDown.
	 *
	 * @var array<int, array{0: string, 1: \Closure, 2: int}>
	 */
	private array $added_list = array();

	protected function tearDown(): void {
		foreach ( $this->added_list as $added ) {
			remove_filter( $added[0], $added[1], $added[2] );
		}
		$this->added_list = array();

		parent::tearDown();
	}

	private function filter( string $hook, \Closure $callback, int $priority = 10, int $args = 1 ): void {
		add_filter( $hook, $callback, $priority, $args );
		$this->added_list[] = array( $hook, $callback, $priority );
	}

	private function pipeline(): Pipeline {
		return new Pipeline( new DbLogger() );
	}

	/**
	 * Rows the stub has stored as blocked. The table name comes from Schema, never from input.
	 */
	private function blocked_rows(): int {
		global $wpdb;

		$sql = 'SELECT COUNT(*) FROM `' . Schema::table() . '` WHERE ability = %s AND result = %s';

		return (int) $wpdb->get_var( $wpdb->prepare( $sql, 'steadhand/zz-admin-stub', Entry::RESULT_BLOCKED ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read of the change log; the table name is Schema's constant.
	}

	/**
	 * Runs the stub through run_admin() as the current user, then asserts it was refused, that it did not run, and that exactly one
	 * blocked row was stored for the refusal.
	 *
	 * @param AdminStubAction      $stub    The stub.
	 * @param array<string, mixed> $input   The input.
	 * @param string               $why     What the test is about, for the failure message.
	 * @param string|null          $code    The expected error code, or null to accept any.
	 * @param string|null          $message The expected message, or null to skip the message check.
	 */
	private function assert_refused_and_logged( AdminStubAction $stub, array $input, string $why, ?string $code, ?string $message ): void {
		$before = $this->blocked_rows();

		$result = $this->pipeline()->run_admin( $stub, $input );
		$this->assert_no_bracket( "After a refusal: $why" );

		$this->assertWPError( $result, $why );
		if ( null !== $code ) {
			$this->assertSame( $code, $result->get_error_code(), $why );
		}
		if ( null !== $message ) {
			$this->assertSame( $message, $result->get_error_message(), $why );
		}
		$this->assertStringNotContainsString( 'SECRET', $result->get_error_message(), $why );
		$this->assertFalse( $stub->executed, "Nothing may run: $why" );
		$this->assertSame( $before + 1, $this->blocked_rows(), "The refusal must be stored as blocked: $why" );
	}

	/**
	 * An administrator who also holds the agent role, created fresh so no cached user carries the role into another test.
	 */
	private function admin_with_agent_role(): int {
		$id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $id )->add_role( Roles::ROLE );

		return $id;
	}

	/**
	 * Asserts that no Steadhand call bracket is open: run_admin() has returned, or has refused, or its execute() has thrown.
	 *
	 * @param string $when What has just happened, for the failure message.
	 */
	private function assert_no_bracket( string $when ): void {
		$this->assertFalse( Pipeline::is_running(), "is_running() must be false. $when" );
		$this->assertFalse( Pipeline::is_write_call(), "is_write_call() must be false. $when" );
		$this->assertFalse( Pipeline::is_writing(), "is_writing() must be false. $when" );
	}

	public function test_an_administrator_runs_the_stub_and_it_is_stored(): void {
		$stub  = new AdminStubAction();
		$admin = $this->admin_id();
		wp_set_current_user( $admin );

		$result = $this->pipeline()->run_admin( $stub, array( 'id' => 1 ) );

		$this->assertIsArray( $result, 'The control case: an administrator must be allowed.' );
		$this->assertTrue( $stub->executed );
		$this->assert_no_bracket( 'After a call that ran.' );

		$row = $this->latest_row( $admin );
		$this->assertSame( 'steadhand/zz-admin-stub', $row['ability'] );
		$this->assertSame( Entry::RESULT_OK, $row['result'] );
		$this->assertSame( (string) $admin, (string) $row['user_id'] );
	}

	public function test_an_execute_that_throws_is_logged_as_an_error_and_leaves_no_bracket_open(): void {
		$stub                   = new AdminStubAction();
		$stub->throw_in_execute = true;
		$admin                  = $this->admin_id();
		wp_set_current_user( $admin );

		$result = $this->pipeline()->run_admin( $stub, array( 'id' => 1 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_error', $result->get_error_code() );
		$this->assertStringNotContainsString( 'SECRET', $result->get_error_message() );
		$this->assertTrue( $stub->executed, 'The control case: execute() must have been reached.' );
		$this->assert_no_bracket( 'After an execute() that threw.' );
		$this->assertSame( Entry::RESULT_ERROR, $this->latest_row( $admin )['result'] );
	}

	public function test_run_admin_refuses_every_caller_who_is_not_an_administrator_without_the_agent_role(): void {
		$callers = array(
			'an editor'                                 => self::factory()->user->create( array( 'role' => 'editor' ) ),
			'a subscriber'                              => self::factory()->user->create( array( 'role' => 'subscriber' ) ),
			'a logged-out visitor (user 0)'             => 0,
			'a dedicated agent'                         => $this->make_agent(),
			'an administrator who holds the agent role' => $this->admin_with_agent_role(),
		);

		foreach ( $callers as $who => $user_id ) {
			wp_set_current_user( $user_id );
			$this->assert_refused_and_logged( new AdminStubAction(), array( 'id' => 1 ), "$who must be refused.", 'steadhand_forbidden', self::ADMIN_ONLY );
		}
	}

	public function test_an_authorize_check_that_throws_is_refused_and_nothing_runs(): void {
		$stub                     = new AdminStubAction();
		$stub->throw_in_authorize = true;
		wp_set_current_user( $this->admin_id() );

		$this->assert_refused_and_logged( $stub, array( 'id' => 1 ), 'authorize_object() throws.', 'steadhand_error', null );
	}

	public function test_an_input_rule_that_throws_is_refused_and_nothing_runs(): void {
		$stub                    = new AdminStubAction();
		$stub->throw_in_validate = true;
		wp_set_current_user( $this->admin_id() );

		$this->assert_refused_and_logged( $stub, array( 'id' => 1 ), 'validate_input() throws.', 'steadhand_error', null );
	}

	public function test_an_input_rule_that_returns_false_is_refused_and_nothing_runs(): void {
		$stub                             = new AdminStubAction();
		$stub->return_false_from_validate = true;
		wp_set_current_user( $this->admin_id() );

		$this->assert_refused_and_logged( $stub, array( 'id' => 1 ), 'validate_input() returns false.', 'steadhand_invalid_input', null );
	}

	public function test_an_id_above_the_schema_maximum_is_refused_and_nothing_runs(): void {
		$stub = new AdminStubAction();
		wp_set_current_user( $this->admin_id() );

		$this->assert_refused_and_logged( $stub, array( 'id' => PostReader::MAX_ID + 1 ), 'The ID is above the maximum.', 'steadhand_invalid_input', null );
	}

	public function test_an_extra_property_is_refused_by_the_schema_and_nothing_runs(): void {
		$stub = new AdminStubAction();
		wp_set_current_user( $this->admin_id() );

		$this->assert_refused_and_logged(
			$stub,
			array(
				'id'    => 1,
				'extra' => 'x',
			),
			'An extra property is not in the schema.',
			'steadhand_invalid_input',
			null
		);
	}

	public function test_a_capability_filter_that_throws_is_refused_without_a_fatal_and_nothing_is_written(): void {
		$stub = new AdminStubAction();
		wp_set_current_user( $this->admin_id() );

		$this->filter(
			'user_has_cap',
			static function ( array $allcaps, array $caps ): array {
				if ( in_array( 'manage_options', $caps, true ) ) {
					throw new \RuntimeException( 'SECRET /var/www/secret-path' );
				}

				return $allcaps;
			},
			10,
			4
		);

		$this->assert_refused_and_logged( $stub, array( 'id' => 1 ), 'The caller check throws.', 'steadhand_error', null );
	}
}
