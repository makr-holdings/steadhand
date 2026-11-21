<?php
/**
 * The steadhand/get-site-info ability: read-only facts about the site and the caller's permissions.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Abilities;

use Steadhand\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Reports the site name, URL, versions, active theme and the caller's Steadhand capabilities.
 */
final class GetSiteInfo extends Ability {

	/**
	 * Returns the ability name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'steadhand/get-site-info';
	}

	/**
	 * Returns the human-readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Get site info', 'steadhand' );
	}

	/**
	 * Returns the description shown to the agent.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Reports the site name, URL, WordPress and PHP versions, the active theme, the Steadhand version, and which Steadhand capabilities this agent holds. Call it first to learn what you are allowed to do. Read-only. Takes no arguments and refuses any.', 'steadhand' );
	}

	/**
	 * Returns the JSON Schema for the input: no arguments.
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Returns the JSON Schema for the output.
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema(): array {
		$capability_properties = array();
		foreach ( Capabilities::all() as $capability ) {
			$capability_properties[ $capability ] = array( 'type' => 'boolean' );
		}

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'name', 'url', 'wordpress_version', 'php_version', 'theme', 'steadhand_version', 'capabilities' ),
			'properties'           => array(
				'name'              => array( 'type' => 'string' ),
				'url'               => array(
					'type'   => 'string',
					'format' => 'uri',
				),
				'wordpress_version' => array( 'type' => 'string' ),
				'php_version'       => array( 'type' => 'string' ),
				'theme'             => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'name', 'version' ),
					'properties'           => array(
						'name'    => array( 'type' => 'string' ),
						'version' => array( 'type' => 'string' ),
					),
				),
				'steadhand_version' => array( 'type' => 'string' ),
				'capabilities'      => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => Capabilities::all(),
					'properties'           => $capability_properties,
				),
			),
		);
	}

	/**
	 * Returns the behavioral annotations: read-only, not destructive, idempotent.
	 *
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	public function annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * Returns the Steadhand capability required to call this ability.
	 *
	 * @return string
	 */
	public function capability(): string {
		return Capabilities::READ;
	}

	/**
	 * Checks the native object-level capability. Site info is not tied to an object, so only 'read' is required.
	 *
	 * @param array<string, mixed> $input Validated input (unused).
	 * @return bool
	 */
	public function authorize_object( array $input ): bool {
		unset( $input );
		return current_user_can( 'read' );
	}

	/**
	 * Collects the site facts and the caller's capabilities.
	 *
	 * @param array<string, mixed> $input Validated input (unused).
	 * @return array<string, mixed>
	 */
	public function execute( array $input ): array {
		unset( $input );
		$theme = wp_get_theme();

		return array(
			'name'              => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'url'               => home_url(),
			'wordpress_version' => get_bloginfo( 'version' ),
			'php_version'       => PHP_VERSION,
			'theme'             => array(
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
			),
			'steadhand_version' => STEADHAND_VERSION,
			'capabilities'      => Capabilities::granted( wp_get_current_user() ),
		);
	}
}
