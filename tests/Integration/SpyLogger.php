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
 * Records every entry and every call, and can be told to fail. events lists the calls in the order they happened, so a
 * test can check the order of the pipeline's stages; mark() adds a step of the test's own to the same list.
 */
final class SpyLogger implements Logger {

	/**
	 * Entries given to log().
	 *
	 * @var Entry[]
	 */
	public array $entries = array();

	/**
	 * Entries given to begin().
	 *
	 * @var Entry[]
	 */
	public array $begun = array();

	/**
	 * Calls to complete(), each as array{id: int, entry: Entry}.
	 *
	 * @var array<int, array{id: int, entry: Entry}>
	 */
	public array $completed = array();

	/**
	 * Every call and mark, in order: 'log', 'begin', 'complete' or whatever mark() was given.
	 *
	 * @var string[]
	 */
	public array $events = array();

	/**
	 * Next row ID begin() hands out.
	 *
	 * @var int
	 */
	private int $next_id = 1;

	/**
	 * Constructor.
	 *
	 * @param bool $succeeds What log() reports.
	 * @param bool $begins   What begin() reports: true hands out a row ID, false returns 0.
	 * @param bool $completes What complete() reports.
	 */
	public function __construct(
		private bool $succeeds = true,
		private bool $begins = true,
		private bool $completes = true
	) {}

	/**
	 * Records the entry.
	 *
	 * @param Entry $entry Entry.
	 */
	public function log( Entry $entry ): bool {
		$this->events[]  = 'log';
		$this->entries[] = $entry;
		return $this->succeeds;
	}

	/**
	 * Records the entry and hands out a row ID, or 0 when told to fail.
	 *
	 * @param Entry $entry Entry.
	 */
	public function begin( Entry $entry ): int {
		$this->events[] = 'begin';
		$this->begun[]  = $entry;

		if ( ! $this->begins ) {
			return 0;
		}

		return $this->next_id++;
	}

	/**
	 * Records the outcome and reports what it was told to.
	 *
	 * @param int   $id    Row ID.
	 * @param Entry $entry Outcome.
	 */
	public function complete( int $id, Entry $entry ): bool {
		$this->events[]    = 'complete';
		$this->completed[] = array(
			'id'    => $id,
			'entry' => $entry,
		);

		return $this->completes;
	}

	/**
	 * Adds a step of the test's own to the event list.
	 *
	 * @param string $event Name of the step.
	 */
	public function mark( string $event ): void {
		$this->events[] = $event;
	}
}
