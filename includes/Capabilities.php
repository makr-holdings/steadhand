<?php
/**
 * Steadhand capabilities and the defaults granted to agent accounts.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand;

defined( 'ABSPATH' ) || exit;

/**
 * Steadhand capability names. They are granted through the steadhand_agent role's defaults or
 * per user, and never to any core role.
 */
final class Capabilities {

	/** Permission to connect to the Steadhand MCP endpoint. */
	public const CONNECT = 'steadhand_connect';

	/** Permission to read site content through Steadhand. */
	public const READ = 'steadhand_read';

	/** Permission to create and edit drafts through Steadhand. */
	public const WRITE_DRAFTS = 'steadhand_write_drafts';

	/** Permission to publish content through Steadhand. */
	public const PUBLISH = 'steadhand_publish';

	/** Permission to manage media through Steadhand. */
	public const MEDIA = 'steadhand_media';

	/**
	 * A name no user holds. MetaCaps answers with it when a post capability must be refused and core's check must not
	 * pass by accident. CapabilityGuard forces it to false on every check, so no filter can grant it. Not in all().
	 */
	public const NO_GRANT = 'steadhand_no_grant';

	/** Permission to change navigation menus through Steadhand. */
	public const MENUS = 'steadhand_menus';

	/** Permission to move content to the trash through Steadhand. */
	public const TRASH = 'steadhand_trash';

	/**
	 * Permission to read and write allowlisted site options (site title, tagline and similar)
	 * through Steadhand. It is not permission to change Steadhand's own settings, which always
	 * require manage_options.
	 */
	public const SETTINGS = 'steadhand_settings';

	/**
	 * Every Steadhand capability, in canonical order.
	 *
	 * @return string[]
	 */
	public static function all(): array {
		return array(
			self::CONNECT,
			self::READ,
			self::WRITE_DRAFTS,
			self::PUBLISH,
			self::MEDIA,
			self::MENUS,
			self::TRASH,
			self::SETTINGS,
		);
	}

	/**
	 * The capabilities the agent role starts with, mapped to true.
	 *
	 * @return array<string, bool>
	 */
	public static function agent_defaults(): array {
		return array(
			self::CONNECT      => true,
			self::READ         => true,
			self::WRITE_DRAFTS => true,
		);
	}

	/**
	 * Whether a user holds a Steadhand capability.
	 *
	 * Deliberately does not use has_cap() or user_can(). On multisite those return
	 * true for super admins, and administrators must never receive Steadhand
	 * capabilities implicitly. Reading allcaps directly means only an explicit grant
	 * counts. A user-level false overrides a role grant, because WordPress merges
	 * user capabilities over role capabilities into allcaps.
	 *
	 * @param \WP_User $user       User to check.
	 * @param string   $capability Steadhand capability name.
	 * @return bool
	 */
	public static function user_has( \WP_User $user, string $capability ): bool {
		if ( ! in_array( $capability, self::all(), true ) ) {
			return false;
		}

		return true === ( $user->allcaps[ $capability ] ?? false );
	}

	/**
	 * Map of every Steadhand capability to whether the user holds it.
	 *
	 * @param \WP_User $user User to check.
	 * @return array<string, bool>
	 */
	public static function granted( \WP_User $user ): array {
		$granted = array();

		foreach ( self::all() as $capability ) {
			$granted[ $capability ] = self::user_has( $user, $capability );
		}

		return $granted;
	}
}
