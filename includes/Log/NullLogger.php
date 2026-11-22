<?php
/**
 * Logger that discards entries.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Placeholder logger that reports every entry as stored.
 *
 * It keeps nothing, so it must not be relied on for auditing. It is used only where a pipeline is needed to list
 * abilities and no call is ever made, as the self-check does.
 */
final class NullLogger implements Logger {

	/**
	 * Discards the entry and reports success.
	 *
	 * @param Entry $entry Entry to discard.
	 * @return bool Always true.
	 */
	public function log( Entry $entry ): bool {
		unset( $entry );
		return true;
	}

	/**
	 * Discards the entry and reports a row ID, so that a write is not refused for lack of a row.
	 *
	 * @param Entry $entry Entry to discard.
	 * @return int Always 1.
	 */
	public function begin( Entry $entry ): int {
		unset( $entry );
		return 1;
	}

	/**
	 * Discards the outcome and reports success.
	 *
	 * @param int   $id    Row ID, ignored.
	 * @param Entry $entry Outcome to discard.
	 * @return bool Always true.
	 */
	public function complete( int $id, Entry $entry ): bool {
		unset( $id, $entry );
		return true;
	}
}
