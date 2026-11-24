<?php
/**
 * The filters of the admin change-log screen: read from the request, checked, and turned into a query.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Admin;

use Steadhand\Log\Entry;

defined( 'ABSPATH' ) || exit;

/**
 * Validated filters for the log screen. Any filter that is set but not valid makes the whole set invalid, and an invalid
 * set never produces a wider query: to_query() then names a user ID of 0, which DbLogger::query_all() refuses with the
 * empty result. The screen also shows no rows for an invalid set.
 */
final class LogFilters {

	/** Largest page number the screen accepts. */
	public const MAX_PAGE = 100000;

	/** Longest digit string read as a number, so that the integer cast cannot overflow. */
	private const MAX_DIGITS = 18;

	/** Shape of an ability name: steadhand/ and a lowercase slug. */
	private const ABILITY_PATTERN = '/^steadhand\/[a-z0-9-]{1,80}\z/';

	/** Shape of a date: Y-m-d, checked again with checkdate(). */
	private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}\z/';

	/** Digits only. */
	private const DIGITS_PATTERN = '/^\d+\z/';

	/** Every result a stored entry may have. */
	private const RESULTS = array(
		Entry::RESULT_OK,
		Entry::RESULT_BLOCKED,
		Entry::RESULT_FIXED,
		Entry::RESULT_ERROR,
	);

	/** The request keys this class reads, in the order values() returns them. */
	private const KEYS = array( 'agent', 'ability', 'result', 'from', 'to', 'paged', 'entry' );

	/**
	 * Constructor. Use from_request().
	 *
	 * @param bool                  $valid  Whether every set filter is valid.
	 * @param array<string, string> $values The validated strings, '' for an unset or invalid filter.
	 * @param array<string, mixed>  $query  The UTC query for DbLogger::query_all(). Empty when the set is invalid.
	 * @param int                   $page   Page number, from 1.
	 * @param int                   $entry  Entry ID to show, or 0 for the list.
	 */
	private function __construct(
		private bool $valid,
		private array $values,
		private array $query,
		private int $page,
		private int $entry
	) {}

	/**
	 * Reads and checks the filters. Every value must be a string: an array value makes the set invalid. An empty string
	 * means the filter is not set.
	 *
	 * @param array<string, mixed> $query The request's query values, unslashed (the screen passes $_GET).
	 * @return self
	 */
	public static function from_request( array $query ): self {
		$valid  = true;
		$values = array();
		$raw    = array();

		foreach ( self::KEYS as $key ) {
			$value = $query[ $key ] ?? '';
			if ( ! is_string( $value ) ) {
				$valid          = false;
				$values[ $key ] = '';
				$raw[ $key ]    = '';
				continue;
			}
			$raw[ $key ] = $value;
		}

		$user_id = self::agent_id( $raw['agent'] ?? '', $valid, $values );
		$ability = self::ability( $raw['ability'] ?? '', $valid, $values );
		$result  = self::result( $raw['result'] ?? '', $valid, $values );
		$after   = self::day_bound( $raw['from'] ?? '', 'from', $valid, $values );
		$before  = self::day_bound( $raw['to'] ?? '', 'to', $valid, $values );
		$page    = self::number( $raw['paged'] ?? '', 1, 1, self::MAX_PAGE, 'paged', $valid, $values );
		$entry   = self::number( $raw['entry'] ?? '', 0, 0, PHP_INT_MAX, 'entry', $valid, $values );

		$query = array();
		if ( $valid ) {
			if ( $user_id > 0 ) {
				$query['user_id'] = $user_id;
			}
			if ( '' !== $ability ) {
				$query['ability'] = $ability;
			}
			if ( '' !== $result ) {
				$query['result'] = $result;
			}
			if ( '' !== $after ) {
				$query['after'] = $after;
			}
			if ( '' !== $before ) {
				$query['before'] = $before;
			}
		}

		// Every key, in the documented order, so values() has the same shape whatever the request held.
		$ordered = array();
		foreach ( self::KEYS as $key ) {
			$ordered[ $key ] = $values[ $key ] ?? '';
		}

		return new self( $valid, $ordered, $query, $page, $entry );
	}

	/**
	 * Whether every set filter is valid. An unset filter is valid.
	 *
	 * @return bool
	 */
	public function is_valid(): bool {
		return $this->valid;
	}

	/**
	 * The query for DbLogger::query_all(). For an invalid set it names user_id 0, so the query returns the empty result, never a
	 * wider one. Callers must still not show rows for an invalid set.
	 *
	 * @return array<string, mixed>
	 */
	public function to_query(): array {
		return $this->valid ? $this->query : array( 'user_id' => 0 );
	}

	/**
	 * The page number, from 1.
	 *
	 * @return int
	 */
	public function page(): int {
		return $this->page;
	}

	/**
	 * The entry to show, or 0 for the list.
	 *
	 * @return int
	 */
	public function entry_id(): int {
		return $this->entry;
	}

	/**
	 * The validated strings, for the form and for building links: agent, ability, result, from, to, paged and entry. A filter
	 * that is not set, or not valid, is ''.
	 *
	 * @return array<string, string>
	 */
	public function values(): array {
		return $this->values;
	}

	/**
	 * Checks an agent filter: digits, above 0. Sets the value and the validity.
	 *
	 * @param string                $raw    The request value.
	 * @param bool                  $valid  Validity, cleared on failure.
	 * @param array<string, string> $values Validated strings, set on success.
	 * @return int The user ID, or 0 when not set.
	 */
	private static function agent_id( string $raw, bool &$valid, array &$values ): int {
		if ( '' === $raw ) {
			return 0;
		}

		if ( 1 !== preg_match( self::DIGITS_PATTERN, $raw ) || strlen( $raw ) > self::MAX_DIGITS || (int) $raw < 1 ) {
			$valid = false;
			return 0;
		}

		$values['agent'] = $raw;

		return (int) $raw;
	}

	/**
	 * Checks an ability filter.
	 *
	 * @param string                $raw    The request value.
	 * @param bool                  $valid  Validity, cleared on failure.
	 * @param array<string, string> $values Validated strings, set on success.
	 * @return string The ability, or '' when not set.
	 */
	private static function ability( string $raw, bool &$valid, array &$values ): string {
		if ( '' === $raw ) {
			return '';
		}

		if ( 1 !== preg_match( self::ABILITY_PATTERN, $raw ) ) {
			$valid = false;
			return '';
		}

		$values['ability'] = $raw;

		return $raw;
	}

	/**
	 * Checks a result filter.
	 *
	 * @param string                $raw    The request value.
	 * @param bool                  $valid  Validity, cleared on failure.
	 * @param array<string, string> $values Validated strings, set on success.
	 * @return string The result, or '' when not set.
	 */
	private static function result( string $raw, bool &$valid, array &$values ): string {
		if ( '' === $raw ) {
			return '';
		}

		if ( ! in_array( $raw, self::RESULTS, true ) ) {
			$valid = false;
			return '';
		}

		$values['result'] = $raw;

		return $raw;
	}

	/**
	 * Checks a date filter (Y-m-d, in the site's timezone) and converts it to a UTC bound, 'Y-m-d H:i:s'. A 'from' is the start
	 * of that local day. A 'to' is the last second of that local day: the start of the next local day, minus one second, taken
	 * in UTC. A local day can be 23 or 25 hours long, so this never leaves an hour out.
	 *
	 * @param string                $raw    The request value.
	 * @param string                $key    The request key: 'from' or 'to'.
	 * @param bool                  $valid  Validity, cleared on failure.
	 * @param array<string, string> $values Validated strings, set on success.
	 * @return string The UTC bound, or '' when not set.
	 */
	private static function day_bound( string $raw, string $key, bool &$valid, array &$values ): string {
		if ( '' === $raw ) {
			return '';
		}

		if ( 1 !== preg_match( self::DATE_PATTERN, $raw ) ) {
			$valid = false;
			return '';
		}

		$year  = (int) substr( $raw, 0, 4 );
		$month = (int) substr( $raw, 5, 2 );
		$day   = (int) substr( $raw, 8, 2 );
		if ( ! checkdate( $month, $day, $year ) ) {
			$valid = false;
			return '';
		}

		try {
			$utc   = new \DateTimeZone( 'UTC' );
			$day   = new \DateTimeImmutable( $raw . ' 00:00:00', wp_timezone() );
			$bound = 'to' === $key
				? $day->modify( '+1 day' )->setTimezone( $utc )->modify( '-1 second' )
				: $day->setTimezone( $utc );
		} catch ( \Exception ) {
			$valid = false;
			return '';
		}

		$values[ $key ] = $raw;

		return $bound->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Checks a number filter: digits, within the given bounds. An unset filter is the default.
	 *
	 * @param string                $raw     The request value.
	 * @param int                   $fallback The value when not set.
	 * @param int                   $min      The smallest allowed value.
	 * @param int                   $max      The largest allowed value.
	 * @param string                $key      The request key.
	 * @param bool                  $valid    Validity, cleared on failure.
	 * @param array<string, string> $values   Validated strings, set on success.
	 * @return int
	 */
	private static function number( string $raw, int $fallback, int $min, int $max, string $key, bool &$valid, array &$values ): int {
		if ( '' === $raw ) {
			return $fallback;
		}

		if ( 1 !== preg_match( self::DIGITS_PATTERN, $raw ) || strlen( $raw ) > self::MAX_DIGITS ) {
			$valid = false;
			return $fallback;
		}

		$number = (int) $raw;
		if ( $number < $min || $number > $max ) {
			$valid = false;
			return $fallback;
		}

		$values[ $key ] = $raw;

		return $number;
	}
}
