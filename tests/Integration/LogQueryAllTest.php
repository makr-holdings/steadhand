<?php
/**
 * The admin reads of the change log: query_all(), find_any() and users(). The per-user query() must not change.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Log\DbLogger;
use Steadhand\Log\Entry;

/**
 * Tests of query_all() and the other admin reads. query_all() reads every user's rows and keeps the same filter rules as
 * query(). Both are for the admin screen, and the caller checks manage_options; these tests call them directly.
 */
final class LogQueryAllTest extends LogTestCase {

	/** Creates three rows for two users, with different results and abilities. Returns their IDs. */
	private function fixtures(): array {
		$a = $this->insert_row(
			array(
				'user_id'     => 5,
				'agent_label' => 'Editor bot',
				'ability'     => 'steadhand/update-post',
				'object_type' => 'post',
				'object_id'   => 10,
				'result'      => Entry::RESULT_OK,
				'message'     => 'Updated',
				'input'       => '{"id":10,"title":"Secret title"}',
			)
		);
		$b = $this->insert_row(
			array(
				'user_id'     => 6,
				'agent_label' => 'Reader bot',
				'ability'     => 'steadhand/get-post',
				'result'      => Entry::RESULT_BLOCKED,
				'message'     => 'Refused',
				'input'       => '{"id":11}',
			)
		);
		$c = $this->insert_row(
			array(
				'user_id'     => 5,
				'agent_label' => 'Editor bot',
				'ability'     => 'steadhand/set-post-terms',
				'result'      => Entry::RESULT_FIXED,
				'message'     => 'Fixed',
				'created_at'  => '2020-01-01 00:00:00',
			)
		);

		return array( $a, $b, $c );
	}

	private function ids( array $page ): array {
		return array_map( static fn( array $item ): int => (int) $item['id'], $page['items'] );
	}

	public function test_returns_rows_of_every_user_with_their_owner_fields(): void {
		[ $a, $b, $c ] = $this->fixtures();

		$page = $this->logger()->query_all( array(), 1, 50 );

		$this->assertIsArray( $page );
		$this->assertSame( 3, $page['total'] );
		$this->assertEqualsCanonicalizing( array( $a, $b, $c ), $this->ids( $page ) );
		$first = $page['items'][0];
		$this->assertArrayHasKey( 'user_id', $first );
		$this->assertArrayHasKey( 'agent_label', $first );
		$this->assertIsInt( $first['user_id'] );
		$this->assertIsString( $first['agent_label'] );
	}

	public function test_items_never_carry_the_stored_input(): void {
		$this->fixtures();

		$page = $this->logger()->query_all( array(), 1, 50 );

		foreach ( $page['items'] as $item ) {
			$this->assertArrayNotHasKey( 'input', $item );
		}
		$this->assertStringNotContainsString( 'Secret title', (string) wp_json_encode( $page ) );
	}

	public function test_newest_first_and_paged(): void {
		[ $a, $b, $c ] = $this->fixtures();

		$first  = $this->logger()->query_all( array(), 1, 2 );
		$second = $this->logger()->query_all( array(), 2, 2 );

		$this->assertSame( 3, $first['total'] );
		$this->assertSame( array( $c, $b ), $this->ids( $first ) );
		$this->assertSame( array( $a ), $this->ids( $second ) );
	}

	public function test_each_filter_narrows_the_result(): void {
		[ $a, $b, $c ] = $this->fixtures();

		$this->assertSame( array( $b ), $this->ids( $this->logger()->query_all( array( 'result' => Entry::RESULT_BLOCKED ), 1, 50 ) ) );
		$this->assertSame( array( $a ), $this->ids( $this->logger()->query_all( array( 'ability' => 'steadhand/update-post' ), 1, 50 ) ) );
		$this->assertSame( array( $a, $c ), array_reverse( $this->ids( $this->logger()->query_all( array( 'user_id' => 5 ), 1, 50 ) ) ) );
		$this->assertSame( array( $c ), $this->ids( $this->logger()->query_all( array( 'before' => '2021-01-01 00:00:00' ), 1, 50 ) ) );
		$this->assertSame( array( $b, $a ), $this->ids( $this->logger()->query_all( array( 'after' => '2021-01-01 00:00:00' ), 1, 50 ) ) );
	}

