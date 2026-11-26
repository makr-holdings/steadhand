<?php
/**
 * Lockdown: keeps agent credentials to the Steadhand MCP endpoint.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps agent credentials to the Steadhand MCP endpoint (SPEC §14.1, D6).
 *
 * Two kinds of user hold the agent role, and they are treated differently. A pure agent is a user whose only role
 * is the agent role (Agents::is_agent()). A mixed-role user also holds another role, such as an administrator who
 * was given the agent role. A mixed-role user is not an agent: it cannot connect to Steadhand (Server::can_connect()
 * and Pipeline::authorize() refuse it). The rules below still stop its application passwords from working as a
 * general credential (REST and XML-RPC), but leave its cookie sessions and account-password login alone, so an
 * administrator is not locked out of wp-admin by having the agent role added.
 *
 * | Rule (hook, priority)                                        | Pure agent                                   | Mixed role (agent role + another role)                        |
 * |--------------------------------------------------------------|----------------------------------------------|---------------------------------------------------------------|
 * | a. refuse_agent_login (authenticate, PHP_INT_MAX)            | password sign-in refused                     | application password sign-in (XML-RPC) refused; account password not affected |
 * | b. refuse_non_application_password_identity (determine_current_user, PHP_INT_MAX) | application password works only on the Steadhand REST route; otherwise resolves to no user | application password resolves to no user; cookie not affected |
 * | c. restrict_agent_routes (rest_pre_dispatch, PHP_INT_MIN)    | only the Steadhand route; everything else 403 | every route but the Steadhand route 403, while the request carries an application password (the transport gate then refuses the Steadhand route) |
 * | c2. restrict_agent_callbacks (rest_request_before_callbacks, PHP_INT_MAX) | same as c; runs after every rest_pre_dispatch filter, so a later filter cannot lift it | same as c |
 * | d. block_agents_in_admin (admin_init, 1)                     | wp-admin, admin-ajax and admin-post refused  | not affected                                                  |
 * | e. refuse_agent_from_unlisted_address (wp_authenticate_application_password_errors, PHP_INT_MAX) | application password refused from an address not on the agent's IP allowlist (SPEC §4.1) | not affected |
 * | e2. refuse_non_application_password_identity (rule b, second layer) | the same check again, on the identity the request already has | not affected |
 *
 * The IP allowlist (rule e) is off for an agent with no list, and refuses every address for an agent whose stored list is corrupt.
 * When it refuses, the agent's identity is dropped and every REST route answers the same generic refusal as rule c, including the
 * Steadhand endpoint. The refusal is reported once per request with the steadhand_agent_ip_refused action, which fires only after
 * the password matched. Nothing is written to the database for it.
 *
 * "Application password" means the request was authenticated by an application password of that same account
 * (rest_get_authenticated_app_password() is set). "Steadhand route" means the request is a REST request whose
 * route, after untrailingslashit() and strtolower(), is /steadhand/mcp, and no other route also matches it.
 *
 * Fail closed: when a check cannot be evaluated, the request is refused. Only the Steadhand endpoint is open to an
 * agent, and only while core routes it there. The rules are registered by Plugin::boot() before its requirements
 * check, so they apply even when the MCP Adapter or the Abilities API is missing. No filter, option or setting widens them.
 */
final class Lockdown {

	/**
	 * Whether this request was refused because an agent's password came from an address that is not on its IP allowlist. Set by
	 * refuse_for_address(), and cleared at shutdown (see forget_refused_request()).
	 *
	 * @var bool
	 */
	private static bool $address_refused = false;

	/**
	 * Attaches the Lockdown rules to WordPress. Called by Plugin::boot() before its requirements check, because the
	 * rules depend only on WordPress core.
	 */
	public function register(): void {
		add_filter( 'authenticate', array( $this, 'refuse_agent_login' ), PHP_INT_MAX );
		add_filter( 'determine_current_user', array( $this, 'refuse_non_application_password_identity' ), PHP_INT_MAX );
		// PHP_INT_MIN: run before any other plugin's rest_pre_dispatch code, so theirs sees the refusal.
		add_filter( 'rest_pre_dispatch', array( $this, 'restrict_agent_routes' ), PHP_INT_MIN, 3 );
		// Second check, last: a later rest_pre_dispatch filter can cancel the first one but not this one.
		add_filter( 'rest_request_before_callbacks', array( $this, 'restrict_agent_callbacks' ), PHP_INT_MAX, 3 );
		add_action( 'admin_init', array( $this, 'block_agents_in_admin' ), 1 );
		// Core calls this after an application password matched and before its last-used record is written, so a refusal here
		// leaves last_used and last_ip untouched. PHP_INT_MAX: nothing runs after it.
		add_action( 'wp_authenticate_application_password_errors', array( $this, 'refuse_agent_from_unlisted_address' ), PHP_INT_MAX, 2 );
		// The refusal flag belongs to one request. PHP-FPM workers keep static state between requests, so it is cleared here.
		add_action( 'shutdown', array( self::class, 'forget_refused_request' ) );
	}

