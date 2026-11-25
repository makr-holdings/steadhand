<?php
/**
 * Test-only ability that takes a chosen name, so a test can ask a question from inside a call that has the name of a real ability
 * (steadhand/trash-post, steadhand/restore-post, steadhand/update-post or steadhand/get-post).
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Closure;
use Steadhand\Abilities\Ability;
use Steadhand\Capabilities;

/**
 * ProbeAbility is final and always has one name, so this one takes its name, and whether it writes, as arguments. Its check runs
 * inside execute(), so it sees the call the way a real ability does. The answer is returned as 'answer'.
 */
final class NamedProbe extends Ability {

	/**
	 * Constructor.
	 *
	 * @param string  $ability_name The ability name the probe reports.
	 * @param Closure $check        Receives the sanitized input, returns the value to report.
	 * @param bool    $write        Whether the probe is a write call (not read-only).
	 * @param bool    $throw_in_authorize Whether authorize_object() throws, to test the exit path of a call.
	 */
	public function __construct(
		private string $ability_name,
		private Closure $check,
		private bool $write = true,
		private bool $throw_in_authorize = false
	) {}

	/**
	 * Returns the ability name.
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->ability_name;
	}

	/**
	 * Returns the label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Named probe';
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
	 * An empty input schema.
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
	 * Output schema.
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema(): array {
		return array( 'type' => 'object' );
	}

	/**
	 * Annotations: a read call when it does not write, otherwise a write call.
	 *
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	public function annotations(): array {
		return array(
			'readonly'    => ! $this->write,
			'destructive' => $this->write,
			'idempotent'  => false,
		);
	}

	/**
	 * The Steadhand capability: READ, so the probe needs nothing beyond what every agent in these tests holds.
	 *
	 * @return string
	 */
	public function capability(): string {
		return Capabilities::READ;
	}

	/**
	 * Always allows the object.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return bool
	 * @throws \RuntimeException When the probe is set to throw here.
	 */
	public function authorize_object( array $input ): bool {
		unset( $input );

		if ( $this->throw_in_authorize ) {
			throw new \RuntimeException( 'The probe throws in authorize_object().' );
		}

		return true;
	}

	/**
	 * Runs the check and reports its answer.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 */
	public function execute( array $input ): array|\WP_Error {
		return array( 'answer' => ( $this->check )( $input ) );
	}
}
