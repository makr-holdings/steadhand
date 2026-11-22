<?php
/**
 * Lockdown, REST: an agent's credentials work only at the Steadhand MCP endpoint.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Roles;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Covers SPEC §14.1 (D6) and the rest_pre_dispatch rule in Lockdown (includes/Lockdown.php, hook
 * rest_pre_dispatch, method restrict_agent_routes()).
 *
 * Requests made with rest_do_request() go through WP_REST_Server::dispatch(), which applies rest_pre_dispatch, so these
 * tests exercise the same gate a real request reaches. Agents are set with wp_set_current_user(), which
 * skips the determine_current_user rule. That rule has its own tests in LockdownLoginTest.
 */
final class LockdownRestTest extends TestCase {

	private const REFUSED_CODE = 'steadhand_agent_endpoint_only';

	private const ROUTE_NAMESPACE_FOR_TEST = 'steadhand';

	/**
	 * Sends a request through rest_do_request() as the current user.
	 *
	 * @param string                    $method HTTP method.
	 * @param string                    $route  REST route, exactly as the request carries it.
	 * @param array<string, mixed>|null $body   JSON body, or null for none.
	 */
	private function request( string $method, string $route, ?array $body = null ): WP_REST_Response {
		$request = new WP_REST_Request( $method, $route );
		if ( null !== $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}

		return rest_do_request( $request );
	}

