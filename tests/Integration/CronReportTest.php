<?php
/**
 * Unit tests for the cron report: which events are read, what counts as overdue, and what is returned.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Steadhand\Diagnostics\Cron;

/**
 * Covers SPEC §5 get-diagnostics for the cron section.
 */
final class CronReportTest extends TestCase {

	private const NOW = 1800000000;

	/**
	 * A current-format cron option: timestamp => hook => key => event, with a version of 2.
	 *
	 * @param array<int, array<string, array<string, array<string, mixed>>>> $events Events by timestamp.
	 * @return array<string|int, mixed>
	 */
	private function current_format( array $events ): array {
		$raw            = $events;
		$raw['version'] = 2;

		return $raw;
	}

	public function test_events_are_read_from_the_current_format_and_the_version_key_is_ignored(): void {
		$raw = $this->current_format(
			array(
				self::NOW - 1000 => array(
					'hook_a' => array(
						'k1' => array(
							'schedule' => false,
							'args'     => array( 'SECRET_ARG' ),
						),
					),
				),
				self::NOW + 60   => array(
					'hook_b' => array(
						'k2' => array(
							'schedule' => 'daily',
							'args'     => array(),
						),
						'k3' => array(
							'schedule' => 'daily',
							'args'     => array( 1 ),
						),
					),
				),
			)
		);

		$events = Cron::events( $raw );

		$this->assertCount( 3, $events );
		$this->assertSame( 'hook_a', $events[0]['hook'] );
		$this->assertSame( self::NOW - 1000, $events[0]['timestamp'] );
	}

	public function test_events_are_read_from_the_legacy_format_without_a_version(): void {
		$raw = array(
			self::NOW - 5 => array(
				'legacy_hook' => array(
					'schedule' => false,
					'args'     => array( 'x' ),
				),
			),
		);

		$events = Cron::events( $raw );

		$this->assertSame(
			array(
				array(
					'timestamp' => self::NOW - 5,
					'hook'      => 'legacy_hook',
				),
			),
			$events
		);
	}

	public function test_garbage_in_the_option_gives_no_events(): void {
		$this->assertSame( array(), Cron::events( 'not an array' ) );
		$this->assertSame(
			array(),
			Cron::events(
				array(
					'version' => 2,
					'x'       => 'y',
				)
			)
		);
	}

	public function test_an_event_more_than_600_seconds_late_is_overdue(): void {
		$report = Cron::report(
			$this->current_format(
				array(
					self::NOW - 601 => array(
						'late' => array(
							'k' => array(
								'schedule' => false,
								'args'     => array(),
							),
						),
					),
					self::NOW - 600 => array(
						'edge' => array(
							'k' => array(
								'schedule' => false,
								'args'     => array(),
							),
						),
					),
				)
			),
			self::NOW,
			false
		);

		$this->assertSame( 1, $report['overdue_count'] );
		$this->assertSame(
			array(
				array(
					'hook'            => 'late',
					'overdue_seconds' => 601,
				),
			),
			$report['overdue']
		);
	}

	public function test_the_next_event_is_the_soonest_future_one(): void {
		$report = Cron::report(
			$this->current_format(
				array(
					self::NOW + 300 => array(
						'far' => array(
							'k' => array(
								'schedule' => false,
								'args'     => array(),
							),
						),
					),
					self::NOW + 45  => array(
						'near' => array(
							'k' => array(
								'schedule' => false,
								'args'     => array(),
							),
						),
					),
				)
			),
			self::NOW,
			false
		);

		$this->assertSame( 45, $report['next_event_in_seconds'] );
		$this->assertSame( 2, $report['total_events'] );
	}

	public function test_next_event_is_null_when_nothing_is_scheduled_ahead(): void {
		$report = Cron::report( array( 'version' => 2 ), self::NOW, true );

		$this->assertNull( $report['next_event_in_seconds'] );
		$this->assertSame( 0, $report['total_events'] );
		$this->assertTrue( $report['disabled'] );
		$this->assertSame( array(), $report['overdue'] );
	}

