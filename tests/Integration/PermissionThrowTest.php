<?php
/**
 * A Throwable during Steadhand's permission check must fail closed, with a fixed message, and must never reach the agent
 * or the change log.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\Registry;
use Steadhand\Capabilities;
use Steadhand\Log\DbLogger;
use Steadhand\Pipeline\Pipeline;

/**
 * Covers the permission stage: a throw from map_meta_cap or user_has_cap, and from an ability's own input or object
 * check, is refused with the fixed steadhand_error text, is logged as an error, and never lets the call run. The
 * exception text never reaches the caller or the change log.
 */
final class PermissionThrowTest extends PostWriteTestCase {

	private const GET_TOOL    = 'steadhand-get-post';
	private const UPDATE_TOOL = 'steadhand-update-post';
	private const FIXED       = 'The permission check could not be completed, so the call was refused. Try again, or check the site error log.';
	private const FIXED_TEXT  = 'The permission check could not be completed, so the call was refused. Try again, or check the site error log. (steadhand_error)';
	private const GENERIC_TXT = 'The ability failed to complete. Try again, or check the site error log. (steadhand_error)';

	/**
	 * Removes the filters this file adds.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'map_meta_cap' );
		remove_all_filters( 'user_has_cap' );
		parent::tearDown();
	}

	/**
	 * Adds a map_meta_cap filter that throws for one capability.
	 *
	 * @param string $cap Capability that makes the filter throw.
	 */
	private function throw_for_meta_cap( string $cap ): void {
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $requested ) use ( $cap ): array {
				if ( $requested === $cap ) {
					throw new \RuntimeException( 'SECRET /var/www/secret-path' );
				}
				return $caps;
			},
			999,
			2
		);
	}

	/**
	 * Adds a user_has_cap filter that throws for one requested capability.
	 *
	 * @param string $cap Requested capability that makes the filter throw.
	 */
	private function throw_for_user_has_cap( string $cap ): void {
		add_filter(
			'user_has_cap',
			static function ( array $allcaps, array $caps, array $args ) use ( $cap ): array {
				unset( $caps );
				if ( ( $args[0] ?? '' ) === $cap ) {
					throw new \RuntimeException( 'SECRET /var/www/secret-path' );
				}
				return $allcaps;
			},
			999,
			3
		);
	}

	/**
	 * Makes an agent that may connect and read, and returns its ID.
	 */
	private function act_as_reader(): int {
		return $this->act_as_agent(
			array(
				Capabilities::CONNECT => true,
				Capabilities::READ    => true,
			)
		);
	}

	/**
	 * A read-only test ability, with the callbacks the plugin builds for it.
	 *
	 * @param PermissionThrowAbility $ability The test ability.
	 * @return array{permission_callback: callable, execute_callback: callable}
	 */
	private function callbacks_for_double( PermissionThrowAbility $ability ): array {
		return ( new Registry( new Pipeline( new DbLogger() ) ) )->args_for( $ability );
	}

	/**
	 * Asserts the one fixed refusal over MCP, and that the exception text is absent.
	 *
	 * @param array<string, mixed> $result Tool result.
	 */
	private function assert_fixed_refusal_over_mcp( array $result ): void {
		$this->assertTrue( $result['isError'] );
		$this->assertSame( self::FIXED_TEXT, $result['content'][0]['text'] );
		$this->assertStringNotContainsString( 'SECRET', (string) $result['content'][0]['text'] );
		$this->assertStringNotContainsString( 'secret-path', (string) $result['content'][0]['text'] );
	}

	/**
	 * The message of a WP_Error returned by a callback, which already ends with its code.
	 *
	 * @param mixed $error A WP_Error.
	 */
	private function with_text( mixed $error ): string {
		$this->assertInstanceOf( \WP_Error::class, $error );

		return $error->get_error_message();
	}

	public function test_a_throw_for_read_post_during_get_post_is_refused_over_mcp_with_the_fixed_text(): void {
		$agent = $this->act_as_agent();
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$this->throw_for_meta_cap( 'read_post' );

		$result = $this->tool_result( self::GET_TOOL, array( 'id' => $post ) );

		$this->assert_fixed_refusal_over_mcp( $result );
		$this->assertSame( 'error', $this->latest_row( $agent )['result'] );
		$this->assertFalse( Pipeline::is_running(), 'The context flag is cleared after the refusal.' );
	}

	public function test_a_throw_for_edit_post_during_update_post_writes_nothing_and_is_refused_over_mcp(): void {
		$agent = $this->act_as_agent();
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$row   = $this->row( $post );
		$this->throw_for_meta_cap( 'edit_post' );

		$result = $this->tool_result(
			self::UPDATE_TOOL,
			array(
				'id'    => $post,
				'title' => 'Changed',
			)
		);

		$this->assert_fixed_refusal_over_mcp( $result );
		$this->assertSame( $row, $this->row( $post ), 'The refused call writes nothing.' );

		$log = $this->latest_row( $agent );
		$this->assertSame( 'steadhand/update-post', $log['ability'] );
		$this->assertSame( 'error', $log['result'] );
		$this->assertSame( self::FIXED, $log['message'] );
		$this->assertStringNotContainsString( 'SECRET', (string) wp_json_encode( $log ) );
		$this->assertFalse( Pipeline::is_running(), 'The context flag is cleared after the refusal.' );
	}

	public function test_a_throw_from_a_user_has_cap_filter_during_update_post_is_refused_over_mcp(): void {
		$agent = $this->act_as_agent();
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$row   = $this->row( $post );
		$this->throw_for_user_has_cap( 'edit_post' );

		$result = $this->tool_result(
			self::UPDATE_TOOL,
			array(
				'id'    => $post,
				'title' => 'Changed',
			)
		);

		$this->assert_fixed_refusal_over_mcp( $result );
		$this->assertSame( $row, $this->row( $post ), 'The refused call writes nothing.' );
		$this->assertSame( self::FIXED, $this->latest_row( $agent )['message'] );
		$this->assertFalse( Pipeline::is_running(), 'The context flag is cleared after the refusal.' );
	}

	public function test_a_throw_from_validate_input_is_refused_by_the_permission_callback_and_never_runs(): void {
		$agent                      = $this->act_as_reader();
		$ability                    = new PermissionThrowAbility();
		$ability->throw_in_validate = true;
		$callbacks                  = $this->callbacks_for_double( $ability );

		$allowed = $callbacks['permission_callback']( array() );

		$this->assertWPError( $allowed, 'A throw must never allow the call.' );
		$this->assertSame( 'steadhand_error', $allowed->get_error_code() );
		$this->assertSame( self::FIXED_TEXT, $this->with_text( $allowed ) );
		$this->assertSame( 'error', $this->latest_row( $agent )['result'] );
		$this->assertSame( self::FIXED, $this->latest_row( $agent )['message'] );
		$this->assertStringNotContainsString( 'SECRET', (string) wp_json_encode( $this->latest_row( $agent ) ) );
		$this->assertFalse( $ability->executed );
		$this->assertFalse( Pipeline::is_running() );
	}

	public function test_a_throw_from_authorize_object_is_refused_by_the_execute_callback_and_never_runs(): void {
		$agent                       = $this->act_as_reader();
		$ability                     = new PermissionThrowAbility();
		$ability->throw_in_authorize = true;
		$callbacks                   = $this->callbacks_for_double( $ability );

		$result = $callbacks['execute_callback']( array() );

		$this->assertWPError( $result, 'A throw must never run the ability.' );
		$this->assertSame( self::FIXED_TEXT, $this->with_text( $result ) );
		$this->assertSame( 'error', $this->latest_row( $agent )['result'] );
		$this->assertSame( self::FIXED, $this->latest_row( $agent )['message'] );
		$this->assertStringNotContainsString( 'SECRET', (string) wp_json_encode( $this->latest_row( $agent ) ) );
		$this->assertFalse( $ability->executed );
		$this->assertFalse( Pipeline::is_running() );
	}

	public function test_a_throw_from_authorize_object_is_refused_by_the_permission_callback_with_the_fixed_text(): void {
		$agent                       = $this->act_as_reader();
		$ability                     = new PermissionThrowAbility();
		$ability->throw_in_authorize = true;
		$callbacks                   = $this->callbacks_for_double( $ability );

		$allowed = $callbacks['permission_callback']( array() );

		$this->assertWPError( $allowed );
		$this->assertSame( self::FIXED_TEXT, $this->with_text( $allowed ) );
		$this->assertSame( self::FIXED, $this->latest_row( $agent )['message'] );
	}

	public function test_a_throw_while_the_input_is_prepared_is_refused_and_logged_without_running(): void {
		$agent     = $this->act_as_reader();
		$ability   = new PermissionThrowAbility();
		$callbacks = $this->callbacks_for_double( $ability );
		// args_for() reads input_schema() at registration, so the switch is set after it. prepare() reads it at call time.
		$ability->throw_in_schema = true;

		$result = $callbacks['execute_callback']( array() );

		$this->assertWPError( $result );
		$this->assertSame( self::FIXED_TEXT, $this->with_text( $result ) );
		$this->assertSame( 'error', $this->latest_row( $agent )['result'] );
		$this->assertSame( self::FIXED, $this->latest_row( $agent )['message'] );
		$this->assertStringNotContainsString( 'SECRET', (string) wp_json_encode( $this->latest_row( $agent ) ) );
		$this->assertFalse( $ability->executed );
		$this->assertFalse( Pipeline::is_running() );
	}

	public function test_a_throw_that_escapes_the_pipeline_is_contained_by_the_registry_closures(): void {
		$this->act_as_reader();
		$ability   = new PermissionThrowAbility();
		$callbacks = $this->callbacks_for_double( $ability );
		// args_for() reads annotations() at registration, so the switch is set after it. At call time, is_read_only_ability()
		// reads annotations() before the pipeline's own catch blocks, so this throw leaves check() and run() altogether.
		$ability->throw_in_annotations = true;

		$allowed = $callbacks['permission_callback']( array() );

		$this->assertWPError( $allowed, 'A throw that escapes the pipeline must not allow the call.' );
		$this->assertSame( 'steadhand_error', $allowed->get_error_code() );
		$this->assertSame( self::GENERIC_TXT, $this->with_text( $allowed ) );

		$result = $callbacks['execute_callback']( array() );

		$this->assertWPError( $result );
		$this->assertSame( self::GENERIC_TXT, $this->with_text( $result ) );
		$this->assertFalse( $ability->executed );
		$this->assertFalse( Pipeline::is_running() );
	}
}
