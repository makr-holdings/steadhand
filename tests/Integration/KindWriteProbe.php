<?php
/**
 * Test-only write ability whose object type comes from its input, so a guardrail can keep the object ID and change its type.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\Ability;
use Steadhand\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Names object 5 of the type given in the kind field.
 */
final class KindWriteProbe extends Ability {

	/**
	 * Returns the ability name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'steadhand/probe-kind-write';
	}

	/**
	 * Returns the label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Probe kind write';
	}

	/**
	 * Returns the description.
	 *
	 * @return string
	 */
	public function description(): string {
		return 'Test-only write ability.';
	}

	/**
	 * Returns the input schema: one enum field.
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'kind' ),
			'properties'           => array(
				'kind' => array(
					'type' => 'string',
					'enum' => array( 'post', 'page' ),
				),
			),
		);
	}

	/**
	 * Returns the output schema.
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema(): array {
		return array( 'type' => 'object' );
	}

	/**
	 * Returns the annotations: a write.
	 *
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	public function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	/**
	 * Returns the capability: steadhand_write_drafts.
	 *
	 * @return string
	 */
	public function capability(): string {
		return Capabilities::WRITE_DRAFTS;
	}

	/**
	 * Allows every call.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return bool
	 */
	public function authorize_object( array $input ): bool {
		unset( $input );

		return true;
	}

	/**
	 * Names object 5, of the type the input gives.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array{type: string, id: int}
	 */
	public function log_object( array $input ): array {
		return array(
			'type' => (string) ( $input['kind'] ?? '' ),
			'id'   => 5,
		);
	}

	/**
	 * Returns an empty result.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ): array {
		unset( $input );

		return array( 'done' => true );
	}
}
