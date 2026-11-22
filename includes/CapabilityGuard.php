<?php
/**
 * Stops any user_has_cap filter from granting a Steadhand capability.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand;

defined( 'ABSPATH' ) || exit;

/**
 * Holds Steadhand's capabilities to their stored values (SPEC §4.2, D13).
 *
 * WP_User::has_cap() passes its answers through the user_has_cap filter. Without this guard, a filter could add
 * steadhand_publish, or grant every name it is asked about, and a drafts-only agent could then change live posts.
 * The guard runs last (PHP_INT_MAX) and sets every Steadhand name to exactly what Capabilities::user_has() reads
 * from the stored allcaps. It can therefore neither add a grant nor remove one: a name the user does not hold stays
 * false, and a name the user holds stays true. Steadhand_no_grant is always false.
 *
 * Other capabilities are not touched, so a filter may still change core's answers (for example, to take a
 * capability away from an administrator).
 */
final class CapabilityGuard {

	/**
	 * Attaches the guard to user_has_cap, after every other filter.
	 */
	public function register(): void {
		add_filter( 'user_has_cap', array( $this, 'filter' ), PHP_INT_MAX, 4 );
	}

	/**
	 * Resets every Steadhand capability in a has_cap() answer to its stored value.
	 *
	 * @param array<string, mixed> $allcaps The answer so far, keyed by capability name.
	 * @param array<int, string>   $caps    The capabilities being checked. Unused: the guard resets all of them.
	 * @param array<int, mixed>    $args    Extra arguments. Unused.
	 * @param mixed                $user    The WP_User being checked.
	 * @return array<string, mixed>
	 */
	public function filter( $allcaps, $caps, $args, $user ): array {
		unset( $caps, $args );

		$allcaps = is_array( $allcaps ) ? $allcaps : array();

		foreach ( Capabilities::all() as $name ) {
			$allcaps[ $name ] = $user instanceof \WP_User && Capabilities::user_has( $user, $name );
		}

		$allcaps[ Capabilities::NO_GRANT ] = false;

		return $allcaps;
	}
}
