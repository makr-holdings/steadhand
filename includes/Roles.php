<?php
/**
 * The Steadhand Agent role: install and uninstall.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand;

defined( 'ABSPATH' ) || exit;

/**
 * Installs the agent role and removes it, along with any Steadhand grants, on uninstall.
 */
final class Roles {

	/** Slug of the agent role. */
	public const ROLE = 'steadhand_agent';

	/**
	 * Create the agent role with its default capabilities.
	 *
	 * Idempotent: any existing role is removed first, so the role always matches the
	 * defaults. The role gets read plus the agent defaults and nothing else.
	 *
	 * @return void
	 */
	public static function install(): void {
		remove_role( self::ROLE );

		add_role(
			self::ROLE,
			__( 'Steadhand Agent', 'steadhand' ),
			array( 'read' => true ) + Capabilities::agent_defaults()
		);
	}

	/**
	 * Remove the agent role and strip Steadhand capabilities from every user.
	 *
	 * Users are never deleted. An account whose only role is the agent role loses its
	 * application passwords, because those credentials were issued for an agent. An
	 * account that also holds another role keeps them, and loses only the agent role
	 * and the Steadhand capabilities.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		$user_ids = array_unique(
			array_map(
				'intval',
				array_merge(
					get_users(
						array(
							'role'   => self::ROLE,
							'fields' => 'ID',
						)
					),
					get_users(
						array(
							'capability__in' => Capabilities::all(),
							'fields'         => 'ID',
						)
					)
				)
			)
		);

		foreach ( $user_ids as $user_id ) {
			$user = get_userdata( $user_id );

			if ( ! $user instanceof \WP_User ) {
				continue;
			}

			if ( in_array( self::ROLE, (array) $user->roles, true ) ) {
				// Application passwords go only when the agent role is the user's sole role. A user who also
				// holds another role (say, an administrator) is a person, and keeps their own passwords.
				if ( array( self::ROLE ) === array_values( (array) $user->roles ) ) {
					\WP_Application_Passwords::delete_all_application_passwords( $user_id );
				}
				$user->remove_role( self::ROLE );
			}

			foreach ( Capabilities::all() as $capability ) {
				$user->remove_cap( $capability );
			}
		}

		remove_role( self::ROLE );
	}
}
