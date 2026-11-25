<?php
/**
 * Disable, enable and delete of an agent on the Agents screen. The negative tests come first: every refusal must leave the agent, its
 * passwords, its capabilities, its content and its change-log rows exactly as they were.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Admin\AgentsHandler;
use Steadhand\Admin\AgentsScreen;
use Steadhand\Capabilities;
use Steadhand\Plugin;
use Steadhand\Roles;

/**
 * Covers SPEC §4.1 for disable, enable and delete, and the Status column and the Disable or delete section of the screen.
 */
final class AgentsDisableDeleteTest extends TestCase {

	use LogRows;

	/**
	 * Filters and actions this test added, removed in tearDown.
	 *
	 * @var array<int, array{0: string, 1: callable, 2: int}>
	 */
	private array $added = array();

	/**
	 * The handler under test.
	 *
	 * @var AgentsHandler
	 */
	private AgentsHandler $handler;

	/**
	 * The screen under test.
	 *
	 * @var AgentsScreen
	 */
	private AgentsScreen $screen;

	protected function setUp(): void {
		parent::setUp();

		$this->handler = new AgentsHandler( ( new Plugin() )->registry() );
		$this->screen  = new AgentsScreen( $this->handler );

		// A test double: a refusal throws instead of ending the test run, and the test catches it.
		$thrower = static function ( $message ): void {
			throw new \WPDieException( (string) $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double; the message is never printed.
		};
		$this->on( 'wp_die_handler', static fn() => $thrower, PHP_INT_MAX );
		$this->on( 'wp_is_application_passwords_available', '__return_true' );

		$_POST                     = array();
		$_GET                      = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	protected function tearDown(): void {
		foreach ( $this->added as $added ) {
			remove_filter( $added[0], $added[1], $added[2] );
		}
		$this->added = array();

		$_POST                     = array();
		$_GET                      = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Adds a filter or action this test removes again in tearDown.
	 *
	 * @param string   $hook     Hook.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 */
	private function on( string $hook, callable $callback, int $priority = 10 ): void {
		add_filter( $hook, $callback, $priority, 10 );
		$this->added[] = array( $hook, $callback, $priority );
	}

	/**
	 * A dedicated agent with the given number of Application Passwords, and the two capabilities a test checks: steadhand_read on and
	 * steadhand_write_drafts off, stored explicitly.
	 *
	 * @param int $passwords Number of Application Passwords to create.
	 * @return int The user ID.
	 */
	private function agent( int $passwords = 1 ): int {
		$id = self::factory()->user->create(
			array(
				'role'       => Roles::ROLE,
				'user_login' => 'agent' . wp_rand( 1000, 999999 ),
			)
		);

		$user = get_userdata( $id );
		$user->add_cap( Capabilities::CONNECT, true );
		$user->add_cap( Capabilities::READ, true );
		$user->add_cap( Capabilities::WRITE_DRAFTS, false );

		for ( $i = 1; $i <= $passwords; $i++ ) {
			\WP_Application_Passwords::create_new_application_password( $id, array( 'name' => 'Password ' . $i ) );
		}

		return $id;
	}

	/**
	 * A user with one role.
	 *
	 * @param string $role The role.
	 * @return int The user ID.
	 */
	private function user_with_role( string $role ): int {
		return self::factory()->user->create( array( 'role' => $role ) );
	}

	/**
	 * Makes the given user the current user.
	 *
	 * @param int $id The user.
	 */
	private function as_user( int $id ): void {
		wp_set_current_user( $id );
	}

	/**
	 * The nonce for an action on a target.
	 *
	 * @param string $action One of the AgentsHandler::ACTION_* values.
	 * @param int    $target The target user.
	 * @return string
	 */
	private function nonce( string $action, int $target ): string {
		return wp_create_nonce( AgentsHandler::nonce_action( $action, $target ) );
	}

	/**
	 * What a refusal must leave alone: whether the user exists, its roles and stored capabilities, and its passwords.
	 *
	 * @param int $id The user.
	 * @return array<string, mixed>
	 */
	private function state( int $id ): array {
		clean_user_cache( $id );
		$user = get_userdata( $id );

		return array(
			'exists'    => $user instanceof \WP_User && $user->exists(),
			'roles'     => $user instanceof \WP_User ? $user->roles : null,
			'caps'      => $user instanceof \WP_User ? $user->caps : null,
			'passwords' => \WP_Application_Passwords::get_user_application_passwords( $id ),
		);
	}

	/**
	 * Records each call of an action: the arguments of every call, in order.
	 *
	 * @param string $hook The action.
	 * @return \ArrayObject<int, array<int, mixed>>
	 */
	private function record( string $hook ): \ArrayObject {
		$seen = new \ArrayObject();
		$this->on(
			$hook,
			static function ( ...$args ) use ( $seen ): void {
				$seen[] = $args;
			}
		);

		return $seen;
	}

	/**
	 * The rendered Agents screen for the current user and $_GET.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		try {
			$this->screen->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	public function test_disable_stores_connect_false_revokes_every_password_and_leaves_the_other_capabilities(): void {
		$agent = $this->agent( 2 );
		$this->as_user( $this->user_with_role( 'administrator' ) );

		$result = $this->handler->disable( $this->nonce( AgentsHandler::ACTION_DISABLE, $agent ), $agent );

		$this->assertSame( 'agent_disabled', $result['code'] );
		$this->assertTrue( $result['ok'] );
		clean_user_cache( $agent );
		$user = get_userdata( $agent );
		$this->assertFalse( $user->caps[ Capabilities::CONNECT ] ?? null, 'steadhand_connect must be stored as an explicit false.' );
		$this->assertSame( array(), \WP_Application_Passwords::get_user_application_passwords( $agent ) );
		$this->assertTrue( $user->caps[ Capabilities::READ ] ?? null, 'Other capabilities are left as they were.' );
		$this->assertFalse( $user->caps[ Capabilities::WRITE_DRAFTS ] ?? null );
		$this->assertSame( array( Roles::ROLE ), array_values( $user->roles ) );
	}

	public function test_disable_fires_the_hook_once_with_the_agent_after_the_check(): void {
		$agent = $this->agent( 1 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$seen = $this->record( 'steadhand_agent_disabled' );

		$this->handler->disable( $this->nonce( AgentsHandler::ACTION_DISABLE, $agent ), $agent );

		$this->assertCount( 1, $seen );
		$this->assertInstanceOf( \WP_User::class, $seen[0][0] );
		$this->assertSame( $agent, $seen[0][0]->ID );
	}

	public function test_a_second_disable_is_harmless(): void {
		$agent = $this->agent( 1 );
		$this->as_user( $this->user_with_role( 'administrator' ) );

		$first  = $this->handler->disable( $this->nonce( AgentsHandler::ACTION_DISABLE, $agent ), $agent );
		$second = $this->handler->disable( $this->nonce( AgentsHandler::ACTION_DISABLE, $agent ), $agent );

		$this->assertSame( 'agent_disabled', $first['code'] );
		$this->assertSame( 'agent_disabled', $second['code'] );
		$this->assertSame( array(), \WP_Application_Passwords::get_user_application_passwords( $agent ) );
	}

	public function test_disable_reports_failure_when_the_stored_value_does_not_read_back(): void {
		$agent = $this->agent( 1 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		// The write of the capabilities is dropped, so steadhand_connect is never stored as false.
		$this->on(
			'update_user_metadata',
			static fn( $check, $object_id, $meta_key ) => 'wp_capabilities' === $meta_key ? true : $check,
			10
		);
		$seen = $this->record( 'steadhand_agent_disabled' );

		$result = $this->handler->disable( $this->nonce( AgentsHandler::ACTION_DISABLE, $agent ), $agent );

		$this->assertSame( 'disable_failed', $result['code'] );
		$this->assertFalse( $result['ok'] );
		$this->assertCount( 0, $seen, 'No hook fires for a disable that was not verified.' );
		$this->assertSame( array(), \WP_Application_Passwords::get_user_application_passwords( $agent ), 'The passwords were still revoked.' );
	}

	public function test_enable_stores_connect_true_creates_no_password_and_fires_the_hook(): void {
		$agent = $this->agent( 0 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$this->handler->disable( $this->nonce( AgentsHandler::ACTION_DISABLE, $agent ), $agent );
		$seen = $this->record( 'steadhand_agent_enabled' );

		$result = $this->handler->enable( $this->nonce( AgentsHandler::ACTION_ENABLE, $agent ), $agent );

		$this->assertSame( 'agent_enabled', $result['code'] );
		clean_user_cache( $agent );
		$this->assertTrue( get_userdata( $agent )->caps[ Capabilities::CONNECT ] ?? null );
		$this->assertSame( array(), \WP_Application_Passwords::get_user_application_passwords( $agent ), 'Enable creates no password.' );
		$this->assertCount( 1, $seen );
		$this->assertSame( $agent, $seen[0][0]->ID );
	}

	public function test_enable_reports_failure_when_the_stored_value_does_not_read_back(): void {
		$agent = $this->agent( 0 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$this->handler->disable( $this->nonce( AgentsHandler::ACTION_DISABLE, $agent ), $agent );
		$this->on(
			'update_user_metadata',
			static fn( $check, $object_id, $meta_key ) => 'wp_capabilities' === $meta_key ? true : $check,
			10
		);
		$seen = $this->record( 'steadhand_agent_enabled' );

		$result = $this->handler->enable( $this->nonce( AgentsHandler::ACTION_ENABLE, $agent ), $agent );

		$this->assertSame( 'enable_failed', $result['code'] );
		$this->assertCount( 0, $seen );
	}

	public function test_delete_removes_the_user_keeps_the_change_log_and_fires_the_hook_with_id_and_login(): void {
		$agent = $this->agent( 1 );
		$login = get_userdata( $agent )->user_login;
		$row   = $this->insert_row(
			array(
				'user_id' => $agent,
				'message' => 'a change made before the delete',
			)
		);
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$seen = $this->record( 'steadhand_agent_deleted' );

		$result = $this->handler->delete( $this->nonce( AgentsHandler::ACTION_DELETE, $agent ), $agent, true );

		$this->assertSame( 'agent_deleted', $result['code'] );
		$this->assertFalse( $this->state( $agent )['exists'], 'The user must be gone.' );
		$this->assertTrue( $this->row_exists( $row ), 'The change-log row for the deleted user is kept.' );
		$this->assertSame( 1, $this->count_rows( $agent ) );
		$this->assertCount( 1, $seen );
		$this->assertSame( $agent, $seen[0][0] );
		$this->assertSame( $login, $seen[0][1] );
	}

	public function test_delete_reports_failure_and_keeps_the_user_when_the_delete_does_not_happen(): void {
		$agent = $this->agent( 1 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		// Core fires delete_user before it removes the row, so a listener that throws there stops the delete with the user still in place.
		$this->on(
			'delete_user',
			static function (): void {
				throw new \RuntimeException( 'listener' );
			}
		);
		$seen = $this->record( 'steadhand_agent_deleted' );

		$result = $this->handler->delete( $this->nonce( AgentsHandler::ACTION_DELETE, $agent ), $agent, true );

		$this->assertSame( 'delete_failed', $result['code'] );
		$this->assertTrue( $this->state( $agent )['exists'] );
		$this->assertSame( array(), $this->state( $agent )['passwords'], 'The passwords were revoked before the delete was tried.' );
		$this->assertCount( 0, $seen );
	}

	public function test_delete_without_confirmation_is_refused_and_changes_nothing(): void {
		$agent = $this->agent( 1 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$before = $this->state( $agent );

		$result = $this->handler->delete( $this->nonce( AgentsHandler::ACTION_DELETE, $agent ), $agent, false );

		$this->assertSame( 'delete_not_confirmed', $result['code'] );
		$this->assertSame( $before, $this->state( $agent ) );
	}

	public function test_delete_of_an_agent_with_attributed_content_is_refused_and_changes_nothing(): void {
		$agent = $this->agent( 1 );
		$post  = self::factory()->post->create(
			array(
				'post_author' => $agent,
				'post_status' => 'draft',
			)
		);
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$before = $this->state( $agent );

		$result = $this->handler->delete( $this->nonce( AgentsHandler::ACTION_DELETE, $agent ), $agent, true );

		$this->assertSame( 'delete_has_content', $result['code'] );
		$this->assertNotNull( get_post( $post ), 'The draft must still exist.' );
		$this->assertSame( $before, $this->state( $agent ), 'The user and its passwords are unchanged.' );
	}

	public function test_delete_by_a_caller_without_delete_user_is_refused_and_changes_nothing(): void {
		$agent = $this->agent( 1 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$this->on(
			'map_meta_cap',
			static fn( $caps, $cap ) => 'delete_user' === $cap ? array( 'do_not_allow' ) : $caps,
			10
		);
		$before = $this->state( $agent );

		$this->expectException( \WPDieException::class );
		try {
			$this->handler->delete( $this->nonce( AgentsHandler::ACTION_DELETE, $agent ), $agent, true );
		} finally {
			$this->assertSame( $before, $this->state( $agent ) );
		}
	}

	public function test_a_listener_that_throws_on_the_deleted_hook_does_not_change_the_result(): void {
		$agent = $this->agent( 1 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$throw = static function (): void {
			throw new \RuntimeException( 'listener' );
		};
		$this->on( 'steadhand_agent_deleted', $throw );

		$result = $this->handler->delete( $this->nonce( AgentsHandler::ACTION_DELETE, $agent ), $agent, true );

		$this->assertSame( 'agent_deleted', $result['code'] );
		$this->assertFalse( $this->state( $agent )['exists'] );
	}

	public function test_a_listener_that_throws_on_the_disabled_or_enabled_hook_does_not_change_the_result(): void {
		$agent = $this->agent( 1 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$throw = static function (): void {
			throw new \RuntimeException( 'listener' );
		};
		$this->on( 'steadhand_agent_disabled', $throw );
		$this->on( 'steadhand_agent_enabled', $throw );

		$disabled = $this->handler->disable( $this->nonce( AgentsHandler::ACTION_DISABLE, $agent ), $agent );
		$this->assertSame( 'agent_disabled', $disabled['code'] );
		$this->assertSame( array(), \WP_Application_Passwords::get_user_application_passwords( $agent ) );

		$enabled = $this->handler->enable( $this->nonce( AgentsHandler::ACTION_ENABLE, $agent ), $agent );
		$this->assertSame( 'agent_enabled', $enabled['code'] );
		clean_user_cache( $agent );
		$this->assertTrue( get_userdata( $agent )->caps[ Capabilities::CONNECT ] ?? null );
	}

	public function test_a_bad_nonce_dies_and_changes_nothing_for_every_action(): void {
		$agent = $this->agent( 1 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$before = $this->state( $agent );

		foreach ( array( 'disable', 'enable', 'delete' ) as $method ) {
			$this->assertDiesWith( fn() => $this->call( $method, 'not-a-nonce', $agent ) );
		}

		$this->assertSame( $before, $this->state( $agent ) );
	}

	public function test_a_nonce_for_another_agent_dies_and_changes_nothing(): void {
		$agent = $this->agent( 1 );
		$other = $this->agent( 1 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$before = $this->state( $agent );

		$disable = fn() => $this->handler->disable( $this->nonce( AgentsHandler::ACTION_DISABLE, $other ), $agent );
		$delete  = fn() => $this->handler->delete( $this->nonce( AgentsHandler::ACTION_DELETE, $other ), $agent, true );
		$this->assertDiesWith( $disable );
		$this->assertDiesWith( $delete );

		$this->assertSame( $before, $this->state( $agent ) );
	}

	public function test_a_nonce_for_another_action_dies_and_changes_nothing(): void {
		$agent = $this->agent( 1 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$before = $this->state( $agent );

		$disable_with_enable_nonce = fn() => $this->handler->disable( $this->nonce( AgentsHandler::ACTION_ENABLE, $agent ), $agent );
		$delete_with_disable_nonce = fn() => $this->handler->delete( $this->nonce( AgentsHandler::ACTION_DISABLE, $agent ), $agent, true );
		$this->assertDiesWith( $disable_with_enable_nonce );
		$this->assertDiesWith( $delete_with_disable_nonce );

		$this->assertSame( $before, $this->state( $agent ) );
	}

	public function test_a_caller_without_manage_options_dies_and_changes_nothing(): void {
		$agent = $this->agent( 1 );
		$this->as_user( $this->user_with_role( 'editor' ) );
		$before = $this->state( $agent );

		$this->assertDiesWith( fn() => $this->handler->disable( $this->nonce( AgentsHandler::ACTION_DISABLE, $agent ), $agent ) );
		$this->assertDiesWith( fn() => $this->handler->enable( $this->nonce( AgentsHandler::ACTION_ENABLE, $agent ), $agent ) );
		$this->assertDiesWith( fn() => $this->handler->delete( $this->nonce( AgentsHandler::ACTION_DELETE, $agent ), $agent, true ) );

		$this->assertSame( $before, $this->state( $agent ) );
	}

	public function test_a_caller_who_holds_the_agent_role_dies_even_with_administrator_rights(): void {
		$agent  = $this->agent( 1 );
		$caller = $this->user_with_role( 'administrator' );
		get_userdata( $caller )->add_role( Roles::ROLE );
		$this->as_user( $caller );
		$before = $this->state( $agent );

		$this->assertDiesWith( fn() => $this->handler->disable( $this->nonce( AgentsHandler::ACTION_DISABLE, $agent ), $agent ) );
		$this->assertDiesWith( fn() => $this->handler->delete( $this->nonce( AgentsHandler::ACTION_DELETE, $agent ), $agent, true ) );

		$this->assertSame( $before, $this->state( $agent ) );
	}

	/**
	 * The most important negative case: an account that is not a dedicated agent is refused before anything happens, by every action.
	 */
	public function test_an_administrator_editor_or_mixed_role_target_is_refused_by_every_action(): void {
		$this->as_user( $this->user_with_role( 'administrator' ) );

		$admin  = $this->user_with_role( 'administrator' );
		$editor = $this->user_with_role( 'editor' );
		$mixed  = $this->user_with_role( 'editor' );
		get_userdata( $mixed )->add_role( Roles::ROLE );

		foreach ( array( $admin, $editor, $mixed ) as $target ) {
			$before = $this->state( $target );

			$this->assertSame( 'not_agent', $this->handler->disable( $this->nonce( AgentsHandler::ACTION_DISABLE, $target ), $target )['code'] );
			$this->assertSame( 'not_agent', $this->handler->enable( $this->nonce( AgentsHandler::ACTION_ENABLE, $target ), $target )['code'] );
			$this->assertSame( 'not_agent', $this->handler->delete( $this->nonce( AgentsHandler::ACTION_DELETE, $target ), $target, true )['code'] );

			$this->assertSame( $before, $this->state( $target ), 'A refused target is not changed.' );
		}
	}

	public function test_the_caller_as_target_is_refused_and_changes_nothing(): void {
		$admin = $this->user_with_role( 'administrator' );
		$this->as_user( $admin );
		$before = $this->state( $admin );

		$this->assertSame( 'not_agent', $this->handler->disable( $this->nonce( AgentsHandler::ACTION_DISABLE, $admin ), $admin )['code'] );
		$this->assertSame( 'not_agent', $this->handler->delete( $this->nonce( AgentsHandler::ACTION_DELETE, $admin ), $admin, true )['code'] );

		$this->assertSame( $before, $this->state( $admin ) );
	}

	public function test_on_a_multisite_network_every_new_action_is_refused_before_anything_changes(): void {
		$network = new AgentsHandler( ( new Plugin() )->registry(), true );
		$agent   = $this->agent( 1 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$before = $this->state( $agent );

		$this->assertSame( 'multisite', $network->disable( $this->nonce( AgentsHandler::ACTION_DISABLE, $agent ), $agent )['code'] );
		$this->assertSame( 'multisite', $network->enable( $this->nonce( AgentsHandler::ACTION_ENABLE, $agent ), $agent )['code'] );
		$this->assertSame( 'multisite', $network->delete( $this->nonce( AgentsHandler::ACTION_DELETE, $agent ), $agent, true )['code'] );

		$this->assertSame( $before, $this->state( $agent ) );
	}

	public function test_the_detail_view_shows_disable_when_connect_is_on_and_enable_when_it_is_off(): void {
		$agent = $this->agent( 1 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$_GET = array( 'agent' => (string) $agent );

		$on = $this->render();
		$this->assertStringContainsString( 'Disable agent', $on );
		$this->assertStringNotContainsString( 'Enable agent', $on );
		$this->assertStringContainsString( 'Revokes every connection and stops the agent connecting.', $on );

		$this->handler->disable( $this->nonce( AgentsHandler::ACTION_DISABLE, $agent ), $agent );

		$off = $this->render();
		$this->assertStringContainsString( 'Enable agent', $off );
		$this->assertStringNotContainsString( 'Disable agent', $off );
	}

	public function test_the_delete_form_is_absent_and_the_reason_shown_when_the_agent_has_content(): void {
		$agent = $this->agent( 1 );
		self::factory()->post->create(
			array(
				'post_author' => $agent,
				'post_status' => 'draft',
			)
		);
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$_GET = array( 'agent' => (string) $agent );

		$html = $this->render();

		$this->assertStringNotContainsString( 'name="confirm_delete"', $html );
		$this->assertStringNotContainsString( 'Delete agent', $html );
		$this->assertStringContainsString( 'Steadhand never deletes content', $html );
	}

	public function test_the_delete_form_has_its_confirmation_box_when_the_agent_has_no_content(): void {
		$agent = $this->agent( 1 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$_GET = array( 'agent' => (string) $agent );

		$html = $this->render();

		$this->assertStringContainsString( 'name="confirm_delete"', $html );
		$this->assertStringContainsString( 'I understand that this agent&#039;s user account is deleted and that this cannot be undone.', $html );
		$this->assertStringContainsString( 'name="steadhand_agents_action" value="delete"', $html );
	}

	public function test_the_list_has_a_status_column_with_the_three_values_and_still_no_form_or_nonce(): void {
		$this->agent( 1 );
		$this->agent( 0 );
		$off = $this->agent( 1 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$this->handler->disable( $this->nonce( AgentsHandler::ACTION_DISABLE, $off ), $off );
		$_GET = array();

		$html = $this->render();

		$this->assertStringContainsString( '<th scope="col">Status</th>', $html );
		$this->assertSame( 1, substr_count( $html, '<td>Active</td>' ) );
		$this->assertSame( 1, substr_count( $html, '<td>No connections</td>' ) );
		$this->assertSame( 1, substr_count( $html, '<td>Disabled</td>' ) );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( '_wpnonce', $html );
	}

	public function test_the_row_action_fires_once_in_the_detail_view_after_the_new_section(): void {
		$agent = $this->agent( 1 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$count = 0;
		$this->on(
			'steadhand_admin_agent_row',
			static function () use ( &$count ): void {
				++$count;
				echo 'ROWMARK';
			}
		);

		$_GET = array( 'agent' => (string) $agent );
		$html = $this->render();

		$this->assertSame( 1, $count );
		$this->assertSame( 1, substr_count( $html, 'ROWMARK' ) );
		$this->assertLessThan( strpos( $html, 'ROWMARK' ), strpos( $html, 'Disable or delete' ), 'The section comes before the row action.' );

		$_GET = array();
		$this->render();
		$this->assertSame( 1, $count, 'The list view does not fire it.' );
	}

	/**
	 * Attributes one post, link or menu item to a user, of the given kind.
	 *
	 * @param int    $user_id The user.
	 * @param string $kind    One of: attachment, revision, trash, auto-draft, nav_menu_item, link.
	 */
	private function attribute( int $user_id, string $kind ): void {
		if ( 'link' === $kind ) {
			require_once ABSPATH . 'wp-admin/includes/bookmark.php';
			$link = wp_insert_link(
				array(
					'link_url'   => 'https://example.com/',
					'link_name'  => 'Example',
					'link_owner' => $user_id,
				),
				true
			);
			$this->assertIsInt( $link, 'The link could not be created in this test environment.' );
			return;
		}

		$kinds = array(
			'attachment'    => array( 'attachment', 'inherit' ),
			'revision'      => array( 'revision', 'inherit' ),
			'trash'         => array( 'post', 'trash' ),
			'auto-draft'    => array( 'post', 'auto-draft' ),
			'nav_menu_item' => array( 'nav_menu_item', 'publish' ),
		);

		list( $type, $status ) = $kinds[ $kind ];
		$id                    = wp_insert_post(
			array(
				'post_type'   => $type,
				'post_status' => $status,
				'post_author' => $user_id,
				'post_title'  => 'Attributed ' . $kind,
			)
		);
		$this->assertIsInt( $id, "The $kind could not be created." );
	}

	public function test_delete_is_refused_for_each_kind_of_attributed_content_and_changes_nothing(): void {
		$this->as_user( $this->user_with_role( 'administrator' ) );

		foreach ( array( 'attachment', 'revision', 'trash', 'auto-draft', 'nav_menu_item', 'link' ) as $kind ) {
			$agent = $this->agent( 1 );
			$this->attribute( $agent, $kind );
			$before = $this->state( $agent );

			$result = $this->handler->delete( $this->nonce( AgentsHandler::ACTION_DELETE, $agent ), $agent, true );

			$this->assertSame( 'delete_has_content', $result['code'], $kind );
			$this->assertSame( $before, $this->state( $agent ), "A $kind must stop the delete with nothing changed." );
		}
	}

	public function test_content_that_appears_during_the_revoke_gives_the_revoked_code_and_keeps_the_user_and_post(): void {
		$agent = $this->agent( 1 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$post = 0;
		// A password is revoked by the delete. Its action fires during the revoke, and a post is attributed to the agent there.
		$this->on(
			'wp_delete_application_password',
			static function ( $user_id ) use ( $agent, &$post ): void {
				if ( (int) $user_id === $agent && 0 === $post ) {
					$post = (int) self::factory()->post->create(
						array(
							'post_author' => $agent,
							'post_status' => 'draft',
						)
					);
				}
			}
		);

		$result = $this->handler->delete( $this->nonce( AgentsHandler::ACTION_DELETE, $agent ), $agent, true );

		$this->assertSame( 'delete_has_content_revoked', $result['code'] );
		$this->assertFalse( $result['ok'] );
		$this->assertGreaterThan( 0, $post, 'The content must have appeared during the revoke.' );
		$this->assertNotNull( get_post( $post ), 'The post must still exist.' );
		$state = $this->state( $agent );
		$this->assertTrue( $state['exists'], 'The user must still exist.' );
		$this->assertSame( array(), $state['passwords'], 'The connections stay revoked.' );
	}

	public function test_content_that_appears_at_the_last_moment_aborts_the_delete_before_anything_is_removed(): void {
		$agent = $this->agent( 1 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$post = 0;
		// Core fires delete_user before it removes anything. This listener runs before Steadhand's, and attributes a post there.
		$this->on(
			'delete_user',
			static function ( $id ) use ( $agent, &$post ): void {
				if ( (int) $id === $agent && 0 === $post ) {
					$post = (int) self::factory()->post->create(
						array(
							'post_author' => $agent,
							'post_status' => 'draft',
						)
					);
				}
			},
			1
		);

		$result = $this->handler->delete( $this->nonce( AgentsHandler::ACTION_DELETE, $agent ), $agent, true );

		$this->assertSame( 'delete_has_content_revoked', $result['code'] );
		$this->assertGreaterThan( 0, $post );
		$this->assertNotNull( get_post( $post ), 'The post must not be deleted with the user.' );
		$this->assertTrue( $this->state( $agent )['exists'], 'The user must not be deleted.' );
	}

	/**
	 * The priorities at which callbacks are attached to a hook, or an empty list. Used to see whether the guard is in place.
	 *
	 * @param string $hook The hook.
	 * @return int[]
	 */
	private function priorities( string $hook ): array {
		$wp_hook = $GLOBALS['wp_filter'][ $hook ] ?? null;

		return $wp_hook instanceof \WP_Hook ? array_map( 'intval', array_keys( $wp_hook->callbacks ) ) : array();
	}

	public function test_the_post_filter_and_the_delete_guard_are_active_during_the_delete_and_removed_after_a_successful_delete(): void {
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$during = array();
		$this->on(
			'delete_user',
			function () use ( &$during ): void {
				$during = array(
					'filter' => $this->priorities( 'post_types_to_delete_with_user' ),
					'guard'  => $this->priorities( 'delete_user' ),
				);
			},
			1
		);
		$filter_before = $this->priorities( 'post_types_to_delete_with_user' );
		$action_before = $this->priorities( 'delete_user' );

		$ok = $this->agent( 1 );
		$this->handler->delete( $this->nonce( AgentsHandler::ACTION_DELETE, $ok ), $ok, true );

		$this->assertContains( PHP_INT_MAX, $during['filter'], 'The post filter is in place while core deletes.' );
		$this->assertContains( PHP_INT_MAX, $during['guard'], 'The guard is in place while core deletes.' );
		$this->assertFalse( $this->state( $ok )['exists'] );
		$this->assertSame( $filter_before, $this->priorities( 'post_types_to_delete_with_user' ), 'The filter is removed after a successful delete.' );
		$this->assertSame( $action_before, $this->priorities( 'delete_user' ), 'The guard is removed after a successful delete.' );
	}

	public function test_the_post_filter_and_the_guard_are_removed_after_a_failed_delete(): void {
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$this->on(
			'delete_user',
			static function (): void {
				throw new \RuntimeException( 'listener' );
			}
		);
		$filter_before = $this->priorities( 'post_types_to_delete_with_user' );
		$action_before = $this->priorities( 'delete_user' );
		$agent         = $this->agent( 1 );

		$result = $this->handler->delete( $this->nonce( AgentsHandler::ACTION_DELETE, $agent ), $agent, true );

		$this->assertSame( 'delete_failed', $result['code'] );
		$this->assertSame( $filter_before, $this->priorities( 'post_types_to_delete_with_user' ) );
		$this->assertSame( $action_before, $this->priorities( 'delete_user' ) );
	}

	public function test_a_role_added_during_the_revoke_stops_the_delete(): void {
		$agent = $this->agent( 1 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		// A listener during the revoke gives the agent a second role, so it is no longer a dedicated agent.
		$this->on(
			'wp_delete_application_password',
			static function ( $user_id ) use ( $agent ): void {
				if ( (int) $user_id === $agent ) {
					get_userdata( $agent )->add_role( 'editor' );
				}
			}
		);

		$result = $this->handler->delete( $this->nonce( AgentsHandler::ACTION_DELETE, $agent ), $agent, true );

		$this->assertSame( 'not_agent_revoked', $result['code'] );
		$state = $this->state( $agent );
		$this->assertTrue( $state['exists'], 'The user must not be deleted.' );
		$this->assertContains( 'editor', $state['roles'] );
	}

	public function test_a_listener_that_throws_on_deleted_user_after_the_row_is_gone_still_reports_a_delete(): void {
		$agent = $this->agent( 1 );
		$login = get_userdata( $agent )->user_login;
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$this->on(
			'deleted_user',
			static function (): void {
				throw new \RuntimeException( 'listener' );
			}
		);
		$seen = $this->record( 'steadhand_agent_deleted' );

		$result = $this->handler->delete( $this->nonce( AgentsHandler::ACTION_DELETE, $agent ), $agent, true );

		$this->assertSame( 'agent_deleted', $result['code'] );
		$this->assertTrue( $result['ok'] );
		$this->assertFalse( $this->state( $agent )['exists'] );
		$this->assertCount( 1, $seen, 'The deleted hook fires once.' );
		$this->assertSame( $login, $seen[0][1] );
	}

	public function test_add_password_is_refused_for_a_disabled_agent_and_creates_no_password(): void {
		$agent = $this->agent( 0 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$this->handler->disable( $this->nonce( AgentsHandler::ACTION_DISABLE, $agent ), $agent );

		$result = $this->handler->add_password( $this->nonce( AgentsHandler::ACTION_ADD_PASSWORD, $agent ), $agent, 'Late' );

		$this->assertSame( 'agent_disabled_no_password', $result['code'] );
		$this->assertFalse( $result['ok'] );
		$this->assertNull( $result['secret'] );
		$this->assertSame( array(), \WP_Application_Passwords::get_user_application_passwords( $agent ) );
	}

	public function test_add_password_is_refused_for_an_agent_disabled_by_unticking_connect_with_a_password_present(): void {
		$agent = $this->agent( 1 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$this->handler->set_capabilities( $this->nonce( AgentsHandler::ACTION_CAPABILITIES, $agent ), $agent, array( Capabilities::READ ) );

		$result = $this->handler->add_password( $this->nonce( AgentsHandler::ACTION_ADD_PASSWORD, $agent ), $agent, 'Late' );

		$this->assertSame( 'agent_disabled_no_password', $result['code'] );
		$this->assertCount( 1, \WP_Application_Passwords::get_user_application_passwords( $agent ), 'No password was added.' );
	}

	public function test_status_says_how_many_connections_a_disabled_agent_still_holds_and_offers_disable_and_enable(): void {
		$agent = $this->agent( 1 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$this->handler->set_capabilities( $this->nonce( AgentsHandler::ACTION_CAPABILITIES, $agent ), $agent, array( Capabilities::READ ) );

		$_GET = array();
		$list = $this->render();
		$this->assertStringContainsString( '<td>Disabled (1 connection not revoked)</td>', $list );

		$_GET   = array( 'agent' => (string) $agent );
		$detail = $this->render();
		$this->assertStringContainsString( 'Disabled (1 connection not revoked)', $detail );
		$this->assertStringContainsString( 'Disable agent', $detail, 'Disable is offered while connections remain.' );
		$this->assertStringContainsString( 'Enable agent', $detail );
		$this->assertStringContainsString( 'Its existing connections work again.', $detail );
		$this->assertStringContainsString( 'they still exist, so use Disable agent or Revoke all passwords to remove them.', $detail );
		$this->assertStringContainsString( 'This agent is disabled. Enable it before adding a connection.', $detail );
		$this->assertStringNotContainsString( 'name="label"', $detail, 'The add-connection form is not printed while disabled.' );
	}

	public function test_a_disabled_agent_with_no_connections_reads_disabled_and_offers_only_enable(): void {
		$agent = $this->agent( 0 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$this->handler->disable( $this->nonce( AgentsHandler::ACTION_DISABLE, $agent ), $agent );

		$_GET   = array( 'agent' => (string) $agent );
		$detail = $this->render();

		$this->assertStringContainsString( '<strong>Status:</strong> Disabled', $detail );
		$this->assertStringNotContainsString( 'not revoked', $detail );
		$this->assertStringContainsString( 'Enable agent', $detail );
		$this->assertStringNotContainsString( 'Disable agent', $detail );
		$this->assertStringContainsString( 'It has no connection now, so add one above before it can connect.', $detail );
	}

	public function test_enable_says_whether_its_existing_connections_came_back(): void {
		$agent = $this->agent( 1 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$this->handler->set_capabilities( $this->nonce( AgentsHandler::ACTION_CAPABILITIES, $agent ), $agent, array( Capabilities::READ ) );

		$with = $this->handler->enable( $this->nonce( AgentsHandler::ACTION_ENABLE, $agent ), $agent );
		$this->assertSame( 'agent_enabled_with_connections', $with['code'] );
		$this->assertTrue( $with['ok'] );

		$this->handler->disable( $this->nonce( AgentsHandler::ACTION_DISABLE, $agent ), $agent );
		$without = $this->handler->enable( $this->nonce( AgentsHandler::ACTION_ENABLE, $agent ), $agent );
		$this->assertSame( 'agent_enabled', $without['code'] );
	}

	public function test_the_delete_form_is_absent_when_the_viewer_lacks_delete_user(): void {
		$agent = $this->agent( 1 );
		$this->as_user( $this->user_with_role( 'administrator' ) );
		$this->on(
			'map_meta_cap',
			static fn( $caps, $cap ) => 'delete_user' === $cap ? array( 'do_not_allow' ) : $caps,
			10
		);
		$_GET = array( 'agent' => (string) $agent );

		$html = $this->render();

		$this->assertStringNotContainsString( 'name="confirm_delete"', $html );
		$this->assertStringNotContainsString( 'Delete agent', $html );
		$this->assertStringNotContainsString( 'name="steadhand_agents_action" value="delete"', $html );
		$this->assertStringContainsString( 'Disable agent', $html, 'Disable needs only edit_user and stays.' );
	}

	/**
	 * Calls one of the three methods with a nonce, for the target, and no confirmation.
	 *
	 * @param string $method The method name: disable, enable or delete.
	 * @param string $nonce  The nonce.
	 * @param int    $agent  The target.
	 * @return array{code: string, ok: bool, user_id: int, secret: null}
	 */
	private function call( string $method, string $nonce, int $agent ): array {
		return 'delete' === $method
			? $this->handler->delete( $nonce, $agent, true )
			: $this->handler->{$method}( $nonce, $agent );
	}

	/**
	 * Asserts that the callable ends the request with wp_die() (the test double throws WPDieException).
	 *
	 * @param callable $call The call.
	 */
	private function assertDiesWith( callable $call ): void {
		try {
			$call();
		} catch ( \WPDieException ) {
			$this->addToAssertionCount( 1 );
			return;
		}

		$this->fail( 'The request was not refused with wp_die().' );
	}
}
