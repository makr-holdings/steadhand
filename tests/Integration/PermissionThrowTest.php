<?php
/**
 * A Throwable during Steadhand's permission check must fail closed, with a fixed message, and must never reach the agent
 * or the change log. A refused call is logged as blocked and does not count toward the rate limits.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\Registry;
use Steadhand\Capabilities;
use Steadhand\Log\DbLogger;
use Steadhand\Pipeline\Pipeline;

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test fixtures: base64 is how the ability receives an image.
/**
 * Covers the permission stage: a throw from map_meta_cap, user_has_cap or get_user_metadata, and from an ability's own
 * input or object check, is refused with the fixed steadhand_error text, is logged as blocked, and never lets the call
 * run. The exception text never reaches the caller or the change log. Also covers the write path: a throw while a log
 * entry is built gives the same answer as a logger failure, and a throw after a write ran says the change may have been
 * applied.
 */
final class PermissionThrowTest extends PostWriteTestCase {

	use ImageBytes;

	private const GET_TOOL    = 'steadhand-get-post';
	private const UPDATE_TOOL = 'steadhand-update-post';
	private const UPLOAD_TOOL = 'steadhand-upload-media';
	private const UPDATE_NAME = 'steadhand/update-post';
	private const FIXED       = 'The permission check could not be completed, so the call was refused. Another plugin or the site raised an error during the check. Ask the site administrator.';
	private const FIXED_TEXT  = self::FIXED . ' (steadhand_error)';
	private const GENERIC_TXT = 'The ability failed to complete. Try again, or check the site error log. (steadhand_error)';
	private const WRITE_TXT   = 'The change could not be completed and may have been partly applied. Check the result before you continue. (steadhand_error)';
	private const INCOMPLETE  = 'The change was made, but its outcome could not be recorded in the change log. Check the result before you continue. (steadhand_log_incomplete)';
	private const LOG_FAILED  = 'The action was not performed because it could not be recorded in the change log. (steadhand_log_failed)';
	private const INPUT_TEXT  = 'The input was refused. (steadhand_invalid_input)';

