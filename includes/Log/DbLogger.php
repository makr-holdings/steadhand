<?php
/**
 * Stores change-log entries in the {prefix}steadhand_log table, and reads them back for the owning agent.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Log;

use Steadhand\Abilities\PostReader;
use Steadhand\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * The change log. Every stored value is redacted and size-capped here, so no caller can store a secret or an
 * unbounded input by passing it in.
 */
final class DbLogger implements Logger {

	/** Largest stored input, in bytes of JSON. Larger input is replaced by a summary. */
	public const MAX_INPUT_BYTES = 16384;

	/**
	 * Largest input that is scanned for secrets, in bytes of JSON. Larger input is not scanned at all: it is replaced
	 * by the summary, so the scan never runs over a huge document.
	 */
	public const MAX_RAW_INPUT_BYTES = 262144;

	/** Largest stored message or guardrail note, in bytes. */
	public const MAX_MESSAGE_BYTES = 2000;

	/** Largest stored agent label, in bytes. */
	public const MAX_LABEL_BYTES = 191;

	/** Refused calls per user per minute that are stored. One marker row follows, then nothing more is stored. */
	public const REFUSALS_PER_MINUTE = 60;

	/** Days an entry is kept, unless the steadhand_log_retention_days filter says otherwise. */
	public const RETENTION_DAYS = 30;

	/** Most rows one delete statement removes while pruning. */
	public const PRUNE_BATCH = 1000;

	/** Most delete batches one prune pass runs. If the budget runs out, the next write continues the pruning. */
	public const PRUNE_MAX_BATCHES = 20;

	/** Longest retention a filter may ask for, in days. */
	private const MAX_RETENTION_DAYS = 3650;

	/** Most top-level key names kept in the summary of an oversized input. */
	private const MAX_KEYS = 50;

	/** Longest key name kept in the summary, in bytes. */
	private const MAX_KEY_BYTES = 100;

	/** Deepest nesting of stored input whose keys are redacted. Deeper arrays are replaced by a marker. */
	private const MAX_KEY_DEPTH = 20;

	/** Option that records when pruning last ran. Not autoloaded. */
	private const PRUNE_OPTION = 'steadhand_log_last_prune';

	/** Longest a stored object_type may be, in bytes. */
	private const MAX_OBJECT_TYPE_BYTES = 40;

	/** Ability names: steadhand/ and a lowercase slug. */
	private const ABILITY_PATTERN = '/^steadhand\/[a-z0-9-]{1,80}\z/';

	/** Every result a stored entry may have. */
	private const RESULTS = array(
		Entry::RESULT_OK,
		Entry::RESULT_BLOCKED,
		Entry::RESULT_FIXED,
		Entry::RESULT_ERROR,
	);

	/**
	 * Rows one delete statement removes while pruning.
	 *
	 * @var int
	 */
	private int $prune_batch;

	/**
	 * Delete statements one prune pass may run.
	 *
	 * @var int
	 */
	private int $prune_max_batches;

	/**
	 * Constructor. The defaults are the production values; tests pass small numbers to reach the budget.
	 *
	 * @param int $prune_batch       Rows one delete statement removes, at least 1.
	 * @param int $prune_max_batches Delete statements one prune pass may run, at least 1.
	 */
	public function __construct( int $prune_batch = self::PRUNE_BATCH, int $prune_max_batches = self::PRUNE_MAX_BATCHES ) {
		$this->prune_batch       = max( 1, $prune_batch );
		$this->prune_max_batches = max( 1, $prune_max_batches );
	}