	/**
	 * Clears the per-request state of the IP allowlist refusal. Called at shutdown, and by the tests between requests.
	 */
	public static function forget_refused_request(): void {
		self::$address_refused = false;
	}

	/**
	 * Refuses a password sign-in for an agent, and an application-password sign-in for a mixed-role user.
	 *
	 * Core's authenticate handlers run first (priorities 20 and 99). This runs last, so it sees the WP_User
	 * that core produced, and replaces it. A WP_Error from core is passed through unchanged, so a wrong
	 * password still gets core's own error.
	 *
	 * A pure agent is refused whatever the password was. A mixed-role user is refused only when the sign-in was
	 * done with its own application password. Core's wp_authenticate_application_password() (priority 20) records
	 * that password in rest_get_authenticated_app_password() via application_password_did_authenticate, before this
	 * runs. That record is set only by a successful application-password authentication, and it is cleared by a
	 * failed one (application_password_failed_authentication). wp-login.php never engages core's application-password
	 * handler, so an account-password sign-in there is not affected.
	 *
	 * @param mixed $user WP_User, WP_Error or null, from the earlier handlers.
	 * @return mixed The refusal for a blocked sign-in, otherwise $user unchanged.
	 */
	public function refuse_agent_login( $user ) {
		if ( ! $user instanceof \WP_User ) {
			return $user;
		}

		$is_agent = Agents::is_agent( $user );
		if ( $is_agent || ( Agents::holds_agent_role( $user ) && self::authenticated_with_own_application_password( $user->ID ) ) ) {
			return self::login_refusal();
		}

		return $user;
	}

	/**
	 * Refuses an application password of a dedicated agent that comes from an address its IP allowlist does not list (rule e).
	 *
	 * Core calls wp_authenticate_application_password_errors only after the password matched, and before it records the password's
	 * last-used time and address. A WP_Error added here stops that record, and the user is not authenticated. The error carries the
	 * same generic text and code as refuse_agent_login(), so it does not reveal the rule. Only a dedicated agent is checked, and an
	 * agent with no list is not affected.
	 *
	 * @param mixed $error The WP_Error core is collecting for this password.
	 * @param mixed $user  The user the password belongs to.
	 */
	public function refuse_agent_from_unlisted_address( $error, $user = null ): void {
		if ( ! $error instanceof \WP_Error || ! $user instanceof \WP_User || ! Agents::is_agent( $user ) ) {
			return;
		}

		$decision = IpAllowlist::evaluate( $user->ID );
		if ( $decision['allowed'] ) {
			return;
		}

		$refusal = self::login_refusal();
		$error->add( $refusal->get_error_code(), $refusal->get_error_message() );
		self::refuse_for_address( $user->ID, $decision['ip'] );
	}

	/**
	 * Drops the agent identity unless the request is allowed to use it (rule b in the class docblock).
	 *
	 * Core resolves the user at determine_current_user priority 20 (wp_validate_application_password() runs there,
	 * and it records the password UUID in rest_get_authenticated_app_password()). This runs after that, so the UUID
	 * is already known. Core accepts an application password only when REST_REQUEST or XMLRPC_REQUEST is defined
	 * (user.php, application_password_is_api_request), and that constant may not be defined yet at this point.
	 * The REST check here is therefore made against the request's route, and not against core's early decision.
	 *
	 * @param int|false $user_id User ID from the earlier handlers, or false.
	 * @return int|false The user ID, or 0 when the user is not allowed to use this identity here.
	 */
	public function refuse_non_application_password_identity( $user_id ) {
		if ( ! $user_id ) {
			return $user_id;
		}

		$user = new \WP_User( (int) $user_id );
		if ( ! $user->exists() ) {
			// Cannot evaluate the account: fail closed.
			return 0;
		}

		if ( Agents::is_agent( $user ) ) {
			if ( ! self::authenticated_with_own_application_password( $user->ID ) || ! self::is_steadhand_rest_request() ) {
				return 0;
			}

			// Second layer of rule e: the identity came from another path, so the address is checked again here.
			$decision = IpAllowlist::evaluate( $user->ID );
			if ( ! $decision['allowed'] ) {
				self::refuse_for_address( $user->ID, $decision['ip'] );
				return 0;
			}

			return $user_id;
		}

		if ( Agents::holds_agent_role( $user ) && self::authenticated_with_own_application_password( $user->ID ) ) {
			// Mixed role: its application passwords work nowhere. Its cookie sessions are not affected.
			return 0;
		}

		return $user_id;
	}

