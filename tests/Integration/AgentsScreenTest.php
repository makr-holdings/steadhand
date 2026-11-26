<?php
/**
 * The Agents screen: its four actions, their refusals, the one-time password, the capabilities and the extension points.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Admin\AgentsHandler;
use Steadhand\Admin\AgentsScreen;
use Steadhand\Admin\LogScreen;
use Steadhand\Agents;
use Steadhand\Capabilities;
use Steadhand\Plugin;
use Steadhand\Roles;

/**
 * Negative tests first: every refusal must leave the agent, its roles, its passwords and every other user as they were.
 */
final class AgentsScreenTest extends TestCase {

	/**
	 * Filters this test added, removed in tearDown.
	 *
	 * @var array<int, array{0: string, 1: callable, 2: int}>
	 */
	private array $added = array();

	/**
	 * The screen under test.
	 *
	 * @var AgentsScreen
	 */
	private AgentsScreen $screen;

	/**
	 * The handler the screen uses.
	 *
	 * @var AgentsHandler
	 */
	private AgentsHandler $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->handler = new AgentsHandler( ( new Plugin() )->registry() );
		$this->screen  = new AgentsScreen( $this->handler );

		// A test double: a refusal throws instead of printing a page, and the test catches it.
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
	 * A dedicated agent with the given number of Application Passwords.
	 *
	 * @param string $login     Login name, or '' for a generated one.
	 * @param int    $passwords Number of Application Passwords to create.
	 * @return int The user ID.
	 */
	private function agent( string $login = '', int $passwords = 1 ): int {
		$id = self::factory()->user->create(
			array(
				'role'       => Roles::ROLE,
				'user_login' => '' === $login ? 'agent' . wp_rand( 1000, 999999 ) : $login,
			)
		);
		for ( $i = 1; $i <= $passwords; $i++ ) {
			\WP_Application_Passwords::create_new_application_password( $id, array( 'name' => 'Password ' . $i ) );
		}

		return $id;
	}