	public function test_overdue_list_is_most_overdue_first_and_capped_at_twenty(): void {
		$events = array();
		for ( $i = 1; $i <= 25; $i++ ) {
			$events[ self::NOW - ( 1000 * $i ) ] = array(
				"hook_$i" => array(
					'k' => array(
						'schedule' => false,
						'args'     => array(),
					),
				),
			);
		}

		$report = Cron::report( $this->current_format( $events ), self::NOW, false );

		$this->assertSame( 25, $report['overdue_count'] );
		$this->assertCount( 20, $report['overdue'] );
		$this->assertSame( 'hook_25', $report['overdue'][0]['hook'] );
		$this->assertSame( 25000, $report['overdue'][0]['overdue_seconds'] );
	}

	public function test_hook_names_are_cut_to_200_bytes(): void {
		$hook   = str_repeat( 'h', 300 );
		$report = Cron::report(
			$this->current_format(
				array(
					self::NOW - 1000 => array(
						$hook => array(
							'k' => array(
								'schedule' => false,
								'args'     => array(),
							),
						),
					),
				)
			),
			self::NOW,
			false
		);

		$this->assertSame( 200, strlen( $report['overdue'][0]['hook'] ) );
	}

	public function test_hook_arguments_are_never_returned(): void {
		$report = Cron::report(
			$this->current_format(
				array(
					self::NOW - 1000 => array(
						'with_args' => array(
							'k' => array(
								'schedule' => false,
								'args'     => array( 'SECRET_ARG_VALUE' ),
							),
						),
					),
				)
			),
			self::NOW,
			false
		);

		$this->assertStringNotContainsString( 'SECRET_ARG_VALUE', (string) wp_json_encode( $report ) );
	}

	public function test_an_event_at_timestamp_one_is_clamped_to_the_schema_maximum(): void {
		$report = Cron::report(
			$this->current_format(
				array(
					1 => array(
						'ancient' => array(
							'k' => array(
								'schedule' => false,
								'args'     => array(),
							),
						),
					),
				)
			),
			self::NOW,
			false
		);

		$this->assertSame( Cron::MAX_SECONDS, $report['overdue'][0]['overdue_seconds'] );
		$this->assertSame( 1000000000, $report['overdue'][0]['overdue_seconds'] );
	}

	public function test_an_event_a_hundred_years_ahead_is_clamped_to_the_schema_maximum(): void {
		$report = Cron::report(
			$this->current_format(
				array(
					self::NOW + 100 * 365 * 86400 => array(
						'far' => array(
							'k' => array(
								'schedule' => false,
								'args'     => array(),
							),
						),
					),
				)
			),
			self::NOW,
			false
		);

		$this->assertSame( Cron::MAX_SECONDS, $report['next_event_in_seconds'] );
		$this->assertSame( 1000000000, $report['next_event_in_seconds'] );
	}

	public function test_the_most_extreme_timestamps_still_give_integers_within_bounds(): void {
		$report = Cron::report(
			$this->current_format(
				array(
					PHP_INT_MIN => array(
						'min' => array(
							'k' => array(
								'schedule' => false,
								'args'     => array(),
							),
						),
					),
					PHP_INT_MAX => array(
						'max' => array(
							'k' => array(
								'schedule' => false,
								'args'     => array(),
							),
						),
					),
				)
			),
			self::NOW,
			false
		);

		$this->assertIsInt( $report['overdue'][0]['overdue_seconds'] );
		$this->assertLessThanOrEqual( Cron::MAX_SECONDS, $report['overdue'][0]['overdue_seconds'] );
		$this->assertIsInt( $report['next_event_in_seconds'] );
		$this->assertLessThanOrEqual( Cron::MAX_SECONDS, $report['next_event_in_seconds'] );
	}
}
