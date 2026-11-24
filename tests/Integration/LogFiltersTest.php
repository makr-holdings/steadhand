<?php
/**
 * LogFilters: every invalid filter makes the set invalid, and a valid set becomes the query for DbLogger::query_all().
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Admin\LogFilters;

/**
 * The filters are checked before any query runs. A set that is not valid must never produce a wider query.
 */
final class LogFiltersTest extends TestCase {

	/**
	 * The timezone option before the test, restored in tearDown.
	 *
	 * @var string
	 */
	private string $timezone = '';

	protected function setUp(): void {
		parent::setUp();
		$this->timezone = (string) get_option( 'timezone_string', '' );
	}

	protected function tearDown(): void {
		update_option( 'timezone_string', $this->timezone );
		parent::tearDown();
	}

	public function test_an_empty_request_is_valid_with_no_query_and_the_defaults(): void {
		$filters = LogFilters::from_request( array() );

		$this->assertTrue( $filters->is_valid() );
		$this->assertSame( array(), $filters->to_query() );
		$this->assertSame( 1, $filters->page() );
		$this->assertSame( 0, $filters->entry_id() );
		$this->assertSame(
			array(
				'agent'   => '',
				'ability' => '',
				'result'  => '',
				'from'    => '',
				'to'      => '',
				'paged'   => '',
				'entry'   => '',
			),
			$filters->values()
		);
	}

	/**
	 * Requests that must make the set invalid, for the data provider.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function invalid_requests(): array {
		return array(
			'agent with an injection'      => array( array( 'agent' => '1 OR 1=1' ) ),
			'agent zero'                   => array( array( 'agent' => '0' ) ),
			'agent negative'               => array( array( 'agent' => '-3' ) ),
			'agent as an array'            => array( array( 'agent' => array( '1' ) ) ),
			'ability with uppercase'       => array( array( 'ability' => 'steadhand/Get-Post' ) ),
			'ability in another namespace' => array( array( 'ability' => 'other/get-post' ) ),
			'ability with a space'         => array( array( 'ability' => 'steadhand/get post' ) ),
			'ability as an array'          => array( array( 'ability' => array( 'steadhand/get-post' ) ) ),
			'unknown result'               => array( array( 'result' => 'nope' ) ),
			'result as an array'           => array( array( 'result' => array( 'ok' ) ) ),
			'impossible date'              => array( array( 'from' => '2026-02-30' ) ),
			'date with slashes'            => array( array( 'from' => '2026/02/03' ) ),
			'date with a time'             => array( array( 'to' => '2026-02-03 10:00' ) ),
			'month thirteen'               => array( array( 'to' => '2026-13-01' ) ),
			'date as an array'             => array( array( 'to' => array( '2026-02-03' ) ) ),
			'page zero'                    => array( array( 'paged' => '0' ) ),
			'page above the cap'           => array( array( 'paged' => '100001' ) ),
			'page with a decimal'          => array( array( 'paged' => '1.5' ) ),
			'entry negative'               => array( array( 'entry' => '-1' ) ),
			'entry as an array'            => array( array( 'entry' => array( '1' ) ) ),
			'one bad filter among good'    => array(
				array(
					'agent'  => '7',
					'result' => 'ok',
					'from'   => '2026-11-01',
					'to'     => '2026-11-31',
				),
			),
		);
	}

	/**
	 * An invalid request makes the set invalid, and its query names no user.
	 *
	 * @param array<string, mixed> $request The request values.
	 * @dataProvider invalid_requests
	 */
	public function test_an_invalid_filter_makes_the_set_invalid_and_the_query_empty( array $request ): void {
		$filters = LogFilters::from_request( $request );

		$this->assertFalse( $filters->is_valid() );
		// user_id 0 is refused by DbLogger::query_all(), so the query returns the empty result, never a wider one.
		$this->assertSame( array( 'user_id' => 0 ), $filters->to_query() );
	}

	public function test_an_invalid_value_is_not_shown_as_a_valid_one(): void {
		$filters = LogFilters::from_request(
			array(
				'agent'  => '1 OR 1=1',
				'result' => 'ok',
			)
		);

		$this->assertSame( '', $filters->values()['agent'] );
		$this->assertSame( 'ok', $filters->values()['result'] );
	}

	public function test_valid_filters_become_the_query_for_query_all(): void {
		$filters = LogFilters::from_request(
			array(
				'agent'   => '7',
				'ability' => 'steadhand/update-post',
				'result'  => 'blocked',
			)
		);

		$this->assertTrue( $filters->is_valid() );
		$this->assertSame(
			array(
				'user_id' => 7,
				'ability' => 'steadhand/update-post',
				'result'  => 'blocked',
			),
			$filters->to_query()
		);
	}

	public function test_the_dates_are_converted_from_the_site_timezone_to_utc(): void {
		update_option( 'timezone_string', 'America/New_York' );

		$filters = LogFilters::from_request(
			array(
				'from' => '2026-11-24',
				'to'   => '2026-11-24',
			)
		);

		// New York is UTC-5 on this date: midnight local is 05:00 UTC, and the last second of the day is 04:59:59 the next day.
		$this->assertSame( '2026-11-24 05:00:00', $filters->to_query()['after'] );
		$this->assertSame( '2026-11-25 04:59:59', $filters->to_query()['before'] );
		$this->assertSame( '2026-11-24', $filters->values()['from'] );
	}

	public function test_the_dates_follow_daylight_saving_time_in_the_site_timezone(): void {
		update_option( 'timezone_string', 'America/New_York' );

		// Daylight saving time is in force on this date: midnight local is 04:00 UTC.
		$filters = LogFilters::from_request( array( 'from' => '2026-07-01' ) );

		$this->assertSame( '2026-07-01 04:00:00', $filters->to_query()['after'] );
	}

	public function test_a_date_that_does_not_exist_is_refused_in_a_timezone_ahead_of_utc(): void {
		update_option( 'timezone_string', 'Pacific/Auckland' );

		$filters = LogFilters::from_request( array( 'to' => '2027-02-29' ) );

		$this->assertFalse( $filters->is_valid(), '2027 is not a leap year.' );
	}

	public function test_the_page_is_read_and_capped(): void {
		$this->assertSame( 3, LogFilters::from_request( array( 'paged' => '3' ) )->page() );
		$this->assertSame( 100000, LogFilters::from_request( array( 'paged' => '100000' ) )->page() );
		$this->assertTrue( LogFilters::from_request( array( 'paged' => '100000' ) )->is_valid() );
		$this->assertFalse( LogFilters::from_request( array( 'paged' => '100001' ) )->is_valid() );
	}

	public function test_the_entry_is_read(): void {
		$filters = LogFilters::from_request( array( 'entry' => '42' ) );

		$this->assertTrue( $filters->is_valid() );
		$this->assertSame( 42, $filters->entry_id() );
		$this->assertSame( '42', $filters->values()['entry'] );
	}

	public function test_a_huge_number_is_refused_rather_than_overflowing(): void {
		$this->assertFalse( LogFilters::from_request( array( 'agent' => str_repeat( '9', 25 ) ) )->is_valid() );
		$this->assertFalse( LogFilters::from_request( array( 'entry' => str_repeat( '9', 25 ) ) )->is_valid() );
	}
}
