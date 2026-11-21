<?php
/**
 * Who counts as a dedicated Steadhand agent.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand;

defined( 'ABSPATH' ) || exit;

/**
 * Agent identity. Only a user whose sole role is the agent role is a dedicated agent (SPEC §4.1, D14).
 */
final class Agents {

	/**
	 * Whether the user is a dedicated agent: the agent role is the user's only role.
	 *
	 * Every Steadhand gate uses this. A user who also holds another role is not an agent, even if it holds
	 * the agent role, so granting the agent role to an administrator does not give that administrator
	 * Steadhand access.
	 *
	 * @param \WP_User $user User to check.
	 * @return bool True only for an existing user whose sole role is the agent role.
	 */
	public static function is_agent( \WP_User $user ): bool {
		return $user->exists() && array( Roles::ROLE ) === array_values( (array) $user->roles );
	}

	/**
	 * Whether the agent role is among the user's roles, alone or with others.
	 *
	 * @param \WP_User $user User to check.
	 * @return bool True for an existing user that holds the agent role.
	 */
	public static function holds_agent_role( \WP_User $user ): bool {
		return $user->exists() && in_array( Roles::ROLE, (array) $user->roles, true );
	}
}
