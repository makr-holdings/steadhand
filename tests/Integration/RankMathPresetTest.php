<?php
/**
 * The Rank Math options preset: while it is on, no option whose name starts with rank-math or rank_math is allowed, through the
 * ability, the writer or the rule. While it is off, the same name is treated like any other filter-added name.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Guardrails\Context;
use Steadhand\Guardrails\Presets;
use Steadhand\Guardrails\Result;
use Steadhand\Guardrails\Rules\ProtectedOptions;
use Steadhand\Pipeline\OptionRules;
use Steadhand\Pipeline\OptionWriter;

/**
 * Negative tests first: with the preset on, a Rank Math option is refused by every route, and its row is unchanged.
 */
final class RankMathPresetTest extends OptionTestCase {

	/**
	 * Adds the given names to the allowlist, as another plugin's filter would.
	 *
	 * @param string[] $names The option names to add.
	 */
	private function allow_through_filter( array $names ): void {
		$this->on(
			'steadhand_allowed_options',
			static function ( array $options ) use ( $names ): array {
				foreach ( $names as $name ) {
					$options[ $name ] = array( 'max_length' => 100 );
				}

				return $options;
			}
		);
	}

	/**
	 * Seeds a row for a name as a plain string, and returns its stored value (read uncached).
	 *
	 * @param string $name  The option name. It is stored in lower case, as the update-option tests store it.
	 * @param string $value The value of a new row.
	 */
	private function seed_option( string $name, string $value ): string {
		$row = strtolower( $name );
		if ( null === OptionRules::stored_raw( $row ) ) {
			add_option( $row, $value, '', 'no' );
			wp_cache_flush();
		}

		return $this->stored_value( $row );
	}

	/**
	 * Stores the preset's state.
	 *
	 * @param bool $on Whether the preset is on.
	 */
	private function preset( bool $on ): void {
		Presets::save( array( Presets::RANK_MATH => $on ) );
	}

	/**
	 * With the preset on (its default), a filter-added Rank Math name is not in the allowlist, and the ability refuses it.
	 *
	 * @dataProvider rank_math_names
	 *
	 * @param string $name The option name.
	 */
	public function test_with_the_preset_on_the_allowlist_drops_a_rank_math_name_and_the_tool_refuses_it( string $name ): void {
		$this->allow_through_filter( array( $name ) );
		$this->preset( true );
		$this->option_agent();
		$before = $this->seed_option( $name, 'plain value' );

		$this->assertArrayNotHasKey( $name, OptionRules::allowed(), "The allowlist must drop $name while the preset is on." );

		$result = $this->execute_ability( self::ABILITY, $this->args( $name, 'changed' ) );
		$this->assertWPError( $result, "The tool must refuse $name while the preset is on." );

		$this->assertSame( $before, $this->stored_value( strtolower( $name ) ), 'The row must be unchanged.' );
	}

	/**
	 * The writer and the rule refuse the same name too, when the preset is on.
	 *
	 * @dataProvider rank_math_names
	 *
	 * @param string $name The option name.
	 */
	public function test_with_the_preset_on_the_writer_and_the_rule_refuse_a_rank_math_name( string $name ): void {
		$this->allow_through_filter( array( $name ) );
		$this->preset( true );
		$this->option_agent();
		$before = $this->seed_option( $name, 'plain value' );

		$writer = $this->in_write(
			fn(): mixed => OptionWriter::set( $name, 'changed', null, static fn(): bool => true ),
			Capabilities::SETTINGS
		);

		$this->assertWPError( $writer, "The writer must refuse $name while the preset is on." );
		$this->assertSame( $before, $this->stored_value( strtolower( $name ) ), 'The row must be unchanged.' );

		$rule = ( new ProtectedOptions() )->evaluate(
			new Context( self::ABILITY, Capabilities::SETTINGS, get_current_user_id(), array( 'option' => $name ), 'option', 0 )
		);
		$this->assertSame( Result::MODE_BLOCK, $rule->mode(), "The rule must block $name while the preset is on." );
	}

