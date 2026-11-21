<?php
/**
 * Test logger that records entries.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Log\Entry;
use Steadhand\Log\Logger;

/**
 * Records every entry; can be told to fail.
 */
final class SpyLogger implements Logger {

	/**
	 * Recorded entries.
	 *
	 * @var Entry[]
	 */
	public array $entries = array();

	/**
	 * Constructor.
	 *
	 * @param bool $succeeds What log() reports.
	 */
	public function __construct( private bool $succeeds = true ) {}

	/**
	 * Records the entry.
	 *
	 * @param Entry $entry Entry.
	 */
	public function log( Entry $entry ): bool {
		$this->entries[] = $entry;
		return $this->succeeds;
	}
}
