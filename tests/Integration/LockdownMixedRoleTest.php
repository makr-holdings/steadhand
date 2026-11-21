<?php
/**
 * Lockdown, mixed roles: adding the agent role to a human's account must not turn its application passwords
 * into a general REST credential.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Lockdown;
use Steadhand\Roles;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Covers the blocking side of D14 (SPEC §4.1). A user who holds the agent role and another role is not an agent, so
 * it cannot connect to Steadhand and the allowing side refuses it. The blocking side still applies: such a user
 * can use an application password nowhere, while its cookie session and account-password login stay as core
 * leaves them.
 */
final class LockdownMixedRoleTest extends TestCase {

	private const REFUSED_CODE = 'steadhand_agent_endpoint_only';

	/**
	 * Creates an administrator who also holds the agent role.
	 */
	private function mixed_role_admin(): \WP_User {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$user     = get_userdata( $admin_id );
		$user->add_role( Roles::ROLE );

		return new \WP_User( $admin_id );
	}

	/**
	 * Sends one REST request as the current user, through the same dispatch core uses for REST.
	 *
	 * @param string $method HTTP method.
	 * @param string $route  REST route.
	 */
	private function request( string $method, string $route ): WP_REST_Response {
		return rest_do_request( new WP_REST_Request( $method, $route ) );
	}

	/**
	 * A mixed-role administrator's application password resolves to no user, through core's own chain.
	 * Core's rule and REST_REQUEST are not needed here: the application password is the only input.
	 */
	public function test_mixed_role_admin_application_password_resolves_to_no_user(): void {
		$user             = $this->mixed_role_admin();
		list( $password ) = $this->app_password_for( $user->ID, 'Admin app' );

		$this->assertSame( 0, $this->authenticate_with_app_password( $user, $password ) );

		wp_set_current_user( 0 );
		$response = $this->request( 'GET', '/wp/v2/users/me' );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( 'rest_not_logged_in', $response->get_data()['code'] ?? null );
	}

	/**
	 * The same mixed-role password is refused at the Steadhand gate: the resolved user is anonymous, so it is not
	 * connected (the allowing side in Server::can_connect() and Pipeline::authorize()).
	 */
	public function test_mixed_role_admin_application_password_is_refused_at_the_steadhand_gate(): void {
		$user             = $this->mixed_role_admin();
		list( $password ) = $this->app_password_for( $user->ID, 'Admin app' );
		$this->authenticate_with_app_password( $user, $password );
		wp_set_current_user( 0 );

		$response = $this->mcp(
			'initialize',
			array(
				'protocolVersion' => '2025-11-25',
				'capabilities'    => new \stdClass(),
				'clientInfo'      => array(
					'name'    => 'steadhand-tests',
					'version' => '1',
				),
			)
		);

		$this->assertContains( $response->get_status(), array( 401, 403 ), 'A mixed-role application password must not open a Steadhand session.' );
	}

	/**
	 * Negative control for the cookie path: the same mixed-role account, signed in by cookie (no application
	 * password marker), keeps core REST and is not stopped in wp-admin.
	 */
	public function test_mixed_role_admin_cookie_session_keeps_core_rest_and_wp_admin(): void {
		$user = $this->mixed_role_admin();
		wp_set_current_user( $user->ID );

		$this->assertSame( 200, $this->request( 'GET', '/wp/v2/users/me' )->get_status() );

		// Returns normally. A refusal would call wp_die(), which the test base turns into an exception.
		( new Lockdown() )->block_agents_in_admin();
		$this->assertSame( $user->ID, get_current_user_id() );
	}

	/**
	 * Guards the blocking side of the REST rules (rules c and FIX 3): when the request carries the mixed-role user's
	 * application password, the user is refused on every route except the Steadhand route, and the later
	 * rest_pre_dispatch filters cannot lift that. The Steadhand route then reaches the transport gate, which refuses it.
	 * The application password marker is set as core sets it.
	 */
	public function test_mixed_role_admin_with_an_application_password_marker_is_refused_everywhere(): void {
		$user           = $this->mixed_role_admin();
		list( , $uuid ) = $this->app_password_for( $user->ID, 'Admin app' );
		$GLOBALS['wp_rest_application_password_uuid'] = $uuid; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Core's own global name.
		wp_set_current_user( $user->ID );

		$cancel = static fn () => null;
		$drop   = static fn ( $result ) => is_wp_error( $result ) ? null : $result;
		add_filter( 'rest_pre_dispatch', $cancel, 10, 3 );
		add_filter( 'rest_pre_dispatch', $drop, 5, 3 );
		try {
			$response = $this->request( 'GET', '/wp/v2/users/me' );
			$this->assertSame( 403, $response->get_status() );
			$this->assertSame( self::REFUSED_CODE, $response->get_data()['code'] ?? null );

			// The Steadhand route is not blocked by the route rules, and the transport gate then refuses the user.
			$response = $this->mcp( 'tools/list' );
			$this->assertContains( $response->get_status(), array( 401, 403 ), 'The Steadhand gate must refuse a mixed-role user.' );
		} finally {
			remove_filter( 'rest_pre_dispatch', $cancel, 10 );
			remove_filter( 'rest_pre_dispatch', $drop, 5 );
		}
	}

	/**
	 * Negative control: a plain administrator (no agent role) with its application password keeps working on core REST.
	 */
	public function test_plain_administrator_application_password_is_unaffected(): void {
		$admin_id         = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$admin            = new \WP_User( $admin_id );
		list( $password ) = $this->app_password_for( $admin_id, 'Admin app' );

		$this->assertSame( $admin_id, $this->authenticate_with_app_password( $admin, $password ) );

		wp_set_current_user( $admin_id );
		$this->assertSame( 200, $this->request( 'GET', '/wp/v2/users/me' )->get_status() );
	}
}
