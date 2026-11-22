<?php
/**
 * Test-only read ability that records, while its object check runs, whether the call is a write call and what edit_post
 * answers for a post.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\Ability;
use Steadhand\Capabilities;
use Steadhand\Pipeline\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * A read-only probe: readonly annotation, steadhand_read. Its object check runs inside check() or run().
 */
final class ReadCheckProbe extends Ability {

	/**
	 * Whether Pipeline::is_write_call() was true inside the object check. Null before the check runs.
	 *
	 * @var bool|null
	 */
	public ?bool $write_call = null;

	/**
	 * What current_user_can( 'edit_post', $draft ) answered inside the object check. Null before the check runs.
	 *
	 * @var bool|null
	 */
	public ?bool $edit_answer = null;

	/**
	 * The post whose edit_post answer is recorded.
	 *
	 * @var int
	 */
	public int $draft = 0;

	/**
	 * Returns the ability name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'steadhand/probe-read-check';
	}

	/**
	 * Returns the label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Probe read check';
	}

	/**
	 * Returns the description.
	 *
	 * @return string
	 */
	public function description(): string {
		return 'Test-only read ability.';
	}

	/**
	 * Returns the input schema: no input.
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
	 * Returns the output schema.
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema(): array {
		return array( 'type' => 'object' );
	}

	/**
	 * Returns the annotations: read only.
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
	 * Returns the capability: steadhand_read.
	 *
	 * @return string
	 */
	public function capability(): string {
		return Capabilities::READ;
	}

	/**
	 * Records the write scope and the edit_post answer, and allows the call.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return bool
	 */
	public function authorize_object( array $input ): bool {
		unset( $input );
		$this->write_call  = Pipeline::is_write_call();
		$this->edit_answer = current_user_can( 'edit_post', $this->draft );

		return true;
	}

	/**
	 * Returns an empty result.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ): array {
		unset( $input );

		return array();
	}
}
