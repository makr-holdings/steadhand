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
	public const DB_VERSION = '1';

	/** Option that stores the installed layout version. */
	public const OPTION = 'steadhand_db_version';

	/** Option that stores when pruning last ran. Not autoloaded. */
	private const PRUNE_OPTION = 'steadhand_log_last_prune';

	/** Transient that holds off a retry of a failed table create for an hour. */
	public const RETRY_TRANSIENT = 'steadhand_schema_retry';

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
	 * the table exists afterwards. When it does not, an hour's back-off is set so that maybe_upgrade() does not retry on
	 * every request. A successful install clears the back-off. Errors from the database are never printed.
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
  undoable TINYINT(1) NOT NULL DEFAULT 0,
  undone_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY user_created (user_id, created_at),
  KEY user_id_id (user_id, id)
) {$charset};";

		$exists = self::quietly(
			static function () use ( $sql ): bool {
				dbDelta( $sql );

				return self::exists();
			}
		);

		if ( $exists ) {
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
	 * Whether the change-log table exists. A failed check reports false and prints nothing.
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
