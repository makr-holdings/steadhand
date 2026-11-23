<?php
/**
 * The shared pipeline: validate → authorize → execute → log.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\GetSiteInfo;
use Steadhand\Abilities\Registry;
use Steadhand\Capabilities;
use Steadhand\Log\Entry;
use Steadhand\Log\Logger;
use Steadhand\Pipeline\Pipeline;

/**
 * Covers SPEC §3.3 and §7 ("logging must not fail open").
 */
final class PipelineTest extends TestCase {

	public function test_successful_call_is_logged_with_agent_and_ability(): void {
		$agent_id = $this->make_agent();
		wp_set_current_user( $agent_id );
		// A successful read is not logged by default. These tests check the entry itself, so they opt in.
		add_filter( 'steadhand_log_read_calls', '__return_true' );
		$logger = new SpyLogger();

		$result = ( new Pipeline( $logger ) )->run( new GetSiteInfo(), array() );

		$this->assertIsArray( $result );
		$this->assertCount( 1, $logger->entries );
		$this->assertSame( $agent_id, $logger->entries[0]->user_id );
		$this->assertSame( 'steadhand/get-site-info', $logger->entries[0]->ability );
		$this->assertSame( Entry::RESULT_OK, $logger->entries[0]->result );
	}

	public function test_refused_call_is_logged_as_blocked_and_does_not_execute(): void {
		wp_set_current_user( $this->make_agent( array( Capabilities::READ => false ) ) );
		$logger = new SpyLogger();

		$result = ( new Pipeline( $logger ) )->run( new GetSiteInfo(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
		$this->assertCount( 1, $logger->entries );
		$this->assertSame( Entry::RESULT_BLOCKED, $logger->entries[0]->result );
	}

	/**
	 * Covers the direct path only: run() refuses the input and logs it as blocked. Over MCP, input that fails the schema
	 * is refused by the adapter before the permission callback, and is not logged. That is the known gap pinned by
	 * PipelineLoggingTest::test_known_gap_schema_refusals_over_mcp_are_not_logged.
	 */
	public function test_invalid_input_is_refused_before_authorization(): void {
		wp_set_current_user( $this->make_agent() );
		$logger = new SpyLogger();

		$result = ( new Pipeline( $logger ) )->run( new GetSiteInfo(), array( 'extra' => 'x' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_invalid_input', $result->get_error_code() );
		$this->assertSame( Entry::RESULT_BLOCKED, $logger->entries[0]->result );
	}

	public function test_result_is_withheld_when_the_log_write_fails(): void {
		wp_set_current_user( $this->make_agent() );
		// A successful read is not logged by default. These tests check the entry itself, so they opt in.
		add_filter( 'steadhand_log_read_calls', '__return_true' );

		$result = ( new Pipeline( new SpyLogger( false ) ) )->run( new GetSiteInfo(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_log_failed', $result->get_error_code() );
	}

	public function test_log_entry_carries_the_application_password_name_as_agent_label(): void {
		$agent_id = $this->make_agent();
		wp_set_current_user( $agent_id );
		list( , $item ) = \WP_Application_Passwords::create_new_application_password( $agent_id, array( 'name' => 'Claude Desktop – Rick' ) );
		// Core records the application password used for the request in this global.
		// The name is fixed by core, so it cannot carry a Steadhand prefix.
		$GLOBALS['wp_rest_application_password_uuid'] = $item['uuid']; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's own global name; see the comment above.
		add_filter( 'steadhand_log_read_calls', '__return_true' );
		$logger = new SpyLogger();

		( new Pipeline( $logger ) )->run( new GetSiteInfo(), array() );
		unset( $GLOBALS['wp_rest_application_password_uuid'] );

		$this->assertSame( 'Claude Desktop – Rick', $logger->entries[0]->agent_label );
	}

	public function test_registered_ability_callbacks_run_through_the_pipeline(): void {
		wp_set_current_user( $this->make_agent() );
		add_filter( 'steadhand_log_read_calls', '__return_true' );
		$logger   = new SpyLogger();
		$registry = new Registry( new Pipeline( $logger ) );

		$args = $registry->args_for( new GetSiteInfo() );
		$this->assertTrue( call_user_func( $args['permission_callback'], array() ) );
		$this->assertCount( 0, $logger->entries, 'An allowed permission check must not log.' );
		call_user_func( $args['execute_callback'], array() );

		$this->assertCount( 1, $logger->entries );
	}

	public function test_refused_permission_callback_logs_exactly_one_blocked_entry(): void {
		wp_set_current_user( $this->make_agent( array( Capabilities::READ => false ) ) );
		$logger = new SpyLogger();
		$args   = ( new Registry( new Pipeline( $logger ) ) )->args_for( new GetSiteInfo() );

		$result = call_user_func( $args['permission_callback'], array() );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
		$this->assertStringEndsWith( ' (steadhand_forbidden)', $result->get_error_message(), 'The registered callback appends the code for MCP.' );
		$this->assertCount( 1, $logger->entries );
		$this->assertSame( Entry::RESULT_BLOCKED, $logger->entries[0]->result );
		$this->assertStringNotContainsString( ' (steadhand_', $logger->entries[0]->message, 'The log keeps the message without the code.' );
	}

	public function test_check_logs_one_blocked_entry_for_a_refused_agent(): void {
		wp_set_current_user( $this->make_agent( array( Capabilities::READ => false ) ) );
		$logger = new SpyLogger();

		$result = ( new Pipeline( $logger ) )->check( new GetSiteInfo(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
		$this->assertCount( 1, $logger->entries );
		$this->assertSame( Entry::RESULT_BLOCKED, $logger->entries[0]->result );
	}

	public function test_check_logs_nothing_for_an_allowed_agent(): void {
		wp_set_current_user( $this->make_agent() );
		$logger = new SpyLogger();

		$this->assertTrue( ( new Pipeline( $logger ) )->check( new GetSiteInfo(), array() ) );
		$this->assertCount( 0, $logger->entries );
	}

	public function test_check_still_returns_the_refusal_when_the_log_write_fails(): void {
		wp_set_current_user( $this->make_agent( array( Capabilities::READ => false ) ) );

		$result = ( new Pipeline( new SpyLogger( false ) ) )->check( new GetSiteInfo(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
	}

	public function test_check_still_returns_the_refusal_when_the_logger_throws(): void {
		wp_set_current_user( $this->make_agent( array( Capabilities::READ => false ) ) );
		$throwing = new class() implements Logger {
			public function log( Entry $entry ): bool {
				unset( $entry );
				throw new \RuntimeException( 'Log store unavailable.' );
			}

			public function begin( Entry $entry ): int {
				unset( $entry );
				throw new \RuntimeException( 'Log store unavailable.' );
			}

			public function complete( int $id, Entry $entry ): bool {
				unset( $id, $entry );
				throw new \RuntimeException( 'Log store unavailable.' );
			}
		};

		$result = ( new Pipeline( $throwing ) )->check( new GetSiteInfo(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
	}

	public function test_administrator_is_refused_even_if_every_steadhand_capability_is_filtered_on(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$grant_all = static fn( array $allcaps ): array => array_merge( $allcaps, array_fill_keys( Capabilities::all(), true ) );
		add_filter( 'user_has_cap', $grant_all );

		try {
			$result = ( new Pipeline( new SpyLogger() ) )->authorize( new GetSiteInfo(), array() );
		} finally {
			remove_filter( 'user_has_cap', $grant_all );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
	}

	public function test_agent_whose_connect_is_denied_is_refused_by_run_even_with_read(): void {
		wp_set_current_user( $this->make_agent( array( Capabilities::CONNECT => false ) ) );
		$logger = new SpyLogger();

		$result = ( new Pipeline( $logger ) )->run( new GetSiteInfo(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
		$this->assertCount( 1, $logger->entries );
		$this->assertSame( Entry::RESULT_BLOCKED, $logger->entries[0]->result );
	}

	public function test_agent_whose_connect_is_denied_is_refused_by_the_ability_even_with_read(): void {
		wp_set_current_user( $this->make_agent( array( Capabilities::CONNECT => false ) ) );
		// Core reports a refused permission check on execute() with _doing_it_wrong(); that is expected here.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$result = wp_get_ability( 'steadhand/get-site-info' )->execute( array() );

		$this->assertWPError( $result );
	}

	/**
	 * Guards the is_agent() check in Pipeline::authorize(): an administrator who holds the agent role and
	 * steadhand_connect (from the role defaults) is refused, and the message says why.
	 */
	public function test_administrator_who_also_holds_the_agent_role_is_refused_by_authorize(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $admin_id )->add_role( \Steadhand\Roles::ROLE );
		wp_set_current_user( $admin_id );

		$result = ( new Pipeline( new SpyLogger() ) )->authorize( new GetSiteInfo(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
		$this->assertStringContainsString( 'dedicated Steadhand agent', $result->get_error_message() );
	}

	/**
	 * Guards the is_agent() check in Pipeline::authorize() against user-level grants on a non-agent.
	 */
	public function test_subscriber_with_user_level_steadhand_grants_is_refused_by_run(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $user_id )->add_cap( Capabilities::CONNECT, true );
		get_userdata( $user_id )->add_cap( Capabilities::READ, true );
		wp_set_current_user( $user_id );
		$logger = new SpyLogger();

		$result = ( new Pipeline( $logger ) )->run( new GetSiteInfo(), array() );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
		$this->assertCount( 1, $logger->entries );
		$this->assertSame( Entry::RESULT_BLOCKED, $logger->entries[0]->result );
	}
}
