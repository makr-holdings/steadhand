<?php
/**
 * Reads the cron event list for the diagnostics report, without changing it.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Diagnostics;

use Steadhand\Abilities\PostReader;
use Steadhand\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the stored cron option into counts and a short list of overdue hooks.
 *
 * The stored option is parsed here, in memory, and never passed to _get_cron_array(). That function writes the
 * option back when it has to convert the legacy format, so calling it would change the site.
 */
final class Cron {

	/**
	 * An event this many seconds or more in the past is overdue.
	 */
	public const OVERDUE_SECONDS = 600;

	/**
	 * Largest value returned for a number of seconds. It is the schema maximum of overdue_seconds and
	 * next_event_in_seconds, so a timestamp far in the past or future is clamped to it and never fails validation.
	 */
	public const MAX_SECONDS = 1000000000;

	/**
	 * Most overdue events listed.
	 */
	public const MAX_OVERDUE = 20;

	/**
	 * Longest hook name returned, in bytes.
	 */
	public const MAX_HOOK_BYTES = 200;

	/**
	 * Lists every scheduled event in a stored cron option. Only the timestamp and the hook name are kept. Arguments are
	 * read past and never returned.
	 *
	 * Current format: timestamp => hook => key => event, with a 'version' key of 2. Legacy format, with no version: the
	 * same, but timestamp => hook => event, one event per hook. Both are read here without writing anything.
	 *
	 * @param mixed $raw The value of the cron option.
	 * @return array<int, array{timestamp: int, hook: string}>
	 */
	public static function events( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$current = isset( $raw['version'] ) && 2 === $raw['version'];
		$events  = array();

		foreach ( $raw as $timestamp => $hooks ) {
			if ( ! is_int( $timestamp ) || ! is_array( $hooks ) ) {
				continue;
			}

			foreach ( $hooks as $hook => $value ) {
				if ( ! is_string( $hook ) || ! is_array( $value ) ) {
					continue;
				}

				if ( ! $current ) {
					$events[] = array(
						'timestamp' => $timestamp,
						'hook'      => $hook,
					);
					continue;
				}

				foreach ( $value as $event ) {
					if ( is_array( $event ) ) {
						$events[] = array(
							'timestamp' => $timestamp,
							'hook'      => $hook,
						);
					}
				}
			}
		}

		return $events;
	}

	/**
	 * Builds the cron section from the stored option.
	 *
	 * @param mixed $raw      The value of the cron option.
	 * @param int   $now      The current time, as a Unix timestamp.
	 * @param bool  $disabled Whether DISABLE_WP_CRON is set.
	 * @return array{disabled: bool, overdue_count: int, overdue: array<int, array{hook: string, overdue_seconds: int}>, next_event_in_seconds: int|null, total_events: int}
	 */
	public static function report( mixed $raw, int $now, bool $disabled ): array {
		$events  = self::events( $raw );
		$overdue = array();
		$next    = null;

		foreach ( $events as $event ) {
			$age = self::seconds( $event['timestamp'], $now );

			if ( $age > self::OVERDUE_SECONDS ) {
				$overdue[] = array(
					'hook'            => self::hook_name( $event['hook'] ),
					'overdue_seconds' => $age,
				);
			} elseif ( $event['timestamp'] >= $now ) {
				$wait = self::seconds( $now, $event['timestamp'] );
				$next = null === $next ? $wait : min( $next, $wait );
			}
		}

		usort( $overdue, static fn( array $a, array $b ): int => $b['overdue_seconds'] <=> $a['overdue_seconds'] );

		return array(
			'disabled'              => $disabled,
			'overdue_count'         => count( $overdue ),
			'overdue'               => array_slice( $overdue, 0, self::MAX_OVERDUE ),
			'next_event_in_seconds' => $next,
			'total_events'          => count( $events ),
		);
	}

	/**
	 * The number of seconds from one time to a later one, clamped to MAX_SECONDS. The subtraction is done in floats, so
	 * the extreme values a stored option can hold cannot overflow into a float that fails the integer schema.
	 *
	 * @param int $from Earlier time.
	 * @param int $to   Later time.
	 * @return int Seconds, from 0 to MAX_SECONDS.
	 */
	private static function seconds( int $from, int $to ): int {
		return (int) max( 0, min( self::MAX_SECONDS, (float) $to - (float) $from ) );
	}

	/**
	 * A hook name as returned: redacted, plain, and cut to MAX_HOOK_BYTES.
	 *
	 * @param string $hook Hook name as stored.
	 * @return string
	 */
	private static function hook_name( string $hook ): string {
		$name = PostReader::cut_utf8( Redactor::text( PostReader::utf8( $hook ) ), self::MAX_HOOK_BYTES );

		// The name must encode as JSON. Redactor::text() already returns valid UTF-8; this checks the string as built.
		return 1 === preg_match( '//u', $name ) ? $name : Redactor::MASK;
	}
}
