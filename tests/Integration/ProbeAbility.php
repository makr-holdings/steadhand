<?php
/**
 * Test-only ability: runs a caller-supplied callback inside a real pipeline call and records what it saw.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Closure;
use Steadhand\Abilities\Ability;
use Steadhand\Capabilities;
use Steadhand\Pipeline\Pipeline;

/**
 * Lets tests drive the pipeline with a real ability. The callback runs in execute() and is the only place
 * where a test asserts against the live site, so the context flag and the sanitised input are seen as they
 * are during a real call.
 */
final class ProbeAbility extends Ability {

	/**
	 * Input that authorize_object() received.
	 *
	 * @var array<string, mixed>
	 */
	public array $seen_in_authorize = array();

	/**
	 * Input that execute() received.
	 *
	 * @var array<string, mixed>
	 */
	public array $seen_in_execute = array();

	/**
	 * Value of Pipeline::is_running() inside authorize_object(), or null if it was never called.
	 *
	 * @var bool|null
	 */
	public ?bool $running_in_authorize = null;

	/**
	 * Value of Pipeline::is_running() inside execute(), or null if it was never called.
	 *
	 * @var bool|null
	 */
	public ?bool $running_in_execute = null;

	/**
	 * Whether authorize_object() should throw.
	 *
	 * @var bool
	 */
	public bool $throw_in_authorize = false;

	/**
	 * Whether execute() was reached.
	 *
	 * @var bool
	 */
	public bool $executed = false;

	/**
	 * Whether log_object() should throw.
	 *
	 * @var bool
	 */
	public bool $throw_in_log_object = false;

	/**
	 * What log_object() returns instead of the default empty object, or null for the default.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $object_override = null;

	/**
	 * Constructor.
	 *
	 * @param string                    $capability          Steadhand capability the call requires.
	 * @param Closure|null              $on_execute          Runs inside execute(); receives the sanitised input and returns the output.
	 * @param array<string, mixed>|null $annotation_override Annotations to return instead of the read-only default. Null keeps the default.
	 */
	public function __construct(
		private string $capability = Capabilities::READ,
		private ?Closure $on_execute = null,
		private ?array $annotation_override = null
	) {}

	/**
	 * Returns the ability name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'steadhand/probe-read';
	}

	/**
	 * Returns the label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Probe';
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
	 * Input schema with one property of each kind the pipeline must handle: an integer, a bounded string,
	 * an enum with a default, and a boolean.
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'count'   => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'title'   => array(
					'type'      => 'string',
					'maxLength' => 5,
				),
				'mode'    => array(
					'type'    => 'string',
					'enum'    => array( 'draft', 'pending' ),
					'default' => 'draft',
				),
				'flag'    => array( 'type' => 'boolean' ),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Output schema (not checked by the pipeline).
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema(): array {
		return array( 'type' => 'object' );
	}

	/**
	 * Annotations.
	 *
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	public function annotations(): array {
		if ( null !== $this->annotation_override ) {
			return $this->annotation_override;
		}

		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * Names no object by default. Throws, or returns the override, when a test asks for it.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array{type: string, id: int}
	 * @throws \RuntimeException When throw_in_log_object is set.
	 */
	public function log_object( array $input ): array {
		if ( $this->throw_in_log_object ) {
			throw new \RuntimeException( 'Probe log_object failed.' );
		}

		if ( null !== $this->object_override ) {
			return $this->object_override;
		}

		return parent::log_object( $input );
	}

	/**
	 * Steadhand capability the call requires.
	 *
	 * @return string
	 */
	public function capability(): string {
		return $this->capability;
	}

	/**
	 * Records what the pipeline showed it, then allows the call (or throws, when asked to).
	 *
	 * @param array<string, mixed> $input Input.
	 * @return bool
	 * @throws \RuntimeException When throw_in_authorize is set.
	 */
	public function authorize_object( array $input ): bool {
		$this->running_in_authorize = Pipeline::is_running();
		$this->seen_in_authorize    = $input;

		if ( $this->throw_in_authorize ) {
			throw new \RuntimeException( 'Probe authorize failed.' );
		}

		return true;
	}

	/**
	 * Records what it was given and runs the callback. The callback may return a WP_Error.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ): array|\WP_Error {
		$this->executed           = true;
		$this->running_in_execute = Pipeline::is_running();
		$this->seen_in_execute    = $input;

		if ( null === $this->on_execute ) {
			return array();
		}

		return ( $this->on_execute )( $input );
	}
}
