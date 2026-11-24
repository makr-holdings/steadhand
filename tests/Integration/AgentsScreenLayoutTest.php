<?php
/**
 * The three views of the Agents screen: the list, one agent's detail and the add form. Each view must show only what it should, and the
 * refusals (a user who is not a dedicated agent, a malformed argument, a viewer who may not create) must print nothing about the user.
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
 * Negative tests first: a view must not show a user, a form or a password that it should not.
 */
final class AgentsScreenLayoutTest extends TestCase {

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
	 * The rendered page as the current user, for the current $_GET.
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

	/**
	 * Runs a render; returns the page, or 'died' when it is refused with wp_die().
	 *
	 * @return string
	 */
	private function render_or_died(): string {
		try {
			return $this->render();
		} catch ( \WPDieException ) {
			return 'died';
		}
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

	/**
	 * The capability checkboxes of a page: each value, and whether it is checked.
	 *
	 * @param string $html The page.
	 * @return array<string, bool>
	 */
	private function checkboxes( string $html ): array {
		$boxes = array();
		preg_match_all( '/<input type="checkbox" name="capabilities\[\]" value="([^"]+)"([^>]*)>/', $html, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			$boxes[ $match[1] ] = str_contains( $match[2], 'checked' );
		}

		return $boxes;
	}

	public function test_the_list_view_is_one_table_with_a_row_per_agent_and_no_forms_or_nonces(): void {
		$this->agent( 'layout-list-one', 2 );
		$this->agent( 'layout-list-two', 0 );
		$this->as_user( $this->admin() );

		$html = $this->render();

		$this->assertSame( 1, substr_count( $html, 'class="widefat striped"' ) );
		$this->assertSame( 2, substr_count( $html, 'class="button button-small"' ), 'One Manage link per agent.' );
		$this->assertStringContainsString( 'layout-list-one', $html );
		$this->assertStringContainsString( 'layout-list-two', $html );
		$this->assertStringNotContainsString( '<form', $html );
		$this->assertStringNotContainsString( '_wpnonce', $html );
	}

	public function test_the_list_view_with_no_agents_prints_no_table(): void {
		$this->as_user( $this->admin() );

		$html = $this->render();

		$this->assertStringContainsString( 'There are no agents yet.', $html );
		$this->assertStringNotContainsString( '<table', $html );
	}

	public function test_the_add_agent_link_shows_for_an_administrator_and_not_for_a_viewer_without_create_users(): void {
		$this->as_user( $this->admin() );
		$html = $this->render();
		$this->assertStringContainsString( 'class="page-title-action"', $html );
		$this->assertStringContainsString( 'view=new', $html );

		$this->on( 'map_meta_cap', static fn( $caps, $cap ) => 'create_users' === $cap ? array( 'do_not_allow' ) : $caps, 10 );
		$this->assertStringNotContainsString( 'page-title-action', $this->render() );
	}

	public function test_a_detail_request_for_an_administrator_prints_the_refusal_and_nothing_about_the_administrator(): void {
		$login  = 'layout-admin-target';
		$target = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => $login,
			)
		);
		$this->as_user( $this->admin() );
		$_GET = array( 'agent' => (string) $target );

		$html = $this->render();

