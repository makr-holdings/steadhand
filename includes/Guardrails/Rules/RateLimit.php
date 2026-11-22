<?php
/**
 * Caps how many writes an agent may make in a minute.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Guardrails\Rules;

use Steadhand\Guardrails\Context;
use Steadhand\Guardrails\Result;
use Steadhand\Guardrails\Rule;
use Steadhand\Log\DbLogger;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks a write when the agent has already made the limit's worth of writes in the last 60 seconds (SPEC §6.1).
 *
 * Counts the change-log rows the agent made to the write abilities, with result ok, fixed or error. A row reserved
 * before a write (begin()) counts, so a write that crashed still counts. Refused calls do not count. The limit is 30
 * unless the steadhand_rate_limit_per_minute filter gives a whole number from 1 to 600. It can be tuned but never
 * turned off. If the count cannot be read, the call is refused.
 */
final class RateLimit implements Rule {

	/** Default and fallback limit, in writes per minute. */
	public const DEFAULT_LIMIT = 30;

	/** Highest limit the filter may set. */
	public const MAX_LIMIT = 600;

	/** Length of the window, in seconds. */
	private const WINDOW_SECONDS = 60;

	/**
	 * Returns the names of the write abilities.
	 *
	 * @var callable
	 */
	private $write_abilities;

	/**
	 * Constructor.
	 *
	 * @param DbLogger $log             Change log to count from.
	 * @param callable $write_abilities Returns the names of the write abilities. Called only when a write is checked.
	 */
	public function __construct( private DbLogger $log, callable $write_abilities ) {
		$this->write_abilities = $write_abilities;
	}

	/**
	 * Returns the rule's ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'steadhand/rate-limit';
	}

	/**
	 * Refuses the call when the agent is at its limit.
	 *
	 * @param Context $context The call.
	 * @return Result
	 */
	public function evaluate( Context $context ): Result {
		$abilities = array_values( (array) ( $this->write_abilities )() );
		$count     = $this->log->count_recent( $context->user_id, $abilities, self::WINDOW_SECONDS );

		if ( $count >= $this->limit( $context->user_id ) ) {
			return Result::block(
				__( 'This agent has made too many changes in the last minute. Wait a minute, then try again.', 'steadhand' )
			);
		}

		return Result::allow();
	}

	/**
	 * The writes per minute allowed for this user.
	 *
	 * @param int $user_id Agent user ID.
	 * @return int
	 */
	private function limit( int $user_id ): int {
		try {
			$limit = apply_filters( 'steadhand_rate_limit_per_minute', self::DEFAULT_LIMIT, $user_id );
		} catch ( \Throwable ) {
			return self::DEFAULT_LIMIT;
		}

		if ( is_string( $limit ) && 1 === preg_match( '/^\d+\z/', $limit ) ) {
			$limit = (int) $limit;
		}

		if ( ! is_int( $limit ) || $limit < 1 || $limit > self::MAX_LIMIT ) {
			return self::DEFAULT_LIMIT;
		}

		return $limit;
	}
}