	/**
	 * Removes the filters and the global this file adds.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'map_meta_cap' );
		remove_all_filters( 'user_has_cap' );
		remove_all_filters( 'get_user_metadata' );
		remove_all_filters( 'steadhand_rate_limit_per_minute' );
		remove_all_filters( 'steadhand_log_read_calls' );
		unset( $GLOBALS['wp_rest_application_password_uuid'] ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's own global name; see act_as_app_password().
		parent::tearDown();
	}

	/**
	 * Adds a map_meta_cap filter that throws for one capability.
	 *
	 * @param string $cap        Capability that makes the filter throw.
	 * @param bool   $type_error Whether to throw a PHP \Error (a TypeError) instead of an exception.
	 */
	private function throw_for_meta_cap( string $cap, bool $type_error = false ): void {
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $requested ) use ( $cap, $type_error ): array {
				if ( $requested === $cap ) {
					throw $type_error ? new \TypeError( 'SECRET /var/www/secret-path' ) : new \RuntimeException( 'SECRET /var/www/secret-path' );
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
	 * Adds a get_user_metadata filter that throws on the Nth and every later read of the application-password list. That
	 * list is read only to name the agent in a log entry, so this throws while an entry is being built.
	 *
	 * @param int $first Which read (1 for the first) is the first to throw.
	 */
	private function throw_on_app_password_read_from( int $first ): void {
		$reads = 0;
		add_filter(
			'get_user_metadata',
			static function ( mixed $value, int $object_id, string $meta_key ) use ( $first, &$reads ): mixed {
				unset( $object_id );
				if ( '_application_passwords' === $meta_key && ++$reads >= $first ) {
					throw new \RuntimeException( 'SECRET /var/www/secret-path' );
				}
				return $value;
			},
			999,
			3
		);
	}

	/**
	 * Makes the agent the current user of an application password, the way a request made with one looks to core, so that
	 * the change log names it.
	 *
	 * @param int $agent Agent user ID.
	 */
	private function act_as_app_password( int $agent ): void {
		list( , $item ) = \WP_Application_Passwords::create_new_application_password( $agent, array( 'name' => 'Claude Desktop - Test' ) );
		// Core records the application password used for the request in this global; see PipelineTest.
		$GLOBALS['wp_rest_application_password_uuid'] = $item['uuid']; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's own global name, read by rest_get_authenticated_app_password().
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
	 * The callbacks the plugin registers for one of its own abilities.
	 *
	 * @param string $name Ability name, e.g. steadhand/update-post.
	 * @return array{permission_callback: callable, execute_callback: callable}
	 */
	private function callbacks_for_ability( string $name ): array {
		$registry = ( new \Steadhand\Plugin() )->registry();

		foreach ( $registry->abilities() as $ability ) {
			if ( $ability->name() === $name ) {
				return $registry->args_for( $ability );
			}
		}

		$this->fail( "No ability named $name." );
	}

	/**
	 * The number of attachments in the table, of every status.
	 */
	private function attachment_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A fixed count of a core table.
	}

	/**
	 * Asserts the one refusal over MCP with the given text, and that the exception text is absent.
	 *
	 * @param array<string, mixed> $result   Tool result.
	 * @param string               $expected The text, with its code suffix.
	 */
	private function assert_refusal_over_mcp( array $result, string $expected ): void {
		$this->assertTrue( $result['isError'] );
		$this->assertSame( $expected, $result['content'][0]['text'] );
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

	/**
	 * Asserts that no Steadhand call is still marked as running, writing or being a write once a refusal is returned.
	 */
	private function assert_nothing_in_flight(): void {
		$this->assertFalse( Pipeline::is_running(), 'The context flag is cleared after the refusal.' );
		$this->assertFalse( Pipeline::is_write_call(), 'No write call is marked after the refusal.' );
		$this->assertFalse( Pipeline::is_writing(), 'No write is marked as writing after the refusal.' );
	}

	public function test_a_throw_for_read_post_during_get_post_is_refused_over_mcp_with_the_fixed_text(): void {
		$agent = $this->act_as_agent();
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$this->throw_for_meta_cap( 'read_post' );

		$result = $this->tool_result( self::GET_TOOL, array( 'id' => $post ) );

		$this->assert_refusal_over_mcp( $result, self::FIXED_TEXT );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
		$this->assertSame( self::FIXED, $this->latest_row( $agent )['message'] );
		$this->assert_nothing_in_flight();
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

		$this->assert_refusal_over_mcp( $result, self::FIXED_TEXT );
		$this->assertSame( $row, $this->row( $post ), 'The refused call writes nothing.' );

		$log = $this->latest_row( $agent );
		$this->assertSame( self::UPDATE_NAME, $log['ability'] );
		$this->assertSame( 'blocked', $log['result'] );
		$this->assertSame( self::FIXED, $log['message'] );
		$this->assertStringNotContainsString( 'SECRET', (string) wp_json_encode( $log ) );
		$this->assert_nothing_in_flight();
	}

	public function test_a_php_error_from_map_meta_cap_during_update_post_is_refused_with_the_same_fixed_text(): void {
		$agent = $this->act_as_agent();
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$row   = $this->row( $post );
		$this->throw_for_meta_cap( 'edit_post', true );

		$result = $this->tool_result(
			self::UPDATE_TOOL,
			array(
				'id'    => $post,
				'title' => 'Changed',
			)
		);

		$this->assert_refusal_over_mcp( $result, self::FIXED_TEXT );
		$this->assertSame( $row, $this->row( $post ), 'The refused call writes nothing.' );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
		$this->assertSame( self::FIXED, $this->latest_row( $agent )['message'] );
		$this->assert_nothing_in_flight();
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

		$this->assert_refusal_over_mcp( $result, self::FIXED_TEXT );
		$this->assertSame( $row, $this->row( $post ), 'The refused call writes nothing.' );
		$this->assertSame( self::FIXED, $this->latest_row( $agent )['message'] );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
		$this->assert_nothing_in_flight();
	}

	public function test_a_refused_permission_check_does_not_use_up_the_per_minute_budget(): void {
		$agent = $this->act_as_agent();
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		// One write a minute. If the refused call counted, the second call below would be refused by the rate limit.
		add_filter( 'steadhand_rate_limit_per_minute', static fn(): int => 1 );
		// Armed until the test disarms it below. Removing every map_meta_cap filter would also remove Steadhand's own.
		$armed = true;
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $requested ) use ( &$armed ): array {
				if ( $armed && 'edit_post' === $requested ) {
					throw new \RuntimeException( 'SECRET /var/www/secret-path' );
				}
				return $caps;
			},
			999,
			2
		);
		$counter = new DbLogger();
		$before  = $counter->count_recent( $agent, array( self::UPDATE_NAME ), 60 );

		$refused = $this->tool_result(
			self::UPDATE_TOOL,
			array(
				'id'    => $post,
				'title' => 'First',
			)
		);

		$this->assert_refusal_over_mcp( $refused, self::FIXED_TEXT );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
		$this->assertSame( $before, $counter->count_recent( $agent, array( self::UPDATE_NAME ), 60 ), 'The refused call is not counted by the rate limit.' );

		$armed   = false;
		$allowed = $this->tool_result(
			self::UPDATE_TOOL,
			array(
				'id'    => $post,
				'title' => 'Second',
			)
		);

		$this->assertFalse( $allowed['isError'], 'The second call is within the budget: ' . ( $allowed['content'][0]['text'] ?? '' ) );
		$this->assertSame( 'Second', get_post( $post )->post_title );
	}

	public function test_a_permission_throw_during_upload_media_is_logged_blocked_and_not_counted_against_the_daily_cap(): void {
		$agent = $this->act_as_agent(
			array(
				Capabilities::CONNECT      => true,
				Capabilities::READ         => true,
				Capabilities::MEDIA        => true,
				Capabilities::WRITE_DRAFTS => true,
			)
		);

		$post = $this->admin_post( array( 'post_status' => 'draft' ) );

		// The parent check is the permission-stage call that reaches a meta capability (edit_post, read_post).
		$this->throw_for_meta_cap( 'edit_post' );
		$this->throw_for_meta_cap( 'read_post' );
		$counter      = new DbLogger();
		$daily_before = $counter->count_recent( $agent, array( 'steadhand/upload-media' ), 86400 );
		$attachments  = $this->attachment_count();

		$result = $this->tool_result(
			self::UPLOAD_TOOL,
			array(
				'filename'    => 'refused.png',
				'data_base64' => base64_encode( $this->encode_image( 'png' ) ),
				'parent'      => $post,
			)
		);

		$this->assert_refusal_over_mcp( $result, self::FIXED_TEXT );
		$this->assertSame( $attachments, $this->attachment_count(), 'The refused upload stores no file and no row.' );
		$log = $this->latest_row( $agent );
		$this->assertSame( 'steadhand/upload-media', $log['ability'] );
		$this->assertSame( 'blocked', $log['result'] );
		$this->assertSame( self::FIXED, $log['message'] );
		$this->assertSame( $daily_before, $counter->count_recent( $agent, array( 'steadhand/upload-media' ), 86400 ), 'The refused upload is not counted by the daily cap.' );
		$this->assert_nothing_in_flight();
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
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
		$this->assertSame( self::FIXED, $this->latest_row( $agent )['message'] );
		$this->assertStringNotContainsString( 'SECRET', (string) wp_json_encode( $this->latest_row( $agent ) ) );
		$this->assertFalse( $ability->executed );
		$this->assert_nothing_in_flight();
	}

	public function test_a_throw_from_authorize_object_is_refused_by_the_execute_callback_and_never_runs(): void {
		$agent                       = $this->act_as_reader();
		$ability                     = new PermissionThrowAbility();
		$ability->throw_in_authorize = true;
		$callbacks                   = $this->callbacks_for_double( $ability );

		$result = $callbacks['execute_callback']( array() );

		$this->assertWPError( $result, 'A throw must never run the ability.' );
		$this->assertSame( self::FIXED_TEXT, $this->with_text( $result ) );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
		$this->assertSame( self::FIXED, $this->latest_row( $agent )['message'] );
		$this->assertStringNotContainsString( 'SECRET', (string) wp_json_encode( $this->latest_row( $agent ) ) );
		$this->assertFalse( $ability->executed );
		$this->assert_nothing_in_flight();
	}

	public function test_a_throw_from_authorize_object_is_refused_by_the_permission_callback_with_the_fixed_text(): void {
		$agent                       = $this->act_as_reader();
		$ability                     = new PermissionThrowAbility();
		$ability->throw_in_authorize = true;
		$callbacks                   = $this->callbacks_for_double( $ability );

		$allowed = $callbacks['permission_callback']( array() );

		$this->assertWPError( $allowed );
		$this->assertSame( self::FIXED_TEXT, $this->with_text( $allowed ) );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
		$this->assertSame( self::FIXED, $this->latest_row( $agent )['message'] );
		$this->assert_nothing_in_flight();
	}

	public function test_a_throw_while_the_input_is_prepared_is_refused_by_the_permission_callback_and_logged_blocked(): void {
		$agent     = $this->act_as_reader();
		$ability   = new PermissionThrowAbility();
		$callbacks = $this->callbacks_for_double( $ability );
		// args_for() reads input_schema() at registration, so the switch is set after it. prepare() reads it at call time.
		$ability->throw_in_schema = true;

		$allowed = $callbacks['permission_callback']( array() );

		$this->assertWPError( $allowed );
		$this->assertSame( self::FIXED_TEXT, $this->with_text( $allowed ) );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
		$this->assertSame( self::FIXED, $this->latest_row( $agent )['message'] );
		$this->assertStringNotContainsString( 'SECRET', (string) wp_json_encode( $this->latest_row( $agent ) ) );
		$this->assertFalse( $ability->executed );
		$this->assert_nothing_in_flight();
	}

	public function test_a_throw_while_the_input_is_prepared_is_refused_by_the_execute_callback_and_logged_blocked(): void {
		$agent     = $this->act_as_reader();
		$ability   = new PermissionThrowAbility();
		$callbacks = $this->callbacks_for_double( $ability );

		$ability->throw_in_schema = true;

		$result = $callbacks['execute_callback']( array() );

		$this->assertWPError( $result );
		$this->assertSame( self::FIXED_TEXT, $this->with_text( $result ) );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
		$this->assertSame( self::FIXED, $this->latest_row( $agent )['message'] );
		$this->assertStringNotContainsString( 'SECRET', (string) wp_json_encode( $this->latest_row( $agent ) ) );
		$this->assertFalse( $ability->executed );
		$this->assert_nothing_in_flight();
	}

	public function test_a_validate_input_that_returns_false_is_refused_by_the_permission_callback_and_never_runs(): void {
		$agent     = $this->act_as_reader();
		$ability   = new PermissionThrowAbility();
		$callbacks = $this->callbacks_for_double( $ability );

		$ability->return_false_from_validate = true;

		$allowed = $callbacks['permission_callback']( array() );

		$this->assertWPError( $allowed, 'A false from validate_input() must never allow the call.' );
		$this->assertSame( 'steadhand_invalid_input', $allowed->get_error_code() );
		$this->assertSame( 400, $allowed->get_error_data()['status'] );
		$this->assertSame( self::INPUT_TEXT, $this->with_text( $allowed ) );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
		$this->assertSame( 'The input was refused.', $this->latest_row( $agent )['message'] );
		$this->assertFalse( $ability->executed );
		$this->assert_nothing_in_flight();
	}

	public function test_a_validate_input_that_returns_false_is_refused_by_the_execute_callback_and_never_runs(): void {
		$agent     = $this->act_as_reader();
		$ability   = new PermissionThrowAbility();
		$callbacks = $this->callbacks_for_double( $ability );

		$ability->return_false_from_validate = true;

		$result = $callbacks['execute_callback']( array() );

		$this->assertWPError( $result, 'A false from validate_input() must never run the ability.' );
		$this->assertSame( 'steadhand_invalid_input', $result->get_error_code() );
		$this->assertSame( self::INPUT_TEXT, $this->with_text( $result ) );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
		$this->assertFalse( $ability->executed );
		$this->assert_nothing_in_flight();
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

		// The annotations cannot be read, so the call cannot be shown to be a read. The execute callback therefore gets the
		// write text, which says the change may have been applied. Nothing ran, so the text is stricter than needed.
		$result = $callbacks['execute_callback']( array() );

		$this->assertWPError( $result );
		$this->assertSame( self::WRITE_TXT, $this->with_text( $result ) );
		$this->assertFalse( $ability->executed );
		$this->assert_nothing_in_flight();
	}

	public function test_a_throw_on_the_second_evaluation_of_edit_post_is_refused_by_the_permission_check(): void {
		$agent = $this->act_as_agent();
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$row   = $this->row( $post );
		$args  = array(
			'id'    => $post,
			'title' => 'Changed',
		);
		// Observed: one permission check evaluates edit_post more than once (see the next test), so the second evaluation is
		// already inside the permission check. The permission callback returns the fixed text (steadhand_error, 500). Nothing
		// runs, nothing is written, and the refusal is logged as blocked.
		$evaluations = 0;
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $requested ) use ( &$evaluations ): array {
				if ( 'edit_post' === $requested && 2 === ++$evaluations ) {
					throw new \RuntimeException( 'SECRET /var/www/secret-path' );
				}
				return $caps;
			},
			999,
			2
		);
		$callbacks = $this->callbacks_for_ability( self::UPDATE_NAME );