	/**
	 * Stores one entry.
	 *
	 * Returns true when the entry was stored, or when the throttle deliberately dropped it. Returns false when the
	 * entry is invalid or could not be written. Never throws.
	 *
	 * @param Entry $entry Entry to store.
	 * @return bool
	 */
	public function log( Entry $entry ): bool {
		try {
			return $this->write( $entry );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Applies the rules for one entry and stores it.
	 *
	 * @param Entry $entry Entry to store.
	 * @return bool
	 */
	private function write( Entry $entry ): bool {
		if ( ! in_array( $entry->result, self::RESULTS, true ) || 1 !== preg_match( self::ABILITY_PATTERN, $entry->ability ) ) {
			return false;
		}

		$input_json = $this->input_json( $entry->input );

		// Only refusals are throttled. An error is written after execute() ran, so it is always stored.
		if ( Entry::RESULT_BLOCKED === $entry->result ) {
			$recent = $this->recent_refusals( $entry->user_id );

			// Past the limit, nothing more is stored. True means the entry was handled, not that a row was written.
			if ( $recent > self::REFUSALS_PER_MINUTE ) {
				return true;
			}

			// At the limit, one marker row says that later refusals are not being logged.
			if ( self::REFUSALS_PER_MINUTE === $recent ) {
				$marker = new Entry(
					$entry->user_id,
					$entry->agent_label,
					$entry->ability,
					Entry::RESULT_BLOCKED,
					/* translators: Message stored in the change log when refused calls are being dropped. */
					__( 'Further refused calls from this user are not being logged for up to a minute.', 'steadhand' )
				);

				return $this->store( $marker, '{}' );
			}
		}

		return $this->store( $entry, $input_json );
	}

	/**
	 * Builds the stored row and inserts it. Runs the listeners and retention after a successful insert.
	 *
	 * @param Entry  $entry      Entry to store.
	 * @param string $input_json Stored input, already redacted and capped.
	 * @return bool
	 */
	private function store( Entry $entry, string $input_json ): bool {
		global $wpdb;

		$row = array(
			'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			'user_id'         => max( 0, $entry->user_id ),
			'agent_label'     => PostReader::cut_utf8( Redactor::text( $entry->agent_label ), self::MAX_LABEL_BYTES ),
			'ability'         => $entry->ability,
			'object_type'     => PostReader::cut_utf8( sanitize_key( $entry->object_type ), self::MAX_OBJECT_TYPE_BYTES ),
			'object_id'       => max( 0, $entry->object_id ),
			'input'           => $input_json,
			'result'          => $entry->result,
			'message'         => PostReader::cut_utf8( Redactor::text( $entry->message ), self::MAX_MESSAGE_BYTES ),
			'guardrail_notes' => PostReader::cut_utf8( Redactor::text( $entry->guardrail_notes ), self::MAX_MESSAGE_BYTES ),
			'snapshot_id'     => max( 0, $entry->snapshot_id ),
			'undoable'        => $entry->undoable ? 1 : 0,
			'undone_by'       => 0,
		);

		$inserted = $this->quietly(
			static fn(): mixed => $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Inserts into the change-log table; $wpdb->insert() escapes every value.
				Schema::table(),
				$row,
				array( '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d' )
			)
		);

		$id = (int) $wpdb->insert_id;
		if ( 1 !== $inserted || $id <= 0 ) {
			return false;
		}

		$row = array( 'id' => $id ) + $row;
		$this->announce( $id, $row );
		$this->prune_if_due();

		return true;
	}

	/**
	 * Tells listeners that an entry was stored. A listener that throws cannot change the outcome.
	 *
	 * @param int                  $id  Stored row ID.
	 * @param array<string, mixed> $row The stored row, redacted.
	 */
	private function announce( int $id, array $row ): void {
		try {
			do_action( 'steadhand_log_entry_stored', $id, $row );
		} catch ( \Throwable ) {
			// Deliberately ignored: the entry is stored, and a listener cannot change that.
			return;
		}
	}

	/**
	 * Redacts and caps the input for storage.
	 *
	 * Never stores an unredacted document, and never a document cut in the middle. Input over MAX_RAW_INPUT_BYTES, input
	 * that cannot be encoded, and input that is still too large after redaction are not stored as they are. They are
	 * replaced by a summary: whether it was truncated, its original size and its top-level key names, each key redacted.
	 * The input over MAX_RAW_INPUT_BYTES is not scanned at all.
	 *
	 * @param array<string, mixed> $input Input as given.
	 * @return string JSON text.
	 */
	private function input_json( array $input ): string {
		$raw = wp_json_encode( $input );
		if ( false === $raw || strlen( $raw ) > self::MAX_RAW_INPUT_BYTES ) {
			return $this->summary( $input, false === $raw ? 0 : strlen( $raw ) );
		}

		$value    = Redactor::value( $input );
		$redacted = is_array( $value ) ? $this->redact_keys( $value ) : array();
		if ( array() === $redacted ) {
			return '{}';
		}

		$json = wp_json_encode( $redacted );
		if ( false !== $json && strlen( $json ) <= self::MAX_INPUT_BYTES ) {
			return $json;
		}

		return $this->summary( $input, strlen( $raw ) );
	}

	/**
	 * Builds the summary stored in place of an input that is too large, cannot be encoded, or is not scanned.
	 *
	 * @param array<array-key, mixed> $input Input as given.
	 * @param int                     $bytes Original size in bytes of JSON, or 0 if it cannot be encoded.
	 * @return string JSON text.
	 */
	private function summary( array $input, int $bytes ): string {
		$keys = array();
		foreach ( array_slice( array_keys( $input ), 0, self::MAX_KEYS ) as $key ) {
			$keys[] = PostReader::cut_utf8( Redactor::text( (string) $key ), self::MAX_KEY_BYTES );
		}

		$summary = wp_json_encode(
			array(
				'truncated' => true,
				'bytes'     => $bytes,
				'keys'      => $keys,
			)
		);

		return false === $summary ? '{"truncated":true,"bytes":0,"keys":[]}' : $summary;
	}

	/**
	 * Redacts every string key of an array, recursively, so that a secret used as a key is not stored. Two keys that
	 * redact to the same text both keep their values: the later one gets #2, #3 and so on. Integer keys are kept.
	 *
	 * @param array<array-key, mixed> $value Redacted input.
	 * @param int                     $depth Current nesting level. Callers leave this at 0.
	 * @return array<array-key, mixed>
	 */
	private function redact_keys( array $value, int $depth = 0 ): array {
		$clean = array();
		foreach ( $value as $key => $child ) {
			if ( is_string( $key ) ) {
				$key = $this->unique_key( $clean, Redactor::text( $key ) );
			} elseif ( array_key_exists( $key, $clean ) ) {
				$key = $this->unique_key( $clean, (string) $key );
			}

			if ( is_array( $child ) ) {
				$child = $depth + 1 > self::MAX_KEY_DEPTH ? '[depth]' : $this->redact_keys( $child, $depth + 1 );
			}

			$clean[ $key ] = $child;
		}

		return $clean;
	}

	/**
	 * Returns the key, or the key with #2, #3 and so on appended, so that it is not already in use.
	 *
	 * @param array<array-key, mixed> $existing Keys already used.
	 * @param string                  $key      Wanted key.
	 * @return string
	 */
	private function unique_key( array $existing, string $key ): string {
		if ( ! array_key_exists( $key, $existing ) ) {
			return $key;
		}

		for ( $n = 2; ; $n++ ) {
			$candidate = $key . '#' . $n;
			if ( ! array_key_exists( $candidate, $existing ) ) {
				return $candidate;
			}
		}
	}

	/**
	 * How many refused calls this user made in the last minute. Errors are not refusals, so they are not counted.
	 *
	 * @param int $user_id Agent user ID.
	 * @return int
	 */
	private function recent_refusals( int $user_id ): int {
		global $wpdb;

		$table = Schema::table();
		$since = gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS );

		return (int) $this->quietly(
			static fn(): mixed => $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A live count must not be cached.
				// The table name is fixed (see Schema::table()); every value is bound by prepare().
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE user_id = %d AND result = %s AND created_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See the comment above.
					max( 0, $user_id ),
					Entry::RESULT_BLOCKED,
					$since
				)
			)
		);
	}

	/**
	 * Deletes entries past retention, in batches, at most once a day.
	 *
	 * A pass deletes batches of PRUNE_BATCH rows until a batch comes back short, which means nothing old is left, or
	 * until PRUNE_MAX_BATCHES batches have run. Only a pass that ended on a short batch records the day's prune. If the
	 * budget ran out, nothing is recorded, so the next write prunes again. Never fails the call that triggered it.
	 */
	private function prune_if_due(): void {
		global $wpdb;

		try {
			$now = time();
			if ( (int) get_option( self::PRUNE_OPTION, 0 ) > $now - DAY_IN_SECONDS ) {
				return;
			}

			$table  = Schema::table();
			$cutoff = gmdate( 'Y-m-d H:i:s', $now - $this->retention_days() * DAY_IN_SECONDS );
			$batch  = $this->prune_batch;

			for ( $i = 0; $i < $this->prune_max_batches; $i++ ) {
				$deleted = $this->quietly(
					static fn(): mixed => $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A bounded delete on the change log.
						// The table name is fixed (see Schema::table()); every value is bound by prepare().
						$wpdb->prepare(
							"DELETE FROM {$table} WHERE created_at < %s ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See the comment above.
							$cutoff,
							$batch
						)
					)
				);

				// A short batch means nothing old is left. A failed delete also counts as short, so it is not retried on
				// every write.
				if ( (int) $deleted < $batch ) {
					update_option( self::PRUNE_OPTION, $now, false );
					return;
				}
			}

			// The budget ran out with old rows possibly left. Nothing is recorded, so the next write continues.
		} catch ( \Throwable ) {
			// Deliberately ignored: pruning is best effort and the entry is already stored.
			return;
		}
	}

	/**
	 * Days to keep entries. The filter may change it; anything that is not a whole number of at least 1 falls back to
	 * the default, and the result is capped.
	 *
	 * @return int
	 */
	private function retention_days(): int {
		try {
			$days = apply_filters( 'steadhand_log_retention_days', self::RETENTION_DAYS );
		} catch ( \Throwable ) {
			return self::RETENTION_DAYS;
		}

		if ( is_string( $days ) && 1 === preg_match( '/^\d+\z/', $days ) ) {
			$days = (int) $days;
		}

		if ( ! is_int( $days ) || $days < 1 ) {
			return self::RETENTION_DAYS;
		}

		return min( $days, self::MAX_RETENTION_DAYS );
	}

	/**
	 * Reads one page of an agent's own entries, newest first. Never returns the stored input.
	 *
	 * Filters are checked before any query runs. An unknown result, a malformed ability or a malformed date returns an
	 * empty result rather than a wider one. If the table cannot be read, returns steadhand_log_unavailable rather than an
	 * empty result, so an unreadable log is never reported as an empty one.
	 *
	 * @param int                  $user_id  The agent whose entries to read. Only its rows are returned.
	 * @param array<string, mixed> $filters  Optional: result, ability, after and before (UTC 'Y-m-d H:i:s', inclusive).
	 * @param int                  $page     Page number, from 1.
	 * @param int                  $per_page Entries per page, 1 to 50.
	 * @return array{items: array<int, array<string, mixed>>, total: int}|\WP_Error
	 */
	public function query( int $user_id, array $filters, int $page, int $per_page ): array|\WP_Error {
		global $wpdb;

		$none  = array(
			'items' => array(),
			'total' => 0,
		);
		$where = array( 'user_id = %d' );
		$args  = array( max( 0, $user_id ) );

		if ( isset( $filters['result'] ) ) {
			if ( ! in_array( $filters['result'], self::RESULTS, true ) ) {
				return $none;
			}
			$where[] = 'result = %s';
			$args[]  = (string) $filters['result'];
		}

		if ( isset( $filters['ability'] ) ) {
			if ( 1 !== preg_match( self::ABILITY_PATTERN, (string) $filters['ability'] ) ) {
				return $none;
			}
			$where[] = 'ability = %s';
			$args[]  = (string) $filters['ability'];
		}

		$bounds = array(
			'after'  => 'created_at >= %s',
			'before' => 'created_at <= %s',
		);
		foreach ( $bounds as $key => $clause ) {
			if ( ! isset( $filters[ $key ] ) ) {
				continue;
			}
			if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\z/', (string) $filters[ $key ] ) ) {
				return $none;
			}
			$where[] = $clause;
			$args[]  = (string) $filters[ $key ];
		}

		$table     = Schema::table();
		$per_page  = min( 50, max( 1, $per_page ) );
		$page      = max( 1, $page );
		$offset    = ( $page - 1 ) * $per_page;
		$condition = implode( ' AND ', $where );

		return $this->quietly(
			function () use ( $wpdb, $table, $condition, $args, $per_page, $offset ): array|\WP_Error {
				// The table name and the clause text are fixed strings built only from the fragments above. Every value is
				// bound by prepare() through $args, so the placeholders are in $condition, not in these literals.
				$count_sql        = "SELECT COUNT(*) FROM {$table} WHERE {$condition}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See the comment above.
				$wpdb->last_error = '';
				$total            = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A live count.
					$wpdb->prepare( $count_sql, ...$args ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The clause is built from fixed fragments; every value is bound by prepare().
				);
				if ( '' !== (string) $wpdb->last_error ) {
					return $this->unavailable();
				}

				if ( 0 === $total ) {
					return array(
						'items' => array(),
						'total' => 0,
					);
				}

				$page_sql         = "SELECT id, created_at, ability, object_type, object_id, result, message, guardrail_notes, undoable, undone_by FROM {$table} WHERE {$condition} ORDER BY id DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See the comment above.
				$wpdb->last_error = '';
				$rows             = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A page of the change log.
					$wpdb->prepare( $page_sql, ...array_merge( $args, array( $per_page, $offset ) ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- See the comment above.
					ARRAY_A
				);
				if ( '' !== (string) $wpdb->last_error ) {
					return $this->unavailable();
				}

				$items = array();
				foreach ( (array) $rows as $row ) {
					$items[] = array(
						'id'              => (int) $row['id'],
						'created_at'      => (string) $row['created_at'],
						'ability'         => (string) $row['ability'],
						'object_type'     => (string) $row['object_type'],
						'object_id'       => (int) $row['object_id'],
						'result'          => (string) $row['result'],
						'message'         => (string) $row['message'],
						'guardrail_notes' => (string) $row['guardrail_notes'],
						'undoable'        => (bool) (int) $row['undoable'],
						'undone_by'       => (int) $row['undone_by'],
					);
				}

				return array(
					'items' => $items,
					'total' => $total,
				);
			}
		);
	}

	/**
	 * The error for a read of the change log that the database did not complete.
	 *
	 * @return \WP_Error
	 */
	private function unavailable(): \WP_Error {
		return new \WP_Error(
			'steadhand_log_unavailable',
			__( 'The change log could not be read. Check the site error log, or deactivate and reactivate Steadhand to recreate its table.', 'steadhand' ),
			array( 'status' => 503 )
		);
	}

	/**
	 * Runs a database call with error output suppressed, so a failure cannot print SQL into an MCP response. The
	 * previous setting is restored either way.
	 *
	 * @param callable $work The database call.
	 * @return mixed What the call returned.
	 */
	private function quietly( callable $work ): mixed {
		global $wpdb;

		$previous = $wpdb->suppress_errors( true );
		try {
			return $work();
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}
}
