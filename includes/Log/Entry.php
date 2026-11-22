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
 * The input is stored only after redaction and size limits, both applied by DbLogger, so a caller
 * may pass the raw input it received.
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
	 * @param int                  $user_id         ID of the agent user that made the call.
	 * @param string               $agent_label     Name of the application password used for the request, or ''.
	 * @param string               $ability         Ability name, e.g. steadhand/get-site-info.
	 * @param string               $result          One of the RESULT_* constants.
	 * @param string               $message         Error message for refused or failed calls, or ''.
	 * @param array<string, mixed> $input           The call's input, before redaction. Empty when none was given.
	 * @param string               $object_type     What object the call is about (for example 'post'), or ''.
	 * @param int                  $object_id       The ID of that object, or 0.
	 * @param string               $guardrail_notes What a guardrail did to the call, or ''.
	 * @param int                  $snapshot_id     The snapshot taken before a write, or 0.
	 * @param bool                 $undoable        Whether the change can be undone.
	 */
	public function __construct(
		public readonly int $user_id,
		public readonly string $agent_label,
		public readonly string $ability,
		public readonly string $result,
		public readonly string $message = '',
		public readonly array $input = array(),
		public readonly string $object_type = '',
		public readonly int $object_id = 0,
		public readonly string $guardrail_notes = '',
		public readonly int $snapshot_id = 0,
		public readonly bool $undoable = false
	) {}
}