		$allowed = $callbacks['permission_callback']( $args );

		$this->assertWPError( $allowed );
		$this->assertSame( self::FIXED_TEXT, $this->with_text( $allowed ) );
		$this->assertSame( $row, $this->row( $post ), 'Nothing is written.' );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
		$this->assertSame( self::FIXED, $this->latest_row( $agent )['message'] );
		$this->assertStringNotContainsString( 'SECRET', (string) wp_json_encode( $this->latest_row( $agent ) ) );
		$this->assert_nothing_in_flight();
	}

	public function test_a_throw_on_the_first_evaluation_after_a_passing_permission_check_is_refused_by_the_execute_callback(): void {
		$agent = $this->act_as_agent();
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$row   = $this->row( $post );
		$args  = array(
			'id'    => $post,
			'title' => 'Changed',
		);

		$evaluations = 0;
		$throw_after = PHP_INT_MAX;
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $requested ) use ( &$evaluations, &$throw_after ): array {
				if ( 'edit_post' === $requested && ++$evaluations > $throw_after ) {
					throw new \RuntimeException( 'SECRET /var/www/secret-path' );
				}
				return $caps;
			},
			999,
			2
		);
		$callbacks = $this->callbacks_for_ability( self::UPDATE_NAME );

		$allowed = $callbacks['permission_callback']( $args );
		$this->assertTrue( $allowed, 'The whole permission check passes before the throw.' );
		// Every evaluation so far was inside the permission check. The next one is the execute stage's own check.
		$throw_after = $evaluations;

		$result = $callbacks['execute_callback']( $args );

		$this->assertWPError( $result );
		$this->assertSame( self::FIXED_TEXT, $this->with_text( $result ) );
		$this->assertSame( $row, $this->row( $post ), 'Nothing is written.' );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
		$this->assertSame( self::FIXED, $this->latest_row( $agent )['message'] );
		$this->assertStringNotContainsString( 'SECRET', (string) wp_json_encode( $this->latest_row( $agent ) ) );
		$this->assert_nothing_in_flight();
	}

	public function test_a_read_refused_by_the_permission_check_gives_the_fixed_text_even_when_its_log_entry_cannot_be_built(): void {
		$agent = $this->act_as_agent();
		$this->act_as_app_password( $agent );
		$post = $this->admin_post( array( 'post_status' => 'draft' ) );
		$this->throw_for_meta_cap( 'read_post' );
		// The refusal is logged through agent_label(), which reads the application-password list. That read throws.
		$this->throw_on_app_password_read_from( 1 );

		$result = $this->tool_result( self::GET_TOOL, array( 'id' => $post ) );

		// The write-side answer is the fixed permission text: check() ignores the logger's result for a refusal.
		$this->assert_refusal_over_mcp( $result, self::FIXED_TEXT );
		$this->assert_nothing_in_flight();
	}

	public function test_a_successful_read_whose_log_entry_cannot_be_built_is_refused_as_a_log_failure(): void {
		$agent = $this->act_as_agent();
		$this->act_as_app_password( $agent );
		$post = $this->admin_post( array( 'post_status' => 'publish' ) );
		add_filter( 'steadhand_log_read_calls', '__return_true' );
		$this->throw_on_app_password_read_from( 1 );

		$result = $this->tool_result( self::GET_TOOL, array( 'id' => $post ) );

		// The read ran, and its entry could not be built, so the logging failure is returned, as for a logger that throws.
		$this->assert_refusal_over_mcp( $result, self::LOG_FAILED );
		$this->assert_nothing_in_flight();
	}

	public function test_a_throw_while_the_write_is_reserved_means_the_write_does_not_run(): void {
		$agent = $this->act_as_agent();
		$this->act_as_app_password( $agent );
		$post = $this->admin_post( array( 'post_status' => 'draft' ) );
		$row  = $this->row( $post );
		// The first read of the application-password list is the reservation row's entry: the write is not performed.
		$this->throw_on_app_password_read_from( 1 );

		$result = $this->tool_result(
			self::UPDATE_TOOL,
			array(
				'id'    => $post,
				'title' => 'Changed',
			)
		);

		$this->assert_refusal_over_mcp( $result, self::LOG_FAILED );
		$this->assertSame( $row, $this->row( $post ), 'The write is not performed without its reservation.' );
		$this->assert_nothing_in_flight();
	}

	public function test_a_throw_after_the_write_while_its_completion_entry_is_built_says_the_change_may_be_applied(): void {
		$agent = $this->act_as_agent();
		$this->act_as_app_password( $agent );
		$post = $this->admin_post( array( 'post_status' => 'draft' ) );
		// Read 1 builds the reservation row, which succeeds. Read 2 builds the completion entry, after the title changed.
		$this->throw_on_app_password_read_from( 2 );

		$result = $this->tool_result(
			self::UPDATE_TOOL,
			array(
				'id'    => $post,
				'title' => 'Changed',
			)
		);

		$this->assertTrue( $result['isError'] );
		$this->assertSame( self::INCOMPLETE, $result['content'][0]['text'] );
		$this->assertStringNotContainsString( 'Try again', (string) $result['content'][0]['text'] );
		$this->assertStringNotContainsString( 'SECRET', (string) $result['content'][0]['text'] );
		$this->assertSame( 'Changed', get_post( $post )->post_title, 'The change was made.' );
		$this->assertSame( 'error', $this->latest_row( $agent )['result'], 'The reserved row is left without an outcome.' );
		$this->assert_nothing_in_flight();
	}
}