	public function test_a_user_filter_of_zero_or_less_returns_the_empty_result_not_everyone(): void {
		$this->fixtures();

		foreach ( array( 0, -1 ) as $user_id ) {
			$this->assertSame(
				array(
					'items' => array(),
					'total' => 0,
				),
				$this->logger()->query_all( array( 'user_id' => $user_id ), 1, 50 )
			);
		}
	}

	public function test_a_malformed_filter_returns_the_empty_result_not_a_wider_one(): void {
		$this->fixtures();
		$empty = array(
			'items' => array(),
			'total' => 0,
		);

		$this->assertSame( $empty, $this->logger()->query_all( array( 'result' => 'nope' ), 1, 50 ) );
		$this->assertSame( $empty, $this->logger()->query_all( array( 'ability' => 'steadhand/Get-Post' ), 1, 50 ) );
		$this->assertSame( $empty, $this->logger()->query_all( array( 'ability' => 'other/get-post' ), 1, 50 ) );
		$this->assertSame( $empty, $this->logger()->query_all( array( 'after' => '2026-02-30' ), 1, 50 ) );
		$this->assertSame( $empty, $this->logger()->query_all( array( 'before' => 'yesterday' ), 1, 50 ) );
		$this->assertSame( $empty, $this->logger()->query_all( array( 'user_id' => '5' ), 1, 50 ) );
	}

	public function test_the_per_user_query_still_reads_only_that_user(): void {
		[ $a, , $c ] = $this->fixtures();

		$page = $this->logger()->query( 5, array(), 1, 50 );

		$this->assertSame( 2, $page['total'] );
		$this->assertEqualsCanonicalizing( array( $a, $c ), $this->ids( $page ) );
		$this->assertArrayNotHasKey( 'agent_label', $page['items'][0] );
		$this->assertArrayNotHasKey( 'user_id', $page['items'][0] );
	}

	public function test_query_all_is_unavailable_when_the_table_is_missing(): void {
		$this->make_table_look_missing();

		$result = $this->logger()->query_all( array(), 1, 50 );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_log_unavailable', $result->get_error_code() );
	}

	public function test_find_any_returns_one_row_of_any_user_with_its_input(): void {
		[ $a ] = $this->fixtures();

		$row = $this->logger()->find_any( $a );

		$this->assertIsArray( $row );
		$this->assertSame( 5, $row['user_id'] );
		$this->assertSame( '{"id":10,"title":"Secret title"}', $row['input'] );
		$this->assertSame( 'steadhand/update-post', $row['ability'] );
	}

	public function test_find_any_returns_null_for_a_missing_row_or_a_missing_table(): void {
		$this->assertNull( $this->logger()->find_any( 987654321 ) );
		$this->assertNull( $this->logger()->find_any( 0 ) );

		[ $a ] = $this->fixtures();
		$this->make_table_look_missing();
		$this->assertNull( $this->logger()->find_any( $a ) );
	}

	public function test_users_lists_the_distinct_user_ids_ascending_as_integers(): void {
		$this->fixtures();
		$this->insert_row( array( 'user_id' => 2 ) );
		$this->insert_row( array( 'user_id' => 0 ) );

		$this->assertSame( array( 2, 5, 6 ), $this->logger()->users() );
	}

	public function test_users_is_empty_when_the_table_is_missing(): void {
		$this->fixtures();
		$this->make_table_look_missing();

		$this->assertSame( array(), $this->logger()->users() );
	}

	private function logger(): DbLogger {
		return new DbLogger();
	}
}
