<?php
/**
 * Contract for storing change-log entries.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Stores one entry per ability call.
 *
 * A single entry (log()) is written after a call has its outcome, and suits calls that cannot change anything.
 * A write takes two steps: begin() reserves a row before the change is made, and complete() records the outcome
 * afterwards. A write whose row cannot be reserved is not performed (SPEC §7).
 */
interface Logger {

	/**
	 * Stores an entry.
	 *
	 * Returns false (or throws) if the entry could not be stored. Callers must then
	 * withhold the result of the call; a call that cannot be logged must not succeed silently.
	 *
	 * @param Entry $entry Entry to store.
	 * @return bool True if the entry was stored.
	 */
	public function log( Entry $entry ): bool;

	/**
	 * Reserves a row for a call that is about to run, and returns its ID.
	 *
	 * The row is stored with result error and the message "The call started, but no outcome was recorded.", whatever
	 * the entry's own result says. A crash before complete() therefore leaves an honest row. Never throttled.
	 *
	 * @param Entry $entry The call, as it will be recorded when it completes.
	 * @return int The row ID (greater than 0), or 0 if the row could not be stored.
	 */
	public function begin( Entry $entry ): int;

	/**
	 * Records the outcome of a call reserved with begin().
	 *
	 * Updates the row with the entry's final result, message, object, guardrail notes, snapshot and undo flag. Only the
	 * row with this ID and the entry's user ID is updated.
	 *
	 * @param int   $id    The row ID that begin() returned.
	 * @param Entry $entry The final outcome. Its result is ok, fixed or error.
	 * @return bool True only if that row holds the outcome afterwards.
	 */
	public function complete( int $id, Entry $entry ): bool;
}
