<?php
/**
 * A write ability whose equivalent_call() does what a test asks: returns null, throws, or returns a malformed value.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\Ability;
use Steadhand\Capabilities;

defined( 'ABSPATH' ) || exit;

/**
 * Used only by UndoGuardrailTest, to check that a throw or a malformed equivalent call refuses the write before it runs.
 */
final class EquivalentCallProbe extends Ability {

	/**
	 * How many times execute() ran, across the test.
	 *
	 * @var int
	 */
	public static int $executed = 0;

	/**
	 * Sets how equivalent_call() behaves.
	 *
	 * @param string $mode 'null' (no equivalent call), 'throw', or 'malformed'.
	 */
	public function __construct( private string $mode ) {}

	public function name(): string {
		return 'steadhand/probe-equivalent';
	}

	public function label(): string {
		return 'Probe equivalent call';
	}

	public function description(): string {
		return 'Test-only write probe. Not registered.';
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(),
		);
	}

	public function output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'ok' ),
			'properties'           => array( 'ok' => array( 'type' => 'boolean' ) ),
		);
	}

	public function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	public function capability(): string {
		return Capabilities::WRITE_DRAFTS;
	}

	public function authorize_object( array $input ): bool {
		unset( $input );

		return true;
	}

	public function equivalent_call( array $input ): ?array {
		unset( $input );

		return match ( $this->mode ) {
			'throw'     => throw new \RuntimeException( 'equivalent_call failed' ),
			'malformed' => array( 'ability' => 'steadhand/set-post-terms' ),
			default     => null,
		};
	}

	public function execute( array $input ): array {
		unset( $input );
		++self::$executed;

		return array( 'ok' => true );
	}
}
