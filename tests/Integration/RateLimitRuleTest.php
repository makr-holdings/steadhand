<?php
/**
 * The rate-limit guardrail: writes per minute per agent, counted from the change log.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Guardrails\Context;
use Steadhand\Guardrails\Result;
use Steadhand\Guardrails\Rules\RateLimit;
use Steadhand\Log\DbLogger;
use Steadhand\Log\Entry;
use Steadhand\Log\Schema;

/**
 * Covers SPEC §6.1 (rate limit per agent). The change-log rows are written directly, so the count is what the table holds.
 */
final class RateLimitRuleTest extends LogTestCase {

	private const WRITE = 'steadhand/probe-write';

	private const MESSAGE = 'This agent has made too many changes in the last minute. Wait a minute, then try again.';

	/**
	 * Adds writes rows for a user, at the current time.
	 *
	 * @param int    $user_id User ID.
	 * @param int    $count   How many rows.
	 * @param string $result  Result of each row.
	 * @param string $ability Ability of each row.
	 */
	private function rows( int $user_id, int $count, string $result = Entry::RESULT_OK, string $ability = self::WRITE ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$this->insert_row(
				array(
					'user_id' => $user_id,
					'ability' => $ability,
					'result'  => $result,
				)
			);
		}
	}

	/**
	 * Runs the rule for a write by this user, with the probe's write names.
	 *
	 * @param int $user_id User ID.
	 */
	private function evaluate( int $user_id ): Result {
		$rule = new RateLimit( new DbLogger(), static fn(): array => array( self::WRITE ) );

		return $rule->evaluate( new Context( self::WRITE, 'steadhand_write_drafts', $user_id, array(), 'post', 0 ) );
	}

	public function test_the_rule_has_the_documented_id(): void {
		$this->assertSame( 'steadhand/rate-limit', ( new RateLimit( new DbLogger(), static fn(): array => array() ) )->id() );
	}

	public function test_the_29th_write_passes_and_the_31st_call_is_blocked_at_the_limit_of_30(): void {
		$user = self::factory()->user->create();

		$this->rows( $user, 29 );
		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( $user )->mode(), 'With 29 writes recorded, the next one may proceed.' );

		$this->rows( $user, 1 );
		$blocked = $this->evaluate( $user );
		$this->assertSame( Result::MODE_BLOCK, $blocked->mode(), 'With 30 writes recorded, the next call is refused.' );
		$this->assertSame( self::MESSAGE, $blocked->message() );
	}

	public function test_another_agents_writes_do_not_count(): void {
		$user  = self::factory()->user->create();
		$other = self::factory()->user->create();

		$this->rows( $other, 40 );

		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( $user )->mode() );
	}

	public function test_read_rows_and_refused_rows_do_not_count(): void {
		$user = self::factory()->user->create();

		$this->rows( $user, 40, Entry::RESULT_BLOCKED );
		$this->rows( $user, 40, Entry::RESULT_OK, 'steadhand/get-site-info' );

		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( $user )->mode() );
	}

	public function test_errors_and_fixed_writes_count(): void {
		$user = self::factory()->user->create();

		$this->rows( $user, 10, Entry::RESULT_ERROR );
		$this->rows( $user, 20, Entry::RESULT_FIXED );

		$this->assertSame( Result::MODE_BLOCK, $this->evaluate( $user )->mode() );
	}

	/**
	 * Values the filter may return that are not a whole number from 1 to 600 fall back to 30. The probe is one below the
	 * fallback limit, so a fallback is visible as a pass.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function values_that_fall_back_to_the_default(): array {
		return array(
			'zero'        => array( 0 ),
			'negative'    => array( -1 ),
			'text'        => array( 'abc' ),
			'above range' => array( 601 ),
			'null'        => array( null ),
			'float'       => array( 12.5 ),
			'array'       => array( array( 5 ) ),
		);
	}

	/**
	 * Runs the rule with a filter value that must fall back to the default.
	 *
	 * @dataProvider values_that_fall_back_to_the_default
	 *
	 * @param mixed $value What the filter returns.
	 */
	public function test_a_filter_value_outside_the_accepted_range_falls_back_to_30( mixed $value ): void {
		add_filter( 'steadhand_rate_limit_per_minute', static fn(): mixed => $value );
		$user = self::factory()->user->create();

		$this->rows( $user, 29 );
		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( $user )->mode() );

		$this->rows( $user, 1 );
		$this->assertSame( Result::MODE_BLOCK, $this->evaluate( $user )->mode() );
	}

	public function test_a_whole_number_filter_value_is_used(): void {
		add_filter( 'steadhand_rate_limit_per_minute', static fn(): int => 5 );
		$user = self::factory()->user->create();

		$this->rows( $user, 4 );
		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( $user )->mode() );

		$this->rows( $user, 1 );
		$this->assertSame( Result::MODE_BLOCK, $this->evaluate( $user )->mode() );
	}

	public function test_a_digit_string_filter_value_is_used(): void {
		add_filter( 'steadhand_rate_limit_per_minute', static fn(): string => '5' );
		$user = self::factory()->user->create();

		$this->rows( $user, 5 );

		$this->assertSame( Result::MODE_BLOCK, $this->evaluate( $user )->mode() );
	}

	public function test_the_limit_of_600_is_accepted_and_601_is_not(): void {
		add_filter( 'steadhand_rate_limit_per_minute', static fn(): int => 600 );
		$user = self::factory()->user->create();
		$this->rows( $user, 599 );
		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( $user )->mode() );
		$this->rows( $user, 1 );
		$this->assertSame( Result::MODE_BLOCK, $this->evaluate( $user )->mode() );
	}

	public function test_a_throwing_filter_falls_back_to_30(): void {
		add_filter(
			'steadhand_rate_limit_per_minute',
			static function (): int {
				throw new \RuntimeException( 'Filter failed.' );
			}
		);
		$user = self::factory()->user->create();

		$this->rows( $user, 30 );

		$this->assertSame( Result::MODE_BLOCK, $this->evaluate( $user )->mode() );
	}

	public function test_a_failed_count_blocks_the_call(): void {
		$user  = self::factory()->user->create();
		$table = Schema::table();
		add_filter(
			'query',
			static function ( string $query ) use ( $table ): string {
				return str_starts_with( $query, 'SELECT COUNT(*) FROM ' . $table )
					? str_replace( 'FROM ' . $table, 'FROM ' . $table . '_missing', $query )
					: $query;
			}
		);

		$this->assertSame( Result::MODE_BLOCK, $this->evaluate( $user )->mode(), 'A count that cannot be read fails closed.' );
	}
}