	/**
	 * Refuses every REST route for a blocked user except the Steadhand MCP endpoint (rule c).
	 *
	 * Runs at the start of WP_REST_Server::dispatch(), at PHP_INT_MIN, so it runs before other plugins' filters. It also
	 * covers rest_do_request() and the sub-requests of /batch/v1 (serve_batch_request_v1() calls rest_pre_dispatch for
	 * each one). The outer /batch/v1 request is refused before any sub-request runs.
	 *
	 * @param mixed                  $result  Result from the earlier callbacks, or null. Replaced, never honoured, for a blocked user.
	 * @param \WP_REST_Server|mixed  $server  The REST server.
	 * @param \WP_REST_Request|mixed $request The request about to be dispatched.
	 * @return mixed $result for an allowed request, otherwise a steadhand_agent_endpoint_only WP_Error.
	 */
	public function restrict_agent_routes( $result, $server = null, $request = null ) {
		if ( self::$address_refused ) {
			return self::refusal();
		}

		if ( ! self::blocks_current_user() ) {
			return $result;
		}

		if ( $server instanceof \WP_REST_Server && $request instanceof \WP_REST_Request && self::routes_to_steadhand( $server, $request->get_route() ) ) {
			return $result;
		}

		return self::refusal();
	}

	/**
	 * Second check (rule c2): refuses a blocked user on every matched route except the Steadhand endpoint.
	 *
	 * Core runs rest_request_before_callbacks in respond_to_request(), after dispatch() has passed the request on
	 * and before the route's permission_callback and callback. That is for every routed request, including /batch/v1
	 * sub-requests. Returning a WP_Error here makes core send that error instead of running the callback. A later
	 * rest_pre_dispatch filter can cancel rule c, but cannot cancel this one.
	 *
	 * @param mixed                      $response The response from the earlier callbacks, or null.
	 * @param array<string, mixed>|mixed $handler  The matched route handler. Unused.
	 * @param \WP_REST_Request|mixed     $request  The request about to be run.
	 * @return mixed $response for an allowed request, otherwise a steadhand_agent_endpoint_only WP_Error.
	 */
	public function restrict_agent_callbacks( $response, $handler = null, $request = null ) {
		unset( $handler );

		if ( self::$address_refused ) {
			return self::refusal();
		}

		if ( ! self::blocks_current_user() ) {
			return $response;
		}

		if ( $request instanceof \WP_REST_Request && self::routes_to_steadhand( rest_get_server(), $request->get_route() ) ) {
			return $response;
		}

		return self::refusal();
	}

	/**
	 * Stops an agent in wp-admin with a 403. Defence in depth: see the class docblock.
	 */
	public function block_agents_in_admin(): void {
		if ( ! Agents::is_agent( wp_get_current_user() ) ) {
			return;
		}

		wp_die(
			esc_html__( 'Steadhand agent credentials cannot be used in wp-admin.', 'steadhand' ),
			esc_html__( 'Forbidden', 'steadhand' ),
			array( 'response' => 403 )
		);
	}

