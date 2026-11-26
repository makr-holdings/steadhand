<?php
/**
 * The preset framework: the stored state, its defaults when the stored value is wrong, the save and its read-back, the
 * uninstall, and the rule that no agent can write the presets option.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Guardrails\Presets;
use Steadhand\Pipeline\OptionRules;

/**
 * Negative tests first: a value that is not a plain true or false never turns a preset on, and nothing an agent sends reaches the
 * presets option.
 */
final class PresetsTest extends OptionTestCase {

	/**
	 * Stores a raw value in the presets option, as a corrupt or hand-edited row would hold it.
	 *
	 * @param mixed $value The raw value.
	 */
	private function store_raw( mixed $value ): void {
		update_option( Presets::OPTION, $value, true );
		wp_cache_flush();
	}

	public function test_with_no_stored_option_the_defaults_apply_and_rank_math_is_on(): void {
		delete_option( Presets::OPTION );
		wp_cache_flush();

		$this->assertSame( array( Presets::RANK_MATH => true ), Presets::states() );
		$this->assertTrue( Presets::enabled( Presets::RANK_MATH ) );
	}

	/**
	 * A stored value that is not an array, or that is an array whose value for the preset is not exactly true or false, gives the
	 * preset its default. The default of rank-math-options is on, so every such value leaves it on.
	 *
	 * @dataProvider corrupt_values
	 *
	 * @param mixed $value The stored value.
	 */
	public function test_corrupt_stored_values_fall_back_to_the_defaults( mixed $value ): void {
		$this->store_raw( $value );

		$this->assertSame( array( Presets::RANK_MATH => true ), Presets::states() );
		$this->assertTrue( Presets::enabled( Presets::RANK_MATH ) );
	}

	/**
	 * The stored values that are not a plain true or false for the preset, or that are not an array at all.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function corrupt_values(): array {
		return array(
			'a string'            => array( 'yes' ),
			'an int'              => array( 1 ),
			'a float'             => array( 0.0 ),
			'null'                => array( null ),
			'a nested array'      => array( array( Presets::RANK_MATH => array( true ) ) ),
			'the string true'     => array( array( Presets::RANK_MATH => 'true' ) ),
			'the string false'    => array( array( Presets::RANK_MATH => 'false' ) ),
			'the int zero'        => array( array( Presets::RANK_MATH => 0 ) ),
			'the int one'         => array( array( Presets::RANK_MATH => 1 ) ),
			'null for the preset' => array( array( Presets::RANK_MATH => null ) ),
			'an object'           => array( (object) array( Presets::RANK_MATH => false ) ),
			'only unknown ids'    => array( array( 'no-such-preset' => false ) ),
		);
	}

	/**
	 * A stored boolean is read as it is, so an administrator's choice to turn the preset off is kept.
	 */
	public function test_a_stored_false_is_read_as_off(): void {
		$this->store_raw( array( Presets::RANK_MATH => false ) );

		$this->assertFalse( Presets::enabled( Presets::RANK_MATH ) );
		$this->assertSame( array( Presets::RANK_MATH => false ), Presets::states() );
	}

	/**
	 * An unknown ID in the stored array is ignored, and never appears in the state or in what the screen lists.
	 */
	public function test_unknown_ids_in_the_stored_value_are_ignored(): void {
		$this->store_raw(
			array(
				'no-such-preset'   => false,
				'OTHER'            => true,
				Presets::RANK_MATH => false,
			)
		);

		$this->assertSame( array( Presets::RANK_MATH => false ), Presets::states() );
		$this->assertFalse( Presets::enabled( 'no-such-preset' ) );
		$this->assertCount( count( Presets::ids() ), Presets::all() );
	}

	public function test_an_unknown_id_is_off_and_is_never_on(): void {
		$this->assertFalse( Presets::enabled( 'no-such-preset' ) );
		$this->assertFalse( Presets::enabled( '' ) );
	}

	/**
	 * Every known ID is valid under the pattern the presets are listed by.
	 */
	public function test_every_known_id_matches_the_id_pattern(): void {
		foreach ( Presets::ids() as $id ) {
			$this->assertSame( 1, preg_match( Presets::ID_PATTERN, $id ), "$id must match the ID pattern." );
		}
	}

	public function test_all_gives_the_text_the_default_and_the_state_of_each_preset(): void {
		$this->store_raw( array( Presets::RANK_MATH => false ) );

		$all = Presets::all();

		$this->assertCount( 1, $all );
		$this->assertSame( Presets::RANK_MATH, $all[0]['id'] );
		$this->assertSame( true, $all[0]['default'] );
		$this->assertSame( false, $all[0]['enabled'] );
		$this->assertNotSame( '', $all[0]['label'] );
		$this->assertNotSame( '', $all[0]['description'] );
	}