	/**
	 * A JSON-RPC initialize message, for the MCP endpoint and the adapter's default server.
	 *
	 * @return array<string, mixed>
	 */
	private function initialize_body(): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => 1,
			'method'  => 'initialize',
			'params'  => array(
				'protocolVersion' => '2025-11-25',
				'capabilities'    => new \stdClass(),
				'clientInfo'      => array(
					'name'    => 'steadhand-tests',
					'version' => '1',
				),
			),
		);
	}

	/**
	 * Asserts that a response is the agent refusal from Lockdown: 403 with the documented code.
	 *
	 * @param WP_REST_Response $response The response to check.
	 * @param string           $why      The route or case, for the failure message.
	 */
	private function assert_refused( WP_REST_Response $response, string $why ): void {
		$this->assertSame( 403, $response->get_status(), $why );
		$this->assertSame( self::REFUSED_CODE, $response->get_data()['code'] ?? null, $why );
	}

	/**
	 * Guards restrict_agent_routes() for core's current-user endpoint, the most common reason an agent
	 * credential was usable outside Steadhand (M0 known gap in README).
	 */
	public function test_agent_is_refused_at_core_users_me(): void {
		wp_set_current_user( $this->make_agent() );

		$this->assert_refused( $this->request( 'GET', '/wp/v2/users/me' ), '/wp/v2/users/me' );
	}

	public function test_agent_is_refused_at_core_posts(): void {
		wp_set_current_user( $this->make_agent() );

		$this->assert_refused( $this->request( 'GET', '/wp/v2/posts' ), '/wp/v2/posts' );
	}

	public function test_agent_is_refused_at_the_rest_index_and_the_namespace_index(): void {
		wp_set_current_user( $this->make_agent() );

		$this->assert_refused( $this->request( 'GET', '/' ), '/' );
		$this->assert_refused( $this->request( 'GET', '/wp/v2' ), '/wp/v2' );
	}

	/**
	 * Guards the batch path: the outer /batch/v1 request is refused before any sub-request runs.
	 */
	public function test_agent_is_refused_at_the_core_abilities_api(): void {
		wp_set_current_user( $this->make_agent() );

		$this->assert_refused( $this->request( 'GET', '/wp-abilities/v1/abilities' ), '/wp-abilities/v1/abilities' );
		$this->assert_refused( $this->request( 'GET', '/wp-abilities/v1/categories' ), '/wp-abilities/v1/categories' );
	}

	public function test_agent_is_refused_at_batch_with_a_users_me_sub_request(): void {
		wp_set_current_user( $this->make_agent() );

		$response = $this->request(
			'POST',
			'/batch/v1',
			array(
				'requests' => array(
					array(
						'method' => 'GET',
						'path'   => '/wp/v2/users/me',
					),
				),
			)
		);

		$this->assert_refused( $response, '/batch/v1' );
	}

	/**
	 * Guards the MCP Adapter's default server, which the M0 known gap left open to agent credentials.
	 */
	public function test_agent_is_refused_at_the_mcp_adapter_default_server(): void {
		wp_set_current_user( $this->make_agent() );

		$this->assert_refused(
			$this->request( 'POST', '/mcp/mcp-adapter-default-server', $this->initialize_body() ),
			'/mcp/mcp-adapter-default-server'
		);
	}

	/**
	 * Guards the route comparison in restrict_agent_routes(). Each spelling must be refused unless core
	 * would route it to the Steadhand endpoint (see the next test).
	 *
	 * '/steadhand/mcp/' is refused here because rest_do_request() does not trim the trailing slash; core's
	 * own routing does that only for a real HTTP request (serve_request(), via untrailingslashit()).
	 */
	public function test_near_miss_spellings_of_the_steadhand_route_are_refused(): void {
		wp_set_current_user( $this->make_agent() );

		foreach ( array( '/steadhand/mcp/', '/steadhand/mcp?x', '/steadhand/mcpx', '/steadhand/mcp/extra', '/steadhand' ) as $route ) {
			$this->assert_refused( $this->request( 'POST', $route, $this->initialize_body() ), $route );
		}
	}

	/**
	 * Guards the case-insensitive side of the comparison: core matches routes with the i modifier, so
	 * /Steadhand/MCP reaches the Steadhand endpoint and must stay allowed for the agent.
	 */
	public function test_a_differently_cased_steadhand_route_reaches_the_endpoint(): void {
		wp_set_current_user( $this->make_agent() );

		$response = $this->request( 'POST', '/Steadhand/MCP', $this->initialize_body() );

		$this->assertSame( 200, $response->get_status() );
	}

	/**
	 * The one route an agent may use still works, and the tool call behind it runs end to end.
	 */
	public function test_agent_still_reaches_the_steadhand_endpoint_and_calls_the_tool(): void {
		wp_set_current_user( $this->make_agent() );

		$this->assertSame( 200, $this->request( 'POST', self::ROUTE, $this->initialize_body() )->get_status() );

		$info = $this->call_tool( self::TOOL )['result']['structuredContent'];
		$this->assertSame( home_url(), $info['url'] );
	}

	/**
	 * Negative control: the restriction is for agents only, so an administrator, a subscriber and a
	 * mixed-role administrator all still reach core's current-user endpoint.
	 */
	public function test_administrator_is_not_affected_at_users_me(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$this->assertSame( 200, $this->request( 'GET', '/wp/v2/users/me' )->get_status() );
	}

	public function test_subscriber_is_not_affected_at_users_me(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->assertSame( 200, $this->request( 'GET', '/wp/v2/users/me' )->get_status() );
	}

	/**
	 * Guards the sole-role rule in the route restriction: a mixed-role user is not an agent, so Lockdown
	 * leaves core REST alone for it (it is refused from Steadhand by the transport gate instead).
	 */
	public function test_mixed_role_administrator_is_not_blocked_at_users_me(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $admin_id )->add_role( Roles::ROLE );
		wp_set_current_user( $admin_id );

		$this->assertSame( 200, $this->request( 'GET', '/wp/v2/users/me' )->get_status() );
	}

	/**
	 * Test-only rest_pre_dispatch callbacks that a later plugin could add: one cancels the block (returns null),
	 * the other drops the WP_Error it receives. Both run after Lockdown's rule.
	 *
	 * @param mixed $result The result so far.
	 * @return mixed
	 */
	public function cancel_block( $result = null ) {
		unset( $result );
		return null;
	}

	/**
	 * See cancel_block(). Drops the refusal, and passes any other result through.
	 *
	 * @param mixed $result The result so far.
	 * @return mixed
	 */
	public function drop_wp_error( $result = null ) {
		return is_wp_error( $result ) ? null : $result;
	}

	/**
	 * Lists the callbacks on a hook, with their priorities, so that they can be put back with restore_callbacks().
	 *
	 * @param string $hook Hook name.
	 * @return array<int, array{0: int, 1: array<string, mixed>}>
	 */
	private function remember_callbacks( string $hook ): array {
		$saved   = array();
		$filters = $GLOBALS['wp_filter'][ $hook ] ?? null;
		if ( $filters instanceof \WP_Hook ) {
			foreach ( $filters->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$saved[] = array( $priority, $callback );
				}
			}
		}

		return $saved;
	}

	/**
	 * Puts back callbacks listed by remember_callbacks(), at their priorities.
	 *
	 * @param string                                             $hook  Hook name.
	 * @param array<int, array{0: int, 1: array<string, mixed>}> $saved What remember_callbacks() returned.
	 */
	private function restore_callbacks( string $hook, array $saved ): void {
		foreach ( $saved as $entry ) {
			list( $priority, $callback ) = $entry;
			add_filter( $hook, $callback['function'], $priority, (int) $callback['accepted_args'] );
		}
	}

	protected function tearDown(): void {
		remove_filter( 'rest_pre_dispatch', array( $this, 'cancel_block' ), 10 );
		remove_filter( 'rest_pre_dispatch', array( $this, 'drop_wp_error' ), 5 );
		parent::tearDown();
	}

	/**
	 * Guards rest_request_before_callbacks (FIX 3). The two later rest_pre_dispatch filters above cancel the first
	 * block. The agent must still be refused on core REST and on the batch endpoint, both for the outer request
	 * and for a sub-request.
	 */
	public function test_agent_is_still_refused_when_later_filters_cancel_the_rest_pre_dispatch_block(): void {
		add_filter( 'rest_pre_dispatch', array( $this, 'cancel_block' ), 10, 3 );
		add_filter( 'rest_pre_dispatch', array( $this, 'drop_wp_error' ), 5, 3 );
		wp_set_current_user( $this->make_agent() );

		$this->assert_refused( $this->request( 'GET', '/wp/v2/users/me' ), '/wp/v2/users/me' );
		$this->assert_refused( $this->request( 'GET', '/wp/v2/posts' ), '/wp/v2/posts' );
		$requests = array(
			'requests' => array(
				array(
					'method' => 'GET',
					'path'   => '/wp/v2/users/me',
				),
			),
		);
		$this->assert_refused( $this->request( 'POST', '/batch/v1', $requests ), 'outer /batch/v1' );

		$batch = new WP_REST_Request( 'POST', '/batch/v1' );
		$batch->set_header( 'Content-Type', 'application/json' );
		$batch->set_body( (string) wp_json_encode( $requests ) );
		$sub = rest_get_server()->serve_batch_request_v1( $batch )->get_data()['responses'][0];

		$this->assertSame( 403, $sub['status'], 'The /batch/v1 sub-request must be refused.' );
		$this->assertSame( self::REFUSED_CODE, $sub['body']['code'] ?? null, 'The /batch/v1 sub-request must carry the Lockdown refusal.' );
	}

	/**
	 * The Steadhand endpoint still works with the later filters in place: a cancelled block must not stop the agent's
	 * own route, and the tool call behind it runs end to end.
	 */
	public function test_the_steadhand_endpoint_still_works_with_the_later_filters_present(): void {
		add_filter( 'rest_pre_dispatch', array( $this, 'cancel_block' ), 10, 3 );
		add_filter( 'rest_pre_dispatch', array( $this, 'drop_wp_error' ), 5, 3 );
		wp_set_current_user( $this->make_agent() );

		$this->assertSame( 200, $this->request( 'POST', self::ROUTE, $this->initialize_body() )->get_status() );
		$this->assertSame( home_url(), $this->call_tool( self::TOOL )['result']['structuredContent']['url'] );
	}

	/**
	 * Guards the order of the first check (M10, and FIX 3's reason to exist). Core's filter chain does not stop at the
	 * first response: every callback runs. So a response an earlier filter produced must still be replaced by
	 * Lockdown's refusal. The earlier filter is put in front of Lockdown here, by re-adding the hook in that order.
	 */
	public function test_an_agent_is_refused_even_when_an_earlier_filter_already_produced_a_response(): void {
		$saved = $this->remember_callbacks( 'rest_pre_dispatch' );
		remove_all_filters( 'rest_pre_dispatch' );
		try {
			add_filter(
				'rest_pre_dispatch',
				static fn () => new WP_REST_Response( array( 'leaked' => true ), 200 ),
				PHP_INT_MIN
			);
			add_filter( 'rest_pre_dispatch', array( new \Steadhand\Lockdown(), 'restrict_agent_routes' ), PHP_INT_MIN, 3 );

			wp_set_current_user( $this->make_agent() );
			$this->assert_refused( $this->request( 'GET', '/wp/v2/users/me' ), '/wp/v2/users/me after an earlier response' );
		} finally {
			remove_all_filters( 'rest_pre_dispatch' );
			$this->restore_callbacks( 'rest_pre_dispatch', $saved );
		}
	}

	/**
	 * Guards the priority of the rest_pre_dispatch rule (M13): it runs before any plugin's rest_pre_dispatch code. A
	 * filter at priority -1000 must receive Lockdown's refusal as its input, not an empty result.
	 */
	public function test_the_rest_pre_dispatch_rule_runs_before_other_plugins_code(): void {
		wp_set_current_user( $this->make_agent() );
		$seen   = null;
		$record = static function ( $result ) use ( &$seen ) {
			$seen = $result;
			return $result;
		};
		add_filter( 'rest_pre_dispatch', $record, -1000, 3 );

		try {
			$this->request( 'GET', '/wp/v2/users/me' );
		} finally {
			remove_filter( 'rest_pre_dispatch', $record, -1000 );
		}

		$this->assertInstanceOf( \WP_Error::class, $seen, 'A filter at priority -1000 must see the refusal already in place.' );
		$this->assertSame( self::REFUSED_CODE, $seen->get_error_code() );
	}

	/**
	 * Guards the route rule against a second route that also matches the Steadhand path (M2). Routes are matched
	 * case-insensitively, so a catch-all route in the same namespace also matches /steadhand/mcp. The agent must be
	 * refused at /STEADHAND/MCP, and, because two routes match, at the exact path too (fail closed). Runs in its own
	 * process so the extra route does not leak into other tests.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_an_overlapping_route_closes_the_endpoint_to_agents(): void {
		rest_get_server();
		register_rest_route(
			self::ROUTE_NAMESPACE_FOR_TEST,
			'/(?P<any>.+)',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => '__return_null',
					'permission_callback' => '__return_true',
				),
			)
		);
		wp_set_current_user( $this->make_agent() );

		$this->assert_refused( $this->request( 'POST', '/STEADHAND/MCP', $this->initialize_body() ), '/STEADHAND/MCP' );
		$this->assert_refused( $this->request( 'POST', self::ROUTE, $this->initialize_body() ), 'exact path with an overlapping route' );
	}

	/**
	 * Fail-closed check (FIX 1): when the Steadhand route is not registered at all, an agent is refused on every
	 * route, including the path that would have been the endpoint. Runs in its own process, because it removes the route.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_agent_is_refused_everywhere_when_the_steadhand_route_is_not_registered(): void {
		$server    = rest_get_server();
		$endpoints = new \ReflectionProperty( \WP_REST_Server::class, 'endpoints' );
		$routes    = $endpoints->getValue( $server );
		unset( $routes[ '/' . self::ROUTE_NAMESPACE_FOR_TEST . '/mcp' ] );
		$endpoints->setValue( $server, $routes );

		wp_set_current_user( $this->make_agent() );

		$this->assert_refused( $this->request( 'GET', '/wp/v2/users/me' ), '/wp/v2/users/me' );
		$this->assert_refused( $this->request( 'POST', self::ROUTE, $this->initialize_body() ), 'the Steadhand path, unregistered' );
	}
}
