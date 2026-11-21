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
 * Stands in until the real change log lands in milestone M2. It keeps nothing,
 * so it must not be relied on for auditing.
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
}
