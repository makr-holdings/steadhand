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
 * It calls update_option() with two arguments only, so the autoload setting is kept as it is. It never calls add_option(), delete_option()
 * or the site-wide functions, and it never creates an option.
 *
 * Refusals (nothing changed): steadhand_forbidden (403, the generic text) when the caller's check or the allowlist refuses the name;
 * steadhand_invalid_input (400) from OptionRules::value_error(); steadhand_refused_option_missing, steadhand_refused_serialized_option
 * and steadhand_refused_option_filtered (409) from OptionRules::stored_error(); steadhand_refused_content_changed (409) when `expected` is
 * sent and does not match the stored value. A change that did not land is steadhand_update_failed (500, nothing changed) or
 * steadhand_update_partial (500, the option holds a value that is neither the value sent nor the value before; that value is not shown).
 *
 * Known limit: the compare and the write are not atomic, so a change by another request between them is overwritten, as for the other writers.
 */
final class OptionWriter {

	/**
	 * Sets an option to a value, if every rule passes.
	 *
	 * @param string      $name          The option name.
	 * @param string      $value         The value to store, as sent (already checked by the ability, and checked again here).
	 * @param string|null $expected      The stored value the caller read, or null when the caller did not send one.
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

		$previous = OptionRules::stored_raw( $name );
		if ( null === $previous ) {
			return self::missing();
		}

		if ( null !== $expected && $expected !== $previous ) {
			return new \WP_Error(
				'steadhand_refused_content_changed',
				__( 'The option changed after it was read, so it was not written. Read the option again, then send the change. Nothing was changed.', 'steadhand' ),
				array( 'status' => 409 )
			);
		}

		if ( $previous === $value ) {
			return array(
				'option'   => $name,
				'value'    => $value,
				'previous' => $previous,
				'changed'  => false,
			);
		}

		try {
			// Two arguments only: WordPress keeps the autoload setting of an existing option when none is given.
			update_option( $name, $value );
		} catch ( \Throwable $error ) {
			// Not reported here: the read-back below decides what the write did, so a throw is neither a success nor a failure on its own.
			unset( $error );
		}

		$stored = OptionRules::stored_raw( $name );
		if ( $stored === $value ) {
			return array(
				'option'   => $name,
				'value'    => $stored,
				'previous' => $previous,
				'changed'  => true,
			);
		}

		if ( $stored === $previous ) {
			return new \WP_Error(
				'steadhand_update_failed',
				__( 'WordPress did not change the option, so nothing was changed.', 'steadhand' ),
				array( 'status' => 500 )
			);
		}

		return new \WP_Error(
			'steadhand_update_partial',
			__( 'The option now holds a value that is neither the value sent nor the value before the call. Something other than Steadhand changed it during the save. Its stored value is not shown. An administrator should check the option.', 'steadhand' ),
			array( 'status' => 500 )
		);
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
}