	/**
	 * Whether core routes this path to the Steadhand endpoint, and to nothing else.
	 *
	 * Core matches each route's regex against the request path with preg_match( '@^' . $route . '$@i' ) (see
	 * WP_REST_Server::match_request_to_handler()). So the match is case-insensitive, and a trailing slash or a
	 * query string is not part of the path. The check uses the same expression, over every registered route. The
	 * path is allowed only if the Steadhand route matches it and no other route does. That rules out a second
	 * route that could take the same request first.
	 *
	 * @param \WP_REST_Server $server Server whose routes are checked.
	 * @param string          $path   The request route.
	 * @return bool True when the request is the Steadhand endpoint.
	 */
	private static function routes_to_steadhand( \WP_REST_Server $server, string $path ): bool {
		$endpoint = '/' . Server::ROUTE_NAMESPACE . '/' . Server::ROUTE;
		$matched  = false;

		foreach ( array_keys( $server->get_routes() ) as $route ) {
			if ( 1 !== preg_match( '@^' . $route . '$@i', $path ) ) {
				continue;
			}

			if ( $endpoint !== $route ) {
				return false;
			}

			$matched = true;
		}

		return $matched;
	}

	/**
	 * Whether the current user is blocked from every route but the Steadhand endpoint (rules c and c2).
	 *
	 * @return bool True for a pure agent, and for a mixed-role user whose request carries an application password.
	 */
	private static function blocks_current_user(): bool {
		return self::blocks( wp_get_current_user() );
	}

	/**
	 * Whether a user is blocked from every route but the Steadhand endpoint.
	 *
	 * @param \WP_User $user User to check.
	 * @return bool
	 */
	private static function blocks( \WP_User $user ): bool {
		if ( Agents::is_agent( $user ) ) {
			return true;
		}

		return Agents::holds_agent_role( $user ) && null !== rest_get_authenticated_app_password();
	}

	/**
	 * Records that this request's agent was refused for its address, and reports it once. The flag makes every REST route answer the
	 * generic refusal, the Steadhand endpoint included. The action fires only after a password matched (rule e), so only a holder of a
	 * valid credential can trigger it. A listener that throws does not change the refusal.
	 *
	 * @param int    $user_id The agent.
	 * @param string $ip      The address seen, or '' when none could be determined.
	 */
	private static function refuse_for_address( int $user_id, string $ip ): void {
		if ( self::$address_refused ) {
			return;
		}

		self::$address_refused = true;

		try {
			/**
			 * Fires when an agent's application password is refused because the request came from an address that is not on the
			 * agent's IP allowlist. Fires at most once per request, after the password matched.
			 *
			 * @param int    $user_id The agent.
			 * @param string $ip      The address seen, or '' when none could be determined.
			 */
			do_action( 'steadhand_agent_ip_refused', $user_id, $ip );
		} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The refusal stands whatever a listener does.
			// A listener must not change the refusal.
		}
	}

	/**
	 * The generic refusal for a sign-in that is not allowed. The same text is used by every rule that refuses a password sign-in.
	 *
	 * @return \WP_Error
	 */
	private static function login_refusal(): \WP_Error {
		return new \WP_Error(
			'steadhand_agent_login_blocked',
			__( 'This account cannot sign in here.', 'steadhand' )
		);
	}

	/**
	 * The refusal every blocked request gets.
	 *
	 * @return \WP_Error
	 */
	private static function refusal(): \WP_Error {
		return new \WP_Error(
			'steadhand_agent_endpoint_only',
			__( 'Steadhand agent credentials only work at the Steadhand MCP endpoint.', 'steadhand' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Whether this is a REST request for the Steadhand endpoint.
	 *
	 * REST_REQUEST is defined by rest_api_loaded() on parse_request, after plugins_loaded and init. The route is read
	 * from the query var that rest_api_loaded() serves. It is compared the way serve_request() compares it, after
	 * untrailingslashit(), and case-insensitively, as core matches routes.
	 *
	 * @return bool
	 */
	private static function is_steadhand_rest_request(): bool {
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return false;
		}

		global $wp;
		if ( ! $wp instanceof \WP ) {
			return false;
		}

		$route = $wp->query_vars['rest_route'] ?? null;
		if ( ! is_string( $route ) ) {
			return false;
		}

		return '/' . Server::ROUTE_NAMESPACE . '/' . Server::ROUTE === strtolower( untrailingslashit( $route ) );
	}

	/**
	 * Whether the request was authenticated with an application password that belongs to this user.
	 *
	 * @param int $user_id User ID.
	 * @return bool
	 */
	private static function authenticated_with_own_application_password( int $user_id ): bool {
		$uuid = rest_get_authenticated_app_password();
		if ( ! is_string( $uuid ) || '' === $uuid ) {
			return false;
		}

		return null !== \WP_Application_Passwords::get_user_application_password( $user_id, $uuid );
	}
}