	/**
	 * Save stores exactly one boolean for every known ID. An ID that is not in the array counts as off, and a value that is not
	 * exactly true counts as off too. Unknown IDs are not stored.
	 */
	public function test_save_stores_exactly_a_boolean_for_every_known_id_and_nothing_else(): void {
		$this->assertTrue(
			Presets::save(
				array(
					Presets::RANK_MATH => 'yes',
					'no-such-preset'   => true,
				)
			)
		);

		$stored = get_option( Presets::OPTION );
		$this->assertSame( array( Presets::RANK_MATH => false ), $stored );
		$this->assertTrue( is_bool( $stored[ Presets::RANK_MATH ] ) );
	}

	public function test_save_of_an_empty_state_turns_every_preset_off_and_stores_it(): void {
		$this->assertTrue( Presets::save( array() ) );

		$this->assertSame( array( Presets::RANK_MATH => false ), get_option( Presets::OPTION ) );
		$this->assertFalse( Presets::enabled( Presets::RANK_MATH ) );
	}

	/**
	 * The state is read from the option at each call, so a save is seen at once by the same request.
	 */
	public function test_a_save_is_seen_by_the_next_read_in_the_same_request(): void {
		Presets::save( array( Presets::RANK_MATH => true ) );
		$this->assertTrue( Presets::enabled( Presets::RANK_MATH ) );

		Presets::save( array( Presets::RANK_MATH => false ) );
		$this->assertFalse( Presets::enabled( Presets::RANK_MATH ) );
	}

	/**
	 * A save whose value does not read back is reported as failed. update_option() returns false for an unchanged value, so the
	 * read-back is what decides. Here the write is blocked, so the old value stays and the save says so.
	 */
	public function test_a_save_that_does_not_read_back_is_reported_as_failed(): void {
		$this->store_raw( array( Presets::RANK_MATH => true ) );
		$this->on(
			'pre_update_option_' . Presets::OPTION,
			static fn( $value, $old ): mixed => $old,
			10,
			2
		);

		$this->assertFalse( Presets::save( array( Presets::RANK_MATH => false ) ) );
		$this->assertTrue( Presets::enabled( Presets::RANK_MATH ), 'A refused save must leave the stored state as it was.' );
	}

	/**
	 * A save that stores the value already stored is still a success. update_option() returns false in that case, so this guards
	 * against reporting an unchanged save as failed.
	 */
	public function test_saving_the_value_already_stored_is_a_success(): void {
		$this->store_raw( array( Presets::RANK_MATH => true ) );

		$this->assertTrue( Presets::save( array( Presets::RANK_MATH => true ) ) );
	}

	/**
	 * The option is stored with autoload on, as the other Steadhand options are. The row is read from the table.
	 */
	public function test_the_option_is_saved_with_autoload_on(): void {
		Presets::save( array( Presets::RANK_MATH => true ) );

		global $wpdb;
		$autoload = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test reads the options table directly.
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", Presets::OPTION ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is core's.
		);

		$this->assertContains( $autoload, array( 'yes', 'on', 'auto-on' ), 'The presets option must autoload.' );
	}

	/**
	 * Uninstall deletes the option, so no preset state is left behind.
	 */
	public function test_uninstall_removes_the_option(): void {
		Presets::save( array( Presets::RANK_MATH => false ) );
		$this->assertNotFalse( get_option( Presets::OPTION ) );

		Presets::uninstall();
		wp_cache_flush();

		$this->assertFalse( get_option( Presets::OPTION ), 'uninstall must delete the presets option.' );
		$this->assertSame( array( Presets::RANK_MATH => true ), Presets::states(), 'With no option, the defaults apply again.' );
	}

	/**
	 * The presets option has the steadhand_ prefix, so OptionRules' hard-coded block keeps every agent from writing it, through the
	 * ability, the writer and the rule, even when a filter adds it to the allowlist.
	 */
	public function test_the_presets_option_is_hard_blocked_and_no_filter_can_add_it(): void {
		$this->assertTrue( OptionRules::hard_blocked( Presets::OPTION ) );
		$this->assertTrue( OptionRules::hard_blocked( 'STEADHAND_PRESETS' ) );

		$this->on(
			'steadhand_allowed_options',
			static function ( array $options ): array {
				$options[ Presets::OPTION ] = array( 'max_length' => 100 );

				return $options;
			}
		);

		$this->assertArrayNotHasKey( Presets::OPTION, OptionRules::allowed(), 'The allowlist must drop the presets option.' );
	}

	public function test_an_agent_cannot_write_the_presets_option_through_update_option_even_via_the_filter(): void {
		Presets::save( array( Presets::RANK_MATH => true ) );
		$before = get_option( Presets::OPTION );

		$this->on(
			'steadhand_allowed_options',
			static function ( array $options ): array {
				$options[ Presets::OPTION ] = array( 'max_length' => 2000 );

				return $options;
			}
		);
		$this->option_agent( array( Capabilities::SETTINGS => true ) );

		$result = $this->execute_ability(
			self::ABILITY,
			$this->args( Presets::OPTION, array( Presets::RANK_MATH => false ) )
		);

		$this->assertWPError( $result, 'An agent must not reach the presets option through update-option.' );
		wp_cache_flush();
		$this->assertSame( $before, get_option( Presets::OPTION ), 'The stored presets must be unchanged.' );
		$this->assertTrue( Presets::enabled( Presets::RANK_MATH ) );
	}
}
