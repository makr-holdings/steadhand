<?php
/**
 * Caps how many images an agent may upload in 24 hours.
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
 * Blocks an upload when the agent has already made LIMIT uploads in the last 24 hours. It is a guardrail, so a refusal is
 * logged as blocked, with the same error code and status as the per-minute rate limit (422, steadhand_guardrail_blocked).
 *
 * The count is the change log's count_recent(): ok, fixed and error rows, so a write that failed or crashed still counts.
 * If the count cannot be read, the upload is refused (fail closed). The limit is a class constant, not a filter.
 */
final class DailyUploadLimit implements Rule {

	/** Uploads allowed per agent in any rolling 24 hours. */
	public const LIMIT = 100;

	/** The ability this rule counts. */
	private const ABILITY = 'steadhand/upload-media';

	/** Length of the window, in seconds. */
	private const WINDOW_SECONDS = 86400;

	/**
	 * Constructor.
	 *
	 * @param DbLogger $log Change log to count from.
	 */
	public function __construct( private DbLogger $log ) {}

	/**
	 * Returns the rule's ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'steadhand/daily-upload-limit';
	}

	/**
	 * Refuses an upload when the agent is at its daily limit. Every other call passes.
	 *
	 * @param Context $context The call.
	 * @return Result
	 */
	public function evaluate( Context $context ): Result {
		if ( self::ABILITY !== $context->ability ) {
			return Result::allow();
		}

		$count = $this->log->count_recent( $context->user_id, array( self::ABILITY ), self::WINDOW_SECONDS );

		if ( $count >= self::LIMIT ) {
			return Result::block(
				sprintf(
					/* translators: %d: the number of uploads allowed in 24 hours. */
					__( 'This agent has reached its limit of %d uploads in 24 hours, so nothing was stored. Try again later.', 'steadhand' ),
					self::LIMIT
				)
			);
		}

		return Result::allow();
	}
}
