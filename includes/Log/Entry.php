<?php
/**
 * One change-log record: who did what, and how it turned out.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Log;

defined( 'ABSPATH' ) || exit;

/**
 * A single outcome of a single ability call.
 *
 * Deliberately has no input field yet. Inputs are only logged once the redaction
 * helper exists (milestone M2), so that secrets can never reach the log by accident.
 */
final class Entry {

	/**
	 * The call succeeded.
	 *
	 * @var string
	 */
	public const RESULT_OK = 'ok';

	/**
	 * The call was refused (invalid input, missing capability, or failed object check).
	 *
	 * @var string
	 */
	public const RESULT_BLOCKED = 'blocked';

	/**
	 * A guardrail changed the call so that it could proceed.
	 *
	 * @var string
	 */
	public const RESULT_FIXED = 'fixed';

	/**
	 * The call was allowed but failed while executing.
	 *
	 * @var string
	 */
	public const RESULT_ERROR = 'error';

	/**
	 * Constructor.
	 *
	 * @param int    $user_id     ID of the agent user that made the call.
	 * @param string $agent_label Name of the application password used for the request, or ''.
	 * @param string $ability     Ability name, e.g. steadhand/get-site-info.
	 * @param string $result      One of the RESULT_* constants.
	 * @param string $message     Error message for refused or failed calls, or ''.
	 */
	public function __construct(
		public readonly int $user_id,
		public readonly string $agent_label,
		public readonly string $ability,
		public readonly string $result,
		public readonly string $message = ''
	) {}
}
