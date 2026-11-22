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
 *
 * Older WordPress wrote a lock with the timestamp only. For that format the user is read from _edit_last. A fresh
 * timestamp-only lock blocks unless _edit_last names the agent, so a missing holder fails safe.
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

		// Parsed as core's wp_check_post_lock() does (wp-admin/includes/post.php): the text before the first colon is the
		// time, and the text after it is the user. Numeric casts make " 1700000000:5" and "1700000000:5\n" mean what core
		// makes of them. A lock that is not a number gives 0, which is not live.
		$lock = get_post_meta( $context->object_id, '_edit_lock', true );
		if ( ! is_string( $lock ) || '' === $lock ) {
			return Result::allow();
		}

		$parts = explode( ':', $lock );
		$since = (int) $parts[0];
		if ( $since <= time() - $this->window() ) {
			return Result::allow();
		}

		if ( isset( $parts[1] ) ) {
			$holder = (int) $parts[1];
			if ( $holder > 0 && $holder !== $context->user_id ) {
				return $this->refuse();
			}

			return Result::allow();
		}

		// The legacy format, timestamp only: the user is in _edit_last. Missing, zero or another user blocks.
		$holder = (int) get_post_meta( $context->object_id, '_edit_last', true );
		if ( $holder > 0 && $holder === $context->user_id ) {
			return Result::allow();
		}

		return $this->refuse();
	}

	/**
	 * The refusal for a post that someone else holds a live lock on. It does not name that user.
	 *
	 * @return Result
	 */
	private function refuse(): Result {
		return Result::block(
			__( 'Someone is editing this post in WordPress right now. Try again after they finish.', 'steadhand' )
		);
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
