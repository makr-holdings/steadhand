<?php
/**
 * Refuses to change a post while someone else is editing it in WordPress.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Guardrails\Rules;

use Steadhand\Guardrails\Context;
use Steadhand\Guardrails\Result;
use Steadhand\Guardrails\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks a write to a post that another user has open in the block editor right now.
 *
 * Reads the _edit_lock meta that core writes when a user opens a post ("<timestamp>:<user id>"). A lock counts when its
 * timestamp is within the wp_check_post_lock_window filter's window (core's filter, 150 seconds by default) and its
 * user is someone other than the agent. A lock held by the agent, a stale lock, a malformed lock and a lock with user
 * 0 do not block. The refusal does not name the other user.
 */
final class EditLock implements Rule {

	/** Default window, in seconds, the same as core's. */
	public const DEFAULT_WINDOW = 150;

	/** Longest window the filter may set, in seconds. */
	public const MAX_WINDOW = 3600;

	/**
	 * Returns the rule's ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'steadhand/edit-lock';
	}

	/**
	 * Refuses the call when another user holds a live edit lock on the post.
	 *
	 * @param Context $context The call.
	 * @return Result
	 */
	public function evaluate( Context $context ): Result {
		if ( 'post' !== $context->object_type || $context->object_id <= 0 ) {
			return Result::allow();
		}

		$lock = get_post_meta( $context->object_id, '_edit_lock', true );
		if ( ! is_string( $lock ) || 1 !== preg_match( '/^(\d+):(\d+)\z/', $lock, $parts ) ) {
			return Result::allow();
		}

		$since  = (int) $parts[1];
		$holder = (int) $parts[2];
		$live   = $since > time() - $this->window();

		if ( $live && $holder > 0 && $holder !== $context->user_id ) {
			return Result::block(
				__( 'Someone is editing this post in WordPress right now. Try again after they finish.', 'steadhand' )
			);
		}

		return Result::allow();
	}

	/**
	 * The lock window in seconds, from core's filter. Anything that is not a whole number from 1 to 3600 gives the default.
	 *
	 * @return int
	 */
	private function window(): int {
		try {
			// Core's own filter, so its name is not ours to prefix.
			$window = apply_filters( 'wp_check_post_lock_window', self::DEFAULT_WINDOW ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's filter.
		} catch ( \Throwable ) {
			return self::DEFAULT_WINDOW;
		}

		if ( is_string( $window ) && 1 === preg_match( '/^\d+\z/', $window ) ) {
			$window = (int) $window;
		}

		if ( ! is_int( $window ) || $window < 1 || $window > self::MAX_WINDOW ) {
			return self::DEFAULT_WINDOW;
		}

		return $window;
	}
}
