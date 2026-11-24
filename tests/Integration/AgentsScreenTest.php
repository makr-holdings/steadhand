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

		foreach ( array( $admin, $editor, $mixed, 999999, 0 ) as $target ) {
			$before = $target > 0 ? $this->state( $target ) : null;
			$nonce  = $this->nonce( AgentsHandler::ACTION_CAPABILITIES, $target );

			$this->assertTrue( $this->refused( fn() => $this->handler->add_password( $this->nonce( AgentsHandler::ACTION_ADD_PASSWORD, $target ), $target, 'Extra' ) ) );
			$this->assertTrue( $this->refused( fn() => $this->handler->revoke_all( $this->nonce( AgentsHandler::ACTION_REVOKE_ALL, $target ), $target ) ) );
			$this->assertTrue( $this->refused( fn() => $this->handler->revoke( $this->nonce( AgentsHandler::ACTION_REVOKE, $target ), $target, '0b0bfbea-2a1f-4e1b-9a5a-000000000001' ) ) );
			$this->assertTrue(
				$this->refused( fn() => $this->handler->set_capabilities( $nonce, $target, array( Capabilities::CONNECT, Capabilities::PUBLISH, Capabilities::MEDIA ) ) )
			);

			if ( $target > 0 ) {
				$this->assertSame( $before, $this->state( $target ) );
			}
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
		$this->assertStringNotContainsString( $uuid, $url );
	}

	public function test_only_offered_capabilities_are_set_and_the_rest_are_ignored(): void {
		$agent = $this->agent( '', 1 );
		$this->as_user( $this->admin() );
		$offered = $this->handler->offered_capabilities();

		$this->assertContains( Capabilities::CONNECT, $offered );
		$this->assertContains( Capabilities::PUBLISH, $offered );
		$this->assertNotContains( Capabilities::MENUS, $offered, 'Not built yet, so not offered.' );
		$this->assertNotContains( Capabilities::TRASH, $offered );
		$this->assertNotContains( Capabilities::SETTINGS, $offered );
		$this->assertNotContains( Capabilities::NO_GRANT, $offered );

		$posted = array( Capabilities::CONNECT, Capabilities::PUBLISH, Capabilities::MENUS, Capabilities::NO_GRANT, 'manage_options', 'edit_posts', 'administrator' );
		$result = $this->handler->set_capabilities( $this->nonce( AgentsHandler::ACTION_CAPABILITIES, $agent ), $agent, $posted );

		$this->assertSame( 'capabilities_saved', $result['code'] );
		$user = get_userdata( $agent );
		$this->assertTrue( Capabilities::user_has( $user, Capabilities::PUBLISH ) );
		$this->assertFalse( Capabilities::user_has( $user, Capabilities::MENUS ) );
		$this->assertFalse( Capabilities::user_has( $user, Capabilities::NO_GRANT ) );
		foreach ( array( 'manage_options', 'edit_posts', 'administrator', Capabilities::MENUS, Capabilities::NO_GRANT ) as $name ) {
			$this->assertArrayNotHasKey( $name, $user->caps, $name );
		}
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
		get_userdata( $agent )->add_cap( Capabilities::MENUS, true );
		$this->as_user( $this->admin() );

		$this->handler->set_capabilities( $this->nonce( AgentsHandler::ACTION_CAPABILITIES, $agent ), $agent, array() );

		$this->assertTrue( get_userdata( $agent )->caps[ Capabilities::MENUS ] );
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
	}

	public function test_the_screen_refuses_a_viewer_who_is_not_an_administrator_or_holds_the_agent_role(): void {
		$this->as_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 'died', $this->call( fn() => $this->render() ) );

		$admin = $this->admin();
		get_userdata( $admin )->add_role( Roles::ROLE );
		$this->as_user( $admin );
		$this->assertSame( 'died', $this->call( fn() => $this->render() ) );
	}

	public function test_a_label_with_markup_is_escaped_on_the_screen(): void {
		$agent = $this->agent( '', 1 );
		$items = \WP_Application_Passwords::get_user_application_passwords( $agent );
		// Core sanitises a name when it creates one, so the markup is put in the stored row directly: the screen must escape it anyway.
		$items[0]['name'] = '<img src=x onerror=alert(1)>';
		update_user_meta( $agent, \WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, $items );

		$this->as_user( $this->admin() );
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
		$this->assertStringNotContainsString( 'name="' . AgentsHandler::FIELD . '"', $html );
		$this->assertStringNotContainsString( 'name="username"', $html );
		$this->assertStringNotContainsString( 'name="capabilities[]"', $html );
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

	public function test_the_row_action_fires_per_dedicated_agent_with_the_user(): void {
		$agent = $this->agent( 'row-agent', 1 );
		$this->agent( 'row-agent-two', 1 );
		$this->as_user( $this->admin() );
		$seen = array();
		$this->on(
			'steadhand_admin_agent_row',
			static function ( $user ) use ( &$seen ) {
				$seen[] = $user->ID;
			},
			10
		);

		$this->render();

		$this->assertContains( $agent, $seen );
		$this->assertCount( 2, $seen );
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

		$this->assertStringContainsString( 'throw-agent', $this->render() );
	}
}