	private function admin(): int {
		return self::factory()->user->create( array( 'role' => 'administrator' ) );
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
	 * Sends a POST of one action to the screen, as the browser would, and returns what process_post() returns, or 'died' when the
	 * request is refused with wp_die().
	 *
	 * @param string              $action One of the AgentsHandler::ACTION_* values.
	 * @param array<string,mixed> $fields The other form fields.
	 * @return mixed
	 */
	private function post( string $action, array $fields ): mixed {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array_merge( array( AgentsHandler::FIELD => $action ), $fields );

		try {
			return $this->screen->process_post();
		} catch ( \WPDieException ) {
			return 'died';
		}
	}

	/**
	 * The nonce for an action on a target (or create).
	 *
	 * @param string $action One of the AgentsHandler::ACTION_* values.
	 * @param int    $target The target user, or 0.
	 * @return string
	 */
	private function nonce( string $action, int $target = 0 ): string {
		return wp_create_nonce( AgentsHandler::nonce_action( $action, $target ) );
	}

	/**
	 * Runs a handler call; returns its result, or 'died' when it was refused with wp_die().
	 *
	 * @param callable $call The call.
	 * @return mixed
	 */
	private function call( callable $call ): mixed {
		try {
			return $call();
		} catch ( \WPDieException ) {
			return 'died';
		}
	}

	/**
	 * Whether a call was refused: by wp_die() ('died'), or with the not_agent code.
	 *
	 * @param callable $call The call.
	 * @return bool
	 */
	private function refused( callable $call ): bool {
		$result = $this->call( $call );

		return 'died' === $result || ( is_array( $result ) && 'not_agent' === $result['code'] );
	}

	/**
	 * The state of an agent that a refusal must not change: its caps, roles and password names.
	 *
	 * @param int $id The agent.
	 * @return array<string, mixed>
	 */
	private function state( int $id ): array {
		$user = get_userdata( $id );

		return array(
			'caps'      => $user ? $user->caps : null,
			'roles'     => $user ? $user->roles : null,
			'passwords' => array_column( \WP_Application_Passwords::get_user_application_passwords( $id ), 'name' ),
		);
	}

	/**
	 * The rendered page as the current user.
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

	public function test_every_action_is_refused_for_callers_who_may_not_manage_agents(): void {
		$agent  = $this->agent( '', 2 );
		$before = $this->state( $agent );

		$callers = array(
			'logged out' => 0,
			'subscriber' => self::factory()->user->create( array( 'role' => 'subscriber' ) ),
			'editor'     => self::factory()->user->create( array( 'role' => 'editor' ) ),
			'agent'      => self::factory()->user->create( array( 'role' => Roles::ROLE ) ),
		);

		foreach ( $callers as $label => $caller ) {
			$this->as_user( $caller );
			$this->assertSame(
				'died',
				$this->post(
					AgentsHandler::ACTION_CREATE,
					array(
						'_wpnonce' => $this->nonce( AgentsHandler::ACTION_CREATE ),
						'username' => 'newone',
						'label'    => 'x',
					)
				),
				$label
			);
			$this->assertSame(
				'died',
				$this->post(
					AgentsHandler::ACTION_ADD_PASSWORD,
					array(
						'user_id'  => (string) $agent,
						'_wpnonce' => $this->nonce( AgentsHandler::ACTION_ADD_PASSWORD, $agent ),
						'label'    => 'Extra',
					)
				),
				$label
			);
			$this->assertSame(
				'died',
				$this->post(
					AgentsHandler::ACTION_REVOKE_ALL,
					array(
						'user_id'  => (string) $agent,
						'_wpnonce' => $this->nonce( AgentsHandler::ACTION_REVOKE_ALL, $agent ),
					)
				),
				$label
			);
			$this->assertSame(
				'died',
				$this->post(
					AgentsHandler::ACTION_CAPABILITIES,
					array(
						'user_id'      => (string) $agent,
						'_wpnonce'     => $this->nonce( AgentsHandler::ACTION_CAPABILITIES, $agent ),
						'capabilities' => array( Capabilities::PUBLISH ),
					)
				),
				$label
			);
		}

		$this->as_user( $this->admin() );
		$this->assertSame( $before, $this->state( $agent ) );
		$this->assertFalse( username_exists( 'newone' ) );
	}

	public function test_an_administrator_who_also_holds_the_agent_role_is_refused(): void {
		$admin = $this->admin();
		get_userdata( $admin )->add_role( Roles::ROLE );
		$agent  = $this->agent( '', 1 );
		$before = $this->state( $agent );

		$this->as_user( $admin );
		$this->assertSame(
			'died',
			$this->post(
				AgentsHandler::ACTION_REVOKE_ALL,
				array(
					'user_id'  => (string) $agent,
					'_wpnonce' => $this->nonce( AgentsHandler::ACTION_REVOKE_ALL, $agent ),
				)
			)
		);
		$this->assertSame(
			'died',
			$this->post(
				AgentsHandler::ACTION_CREATE,
				array(
					'_wpnonce' => $this->nonce( AgentsHandler::ACTION_CREATE ),
					'username' => 'newtwo',
					'label'    => 'x',
				)
			)
		);
		$this->assertSame( $before, $this->state( $agent ) );
	}

	public function test_a_missing_or_wrong_nonce_is_refused_and_changes_nothing(): void {
		$admin  = $this->admin();
		$agent  = $this->agent( '', 1 );
		$other  = $this->agent( '', 1 );
		$before = $this->state( $agent );
		$this->as_user( $admin );

		// No nonce at all.
		$this->assertSame( 'died', $this->post( AgentsHandler::ACTION_REVOKE_ALL, array( 'user_id' => (string) $agent ) ) );
		// The nonce of another action on the same user.
		$this->assertSame(
			'died',
			$this->post(
				AgentsHandler::ACTION_REVOKE_ALL,
				array(
					'user_id'  => (string) $agent,
					'_wpnonce' => $this->nonce( AgentsHandler::ACTION_CAPABILITIES, $agent ),
				)
			)
		);
		// The nonce of another target user.
		$this->assertSame(
			'died',
			$this->post(
				AgentsHandler::ACTION_REVOKE_ALL,
				array(
					'user_id'  => (string) $agent,
					'_wpnonce' => $this->nonce( AgentsHandler::ACTION_REVOKE_ALL, $other ),
				)
			)
		);
		// A nonce for create, sent to create with a target.
		$this->assertSame(
			'died',
			$this->post(
				AgentsHandler::ACTION_ADD_PASSWORD,
				array(
					'user_id'  => (string) $agent,
					'_wpnonce' => $this->nonce( AgentsHandler::ACTION_CREATE ),
					'label'    => 'Extra',
				)
			)
		);

		$this->assertSame( $before, $this->state( $agent ) );
	}

	public function test_a_get_request_changes_nothing(): void {
		$admin  = $this->admin();
		$agent  = $this->agent( '', 1 );
		$before = $this->state( $agent );
		$this->as_user( $admin );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array(
			AgentsHandler::FIELD => AgentsHandler::ACTION_REVOKE_ALL,
			'user_id'            => (string) $agent,
			'_wpnonce'           => $this->nonce( AgentsHandler::ACTION_REVOKE_ALL, $agent ),
		);

		$this->assertNull( $this->screen->process_post() );
		$this->assertSame( $before, $this->state( $agent ) );
	}

	public function test_an_administrator_denied_create_users_cannot_create(): void {
		$admin = $this->admin();
		$this->on( 'map_meta_cap', static fn( $caps, $cap ) => 'create_users' === $cap ? array( 'do_not_allow' ) : $caps, 10 );
		$this->as_user( $admin );

		$users = count( get_users( array( 'fields' => 'ID' ) ) );
		$this->assertSame(
			'died',
			$this->post(
				AgentsHandler::ACTION_CREATE,
				array(
					'_wpnonce' => $this->nonce( AgentsHandler::ACTION_CREATE ),
					'username' => 'denied',
					'label'    => 'x',
				)
			)
		);
		$this->assertCount( $users, get_users( array( 'fields' => 'ID' ) ) );
		$this->assertFalse( username_exists( 'denied' ) );
	}

	public function test_an_administrator_denied_edit_user_cannot_change_the_agent(): void {
		$admin  = $this->admin();
		$agent  = $this->agent( '', 1 );
		$before = $this->state( $agent );
		$this->on( 'map_meta_cap', static fn( $caps, $cap ) => 'edit_user' === $cap ? array( 'do_not_allow' ) : $caps, 10 );
		$this->as_user( $admin );

		$this->assertSame(
			'died',
			$this->post(
				AgentsHandler::ACTION_ADD_PASSWORD,
				array(
					'user_id'  => (string) $agent,
					'_wpnonce' => $this->nonce( AgentsHandler::ACTION_ADD_PASSWORD, $agent ),
					'label'    => 'Extra',
				)
			)
		);
		$this->assertSame(
			'died',
			$this->post(
				AgentsHandler::ACTION_REVOKE_ALL,
				array(
					'user_id'  => (string) $agent,
					'_wpnonce' => $this->nonce( AgentsHandler::ACTION_REVOKE_ALL, $agent ),
				)
			)
		);
		$this->assertSame(
			'died',
			$this->post(
				AgentsHandler::ACTION_CAPABILITIES,
				array(
					'user_id'      => (string) $agent,
					'_wpnonce'     => $this->nonce( AgentsHandler::ACTION_CAPABILITIES, $agent ),
					'capabilities' => array( Capabilities::PUBLISH ),
				)
			)
		);
		$this->assertSame( $before, $this->state( $agent ) );
	}

	public function test_every_targeted_action_refuses_a_non_agent_and_changes_nothing(): void {
		$admin  = $this->admin();
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$mixed  = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $mixed )->add_role( Roles::ROLE );
		$this->as_user( $this->admin() );

		// The three real users are not agents, so every action answers with the not_agent code, exactly.
		foreach ( array( $admin, $editor, $mixed ) as $target ) {
			$before = $this->state( $target );

			$this->assertSame( 'not_agent', $this->handler->add_password( $this->nonce( AgentsHandler::ACTION_ADD_PASSWORD, $target ), $target, 'Extra' )['code'], "add_password for $target" );
			$this->assertSame( 'not_agent', $this->handler->revoke_all( $this->nonce( AgentsHandler::ACTION_REVOKE_ALL, $target ), $target )['code'], "revoke_all for $target" );
			$this->assertSame( 'not_agent', $this->handler->revoke( $this->nonce( AgentsHandler::ACTION_REVOKE, $target ), $target, '0b0bfbea-2a1f-4e1b-9a5a-000000000001' )['code'], "revoke for $target" );
			$this->assertSame(
				'not_agent',
				$this->handler->set_capabilities( $this->nonce( AgentsHandler::ACTION_CAPABILITIES, $target ), $target, array( Capabilities::CONNECT, Capabilities::PUBLISH, Capabilities::MEDIA ) )['code'],
				"set_capabilities for $target"
			);

			$this->assertSame( $before, $this->state( $target ) );
		}

		// 999999 and 0 are not users. The guard may refuse them before the agent check, so either refusal is accepted.
		foreach ( array( 999999, 0 ) as $target ) {
			$this->assertTrue( $this->refused( fn() => $this->handler->add_password( $this->nonce( AgentsHandler::ACTION_ADD_PASSWORD, $target ), $target, 'Extra' ) ) );
			$this->assertTrue( $this->refused( fn() => $this->handler->revoke_all( $this->nonce( AgentsHandler::ACTION_REVOKE_ALL, $target ), $target ) ) );
			$this->assertTrue( $this->refused( fn() => $this->handler->revoke( $this->nonce( AgentsHandler::ACTION_REVOKE, $target ), $target, '0b0bfbea-2a1f-4e1b-9a5a-000000000001' ) ) );
			$this->assertTrue(
				$this->refused( fn() => $this->handler->set_capabilities( $this->nonce( AgentsHandler::ACTION_CAPABILITIES, $target ), $target, array( Capabilities::CONNECT ) ) )
			);
		}

		$admin_user = get_userdata( $admin );
		foreach ( Capabilities::all() as $capability ) {
			$this->assertFalse( Capabilities::user_has( $admin_user, $capability ), $capability );
		}
	}

	public function test_a_mixed_role_user_gets_no_steadhand_capability_or_password(): void {
		$mixed = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $mixed )->add_role( Roles::ROLE );
		$this->as_user( $this->admin() );
		$before = $this->state( $mixed );

		$result = $this->handler->set_capabilities( $this->nonce( AgentsHandler::ACTION_CAPABILITIES, $mixed ), $mixed, array( Capabilities::CONNECT ) );

		$this->assertSame( 'not_agent', $result['code'] );
		$this->assertSame( $before, $this->state( $mixed ) );
		$this->assertSame( array(), \WP_Application_Passwords::get_user_application_passwords( $mixed ) );
	}

	public function test_bad_usernames_are_refused_and_no_user_is_created(): void {
		$this->as_user( $this->admin() );
		$existing = get_userdata( $this->admin() )->user_login;
		$count    = count( get_users( array( 'fields' => 'ID' ) ) );

		foreach ( array( 'Upper', 'has space', '-leading', str_repeat( 'a', 61 ), 'admin<script>', '', $existing ) as $username ) {
			$code = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), $username, 'Desk' )['code'];
			$this->assertContains( $code, array( 'invalid_username', 'username_taken' ), $username );
		}

		$this->assertCount( $count, get_users( array( 'fields' => 'ID' ) ) );
	}

	public function test_bad_labels_are_refused_and_no_user_is_created(): void {
		$this->as_user( $this->admin() );
		$count = count( get_users( array( 'fields' => 'ID' ) ) );

		foreach ( array( '', str_repeat( 'a', 61 ), "two\nlines", "bell\x07", '<b>Desk</b>', ' Desk', 'Desk ' ) as $label ) {
			$this->assertSame( 'invalid_label', $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'label-agent', $label )['code'], wp_json_encode( $label ) );
		}

		$this->assertCount( $count, get_users( array( 'fields' => 'ID' ) ) );
		$this->assertFalse( username_exists( 'label-agent' ) );
	}

	public function test_create_makes_a_dedicated_agent_with_the_defaults_and_one_password(): void {
		$this->as_user( $this->admin() );

		$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'desk-agent', 'Claude Desktop' );

		$this->assertSame( 'created', $result['code'] );
		$this->assertTrue( $result['ok'] );
		$user = get_userdata( $result['user_id'] );
		$this->assertSame( array( Roles::ROLE ), array_values( $user->roles ) );
		$this->assertTrue( Agents::is_agent( $user ) );
		$this->assertSame( array( Roles::ROLE ), array_keys( $user->caps ), 'No user-level capability is stored at creation.' );
		$expected = array_fill_keys( Capabilities::all(), false );
		foreach ( Capabilities::agent_defaults() as $capability => $grant ) {
			$expected[ $capability ] = $grant;
		}
		$this->assertSame( $expected, Capabilities::granted( $user ) );
		$this->assertTrue( Capabilities::user_has( $user, Capabilities::CONNECT ) );
		$this->assertFalse( Capabilities::user_has( $user, Capabilities::PUBLISH ) );
		$this->assertFalse( Capabilities::user_has( $user, Capabilities::MEDIA ) );

		$passwords = \WP_Application_Passwords::get_user_application_passwords( $user->ID );
		$this->assertCount( 1, $passwords );
		$this->assertSame( 'Claude Desktop', $passwords[0]['name'] );
		$this->assertNotSame( $result['secret']['password'], $passwords[0]['password'], 'Only the hash is stored.' );
	}

	public function test_the_password_from_create_authenticates(): void {
		$this->as_user( $this->admin() );
		$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'auth-agent', 'Auth test' );

		$item = \WP_Application_Passwords::get_user_application_passwords( $result['user_id'] )[0];
		$this->assertTrue( \WP_Application_Passwords::check_password( $result['secret']['password'], $item['password'] ) );
		$this->assertFalse( \WP_Application_Passwords::check_password( 'not-the-password', $item['password'] ) );
	}

	public function test_when_the_password_step_fails_no_user_remains(): void {
		$this->as_user( $this->admin() );
		$this->on( 'wp_is_application_passwords_available_for_user', static fn() => false, 10 );
		$count = count( get_users( array( 'fields' => 'ID' ) ) );

		$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'half-made', 'Desk' );

		$this->assertSame( 'app_passwords_unavailable', $result['code'] );
		$this->assertFalse( username_exists( 'half-made' ) );
		$this->assertCount( $count, get_users( array( 'fields' => 'ID' ) ) );
	}

	public function test_create_is_refused_when_application_passwords_are_unavailable(): void {
		$this->as_user( $this->admin() );
		$this->on( 'wp_is_application_passwords_available', static fn() => false, 10 );

		$this->assertSame( 'app_passwords_unavailable', $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'no-https', 'Desk' )['code'] );
		$this->assertFalse( username_exists( 'no-https' ) );
	}

	public function test_the_password_is_shown_once_and_stored_nowhere_else(): void {
		$this->as_user( $this->admin() );
		$response = $this->post(
			AgentsHandler::ACTION_CREATE,
			array(
				'_wpnonce' => $this->nonce( AgentsHandler::ACTION_CREATE ),
				'username' => 'secret-agent',
				'label'    => 'Secret test',
			)
		);
		$this->assertNull( $response, 'Create does not redirect.' );

		$html     = $this->render();
		$password = $this->password_on_screen( $html );
		$this->assertNotSame( '', $password );
		$this->assertSame( 1, substr_count( $html, $password ), 'The password appears exactly once in the response.' );
		$this->assertStringContainsString( 'This password is shown once. Copy it now. It cannot be shown again.', $html );
		$this->assertStringContainsString( rest_url( 'steadhand/mcp' ), $html );

		// A later GET (a new request, so a new screen object) does not show it again.
		$this->screen              = new AgentsScreen( $this->handler );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array();
		$this->assertStringNotContainsString( $password, $this->render() );

		// Not in any option or transient, nor in any user meta value.
		global $wpdb;
		$stored = array_merge(
			$wpdb->get_col( "SELECT option_value FROM {$wpdb->options}" ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test: reads every option.
			$wpdb->get_col( "SELECT meta_value FROM {$wpdb->usermeta}" ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test: reads every user meta value.
		);
		foreach ( $stored as $value ) {
			$this->assertStringNotContainsString( $password, (string) $value );
		}
	}

	public function test_the_password_is_not_in_any_steadhand_hook_argument(): void {
		$this->as_user( $this->admin() );
		$seen = array();
		// The 'all' hook passes the hook name first, then the arguments of the call.
		$this->on(
			'all',
			static function ( ...$args ) use ( &$seen ): void {
				if ( str_starts_with( (string) ( $args[0] ?? '' ), 'steadhand_' ) ) {
					$seen[] = print_r( $args, true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Test: captures the arguments as text.
				}
			},
			PHP_INT_MIN
		);

		$this->post(
			AgentsHandler::ACTION_CREATE,
			array(
				'_wpnonce' => $this->nonce( AgentsHandler::ACTION_CREATE ),
				'username' => 'hook-agent',
				'label'    => 'Hook test',
			)
		);
		$password = $this->password_on_screen( $this->render() );
		$this->assertNotSame( '', $password );

		$this->assertNotEmpty( $seen, 'The created action fired.' );
		foreach ( $seen as $args ) {
			$this->assertStringNotContainsString( $password, $args );
		}
	}

	/**
	 * The plaintext password from the one-time panel of a rendered page, or '' when the page has none.
	 *
	 * @param string $html The rendered page.
	 * @return string
	 */
	private function password_on_screen( string $html ): string {
		return 1 === preg_match( '/Application password: <code>([^<]+)<\/code>/', $html, $match ) ? $match[1] : '';
	}

	public function test_add_password_adds_one_and_shows_it(): void {
		$agent = $this->agent( '', 1 );
		$this->as_user( $this->admin() );

		$this->assertNull(
			$this->post(
				AgentsHandler::ACTION_ADD_PASSWORD,
				array(
					'user_id'  => (string) $agent,
					'_wpnonce' => $this->nonce( AgentsHandler::ACTION_ADD_PASSWORD, $agent ),
					'label'    => 'Second',
				)
			)
		);
		$this->assertCount( 2, \WP_Application_Passwords::get_user_application_passwords( $agent ) );
		$this->assertStringContainsString( 'This password is shown once', $this->render() );
	}

	public function test_add_password_refusals(): void {
		$full = $this->agent( '', 10 );
		$this->as_user( $this->admin() );
		$this->assertSame( 'too_many_passwords', $this->handler->add_password( $this->nonce( AgentsHandler::ACTION_ADD_PASSWORD, $full ), $full, 'Eleventh' )['code'] );
		$this->assertCount( 10, \WP_Application_Passwords::get_user_application_passwords( $full ) );

		$taken = $this->agent( '', 1 );
		$this->assertSame( 'label_taken', $this->handler->add_password( $this->nonce( AgentsHandler::ACTION_ADD_PASSWORD, $taken ), $taken, 'PASSWORD 1' )['code'] );
		$this->assertCount( 1, \WP_Application_Passwords::get_user_application_passwords( $taken ) );

		$other = $this->agent( '', 0 );
		$this->on( 'wp_is_application_passwords_available_for_user', static fn( $available, $user ) => $user->ID === $other ? false : $available, 10 );
		$this->assertSame( 'app_passwords_unavailable', $this->handler->add_password( $this->nonce( AgentsHandler::ACTION_ADD_PASSWORD, $other ), $other, 'Desk' )['code'] );
		$this->assertSame( array(), \WP_Application_Passwords::get_user_application_passwords( $other ) );
	}

	public function test_revoke_one_by_uuid_and_refuse_another_users_password(): void {
		$agent = $this->agent( '', 2 );
		$other = $this->agent( '', 1 );
		$this->as_user( $this->admin() );

		$mine   = \WP_Application_Passwords::get_user_application_passwords( $agent );
		$theirs = \WP_Application_Passwords::get_user_application_passwords( $other );

		// Another user's password: refused, and it still exists.
		$this->assertSame( 'password_not_found', $this->handler->revoke( $this->nonce( AgentsHandler::ACTION_REVOKE, $agent ), $agent, $theirs[0]['uuid'] )['code'] );
		$this->assertCount( 1, \WP_Application_Passwords::get_user_application_passwords( $other ) );

		// A malformed UUID: refused.
		$this->assertSame( 'password_not_found', $this->handler->revoke( $this->nonce( AgentsHandler::ACTION_REVOKE, $agent ), $agent, "' OR 1=1" )['code'] );
		$this->assertCount( 2, \WP_Application_Passwords::get_user_application_passwords( $agent ) );

		// Its own password: revoked.
		$this->assertSame( 'password_revoked', $this->handler->revoke( $this->nonce( AgentsHandler::ACTION_REVOKE, $agent ), $agent, $mine[0]['uuid'] )['code'] );
		$remaining = array_column( \WP_Application_Passwords::get_user_application_passwords( $agent ), 'uuid' );
		$this->assertSame( array( $mine[1]['uuid'] ), $remaining );
	}

	public function test_revoke_all_removes_every_password_of_the_agent_only(): void {
		$agent = $this->agent( '', 3 );
		$other = $this->agent( '', 1 );
		$this->as_user( $this->admin() );

		$this->assertSame( 'passwords_revoked', $this->handler->revoke_all( $this->nonce( AgentsHandler::ACTION_REVOKE_ALL, $agent ), $agent )['code'] );
		$this->assertSame( array(), \WP_Application_Passwords::get_user_application_passwords( $agent ) );
		$this->assertCount( 1, \WP_Application_Passwords::get_user_application_passwords( $other ) );
	}

	public function test_revoke_redirects_with_a_fixed_code_and_no_secret(): void {
		$agent = $this->agent( '', 1 );
		$this->as_user( $this->admin() );
		$uuid = \WP_Application_Passwords::get_user_application_passwords( $agent )[0]['uuid'];

		$url = $this->post(
			AgentsHandler::ACTION_REVOKE,
			array(
				'user_id'  => (string) $agent,
				'uuid'     => $uuid,
				'_wpnonce' => $this->nonce( AgentsHandler::ACTION_REVOKE, $agent ),
			)
		);

		$this->assertIsString( $url );
		$this->assertStringContainsString( 'agents=password_revoked', $url );
		$this->assertSame( (string) $agent, $this->query_of( $url )['agent'] ?? '' );
		$this->assertStringNotContainsString( $uuid, $url );
	}

	public function test_only_offered_capabilities_are_set_and_the_rest_are_ignored(): void {
		$agent = $this->agent( '', 1 );
		$this->as_user( $this->admin() );
		$offered = $this->handler->offered_capabilities();

		$this->assertContains( Capabilities::CONNECT, $offered );
		$this->assertContains( Capabilities::PUBLISH, $offered );
		$this->assertContains( Capabilities::MENUS, $offered, 'Built now (update-menu), so offered.' );
		$this->assertContains( Capabilities::TRASH, $offered, 'Trash and restore is built, so it is offered.' );
		$this->assertNotContains( Capabilities::SETTINGS, $offered );
		$this->assertNotContains( Capabilities::NO_GRANT, $offered );

		$posted = array( Capabilities::CONNECT, Capabilities::PUBLISH, Capabilities::TRASH, Capabilities::SETTINGS, Capabilities::NO_GRANT, 'manage_options', 'edit_posts', 'administrator' );
		$result = $this->handler->set_capabilities( $this->nonce( AgentsHandler::ACTION_CAPABILITIES, $agent ), $agent, $posted );

		$this->assertSame( 'capabilities_saved', $result['code'] );
		$user = get_userdata( $agent );
		$this->assertTrue( Capabilities::user_has( $user, Capabilities::PUBLISH ) );
		$this->assertTrue( Capabilities::user_has( $user, Capabilities::TRASH ) );
		$this->assertFalse( Capabilities::user_has( $user, Capabilities::SETTINGS ) );
		$this->assertFalse( Capabilities::user_has( $user, Capabilities::NO_GRANT ) );
		foreach ( array( 'manage_options', 'edit_posts', 'administrator', Capabilities::SETTINGS, Capabilities::NO_GRANT ) as $name ) {
			$this->assertArrayNotHasKey( $name, $user->caps, $name );
		}
	}

	public function test_the_menus_capability_is_offered_with_the_public_site_sentence_and_is_unticked_by_default(): void {
		$agent = $this->agent( '', 1 );
		$this->as_user( $this->admin() );
		$_GET = array( 'agent' => (string) $agent );

		$html = $this->render();

		$this->assertStringContainsString( 'value="steadhand_menus"', $html );
		$this->assertStringNotContainsString( 'value="steadhand_menus" checked', $html, 'Menus is off by default.' );
		$this->assertStringContainsString( 'This changes the public site at once, and it needs steadhand_publish as well.', $html );
		$this->assertStringContainsString( 'It cannot delete items or menus, or change theme locations.', $html );
		$this->assertArrayNotHasKey( Capabilities::MENUS, Capabilities::agent_defaults() );
	}

	public function test_the_trash_capability_is_offered_with_its_text_and_is_unticked_by_default(): void {
		$agent = $this->agent( '', 1 );
		$this->as_user( $this->admin() );
		$_GET = array( 'agent' => (string) $agent );

		$html = $this->render();

		$this->assertStringContainsString( 'value="steadhand_trash"', $html );
		$this->assertStringNotContainsString( 'value="steadhand_trash" checked', $html, 'The trash is off by default.' );
		$this->assertStringContainsString( 'Move posts and pages to the trash and restore the ones it trashed.', $html );
		$this->assertArrayNotHasKey( Capabilities::TRASH, Capabilities::agent_defaults() );
	}

	public function test_unchecking_connect_stores_an_explicit_false(): void {
		$agent = $this->agent( '', 1 );
		$this->as_user( $this->admin() );

		$this->handler->set_capabilities( $this->nonce( AgentsHandler::ACTION_CAPABILITIES, $agent ), $agent, array( Capabilities::READ ) );

		$user = get_userdata( $agent );
		$this->assertArrayHasKey( Capabilities::CONNECT, $user->caps );
		$this->assertFalse( $user->caps[ Capabilities::CONNECT ] );
		$this->assertFalse( Capabilities::user_has( $user, Capabilities::CONNECT ) );
		$this->assertTrue( Capabilities::user_has( $user, Capabilities::READ ) );
	}

	public function test_a_capability_that_is_not_offered_keeps_its_stored_value(): void {
		$agent = $this->agent( '', 1 );
		get_userdata( $agent )->add_cap( Capabilities::SETTINGS, true );
		$this->as_user( $this->admin() );

		$this->handler->set_capabilities( $this->nonce( AgentsHandler::ACTION_CAPABILITIES, $agent ), $agent, array() );

		$this->assertTrue( get_userdata( $agent )->caps[ Capabilities::SETTINGS ] );
	}

	public function test_set_capabilities_redirects_with_a_code(): void {
		$agent = $this->agent( '', 1 );
		$this->as_user( $this->admin() );

		$url = $this->post(
			AgentsHandler::ACTION_CAPABILITIES,
			array(
				'user_id'      => (string) $agent,
				'_wpnonce'     => $this->nonce( AgentsHandler::ACTION_CAPABILITIES, $agent ),
				'capabilities' => array( Capabilities::READ ),
			)
		);

		$this->assertStringContainsString( 'agents=capabilities_saved', (string) $url );
		$this->assertSame( (string) $agent, $this->query_of( (string) $url )['agent'] ?? '' );
	}

	/**
	 * The query arguments of a redirect URL.
	 *
	 * @param string $url The URL.
	 * @return array<string, string>
	 */
	private function query_of( string $url ): array {
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		parse_str( $query, $args );

		return array_map( 'strval', $args );
	}

	public function test_the_screen_refuses_a_viewer_who_is_not_an_administrator_or_holds_the_agent_role(): void {
		$agent = $this->agent( 'refused-agent', 1 );
		$views = array(
			array(),
			array( 'view' => 'new' ),
			array( 'agent' => (string) $agent ),
		);

		$this->as_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		foreach ( $views as $query ) {
			$_GET = $query;
			$this->assertSame( 'died', $this->call( fn() => $this->render() ), wp_json_encode( $query ) );
		}

		$admin = $this->admin();
		get_userdata( $admin )->add_role( Roles::ROLE );
		$this->as_user( $admin );
		foreach ( $views as $query ) {
			$_GET = $query;
			$this->assertSame( 'died', $this->call( fn() => $this->render() ), wp_json_encode( $query ) );
		}
	}

	public function test_a_label_with_markup_is_escaped_on_the_screen(): void {
		$agent = $this->agent( '', 1 );
		$items = \WP_Application_Passwords::get_user_application_passwords( $agent );
		// Core sanitises a name when it creates one, so the markup is put in the stored row directly: the screen must escape it anyway.
		$items[0]['name'] = '<img src=x onerror=alert(1)>';
		update_user_meta( $agent, \WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, $items );

		$this->as_user( $this->admin() );
		$_GET = array( 'agent' => (string) $agent );
		$html = $this->render();

		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( '&lt;img src=x', $html );
	}

	public function test_a_mixed_role_user_is_listed_with_a_warning_and_no_forms(): void {
		$mixed = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => 'mixedrole',
			)
		);
		get_userdata( $mixed )->add_role( Roles::ROLE );
		$this->as_user( $this->admin() );

