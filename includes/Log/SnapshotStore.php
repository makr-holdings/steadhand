<?php
/**
 * The snapshots table: the saved state that undoes a term or featured-image change.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Log;

use Steadhand\Pipeline\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * Stores and reads the state an undo needs (SPEC §7). A row holds one JSON state: the post's terms in one taxonomy, or its
 * featured image. Post text is not stored here; it uses core revisions.
 *
 * Only a write can save a row (Pipeline::is_writing()); outside a write save() throws, as Snapshots does. Every other call
 * never throws: a failed query, a missing table, or a state that will not encode gives 0 (save) or null (find), so the write
 * that asked for the save is recorded as not undoable and not as an error. All SQL goes through $wpdb->prepare() or
 * $wpdb->insert(), and the table name is fixed (Schema::snapshots_table()).
 */
final class SnapshotStore {

	/** A snapshot of one post's terms in one taxonomy. */
	public const KIND_POST_TERMS = 'post_terms';

	/** A snapshot of one post's featured image. */
	public const KIND_FEATURED_IMAGE = 'featured_image';

	/** The state of one classic menu item before an update-menu call: its menu, status, title, parent and order. Never undoable yet. */
	public const KIND_MENU_ITEM = 'menu_item';

	/**
	 * The most bytes one saved state may take, as encoded JSON. A post with the most terms set-post-terms can see (500 in one
	 * taxonomy, each ID at most 10 digits and a comma) fits well inside it. A larger state is not saved, so the write is not
	 * undoable.
	 */
	public const MAX_STATE_BYTES = 16384;

	/** The kinds save() accepts. */
	private const KINDS = array( self::KIND_POST_TERMS, self::KIND_FEATURED_IMAGE, self::KIND_MENU_ITEM );

	/** The longest object type that fits the table. */
	private const MAX_OBJECT_TYPE_BYTES = 32;

	/** Nesting a stored state may have. The states are flat objects of lists and integers. */
	private const MAX_DEPTH = 8;

	/**
	 * Saves one state and returns its row ID. Called only from a write, for the state before a call (snapshot()) or after it
	 * (snapshot_after()).
	 *
	 * Returns 0, and saves nothing, when the snapshots table is not ready, the kind is not one of KINDS, the object is not
	 * valid, the state will not encode, or the state is larger than MAX_STATE_BYTES. The user saved is the current user.
	 *
	 * @param string               $kind        One of the KIND_ constants.
	 * @param string               $object_type The object's type, for the post: 'post'. At most 32 bytes.
	 * @param int                  $object_id   The object's ID, at least 1.
	 * @param array<string, mixed> $state      The state to save. Encoded as JSON.
	 * @return int The row ID, or 0.
	 * @throws \LogicException When called outside a write.
	 */
	public static function save( string $kind, string $object_type, int $object_id, array $state ): int {
		if ( ! Pipeline::is_writing() ) {
			throw new \LogicException( 'SnapshotStore::save() may only be called from a write ability.' );
		}

		try {
			return self::insert( $kind, $object_type, $object_id, $state );
		} catch ( \Throwable ) {
			return 0;
		}
	}

	/**
	 * Reads one saved state. Returns null for a missing row, a row whose state is not a JSON object, or a table that is not
	 * ready. Never throws. The state is returned decoded; checking its shape is the caller's job.
	 *
	 * @param int $id Row ID.
	 * @return array{id: int, created_at: string, user_id: int, kind: string, object_type: string, object_id: int, state: array<string, mixed>}|null
	 */
	public static function find( int $id ): ?array {
		global $wpdb;

		try {
			if ( $id <= 0 || ! Schema::snapshots_ready() ) {
				return null;
			}

			$table = Schema::snapshots_table();
			$row   = self::quietly(
				static function () use ( $wpdb, $table, $id ): mixed {
					// The table name is fixed (see Schema::snapshots_table()); every value is bound by prepare().
					return $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A live read of one saved state.
						$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See the comment above.
						ARRAY_A
					);
				}
			);

			if ( ! is_array( $row ) ) {
				return null;
			}

			$state = json_decode( (string) $row['state'], true, self::MAX_DEPTH, JSON_THROW_ON_ERROR );
			if ( ! is_array( $state ) ) {
				return null;
			}

			return array(
				'id'          => (int) $row['id'],
				'created_at'  => (string) $row['created_at'],
				'user_id'     => (int) $row['user_id'],
				'kind'        => (string) $row['kind'],
				'object_type' => (string) $row['object_type'],
				'object_id'   => (int) $row['object_id'],
				'state'       => $state,
			);
		} catch ( \Throwable ) {
			return null;
		}
	}

	/**
	 * Encodes the state and inserts the row. Every rule that makes save() return 0 is checked here.
	 *
	 * @param string               $kind        Kind.
	 * @param string               $object_type Object type.
	 * @param int                  $object_id   Object ID.
	 * @param array<string, mixed> $state       State.
	 * @return int The row ID, or 0.
	 */
	private static function insert( string $kind, string $object_type, int $object_id, array $state ): int {
		global $wpdb;

		if ( ! in_array( $kind, self::KINDS, true ) || '' === $object_type || strlen( $object_type ) > self::MAX_OBJECT_TYPE_BYTES || $object_id < 1 ) {
			return 0;
		}

		if ( ! Schema::snapshots_ready() ) {
			return 0;
		}

		$json = wp_json_encode( $state, 0, self::MAX_DEPTH );
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_STATE_BYTES ) {
			return 0;
		}

		$inserted = self::quietly(
			static fn(): mixed => $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Inserts into the snapshots table; $wpdb->insert() escapes every value.
				Schema::snapshots_table(),
				array(
					'created_at'  => gmdate( 'Y-m-d H:i:s' ),
					'user_id'     => max( 0, get_current_user_id() ),
					'kind'        => $kind,
					'object_type' => $object_type,
					'object_id'   => $object_id,
					'state'       => $json,
				),
				array( '%s', '%d', '%s', '%s', '%d', '%s' )
			)
		);

		$id = (int) $wpdb->insert_id;

		return 1 === $inserted && $id > 0 ? $id : 0;
	}

	/**
	 * Runs a database call with error output suppressed, so a failure cannot print SQL into a response. The previous setting is
	 * restored either way.
	 *
	 * @param callable $work The database call.
	 * @return mixed What the call returned.
	 */
	private static function quietly( callable $work ): mixed {
		global $wpdb;

		$previous = $wpdb->suppress_errors( true );
		try {
			return $work();
		} finally {
			$wpdb->suppress_errors( $previous );
		}
	}
}
