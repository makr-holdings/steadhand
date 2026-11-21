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
}
