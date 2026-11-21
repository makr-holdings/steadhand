<?php
/**
 * Lockdown, sign-in: agents cannot sign in with a password, and an agent is only ever the current user
 * through its own application password.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Roles;

/**
 * Covers SPEC §4.1 ("agent users cannot log in to wp-admin interactively") and the two sign-in rules in
 * Lockdown (includes/Lockdown.php):
 *
 * - authenticate (method refuse_agent_login): the `authenticate` chain. wp-login.php, wp_signon() and
 *   XML-RPC login (class-wp-xmlrpc-server.php login(), which calls wp_authenticate()) all pass through it.
 * - determine_current_user (method refuse_non_application_password_identity): the cookie path and any
 *   other path that does not go through an application password.
 *
 * REST application-password authentication does not run the `authenticate` chain (it calls
 * wp_authenticate_application_password() directly from wp_validate_application_password()), so the REST
 * case is handled by the determine_current_user rule and the route rule, not by the login rule.
 */
final class LockdownLoginTest extends TestCase {

	private const ACCOUNT_PASSWORD = 'account-password-for-tests';

	/**
	 * Creates an agent with a known account password, as a stand-in for an admin who set one.
	 *
	 * @return \WP_User
	 */
	private function agent_with_account_password(): \WP_User {
		$agent_id = self::factory()->user->create(
			array(
				'role'      => Roles::ROLE,
				'user_pass' => self::ACCOUNT_PASSWORD,
			)
		);

		return new \WP_User( $agent_id );
	}

