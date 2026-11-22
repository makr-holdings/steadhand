<?php
/**
 * Test-only read ability whose caller check takes a list of capabilities, so the pipeline's "any of" rule can be driven.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\Ability;
use Steadhand\Capabilities;

/**
 * Its capability() is Steadhand's read capability, which is also what an empty capabilities_any() falls back to.
 */
final class CapabilitiesAnyProbe extends Ability {

	/**
	 * Constructor.
	 *
	 * @param string[] $declared What capabilities_any() returns.
	 */
	public function __construct( private array $declared ) {}

	/**
	 * Returns the ability name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'steadhand/probe-any';
	}

	/**
	 * Returns the label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Probe any';
	}

	/**
	 * Returns the description.
	 *
	 * @return string
	 */
	public function description(): string {
		return 'Test-only ability.';
	}

	/**
	 * Accepts no input.
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(),
		);
	}

	/**
	 * Output schema, not checked by the pipeline.
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema(): array {
		return array( 'type' => 'object' );
	}

	/**
	 * Read-only annotations.
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
	 * Steadhand's read capability, the fallback for an empty list.
	 *
	 * @return string
	 */
	public function capability(): string {
		return Capabilities::READ;
	}

	/**
	 * Returns what the test declared.
	 *
	 * @return string[]
	 */
	public function capabilities_any(): array {
		return $this->declared;
	}

	/**
	 * Allows every object.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return bool
	 */
	public function authorize_object( array $input ): bool {
		unset( $input );
		return true;
	}

	/**
	 * Returns an empty output.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ): array {
		unset( $input );
		return array();
	}
}