		$html = $this->render();

		$this->assertStringContainsString( 'mixedrole', $html );
		$this->assertStringContainsString( 'This user also has another role, so it is not an agent and cannot connect (see D14).', $html );
		$this->assertStringNotContainsString( 'name="user_id" value="' . $mixed . '"', $html );
	}

	public function test_forms_are_hidden_without_edit_user_or_create_users(): void {
		$agent = $this->agent( 'visible-agent', 1 );
		$this->on( 'map_meta_cap', static fn( $caps, $cap ) => in_array( $cap, array( 'edit_user', 'create_users' ), true ) ? array( 'do_not_allow' ) : $caps, 10 );
		$this->as_user( $this->admin() );

		$html = $this->render();
		$this->assertStringContainsString( 'visible-agent', $html );
		$this->assertStringNotContainsString( '<form', $html );

		$_GET = array( 'agent' => (string) $agent );
		$html = $this->render();
		$this->assertStringContainsString( '<h2>visible-agent</h2>', $html );
		$this->assertStringNotContainsString( 'name="' . AgentsHandler::FIELD . '"', $html );
		$this->assertStringNotContainsString( 'name="username"', $html );
		$this->assertStringNotContainsString( 'name="capabilities[]"', $html );
		$this->assertStringNotContainsString( 'name="label"', $html );
	}

	public function test_an_unknown_code_prints_nothing_and_a_known_one_prints_its_notice(): void {
		$this->as_user( $this->admin() );

		$_GET = array( 'agents' => '<script>alert(1)</script>' );
		$html = $this->render();
		$this->assertStringNotContainsString( 'notice', $html );
		$this->assertStringNotContainsString( '<script>', $html );

		$_GET = array( 'agents' => 'password_revoked' );
		$this->assertStringContainsString( 'The password was revoked.', $this->render() );
	}

	public function test_the_create_form_is_replaced_by_a_notice_when_application_passwords_are_unavailable(): void {
		$this->as_user( $this->admin() );
		$this->on( 'wp_is_application_passwords_available', static fn() => false, 10 );

		$html = $this->render();

		$this->assertStringContainsString( 'Application Passwords are not available on this site', $html );
		$this->assertStringNotContainsString( 'name="username"', $html );
	}

	public function test_the_capabilities_changed_action_fires_with_the_offered_values(): void {
		$agent = $this->agent( '', 1 );
		$this->as_user( $this->admin() );
		$seen = null;
		$this->on(
			'steadhand_agent_capabilities_changed',
			static function ( $user_id, $values ) use ( &$seen ) {
				$seen = array( $user_id, $values );
			},
			10
		);

		$this->handler->set_capabilities( $this->nonce( AgentsHandler::ACTION_CAPABILITIES, $agent ), $agent, array( Capabilities::READ, Capabilities::MENUS ) );

		$this->assertIsArray( $seen );
		$this->assertSame( $agent, $seen[0] );
		$this->assertSame( $this->handler->offered_capabilities(), array_keys( $seen[1] ) );
		$this->assertTrue( $seen[1][ Capabilities::READ ] );
		$this->assertFalse( $seen[1][ Capabilities::CONNECT ] );
	}

	public function test_the_created_action_fires_with_the_user_and_the_label(): void {
		$this->as_user( $this->admin() );
		$seen = null;
		$this->on(
			'steadhand_agent_created',
			static function ( $user_id, $label ) use ( &$seen ) {
				$seen = array( $user_id, $label );
			},
			10
		);

		$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'hooked-agent', 'Hooked' );

		$this->assertSame( array( $result['user_id'], 'Hooked' ), $seen );
	}

	public function test_the_row_action_fires_once_in_the_detail_view_with_the_user(): void {
		$agent = $this->agent( 'row-agent', 1 );
		$this->agent( 'row-agent-two', 1 );
		$this->as_user( $this->admin() );
		$seen = array();
		$this->on(
			'steadhand_admin_agent_row',
			static function ( $user ) use ( &$seen ) {
				$seen[] = $user;
			},
			10
		);

		$this->render();
		$this->assertSame( array(), $seen, 'The list view does not fire it.' );

		$_GET = array( 'agent' => (string) $agent );
		$this->render();

		$this->assertCount( 1, $seen );
		$this->assertInstanceOf( \WP_User::class, $seen[0] );
		$this->assertSame( $agent, $seen[0]->ID );
	}

	public function test_a_throwing_listener_breaks_nothing(): void {
		$agent = $this->agent( 'throw-agent', 1 );
		$this->as_user( $this->admin() );
		$throw = static function (): void {
			throw new \RuntimeException( 'listener failure' );
		};
		$this->on( 'steadhand_agent_created', $throw, 10 );
		$this->on( 'steadhand_agent_capabilities_changed', $throw, 10 );
		$this->on( 'steadhand_admin_agent_row', $throw, 10 );

		$created = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'throw-new', 'Desk' );
		$this->assertSame( 'created', $created['code'] );

		$saved = $this->handler->set_capabilities( $this->nonce( AgentsHandler::ACTION_CAPABILITIES, $agent ), $agent, array( Capabilities::READ ) );
		$this->assertSame( 'capabilities_saved', $saved['code'] );

		$_GET = array( 'agent' => (string) $agent );
		$this->assertStringContainsString( '<h2>throw-agent</h2>', $this->render() );
	}

	/**
	 * The fields of one action's form, for a target (or for create). The nonce is added by the caller.
	 *
	 * @param string $action The action.
	 * @param int    $agent  The agent the form targets. Not used by create.
	 * @return array<string,mixed>
	 */
	private function fields_for( string $action, int $agent ): array {
		return match ( $action ) {
			AgentsHandler::ACTION_CREATE       => array(
				'username' => 'nonce-agent',
				'label'    => 'Desk',
			),
			AgentsHandler::ACTION_ADD_PASSWORD => array(
				'user_id' => (string) $agent,
				'label'   => 'Extra',
			),
			AgentsHandler::ACTION_REVOKE       => array(
				'user_id' => (string) $agent,
				'uuid'    => \WP_Application_Passwords::get_user_application_passwords( $agent )[0]['uuid'],
			),
			AgentsHandler::ACTION_REVOKE_ALL   => array( 'user_id' => (string) $agent ),
			default                            => array(
				'user_id'      => (string) $agent,
				'capabilities' => array( Capabilities::PUBLISH ),
			),
		};
	}

	/**
	 * Sends each of the five actions once, with its valid nonce, and returns what each POST gave: 'died' or the request's result.
	 *
	 * @param int $agent The agent the forms target.
	 * @return array<string,mixed>
	 */
	private function each_action( int $agent ): array {
		$results = array();
		foreach ( array( AgentsHandler::ACTION_CREATE, AgentsHandler::ACTION_ADD_PASSWORD, AgentsHandler::ACTION_REVOKE, AgentsHandler::ACTION_REVOKE_ALL, AgentsHandler::ACTION_CAPABILITIES ) as $action ) {
			$target = AgentsHandler::ACTION_CREATE === $action ? 0 : $agent;

			$results[ $action ] = $this->post( $action, $this->fields_for( $action, $agent ) + array( '_wpnonce' => $this->nonce( $action, $target ) ) );
		}

		return $results;
	}

	/**
	 * Cases for the nonce test: each action, and a nonce that is missing, is another action's, or is for another target user.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function nonce_cases(): array {
		$cases = array();
		foreach ( array( AgentsHandler::ACTION_CREATE, AgentsHandler::ACTION_ADD_PASSWORD, AgentsHandler::ACTION_REVOKE, AgentsHandler::ACTION_REVOKE_ALL, AgentsHandler::ACTION_CAPABILITIES ) as $action ) {
			$cases[ $action . ', no nonce' ] = array( $action, 'none' );

			$cases[ $action . ', another action\'s nonce' ] = array( $action, 'other_action' );

			if ( AgentsHandler::ACTION_CREATE !== $action ) {
				$cases[ $action . ', another target\'s nonce' ] = array( $action, 'other_target' );
			}
		}

		return $cases;
	}

	/**
	 * A missing nonce, another action's nonce or another target's nonce is refused for every action, and changes nothing.
	 *
	 * @dataProvider nonce_cases
	 * @param string $action  The action the form posts.
	 * @param string $variant The nonce variant: none, other_action or other_target.
	 */
	public function test_a_missing_or_foreign_nonce_is_refused_for_every_action( string $action, string $variant ): void {
		$this->as_user( $this->admin() );

		$agent        = $this->agent( '', 2 );
		$other        = $this->agent( '', 1 );
		$before       = $this->state( $agent );
		$other_before = $this->state( $other );
		$users        = count( get_users( array( 'fields' => 'ID' ) ) );

		$fields = $this->fields_for( $action, $agent );

		$target = AgentsHandler::ACTION_CREATE === $action ? 0 : $agent;

		$other_action = AgentsHandler::ACTION_CAPABILITIES === $action ? AgentsHandler::ACTION_REVOKE_ALL : AgentsHandler::ACTION_CAPABILITIES;

		if ( 'other_action' === $variant ) {
			$fields['_wpnonce'] = $this->nonce( $other_action, $target );
		} elseif ( 'other_target' === $variant ) {
			$fields['_wpnonce'] = $this->nonce( $action, $other );
		}

		$this->assertSame( 'died', $this->post( $action, $fields ) );
		$this->assertSame( $before, $this->state( $agent ) );
		$this->assertSame( $other_before, $this->state( $other ) );
		$this->assertCount( $users, get_users( array( 'fields' => 'ID' ) ) );
	}

	public function test_a_role_with_create_users_edit_users_and_list_users_but_not_manage_options_is_refused_for_every_action(): void {
		$role = 'steadhand_test_no_manage';
		add_role(
			$role,
			'Test role',
			array(
				'read'         => true,
				'create_users' => true,
				'edit_users'   => true,
				'list_users'   => true,
			)
		);

		try {
			$this->as_user( self::factory()->user->create( array( 'role' => $role ) ) );
			$agent  = $this->agent( '', 1 );
			$before = $this->state( $agent );
			$users  = count( get_users( array( 'fields' => 'ID' ) ) );

			foreach ( $this->each_action( $agent ) as $action => $result ) {
				$this->assertSame( 'died', $result, $action );
			}
			$this->assertSame( 'died', $this->call( fn() => $this->render() ) );

			$this->assertSame( $before, $this->state( $agent ) );
			$this->assertCount( $users, get_users( array( 'fields' => 'ID' ) ) );
		} finally {
			remove_role( $role );
		}
	}

	public function test_a_role_with_manage_options_but_not_list_users_is_refused_for_every_action(): void {
		$role = 'steadhand_test_no_list';
		add_role(
			$role,
			'Test role',
			array(
				'read'           => true,
				'manage_options' => true,
				'create_users'   => true,
				'edit_users'     => true,
			)
		);

		try {
			$this->as_user( self::factory()->user->create( array( 'role' => $role ) ) );
			$agent  = $this->agent( '', 1 );
			$before = $this->state( $agent );
			$users  = count( get_users( array( 'fields' => 'ID' ) ) );

			foreach ( $this->each_action( $agent ) as $action => $result ) {
				$this->assertSame( 'died', $result, $action );
			}
			$this->assertSame( 'died', $this->call( fn() => $this->render() ) );

			$this->assertSame( $before, $this->state( $agent ) );
			$this->assertCount( $users, get_users( array( 'fields' => 'ID' ) ) );
		} finally {
			remove_role( $role );
		}
	}

	public function test_a_malformed_user_id_is_refused_and_changes_no_agent(): void {
		$agent = $this->agent( '', 1 );
		$this->as_user( $this->admin() );
		$before = $this->state( $agent );

		// The nonce is for target 0, because the screen reads a malformed ID as 0. The refusal is the not_agent code.
		foreach ( array( $agent . 'abc', ' ' . $agent, '-' . $agent, '1e3', array( (string) $agent ) ) as $raw ) {
			$url = $this->post(
				AgentsHandler::ACTION_REVOKE_ALL,
				array(
					'user_id'  => $raw,
					'_wpnonce' => $this->nonce( AgentsHandler::ACTION_REVOKE_ALL, 0 ),
				)
			);

			$this->assertIsString( $url, wp_json_encode( $raw ) );
			$this->assertStringContainsString( 'agents=not_agent', $url, wp_json_encode( $raw ) );
		}

		$this->assertSame( $before, $this->state( $agent ) );
	}

	public function test_an_empty_or_unknown_action_is_refused(): void {
		$this->as_user( $this->admin() );

		foreach ( array( '', 'bogus', 'CREATE', 'revoke-password' ) as $action ) {
			$this->assertSame( 'died', $this->post( $action, array( '_wpnonce' => $this->nonce( $action ) ) ), wp_json_encode( $action ) );
		}
	}

	public function test_the_load_hook_is_attached_only_to_the_agents_page(): void {
		// add_submenu_page() returns false for a user without the capability, so the menu is built as an administrator.
		$this->as_user( $this->admin() );
		do_action( 'admin_menu' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's admin_menu hook, on which the plugin's menu is built.

		$found = array();
		foreach ( $GLOBALS['wp_filter'] as $tag => $hook ) {
			if ( ! str_starts_with( (string) $tag, 'load-' ) ) {
				continue;
			}
			foreach ( $hook->callbacks as $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$function = $callback['function'];
					if ( is_array( $function ) && $function[0] instanceof AgentsScreen ) {
						$found[] = $tag;
					}
				}
			}
		}

		$this->assertSame( array( 'load-' . get_plugin_page_hookname( AgentsScreen::SLUG, LogScreen::SLUG ) ), array_values( array_unique( $found ) ) );
	}

	public function test_the_url_shows_only_redirect_codes_and_never_the_one_time_panel_codes(): void {
		$this->as_user( $this->admin() );

		$_GET = array( 'agents' => 'created' );
		$this->assertStringNotContainsString( 'The agent was created.', $this->render() );

		$_GET = array( 'agents' => 'password_added' );
		$this->assertStringNotContainsString( 'The password was added.', $this->render() );

		$_GET = array( 'agents' => 'create_failed' );
		$this->assertStringNotContainsString( 'The agent could not be created.', $this->render() );

		$_GET = array( 'agents' => 'password_revoked' );
		$this->assertStringContainsString( 'The password was revoked.', $this->render() );
	}

	public function test_the_url_shows_revoke_failed_and_capabilities_unverified_and_no_one_time_panel_notice(): void {
		$this->as_user( $this->admin() );

		$_GET = array( 'agents' => 'revoke_failed' );
		$this->assertStringContainsString( 'The password could not be revoked. It may still work.', $this->render() );

		$_GET = array( 'agents' => 'capabilities_unverified' );
		$this->assertStringContainsString( 'this user is no longer a dedicated agent, so they grant nothing', $this->render() );

		// The one-time panel's codes are never shown from a URL, even though the screen knows their text.
		$_GET = array( 'agents' => 'create_failed_cleanup' );
		$this->assertStringNotContainsString( 'removing the user it made did not finish', $this->render() );

		$_GET = array( 'agents' => 'extra_role' );
		$this->assertStringNotContainsString( 'another plugin gave the new user an extra role', $this->render() );
	}

	public function test_label_rules_refuse_invisible_control_separator_and_bad_utf8_characters(): void {
		$refused = array(
			"\u{202E}Desk",
			"Desk\u{202E}",
			"De\u{200B}sk",
			"Desk\u{200B}",
			"Desk\u{FEFF}",
			"Desk\u{00A0}",
			"\u{00A0}Desk",
			"\u{3000}Desk",
			"Desk\u{0085}",
			"Desk\u{2028}more",
			"Desk\u{2029}",
			"Caf\xC3\x28",
			"Desk\xFF",
		);
		foreach ( $refused as $label ) {
			$this->assertFalse( AgentsHandler::is_valid_label( $label ), bin2hex( $label ) );
		}

		// Length is counted in characters: 60 two-byte characters are accepted, 61 are not.
		$this->assertTrue( AgentsHandler::is_valid_label( str_repeat( "\u{00E9}", 60 ) ) );
		$this->assertFalse( AgentsHandler::is_valid_label( str_repeat( "\u{00E9}", 61 ) ) );
		$this->assertTrue( AgentsHandler::is_valid_label( "Caf\u{00E9} desk" ) );

		// Through create, a refused label leaves no user behind.
		$this->as_user( $this->admin() );
		$users = count( get_users( array( 'fields' => 'ID' ) ) );
		$this->assertSame( 'invalid_label', $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'rtl-agent', "\u{202E}Desk" )['code'] );
		$this->assertFalse( username_exists( 'rtl-agent' ) );
		$this->assertCount( $users, get_users( array( 'fields' => 'ID' ) ) );
	}

	public function test_the_password_is_stored_in_no_table_and_passed_to_no_write_hook(): void {
		$this->as_user( $this->admin() );
		$seen    = array();
		$capture = static function ( ...$args ) use ( &$seen ): void {
			// The 'all' hook passes the hook name first. Core carries the plaintext in two places by design: wp_generate_password() passes
			// it through its random_password filter, and core's wp_create_application_password action receives it. Steadhand listens to
			// neither. Every other hook, including every option, transient and meta write, must not carry it.
			if ( in_array( (string) ( $args[0] ?? '' ), array( 'random_password', 'wp_create_application_password' ), true ) ) {
				return;
			}
			$seen[] = print_r( array_slice( $args, 1 ), true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- Test: captures the arguments as text.
		};

		add_filter( 'all', $capture, PHP_INT_MIN, 10 );
		try {
			$this->post(
				AgentsHandler::ACTION_CREATE,
				array(
					'_wpnonce' => $this->nonce( AgentsHandler::ACTION_CREATE ),
					'username' => 'stored-nowhere',
					'label'    => 'Stored nowhere',
				)
			);
		} finally {
			remove_filter( 'all', $capture, PHP_INT_MIN );
		}

		$password = $this->password_on_screen( $this->render() );
		$this->assertNotSame( '', $password );
		$this->assertNotEmpty( $seen, 'Hooks ran during the request.' );

		$plain = array( $password, str_replace( ' ', '', $password ) );
		foreach ( $seen as $arguments ) {
			foreach ( $plain as $text ) {
				$this->assertStringNotContainsString( $text, $arguments );
			}
		}

		// Every row of every table of the test database, whatever its columns.
		global $wpdb;
		foreach ( $wpdb->get_col( 'SHOW TABLES' ) as $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test: reads every table; the names come from SHOW TABLES.
			foreach ( $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_N ) as $row ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test: reads every row.
				foreach ( $row as $value ) {
					foreach ( $plain as $text ) {
						$this->assertStringNotContainsString( $text, (string) $value, "table $table" );
					}
				}
			}
		}
	}

	public function test_on_a_multisite_network_every_action_is_refused_before_anything_changes(): void {
		$network = new AgentsHandler( ( new Plugin() )->registry(), true );
		$agent   = $this->agent( '', 1 );
		$other   = $this->admin();
		$this->as_user( $this->admin() );
		$before = $this->state( $agent );
		$users  = count( get_users( array( 'fields' => 'ID' ) ) );
		$uuid   = \WP_Application_Passwords::get_user_application_passwords( $agent )[0]['uuid'];

		$this->assertSame( 'multisite', $network->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'network-agent', 'Desk' )['code'] );
		$this->assertSame( 'multisite', $network->add_password( $this->nonce( AgentsHandler::ACTION_ADD_PASSWORD, $agent ), $agent, 'Extra' )['code'] );
		$this->assertSame( 'multisite', $network->revoke( $this->nonce( AgentsHandler::ACTION_REVOKE, $agent ), $agent, $uuid )['code'] );
		$this->assertSame( 'multisite', $network->revoke_all( $this->nonce( AgentsHandler::ACTION_REVOKE_ALL, $agent ), $agent )['code'] );
		$this->assertSame( 'multisite', $network->set_capabilities( $this->nonce( AgentsHandler::ACTION_CAPABILITIES, $agent ), $agent, array( Capabilities::READ ) )['code'] );
		// The network check comes before the agent check, so a non-agent target gets the same answer.
		$this->assertSame( 'multisite', $network->revoke_all( $this->nonce( AgentsHandler::ACTION_REVOKE_ALL, $other ), $other )['code'] );

		$this->assertSame( $before, $this->state( $agent ) );
		$this->assertCount( $users, get_users( array( 'fields' => 'ID' ) ) );
		$this->assertFalse( username_exists( 'network-agent' ) );
	}

	public function test_on_a_multisite_network_a_missing_nonce_is_still_refused_with_wp_die(): void {
		$network = new AgentsHandler( ( new Plugin() )->registry(), true );
		$this->as_user( $this->admin() );

		$this->assertSame( 'died', $this->call( fn() => $network->create( '', 'network-agent', 'Desk' ) ) );
	}

	public function test_on_a_multisite_network_the_screen_shows_only_the_notice(): void {
		$this->screen = new AgentsScreen( new AgentsHandler( ( new Plugin() )->registry(), true ) );
		$this->agent( 'network-listed', 1 );
		$this->as_user( $this->admin() );

		$html = $this->render();

		$this->assertStringContainsString( 'Agent management is not available on multisite networks.', $html );
		$this->assertStringNotContainsString( 'network-listed', $html );
		$this->assertStringNotContainsString( 'name="' . AgentsHandler::FIELD . '"', $html );
		$this->assertStringNotContainsString( 'name="username"', $html );
	}

	public function test_the_handler_checks_is_multisite_unless_a_test_says_otherwise(): void {
		$this->assertSame( is_multisite(), ( new AgentsHandler( ( new Plugin() )->registry() ) )->is_network() );
	}
}
