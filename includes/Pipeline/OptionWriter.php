<?php
/**
 * The only place Steadhand changes an option (SPEC §5, the update-option ability).
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * Sets one allowlisted option to a plain-text value. It runs only inside a write (Pipeline::is_writing()) and trusts nothing the ability
 * checked: it runs every rule of OptionRules again on fresh data, reads the stored value uncached, and reads the result back uncached.
 *
 * Values are display text (OptionRules::display()). The value is passed to update_option(), which runs sanitize_option() and stores the
 * stored form; the read-back must equal that stored form. `previous` and `value` in the result are the display text, and `expected` is
 * compared with the display text of the stored value.
 *
 * It calls update_option() with two arguments only, so the autoload setting is kept as it is. It never calls add_option(), delete_option()
 * or the site-wide functions, and it never creates an option.
 *
 * Refusals (nothing changed): steadhand_forbidden (403, the generic text) when the caller's check or the allowlist refuses the name;
 * steadhand_invalid_input (400) from OptionRules::value_error(); steadhand_refused_option_missing, steadhand_refused_serialized_option
 * and steadhand_refused_option_filtered (409) from OptionRules::stored_error(); steadhand_refused_content_changed (409) when `expected` is
 * sent and does not match the display text of the stored value. A change that did not land is steadhand_update_failed (500, nothing
 * changed) when the stored value is still the one before, or steadhand_update_partial (500) when the stored value is neither that nor the
 * stored form of the value sent; that value is not shown.
 *
 * Known limit: the compare and the write are not atomic, so a change by another request between them is overwritten, as for the other writers.
 */
final class OptionWriter {

	/**
	 * Sets an option to a value, if every rule passes.
	 *
	 * @param string      $name          The option name.
	 * @param string      $value         The display text to store, as sent (already checked by the ability, and checked again here).
	 * @param string|null $expected      The display text of the stored value the caller read, or null when the caller did not send one.
	 * @param callable    $still_allowed Returns true while the caller may still change this option. Called once, first.
	 * @return array{option: string, value: string, previous: string, changed: bool}|\WP_Error
	 * @throws \LogicException When called outside a write.
	 */
	public static function set( string $name, string $value, ?string $expected, callable $still_allowed ): array|\WP_Error {
		self::assert_writing();

		if ( true !== $still_allowed() || ! OptionRules::valid_name( $name ) || OptionRules::hard_blocked( $name ) || ! array_key_exists( $name, OptionRules::allowed() ) ) {
			return self::forbidden();
		}

		$refused = OptionRules::value_error( $name, $value );
		if ( null !== $refused ) {
			return $refused;
		}

		$refused = OptionRules::stored_error( $name );
		if ( null !== $refused ) {
			return $refused;
		}

		$raw_before = OptionRules::stored_raw( $name );
		if ( null === $raw_before ) {
			return self::missing();
		}

		$previous = OptionRules::display( $raw_before );
		if ( null !== $expected && $expected !== $previous ) {
			return new \WP_Error(
				'steadhand_refused_content_changed',
				__( 'The option changed after it was read, so it was not written. Read the option again, then send the change. Nothing was changed.', 'steadhand' ),
				array( 'status' => 409 )
			);
		}

		$stored = OptionRules::stored_form( $name, $value );
		if ( null === $stored ) {
			return self::invalid_stored();
		}

		// The stored form is what the option already holds, so nothing is written. Its display text then equals the value, because
		// value_error() checked that the stored form decodes back to it.
		if ( $raw_before === $stored ) {
			return array(
				'option'   => $name,
				'value'    => $previous,
				'previous' => $previous,
				'changed'  => false,
			);
		}

		try {
			// Two arguments only: WordPress keeps the autoload setting of an existing option when none is given. update_option()
			// runs sanitize_option() on the value, so the row receives $stored.
			update_option( $name, $value );
		} catch ( \Throwable $error ) {
			// Not reported here: the read-back below decides what the write did, so a throw is neither a success nor a failure on its own.
			unset( $error );
		}

		$after = OptionRules::stored_raw( $name );
		switch ( self::read_back_outcome( $after, $stored, $raw_before ) ) {
			case 'stored':
				return array(
					'option'   => $name,
					'value'    => OptionRules::display( (string) $after ),
					'previous' => $previous,
					'changed'  => true,
				);

			case 'different_form':
				return new \WP_Error(
					'steadhand_update_partial',
					__( 'WordPress stored the value in a different form from the one Steadhand expected, a serialized form. Its stored value is not shown. An administrator should check the option.', 'steadhand' ),
					array( 'status' => 500 )
				);

			case 'not_changed':
				return new \WP_Error(
					'steadhand_update_failed',
					__( 'WordPress did not change the option, so nothing was changed.', 'steadhand' ),
					array( 'status' => 500 )
				);

			default:
				return new \WP_Error(
					'steadhand_update_partial',
					__( 'The option now holds a value that is neither the value sent nor the value before the call. Something other than Steadhand changed it during the save. Its stored value is not shown. An administrator should check the option.', 'steadhand' ),
					array( 'status' => 500 )
				);
		}
	}

	/**
	 * Decides what a write did, from the row read back. 'stored': the row holds the stored form. 'different_form': the row holds the
	 * serialized form of the stored form, which update_option() makes of a value that looks serialized (maybe_serialize()); the value rule
	 * refuses such a value, so this is defensive, and it is not reported as another writer's change. 'not_changed': the row is what it was.
	 * 'other': anything else.
	 *
	 * @param string|null $after  The row read back, or null.
	 * @param string      $stored The stored form of the value sent.
	 * @param string      $before The raw row before the write.
	 * @return string One of 'stored', 'different_form', 'not_changed' or 'other'.
	 */
	public static function read_back_outcome( ?string $after, string $stored, string $before ): string {
		if ( $after === $stored ) {
			return 'stored';
		}

		if ( null !== $after && maybe_serialize( $stored ) === $after ) {
			return 'different_form';
		}

		if ( $after === $before ) {
			return 'not_changed';
		}

		return 'other';
	}

	/**
	 * Throws when the function is called outside a write, as MenuWriter does.
	 *
	 * @throws \LogicException When Pipeline::is_writing() is false.
	 */
	private static function assert_writing(): void {
		if ( ! Pipeline::is_writing() ) {
			throw new \LogicException( 'OptionWriter may only be called from a write ability.' );
		}
	}

	/**
	 * The generic refusal for a name the caller may not change.
	 */
	private static function forbidden(): \WP_Error {
		return new \WP_Error( 'steadhand_forbidden', __( 'Not found, or this agent may not access it.', 'steadhand' ), array( 'status' => 403 ) );
	}

	/**
	 * The refusal for an option with no row, found between the checks and the compare.
	 */
	private static function missing(): \WP_Error {
		return new \WP_Error(
			'steadhand_refused_option_missing',
			__( 'This option does not exist in the database. Steadhand never creates an option, so nothing was changed.', 'steadhand' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * The refusal when the stored form of a value cannot be worked out, which the value rule already refuses.
	 */
	private static function invalid_stored(): \WP_Error {
		return new \WP_Error(
			'steadhand_invalid_input',
			__( 'WordPress would store this value differently from the value sent, so it was refused. Nothing was changed.', 'steadhand' ),
			array( 'status' => 400 )
		);
	}
}