	/**
	 * Runs the determine_current_user chain as if core had resolved $user_id just before Lockdown's rule.
	 *
	 * Core's own callbacks (wp_validate_auth_cookie() at priority 10 and the others) ignore an incoming ID,
	 * so passing an ID straight to apply_filters() would test core, not Lockdown. This injects the ID one
	 * priority before PHP_INT_MAX, where Lockdown runs.
	 *
	 * @param int $user_id User ID that core would have resolved.
	 * @return mixed The chain's result.
	 */
	private function resolved_by_core( int $user_id ): mixed {
		$inject = static fn () => $user_id;
		add_filter( 'determine_current_user', $inject, PHP_INT_MAX - 1 );

		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
			return apply_filters( 'determine_current_user', false );
		} finally {
			remove_filter( 'determine_current_user', $inject, PHP_INT_MAX - 1 );
		}
	}

	/**
	 * Defines REST_REQUEST, as rest_api_loaded() does for a REST request. Only for tests that run in their own process,
	 * because the constant cannot be undefined afterwards.
	 */
	private function define_rest_request(): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Core's own constant.
		define( 'REST_REQUEST', true );
	}

	/**
	 * Guards refuse_agent_login() for password sign-in: the right password does not produce a session.
	 * wp_authenticate() is what wp-login.php, wp_signon() and XML-RPC call (pluggable.php), so this covers all three.
	 */
	public function test_agent_is_refused_password_sign_in_even_with_the_right_password(): void {
		$agent = $this->agent_with_account_password();

		$result = wp_authenticate( $agent->user_login, self::ACCOUNT_PASSWORD );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_agent_login_blocked', $result->get_error_code() );
	}

	/**
	 * Guards the rule against revealing more than core does: with a wrong password the agent gets core's own
	 * error, unchanged by Lockdown.
	 */
	public function test_agent_with_a_wrong_password_gets_the_core_error(): void {
		$agent = $this->agent_with_account_password();

		$result = wp_authenticate( $agent->user_login, 'not-the-password' );

		$this->assertWPError( $result );
		$this->assertSame( 'incorrect_password', $result->get_error_code() );
	}

	/**
	 * Guards wp_signon(), the programmatic sign-in used by wp-login.php. A refused sign-in sets no cookie.
	 */
	public function test_wp_signon_is_refused_for_an_agent(): void {
		$agent = $this->agent_with_account_password();

		$result = wp_signon(
			array(
				'user_login'    => $agent->user_login,
				'user_password' => self::ACCOUNT_PASSWORD,
				'remember'      => false,
			),
			false
		);

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_agent_login_blocked', $result->get_error_code() );
	}

	/**
	 * Negative control: the login rule is only for agents. A subscriber with the right password still signs in.
	 */
	public function test_subscriber_with_the_right_password_still_signs_in(): void {
		$user_id = self::factory()->user->create(
			array(
				'role'      => 'subscriber',
				'user_pass' => self::ACCOUNT_PASSWORD,
			)
		);
		$user    = new \WP_User( $user_id );

		$result = wp_authenticate( $user->user_login, self::ACCOUNT_PASSWORD );

		$this->assertInstanceOf( \WP_User::class, $result );
		$this->assertSame( $user_id, $result->ID );
	}

	/**
	 * Guards refuse_agent_login() on the XML-RPC password path. Core's wp_authenticate_application_password()
	 * runs in the authenticate chain only for API requests (application_password_is_api_request, which XML-RPC
	 * satisfies through XMLRPC_REQUEST), so the test sets that filter to true, as core does for XML-RPC.
	 * The agent's application password then produces a WP_User in the chain, and Lockdown must refuse it.
	 * The control case shows the same call works for a subscriber, so the refusal is caused by the rule.
	 */
	public function test_agent_application_password_is_refused_through_the_xmlrpc_authenticate_chain(): void {
		add_filter( 'application_password_is_api_request', '__return_true' );
		add_filter( 'wp_is_application_passwords_available', '__return_true' );

		$agent                = $this->agent_with_account_password();
		list( $app_password ) = \WP_Application_Passwords::create_new_application_password( $agent->ID, array( 'name' => 'XML-RPC check' ) );

		$result = wp_authenticate( $agent->user_login, $app_password );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_agent_login_blocked', $result->get_error_code() );

		$subscriber_id               = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$subscriber                  = new \WP_User( $subscriber_id );
		list( $subscriber_password ) = \WP_Application_Passwords::create_new_application_password( $subscriber_id, array( 'name' => 'Control' ) );

		$control = wp_authenticate( $subscriber->user_login, $subscriber_password );

		$this->assertInstanceOf( \WP_User::class, $control, 'The control must authenticate, or the test proves nothing.' );
	}

	/**
	 * Guards refuse_non_application_password_identity() for a session that was not opened with an application
	 * password: a cookie minted before the account became an agent must not make it the current user.
	 */
	public function test_agent_id_without_an_application_password_resolves_to_no_user(): void {
		$agent_id = $this->make_agent();

		$this->assertSame( 0, $this->resolved_by_core( $agent_id ) );
	}

	/**
	 * Guards the rule's application-password condition, outside a REST request. Core accepts an application password
	 * here (the filter is set), so the rule alone must drop the agent: REST_REQUEST is not defined in this process.
	 * The rest_route query var names the Steadhand endpoint, which proves that the request flag is what counts.
	 */
	public function test_agent_app_password_outside_a_rest_request_resolves_to_no_user(): void {
		$agent                                   = $this->agent_with_account_password();
		list( $password )                        = $this->app_password_for( $agent->ID, 'Outside REST' );
		$previous                                = $GLOBALS['wp']->query_vars['rest_route'] ?? null;
		$GLOBALS['wp']->query_vars['rest_route'] = '/steadhand/mcp';

		try {
			$this->assertSame( 0, $this->authenticate_with_app_password( $agent, $password ) );
		} finally {
			$GLOBALS['wp']->query_vars['rest_route'] = $previous;
		}
	}

	/**
	 * Guards the rule's route condition on a REST request: an agent's application password works on the Steadhand
	 * endpoint and on nothing else. Runs in its own process because REST_REQUEST cannot be undefined afterwards.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_agent_app_password_on_the_steadhand_rest_route_keeps_the_agent(): void {
		$this->define_rest_request();
		$agent            = $this->agent_with_account_password();
		list( $password ) = $this->app_password_for( $agent->ID, 'Claude Desktop' );

		$GLOBALS['wp']->query_vars['rest_route'] = '/steadhand/mcp';
		$this->assertSame( $agent->ID, $this->authenticate_with_app_password( $agent, $password ) );

		$GLOBALS['wp']->query_vars['rest_route'] = '/wp/v2/users/me';
		$this->assertSame( 0, $this->authenticate_with_app_password( $agent, $password ), 'The same password on core REST must resolve to no user.' );
	}

	/**
	 * Guards the route comparison in the identity rule. Only the Steadhand route, after untrailingslashit() and
	 * strtolower(), keeps the agent. Each other spelling resolves to no user, and none of them raises a warning
	 * (PHPUnit turns warnings into failures). Runs in its own process because it defines REST_REQUEST.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_route_spellings_decide_the_identity_rule_without_warnings(): void {
		$this->define_rest_request();
		$agent            = $this->agent_with_account_password();
		list( $password ) = $this->app_password_for( $agent->ID, 'Spellings' );

		$cases = array(
			'/steadhand/mcp'   => $agent->ID,
			'/steadhand/mcp/'  => $agent->ID,
			'/Steadhand/MCP'   => $agent->ID,
			'/STEADHAND/MCP/'  => $agent->ID,
			'/steadhand/mcpx'  => 0,
			'/steadhand/mcp/x' => 0,
			'/steadhand'       => 0,
			''                 => 0,
			'/'                => 0,
		);
		foreach ( $cases as $route => $expected ) {
			$GLOBALS['wp']->query_vars['rest_route'] = $route;
			$this->assertSame( $expected, $this->authenticate_with_app_password( $agent, $password ), "rest_route '$route'" );
		}

		$GLOBALS['wp']->query_vars['rest_route'] = array( '/steadhand/mcp' );
		$this->assertSame( 0, $this->authenticate_with_app_password( $agent, $password ), 'An array-valued rest_route must resolve to no user.' );

		unset( $GLOBALS['wp']->query_vars['rest_route'] );
		$this->assertSame( 0, $this->authenticate_with_app_password( $agent, $password ), 'A missing rest_route must resolve to no user.' );
	}

	/**
	 * Guards the identity rule's requirement for a REST request and the Steadhand route to come with the
	 * application password: the same route with no password resolves to no user.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_steadhand_route_without_an_application_password_resolves_to_no_user(): void {
		$this->define_rest_request();
		$agent = $this->make_agent();

		$GLOBALS['wp']->query_vars['rest_route'] = '/steadhand/mcp';
		$this->assertSame( 0, $this->resolved_by_core( $agent ) );
	}

	/**
	 * Guards the ownership check in the identity rule: a marker naming an application password of another account
	 * does not keep this agent, even on the Steadhand route of a REST request.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_application_password_of_another_account_does_not_keep_the_agent(): void {
		$this->define_rest_request();
		$agent_id                                     = $this->make_agent();
		$admin_id                                     = self::factory()->user->create( array( 'role' => 'administrator' ) );
		list( , $uuid )                               = $this->app_password_for( $admin_id, 'Admin app' );
		$GLOBALS['wp_rest_application_password_uuid'] = $uuid; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's own global name.
		$GLOBALS['wp']->query_vars['rest_route']      = '/steadhand/mcp';

		$this->assertSame( 0, $this->resolved_by_core( $agent_id ) );
	}

	/**
	 * Guards the identity rule for a non-agent with an application password outside a REST request: the rule
	 * never touches non-agents.
	 */
	public function test_a_non_agent_application_password_outside_rest_is_kept(): void {
		$admin            = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user             = new \WP_User( $admin );
		list( $password ) = $this->app_password_for( $admin, 'Admin app' );

		$this->assertSame( $admin, $this->authenticate_with_app_password( $user, $password ) );
	}

	/**
	 * Guards the ID check in the identity rule: an ID that does not belong to an existing user resolves to no
	 * user. The rule returns the ID only for an account that exists.
	 */
	public function test_a_user_id_that_does_not_exist_resolves_to_no_user(): void {
		$this->assertSame( 0, $this->resolved_by_core( 999999999 ) );
	}

	/**
	 * Negative control: the determine_current_user rule never touches non-agent IDs, including a mixed-role
	 * administrator who holds the agent role.
	 */
	public function test_non_agent_ids_are_always_kept_by_determine_current_user(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$mixed_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $mixed_id )->add_role( Roles::ROLE );
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$this->assertSame( $admin_id, $this->resolved_by_core( $admin_id ) );
		$this->assertSame( $mixed_id, $this->resolved_by_core( $mixed_id ) );
		$this->assertSame( $subscriber_id, $this->resolved_by_core( $subscriber_id ) );
	}
}