	/**
	 * With the preset off, a plain-string name a filter added is allowed, the tool writes it, and the writer does too.
	 */
	public function test_with_the_preset_off_a_rank_math_name_is_allowed_like_any_other(): void {
		$name = 'rank-math-options-general';
		$this->allow_through_filter( array( $name ) );
		$this->preset( false );
		$this->option_agent();
		$this->seed_option( $name, 'plain value' );

		$this->assertArrayHasKey( $name, OptionRules::allowed(), 'With the preset off, the filter-added name is on the allowlist.' );

		$result = $this->execute_ability( self::ABILITY, $this->args( $name, 'changed' ) );
		$this->assertIsArray( $result, 'The tool must write the name when the preset is off.' );
		$this->assertSame( 'changed', $this->stored_value( $name ) );
	}

	public function test_with_the_preset_off_the_writer_writes_a_rank_math_name(): void {
		$name = 'rank_math_foo';
		$this->allow_through_filter( array( $name ) );
		$this->preset( false );
		$this->option_agent();
		$this->seed_option( $name, 'plain value' );

		$writer = $this->in_write(
			fn(): mixed => OptionWriter::set( $name, 'changed', null, static fn(): bool => true ),
			Capabilities::SETTINGS
		);

		$this->assertFalse( is_wp_error( $writer ), "The writer must write $name when the preset is off." );
		$this->assertSame( 'changed', $this->stored_value( $name ) );
	}

	/**
	 * A mixed-case name is caught with the preset on, as the match is case-insensitive.
	 */
	public function test_a_mixed_case_rank_math_name_is_caught_while_the_preset_is_on(): void {
		$name = 'Rank-Math-x';
		$this->allow_through_filter( array( $name ) );
		$this->preset( true );

		$this->assertTrue( OptionRules::preset_blocked( 'rank-math-options' ) );
		$this->assertTrue( OptionRules::preset_blocked( $name ) );
		$this->assertArrayNotHasKey( $name, OptionRules::allowed() );
	}

	/**
	 * A name that only contains rank math, without the prefix, is not caught by the preset.
	 */
	public function test_a_name_that_only_contains_the_words_is_not_caught(): void {
		$this->preset( true );

		$this->assertFalse( OptionRules::preset_blocked( 'my-rank-math' ) );
		$this->assertFalse( OptionRules::preset_blocked( 'rankmath-options' ) );
		$this->assertFalse( OptionRules::preset_blocked( 'rank math' ) );
	}

	/**
	 * The preset never changes the hard-coded blocks: their answer is the same whether it is on or off.
	 */
	public function test_hard_blocked_is_the_same_with_the_preset_on_or_off(): void {
		$names = array( 'rank-math-options', 'Rank_Math_x', 'blogname', 'steadhand_presets', 'widget_text', 'siteurl', 'user_roles' );

		$answers = array();
		foreach ( array( true, false ) as $on ) {
			$this->preset( $on );
			$answers[ $on ? 'on' : 'off' ] = array_map( static fn( string $name ): bool => OptionRules::hard_blocked( $name ), $names );
		}

		$this->assertSame( $answers['on'], $answers['off'], 'The preset must not change hard_blocked().' );
		$this->assertFalse( OptionRules::hard_blocked( 'rank-math-options' ), 'The Rank Math prefix is not hard-blocked.' );
		$this->assertTrue( OptionRules::hard_blocked( 'siteurl' ) );
	}

	/**
	 * The names the preset refuses, with the prefixes in lower case and in mixed case.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function rank_math_names(): array {
		return array(
			'rank-math prefix'     => array( 'rank-math-options-general' ),
			'rank_math prefix'     => array( 'rank_math_foo' ),
			'mixed-case rank-math' => array( 'Rank-Math-x' ),
			'upper-case rank_math' => array( 'RANK_MATH_BAR' ),
		);
	}
}
