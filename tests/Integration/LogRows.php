<?php
/**
 * Change-log helpers for tests: direct table access for fixtures and checks.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Log\DbLogger;
use Steadhand\Log\Entry;
use Steadhand\Log\Schema;

/**
 * Helpers that write fixture rows to, and read rows from, the change-log table. Used by test cases that need them.
 */
trait LogRows {

	/**
	 * Inserts a change-log row directly, with any column overridden. Used for fixtures with a chosen created_at.
	 *
	 * @param array<string, mixed> $fields Columns to set. Others take their defaults.
	 * @return int The new row ID.
	 */
	protected function insert_row( array $fields = array() ): int {
		global $wpdb;

		$row = array_merge(
			array(
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
				'user_id'         => 0,
				'agent_label'     => '',
				'ability'         => 'steadhand/get-site-info',
				'object_type'     => '',
				'object_id'       => 0,
				'input'           => '{}',
				'result'          => Entry::RESULT_OK,
				'message'         => '',
				'guardrail_notes' => '',
				'snapshot_id'     => 0,
				'undoable'        => 0,
				'undone_by'       => 0,
			),
			$fields
		);

		$wpdb->insert( Schema::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture written straight to the change-log table.

		return (int) $wpdb->insert_id;
	}

	/**
	 * Reads one stored row, as an associative array, including its input column.
	 *
	 * @param int $id Row ID.
	 * @return array<string, mixed>
	 */
	protected function stored( int $id ): array {
		global $wpdb;

		$table = Schema::table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test reads the change-log table directly.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is fixed.
			ARRAY_A
		);

		$this->assertIsArray( $row, "Row $id must exist." );

		return $row;
	}

	/**
	 * Whether a row with this ID exists.
	 *
	 * @param int $id Row ID.
	 */
	protected function row_exists( int $id ): bool {
		global $wpdb;

		$table = Schema::table();

		return null !== $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test reads the change-log table directly.
			$wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is fixed.
		);
	}

	/**
	 * Number of rows stored for one user.
	 *
	 * @param int $user_id User ID.
	 */
	protected function count_rows( int $user_id ): int {
		global $wpdb;

		$table = Schema::table();

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test reads the change-log table directly.
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is fixed.
		);
	}

	/**
	 * The most recently stored row for one user, as an associative array.
	 *
	 * @param int $user_id User ID.
	 * @return array<string, mixed>
	 */
	protected function latest_row( int $user_id ): array {
		global $wpdb;

		$table = Schema::table();
		$row   = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test reads the change-log table directly.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d ORDER BY id DESC LIMIT 1", $user_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is fixed.
			ARRAY_A
		);

		$this->assertIsArray( $row, "User $user_id must have a row." );

		return $row;
	}

	/**
	 * Makes INSERTs into the change-log table fail: the statement names a table that does not exist.
	 */
	protected function make_inserts_fail(): void {
		$table = Schema::table();

		add_filter(
			'query',
			static function ( string $query ) use ( $table ): string {
				return str_starts_with( $query, 'INSERT INTO `' . $table . '`' )
					? str_replace( 'INSERT INTO `' . $table . '`', 'INSERT INTO `' . $table . '_missing`', $query )
					: $query;
			}
		);
	}

	/**
	 * Makes the table look missing to exists(): the existence check finds nothing.
	 */
	protected function make_table_look_missing(): void {
		add_filter(
			'query',
			static function ( string $query ): string {
				return str_starts_with( $query, 'SHOW TABLES LIKE' )
					? "SHOW TABLES LIKE 'steadhand_no_such_table'"
					: $query;
			}
		);
	}

	/**
	 * Logs one entry through the real logger and returns its outcome.
	 *
	 * @param Entry $entry Entry to log.
	 */
	protected function log( Entry $entry ): bool {
		return ( new DbLogger() )->log( $entry );
	}

	/**
	 * Builds an entry with defaults that tests override by name.
	 *
	 * @param int                  $user_id User ID.
	 * @param string               $result  Result.
	 * @param string               $message Message.
	 * @param array<string, mixed> $input   Input.
	 */
	protected function entry( int $user_id, string $result = Entry::RESULT_OK, string $message = '', array $input = array() ): Entry {
		return new Entry( $user_id, 'Test agent', 'steadhand/get-site-info', $result, $message, $input );
	}
}
