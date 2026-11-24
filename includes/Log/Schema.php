<?php
/**
 * The change-log table: its schema, installation, upgrade and removal.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Log;

defined( 'ABSPATH' ) || exit;

/**
 * Creates, upgrades and removes the {prefix}steadhand_log table. The table name is fixed and never built from input.
 */
final class Schema {

	/** Version of the table layout. Bump it with any change to the columns or keys. */
	public const DB_VERSION = '2';

	/** Option that stores the installed layout version. */
	public const OPTION = 'steadhand_db_version';

	/** Option that stores when pruning last ran. Not autoloaded. */
	private const PRUNE_OPTION = 'steadhand_log_last_prune';

	/** Transient that holds off a retry of a failed table create for an hour. */
	public const RETRY_TRANSIENT = 'steadhand_schema_retry';

	/**
	 * Every column of the table, in the order of the CREATE TABLE in install(). Keep the two in step: ready() checks
	 * these names against the real table, and the schema test compares this list with the columns the table has.
	 */
	public const REQUIRED_COLUMNS = array(
		'id',
		'created_at',
		'user_id',
		'agent_label',
		'ability',
		'object_type',
		'object_id',
		'input',
		'result',
		'message',
		'guardrail_notes',
		'snapshot_id',
		'after_snapshot_id',
		'undoable',
		'undone_by',
	);

	/**
	 * Returns the full name of the change-log table.
	 *
	 * @return string
	 */
	public static function table(): string {
		global $wpdb;

		return $wpdb->prefix . 'steadhand_log';
	}

	/**
	 * Creates or updates the table with dbDelta(), then records the layout version. The version is recorded only when
	 * ready() is true afterwards: the table exists and has every column in REQUIRED_COLUMNS. A failed upgrade (for
	 * example a dbDelta() ALTER that the database refused) therefore leaves the old version recorded, so the next
	 * request tries again. When the table is not ready, an hour's back-off is set so that maybe_upgrade() does not retry
	 * on every request. A successful install clears the back-off. Errors from the database are never printed.
	 *
	 * Once the table is ready, every row that is marked undoable but has no after-state is set to not undoable. Rows
	 * written before version 2 have no after-state, so they cannot be undone. The statement is idempotent, so it runs on
	 * every install. A row written by this version always has an after-state when it is undoable, so it is never changed.
	 *
	 * This is the activation path, so it always tries, whatever the back-off says.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$charset = $wpdb->get_charset_collate();

		// dbDelta() needs one column per line, two spaces before PRIMARY KEY, and KEY rather than INDEX.
		$sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  created_at DATETIME NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  agent_label VARCHAR(191) NOT NULL DEFAULT '',
  ability VARCHAR(100) NOT NULL DEFAULT '',
  object_type VARCHAR(40) NOT NULL DEFAULT '',
  object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  input LONGTEXT NOT NULL,
  result VARCHAR(20) NOT NULL DEFAULT '',
  message TEXT NOT NULL,
  guardrail_notes TEXT NOT NULL,
  snapshot_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  after_snapshot_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  undoable TINYINT(1) NOT NULL DEFAULT 0,
  undone_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY user_created (user_id, created_at),
  KEY user_id_id (user_id, id)
) {$charset};";

		$ready = self::quietly(
			static function () use ( $sql ): bool {
				dbDelta( $sql );

				return self::ready();
			}
		);

		if ( $ready ) {
			// Rows written before version 2 have no after-state, so none of them may claim to be undoable. The statement
			// touches only rows that are undoable without one, so it is idempotent: it changes nothing once they are clear,
			// and it runs on every install that succeeds, including activation. A failed statement leaves the version
			// unrecorded, so the next request runs it again.
			$cleared = self::quietly(
				static fn(): mixed => $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET undoable = %d WHERE undoable = %d AND after_snapshot_id = %d", 0, 1, 0 ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The table name is fixed (see table()); every value is bound by prepare().
			);
			$ready   = false !== $cleared;
		}

		if ( $ready ) {
			update_option( self::OPTION, self::DB_VERSION );
			delete_transient( self::RETRY_TRANSIENT );
		} else {
			set_transient( self::RETRY_TRANSIENT, 1, HOUR_IN_SECONDS );
		}
	}

	/**
	 * Installs the table when the recorded layout version is missing or stale. Runs on every boot; the cost is one
	 * autoloaded option read. While the back-off set by a failed install() is in place, does nothing.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( self::OPTION ) === self::DB_VERSION ) {
			return;
		}

		if ( get_transient( self::RETRY_TRANSIENT ) ) {
			return;
		}

		self::install();
	}

	/**
	 * Whether the change-log table is usable: it exists and has every column in REQUIRED_COLUMNS. A table that exists
	 * without a column the code writes (an upgrade whose ALTER failed) is not ready, and the inserts into it fail. A failed
	 * check reports false and prints nothing.
	 *
	 * @return bool
	 */
	public static function ready(): bool {
		global $wpdb;

		if ( ! self::exists() ) {
			return false;
		}

		$table   = self::table();
		$columns = self::quietly(
			static fn(): mixed => $wpdb->get_col( "SHOW COLUMNS FROM {$table}" ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is fixed (see table()); SHOW COLUMNS cannot bind an identifier.
		);

		return is_array( $columns ) && array() === array_diff( self::REQUIRED_COLUMNS, $columns );
	}

	/**
	 * Whether the change-log table exists. A failed check reports false and prints nothing. Use ready() where the code
	 * needs the table to be usable.
	 *
	 * @return bool
	 */
	public static function exists(): bool {
		global $wpdb;

		$found = self::quietly(
			static fn(): mixed => $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A table check must not be cached.
				$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table() ) )
			)
		);

		return is_string( $found ) && '' !== $found;
	}

	/**
	 * Runs a database call with error output suppressed, so a failure cannot print SQL into a response. The previous
	 * setting is restored either way.
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

	/**
	 * Drops the change-log table and removes its options. Called by uninstall.php.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		global $wpdb;

		// The table name is fixed (see table()), so there is nothing to bind. The schema change is the point.
		$table = self::table();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed table name; uninstall drops the table.

		delete_option( self::OPTION );
		delete_option( self::PRUNE_OPTION );
		delete_transient( self::RETRY_TRANSIENT );
	}
}