		$this->assertStringContainsString( 'That user is not a dedicated agent.', $html );
		$this->assertStringNotContainsString( $login, $html );
		$this->assertStringNotContainsString( '<h2>', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_a_detail_request_for_a_missing_user_prints_the_refusal_and_no_form(): void {
		$this->as_user( $this->admin() );
		$_GET = array( 'agent' => '987654321' );

		$html = $this->render();

		$this->assertStringContainsString( 'That user is not a dedicated agent.', $html );
		$this->assertStringNotContainsString( '<h2>', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_a_detail_request_for_a_mixed_role_user_shows_the_normal_list_warning_and_the_fixed_notice(): void {
		$login = 'layout-mixed-login';
		$mixed = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => $login,
			)
		);
		get_userdata( $mixed )->add_role( Roles::ROLE );
		$this->as_user( $this->admin() );

		$_GET = array();
		$list = $this->render();

		$_GET = array( 'agent' => (string) $mixed );
		$html = $this->render();

		$notice = '<div class="notice notice-error"><p>That user is not a dedicated agent.</p></div>';
		$this->assertStringContainsString( $notice, $html );
		$this->assertStringContainsString( $login . ': This user also has another role', $html, 'The list prints its normal warning for the mixed-role holder.' );
		$this->assertSame( $list, str_replace( $notice, '', $html ), 'The fallback is the normal list output plus the fixed notice, and nothing else.' );
		$this->assertStringNotContainsString( '<h2>', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_a_detail_request_for_an_administrator_or_a_missing_user_adds_nothing_about_that_user_to_the_list(): void {
		$this->as_user( $this->admin() );

		$_GET = array();
		$list = $this->render();

		$admin = self::factory()->user->create(
			array(
				'role'       => 'administrator',
				'user_login' => 'layout-admin-plain',
			)
		);

		$_GET = array( 'agent' => (string) $admin );
		$html = $this->render();
		$this->assertSame( $list, str_replace( '<div class="notice notice-error"><p>That user is not a dedicated agent.</p></div>', '', $html ) );
		$this->assertStringNotContainsString( 'layout-admin-plain', $html );

		$_GET = array( 'agent' => '987654321' );
		$html = $this->render();
		$this->assertSame( $list, str_replace( '<div class="notice notice-error"><p>That user is not a dedicated agent.</p></div>', '', $html ) );
	}

	public function test_a_create_post_with_an_invalid_nonce_dies_and_no_posted_value_is_printed(): void {
		$this->as_user( $this->admin() );
		$marker = 'zz-marker-zz';

		ob_start();
		$result = $this->post(
			AgentsHandler::ACTION_CREATE,
			array(
				'username'             => $marker,
				'label'                => 'Desk app',
				'capabilities_present' => '1',
				'capabilities'         => array( Capabilities::CONNECT ),
				'_wpnonce'             => 'not-a-valid-nonce',
			)
		);
		$output = (string) ob_get_clean();

		$this->assertSame( 'died', $result, 'The guard refuses a bad nonce with wp_die().' );
		$this->assertFalse( username_exists( $marker ), 'No user is made.' );
		$this->assertStringNotContainsString( $marker, $output );

		$html = $this->render();
		$this->assertStringNotContainsString( $marker, $html );
	}

	public function test_malformed_agent_and_view_arguments_fall_back_to_the_list(): void {
		$this->agent( 'layout-fallback', 1 );
		$this->as_user( $this->admin() );

		foreach ( array( 'abc', '-1', '1 OR 1=1', '1e3', '' ) as $value ) {
			$_GET = array( 'agent' => $value );
			$html = $this->render();
			$this->assertStringContainsString( 'class="widefat striped"', $html, wp_json_encode( $value ) );
			$this->assertStringNotContainsString( '<h2>', $html, wp_json_encode( $value ) );
			$this->assertStringNotContainsString( 'notice', $html, wp_json_encode( $value ) );
			$this->assertStringNotContainsString( '<form', $html, wp_json_encode( $value ) );
		}

		// view=new is an exact string. Any other value is the list.
		$_GET = array( 'view' => 'NEW' );
		$html = $this->render();
		$this->assertStringContainsString( 'class="widefat striped"', $html );
		$this->assertStringNotContainsString( 'name="username"', $html );
	}

	public function test_the_detail_view_of_one_agent_shows_no_other_agent(): void {
		$a = $this->agent( 'layout-agent-a', 1 );
		$b = $this->agent( 'layout-agent-b', 1 );
		$this->as_user( $this->admin() );
		$uuid_b = \WP_Application_Passwords::get_user_application_passwords( $b )[0]['uuid'];
		$_GET   = array( 'agent' => (string) $a );

		$html = $this->render();

		$this->assertStringContainsString( '<h2>layout-agent-a</h2>', $html );
		$this->assertStringNotContainsString( 'layout-agent-b', $html );
		$this->assertStringNotContainsString( 'name="user_id" value="' . $b . '"', $html );
		$this->assertStringNotContainsString( $uuid_b, $html );
		$this->assertStringContainsString( 'name="user_id" value="' . $a . '"', $html );
	}

	public function test_the_add_view_pre_checks_the_role_defaults_and_carries_the_capabilities_marker(): void {
		$this->as_user( $this->admin() );
		$_GET = array( 'view' => 'new' );

		$html     = $this->render();
		$defaults = Capabilities::agent_defaults();
		$boxes    = $this->checkboxes( $html );

		$this->assertSame( $this->handler->offered_capabilities(), array_keys( $boxes ) );
		foreach ( $boxes as $capability => $checked ) {
			$this->assertSame( true === ( $defaults[ $capability ] ?? false ), $checked, $capability );
		}
		$this->assertStringContainsString( '<input type="hidden" name="capabilities_present" value="1">', $html );
		$this->assertStringContainsString( 'name="username"', $html );
		$this->assertStringContainsString( 'name="label"', $html );
		$this->assertStringContainsString( 'A name for the app that will use this password, such as Claude Desktop. It is shown next to the agent in the change log. You can add more connections to the agent later.', $html );
		$this->assertStringContainsString( 'Back to all agents', $html );
	}

	public function test_a_viewer_without_create_users_gets_no_create_form_at_view_new(): void {
		$this->on( 'map_meta_cap', static fn( $caps, $cap ) => 'create_users' === $cap ? array( 'do_not_allow' ) : $caps, 10 );
		$this->as_user( $this->admin() );
		$_GET = array( 'view' => 'new' );

		$html = $this->render();

		$this->assertStringNotContainsString( 'name="username"', $html );
		$this->assertStringNotContainsString( 'name="capabilities[]"', $html );
		$this->assertStringNotContainsString( 'Create an agent', $html );
		$this->assertStringNotContainsString( '<form', $html );
	}

	public function test_without_application_passwords_no_create_form_is_shown_at_view_new(): void {
		$this->on( 'wp_is_application_passwords_available', static fn() => false, 10 );
		$this->as_user( $this->admin() );
		$_GET = array( 'view' => 'new' );

		$html = $this->render();

		$this->assertStringContainsString( 'Application Passwords are not available on this site', $html );
		$this->assertStringNotContainsString( 'name="username"', $html );
		$this->assertStringNotContainsString( 'name="capabilities[]"', $html );
		$this->assertStringNotContainsString( 'page-title-action', $html );
	}

	public function test_a_successful_create_shows_the_one_time_panel_then_the_new_agents_detail(): void {
		$this->as_user( $this->admin() );
		$this->post(
			AgentsHandler::ACTION_CREATE,
			array(
				'_wpnonce' => $this->nonce( AgentsHandler::ACTION_CREATE ),
				'username' => 'layout-new',
				'label'    => 'Desk app',
			)
		);

		$html = $this->render();

		$panel  = strpos( $html, 'Application password:' );
		$detail = strpos( $html, '<h2>layout-new</h2>' );
		$this->assertIsInt( $panel );
		$this->assertIsInt( $detail );
		$this->assertLessThan( $detail, $panel, 'The panel comes before the detail.' );
		$this->assertStringContainsString( 'The agent was created. Its password is shown below.', $html );
		$this->assertStringContainsString( 'Desk app', $html );
		$this->assertStringNotContainsString( 'name="username"', $html );
	}

	public function test_a_create_with_no_box_checked_grants_no_capability(): void {
		$this->as_user( $this->admin() );
		$this->post(
			AgentsHandler::ACTION_CREATE,
			array(
				'_wpnonce'             => $this->nonce( AgentsHandler::ACTION_CREATE ),
				'username'             => 'layout-none',
				'label'                => 'Desk',
				'capabilities_present' => '1',
			)
		);

		$user = get_user_by( 'login', 'layout-none' );
		$this->assertInstanceOf( \WP_User::class, $user );
		foreach ( Capabilities::all() as $capability ) {
			$this->assertFalse( Capabilities::user_has( $user, $capability ), $capability );
		}
	}

	public function test_a_failed_create_shows_the_add_view_and_no_panel(): void {
		$this->as_user( $this->admin() );
		$this->post(
			AgentsHandler::ACTION_CREATE,
			array(
				'_wpnonce' => $this->nonce( AgentsHandler::ACTION_CREATE ),
				'username' => 'Not Valid!',
				'label'    => 'Desk',
			)
		);

		$html = $this->render();

		$this->assertStringContainsString( 'The username is not valid.', $html );
		$this->assertStringContainsString( 'name="username"', $html );
		$this->assertStringNotContainsString( 'Application password:', $html );
	}

	public function test_set_capabilities_and_revoke_redirects_carry_the_agent_and_the_code(): void {
		$agent = $this->agent( '', 1 );
		$this->as_user( $this->admin() );
		$uuid = \WP_Application_Passwords::get_user_application_passwords( $agent )[0]['uuid'];

		$url = $this->post(
			AgentsHandler::ACTION_CAPABILITIES,
			array(
				'user_id'      => (string) $agent,
				'_wpnonce'     => $this->nonce( AgentsHandler::ACTION_CAPABILITIES, $agent ),
				'capabilities' => array( Capabilities::READ ),
			)
		);
		$this->assertIsString( $url );
		$this->assertSame(
			array(
				'page'   => AgentsScreen::SLUG,
				'agents' => 'capabilities_saved',
				'agent'  => (string) $agent,
			),
			array_intersect_key( $this->query_of( $url ), array_flip( array( 'page', 'agents', 'agent' ) ) )
		);

		$url = $this->post(
			AgentsHandler::ACTION_REVOKE,
			array(
				'user_id'  => (string) $agent,
				'uuid'     => $uuid,
				'_wpnonce' => $this->nonce( AgentsHandler::ACTION_REVOKE, $agent ),
			)
		);
		$this->assertIsString( $url );
		$this->assertSame( (string) $agent, $this->query_of( $url )['agent'] ?? '' );
		$this->assertSame( 'password_revoked', $this->query_of( $url )['agents'] ?? '' );
	}

	public function test_the_row_action_fires_zero_times_in_the_list_and_add_views_and_a_throwing_listener_breaks_nothing(): void {
		$agent = $this->agent( 'layout-throw', 1 );
		$this->as_user( $this->admin() );
		$seen = 0;
		$this->on(
			'steadhand_admin_agent_row',
			static function () use ( &$seen ): void {
				++$seen;
				throw new \RuntimeException( 'listener failure' );
			},
			10
		);

		$this->assertStringContainsString( 'layout-throw', $this->render() );
		$_GET = array( 'view' => 'new' );
		$this->render();
		$this->assertSame( 0, $seen, 'Neither the list nor the add view fires it.' );

		$_GET = array( 'agent' => (string) $agent );
		$html = $this->render();
		$this->assertSame( 1, $seen, 'The detail view fires it once.' );
		$this->assertStringContainsString( '<h2>layout-throw</h2>', $html );
	}

	public function test_an_agent_with_no_connections_says_so_and_offers_the_add_form(): void {
		$agent = $this->agent( 'layout-empty', 0 );
		$this->as_user( $this->admin() );
		$_GET = array( 'agent' => (string) $agent );

		$html = $this->render();

		$this->assertStringContainsString( 'This agent has no connections, so it cannot connect.', $html );
		$this->assertStringContainsString( 'Add connection', $html );
		$this->assertStringContainsString( 'name="label"', $html );
	}

	public function test_the_list_and_detail_views_are_refused_for_every_view_by_a_viewer_who_may_not_manage_agents(): void {
		$agent = $this->agent( 'layout-refused', 1 );
		$this->as_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		foreach ( array( array(), array( 'view' => 'new' ), array( 'agent' => (string) $agent ) ) as $query ) {
			$_GET = $query;
			$this->assertSame( 'died', $this->render_or_died(), wp_json_encode( $query ) );
		}
	}

	/**
	 * Sends a create POST as an administrator, with the given fields on top of the nonce and a label, and returns the page it redraws.
	 *
	 * @param array<string,mixed> $fields The form fields.
	 * @return string
	 */
	private function refused_create( array $fields ): string {
		$this->as_user( $this->admin() );
		$this->post(
			AgentsHandler::ACTION_CREATE,
			array_merge(
				array(
					'_wpnonce' => $this->nonce( AgentsHandler::ACTION_CREATE ),
					'label'    => 'Desk app',
				),
				$fields
			)
		);

		return $this->render();
	}

	public function test_a_refused_create_redraws_the_form_with_the_boxes_as_posted(): void {
		self::factory()->user->create( array( 'user_login' => 'taken-login' ) );

		$html  = $this->refused_create(
			array(
				'username'             => 'taken-login',
				'capabilities_present' => '1',
				'capabilities'         => array( Capabilities::CONNECT, Capabilities::READ ),
			)
		);
		$boxes = $this->checkboxes( $html );

		$this->assertStringContainsString( 'That username already exists.', $html );
		$this->assertStringNotContainsString( 'Application password:', $html );
		$this->assertSame( $this->handler->offered_capabilities(), array_keys( $boxes ) );
		foreach ( $boxes as $capability => $checked ) {
			$this->assertSame( in_array( $capability, array( Capabilities::CONNECT, Capabilities::READ ), true ), $checked, $capability );
		}
		$this->assertArrayHasKey( Capabilities::WRITE_DRAFTS, $boxes );
		$this->assertFalse( $boxes[ Capabilities::WRITE_DRAFTS ], 'A box the administrator unchecked stays unchecked after a refusal.' );
		$this->assertStringContainsString( 'value="taken-login"', $html );
		$this->assertStringContainsString( 'value="Desk app"', $html );
	}

	public function test_posted_text_is_printed_only_escaped_inside_a_value_attribute(): void {
		$login = '"><script>alert(1)</script>';
		$label = '"><img src=x onerror=alert(1)>';

		$html = $this->refused_create(
			array(
				'username' => $login,
				'label'    => $label,
			)
		);

		$this->assertStringNotContainsString( $login, $html, 'The raw login is never printed.' );
		$this->assertStringNotContainsString( $label, $html, 'The raw connection name is never printed.' );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringContainsString( 'value="' . esc_attr( $login ) . '"', $html );
		$this->assertStringContainsString( 'value="' . esc_attr( $label ) . '"', $html );
	}

	public function test_a_posted_name_that_is_not_offered_gets_no_checkbox(): void {
		self::factory()->user->create( array( 'user_login' => 'taken-two' ) );

		$html  = $this->refused_create(
			array(
				'username'             => 'taken-two',
				'capabilities_present' => '1',
				'capabilities'         => array( 'manage_options', Capabilities::READ ),
			)
		);
		$boxes = $this->checkboxes( $html );

		$this->assertSame( $this->handler->offered_capabilities(), array_keys( $boxes ) );
		$this->assertArrayNotHasKey( 'manage_options', $boxes );
		$this->assertStringNotContainsString( 'value="manage_options"', $html );
		$this->assertTrue( $boxes[ Capabilities::READ ] );
	}

	public function test_a_new_request_for_the_add_view_after_a_refused_create_shows_the_defaults(): void {
		self::factory()->user->create( array( 'user_login' => 'taken-three' ) );
		$this->refused_create(
			array(
				'username'             => 'taken-three',
				'capabilities_present' => '1',
				'capabilities'         => array( Capabilities::CONNECT ),
			)
		);

		// The next request is a new screen, a GET, with nothing carried over from the POST.
		$this->screen              = new AgentsScreen( $this->handler );
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = array( 'view' => 'new' );

		$html     = $this->render();
		$defaults = Capabilities::agent_defaults();
		foreach ( $this->checkboxes( $html ) as $capability => $checked ) {
			$this->assertSame( true === ( $defaults[ $capability ] ?? false ), $checked, $capability );
		}
		$this->assertStringNotContainsString( 'taken-three', $html );
	}

	public function test_a_get_on_the_same_screen_after_a_refused_create_shows_the_defaults(): void {
		self::factory()->user->create( array( 'user_login' => 'taken-four' ) );
		$this->refused_create(
			array(
				'username'             => 'taken-four',
				'capabilities_present' => '1',
				'capabilities'         => array( Capabilities::CONNECT ),
			)
		);

		// The same screen object now receives a GET of view=new. The posted create must not be drawn again.
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = array( 'view' => 'new' );
		$this->screen->process_post();

		$html     = $this->render();
		$defaults = Capabilities::agent_defaults();
		foreach ( $this->checkboxes( $html ) as $capability => $checked ) {
			$this->assertSame( true === ( $defaults[ $capability ] ?? false ), $checked, $capability );
		}
		$this->assertStringNotContainsString( 'taken-four', $html );
	}

	public function test_after_a_successful_create_a_later_get_never_shows_the_password(): void {
		$this->as_user( $this->admin() );
		$this->post(
			AgentsHandler::ACTION_CREATE,
			array(
				'_wpnonce' => $this->nonce( AgentsHandler::ACTION_CREATE ),
				'username' => 'layout-once',
				'label'    => 'Desk app',
			)
		);

		$first = $this->render();
		$this->assertSame( 1, preg_match( '/Application password: <code>([^<]+)<\/code>/', $first, $match ), 'The POST response shows the password.' );
		$password = $match[1];
		$id       = (int) get_user_by( 'login', 'layout-once' )->ID;

		$queries = array(
			array( 'agent' => (string) $id ),
			array(
				'agent'  => (string) $id,
				'agents' => 'created',
			),
		);
		foreach ( $queries as $query ) {
			// A new request: a new screen and a GET, with nothing carried over from the POST.
			$this->screen              = new AgentsScreen( $this->handler );
			$_POST                     = array();
			$_SERVER['REQUEST_METHOD'] = 'GET';
			$_GET                      = $query;

			$html = $this->render();
			$this->assertStringNotContainsString( 'Application password:', $html, wp_json_encode( $query ) );
			$this->assertStringNotContainsString( $password, $html, wp_json_encode( $query ) );
		}
	}
}
