<?php
/**
 * MCP server registration and the transport gate for the Steadhand endpoint.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Steadhand MCP server with the MCP Adapter and gates who may connect to it.
 */
final class Server {

	/**
	 * Server ID, as registered with the MCP Adapter.
	 */
	public const ID = 'steadhand';

	/**
	 * REST route namespace; the endpoint is /wp-json/steadhand/mcp.
	 */
	public const ROUTE_NAMESPACE = 'steadhand';

	/**
	 * REST route of the MCP endpoint, below the namespace.
	 */
	public const ROUTE = 'mcp';

	/**
	 * Constructor.
	 *
	 * @param Abilities\Registry $registry Registry whose abilities form the server's tool list.
	 */
	public function __construct( private Abilities\Registry $registry ) {
	}

	/**
	 * Creates the Steadhand MCP server. Hooked on `mcp_adapter_init`.
	 *
	 * Does nothing unless the MCP Adapter is present, so this file stays loadable without it.
	 *
	 * @param mixed $adapter The \WP\MCP\Core\McpAdapter instance passed by the adapter's init action.
	 */
	public function register( $adapter ): void {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		// The two nulls select the adapter's null error and observability handlers on purpose.
		// The adapter's error-log handler writes tool-call context to the PHP error log, and
		// Steadhand must never log unredacted agent input. Logging goes through Steadhand's own
		// redacting change log instead.
		$adapter->create_server(
			self::ID,
			self::ROUTE_NAMESPACE,
			self::ROUTE,
			'Steadhand',
			__( 'Safe, least-privilege WordPress tools for AI agents. Every call is permission-checked.', 'steadhand' ),
			STEADHAND_VERSION,
			array( \WP\MCP\Transport\HttpTransport::class ),
			null,
			null,
			$this->registry->names(),
			array(),
			array(),
			array( $this, 'can_connect' )
		);
	}

	/**
	 * Transport gate: whether the current request may reach the Steadhand endpoint at all.
	 *
	 * The user must be a dedicated agent (the agent role is its only role; see Agents::is_agent()) and must
	 * hold the Steadhand connect capability. The capability is checked directly rather than with
	 * current_user_can(). Administrators do not hold Steadhand capabilities automatically, so an
	 * administrator must be granted access explicitly through the agent role.
	 *
	 * @param mixed $request The REST request passed by the MCP Adapter. Unused.
	 * @return bool True when the current user is a dedicated agent that holds the Steadhand connect capability.
	 */
	public function can_connect( $request = null ): bool {
		unset( $request );

		$user = wp_get_current_user();

		$connects = is_user_logged_in()
			&& Agents::is_agent( $user )
			&& Capabilities::user_has( $user, Capabilities::CONNECT );
		if ( ! $connects ) {
			return false;
		}

		// Layer 3 of the IP allowlist (SPEC §4.1): whatever set this identity, the request must come from a listed address. It is the
		// same refusal as above, so nothing new is revealed. It covers identities that Lockdown's application-password rules never see.
		$decision = IpAllowlist::evaluate( $user->ID );
		if ( ! $decision['allowed'] ) {
			Lockdown::report_ip_refusal( $user->ID, $decision['ip'] );
			return false;
		}

		return true;
	}
}
