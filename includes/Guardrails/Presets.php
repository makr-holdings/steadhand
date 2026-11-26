<?php
/**
 * The guardrail presets: a fixed list of optional rules, each on or off, stored in one option.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Guardrails;

defined( 'ABSPATH' ) || exit;

/**
 * The presets an administrator turns on and off on the Guardrails screen (SPEC §6.2, D27). The list is fixed in code, and no filter
 * adds a preset in this version. The state is one option, steadhand_presets, an array of id => bool, and every known id is stored
 * in it explicitly.
 *
 * Reading is defensive. A stored value that is not an array gives the defaults; an unknown ID is ignored; a value for a known ID that
 * is not exactly true or false gives that preset's default. Nothing is cached, so a save is seen by the next read.
 */
final class Presets {

	/** The option that stores the state. OptionRules' steadhand_ prefix block keeps every agent from writing it. */
	public const OPTION = 'steadhand_presets';

	/** The Rank Math preset. */
	public const RANK_MATH = 'rank-math-options';

	/** Shape of a preset ID. */
	public const ID_PATTERN = '/^[a-z0-9-]{1,40}\z/';

	/**
	 * The known presets, in display order: ID => default.
	 *
	 * @var array<string, bool>
	 */
	private const DEFAULTS = array(
		self::RANK_MATH => true,
	);

	/**
	 * The IDs of the known presets, in display order.
	 *
	 * @return string[]
	 */
	public static function ids(): array {
		return array_keys( self::DEFAULTS );
	}

	/**
	 * Whether a preset is on. An unknown ID is off.
	 *
	 * @param string $id The preset ID.
	 * @return bool
	 */
	public static function enabled( string $id ): bool {
		return self::states()[ $id ] ?? false;
	}

	/**
	 * Every known preset with its text and state: id, label, description, default and enabled.
	 *
	 * @return array<int, array{id: string, label: string, description: string, default: bool, enabled: bool}>
	 */
	public static function all(): array {
		$states = self::states();
		$texts  = self::texts();
		$all    = array();

		foreach ( self::DEFAULTS as $id => $default ) {
			$all[] = array(
				'id'          => $id,
				'label'       => $texts[ $id ]['label'],
				'description' => $texts[ $id ]['description'],
				'default'     => $default,
				'enabled'     => $states[ $id ],
			);
		}

		return $all;
	}

	/**
	 * The state of every known preset, as id => bool, read from the option. Defaults fill every value the option does not hold as a
	 * plain true or false.
	 *
	 * @return array<string, bool>
	 */
	public static function states(): array {
		$stored = get_option( self::OPTION, null );
		$stored = is_array( $stored ) ? $stored : array();

		$states = array();
		foreach ( self::DEFAULTS as $id => $default ) {
			$value         = $stored[ $id ] ?? null;
			$states[ $id ] = is_bool( $value ) ? $value : $default;
		}

		return $states;
	}

	/**
	 * Stores the state. Only known IDs are read, and every known ID is stored as true or false. A value that is not exactly true counts
	 * as off. Returns true only when the option reads back as the state that was stored.
	 *
	 * @param array<string, mixed> $state The state, as id => bool.
	 * @return bool
	 */
	public static function save( array $state ): bool {
		$clean = array();
		foreach ( self::ids() as $id ) {
			$clean[ $id ] = true === ( $state[ $id ] ?? false );
		}

		// update_option() returns false when the value is unchanged, so the stored value is read back instead.
		update_option( self::OPTION, $clean, true );

		$stored = get_option( self::OPTION, null );

		return is_array( $stored ) && $stored === $clean;
	}

	/**
	 * Deletes the option. Called by uninstall.php.
	 *
	 * @return void
	 */
	public static function uninstall(): void {
		delete_option( self::OPTION );
	}

	/**
	 * The label and the description of each known preset, translated when this is called.
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	private static function texts(): array {
		return array(
			self::RANK_MATH => array(
				'label'       => __( 'Rank Math options', 'steadhand' ),
				'description' => __( 'Refuses an update-option call for any option whose name starts with rank-math or rank_math, in any letter case, so an agent cannot change the Rank Math settings. The built-in allowlist has no such option, so this matters only when another plugin adds option names to the allowlist with the steadhand_allowed_options filter. Routing SEO data through post meta is not part of this preset, because no tool writes post meta.', 'steadhand' ),
			),
		);
	}
}
