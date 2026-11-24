<?php
/**
 * Test-only write ability: records each stage of the write path, and runs a caller-supplied callback in execute().
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
 * Drives the write path with a real ability. The fields below say what it was given and what it saw, and the switches
 * make each stage fail on request.
 */
final class WriteProbeAbility extends Ability {

	/**
	 * Input that execute() received (the final, possibly fixed, input).
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $seen_in_execute = null;

	/**
	 * Whether execute() ran.
	 *
	 * @var bool
	 */
	public bool $executed = false;

	/**
	 * Value of Pipeline::is_running() inside execute().
	 *
	 * @var ?bool
	 */
	public ?bool $running_in_execute = null;

	/**
	 * Value of Pipeline::is_writing() inside execute().
	 *
	 * @var ?bool
	 */
	public ?bool $writing_in_execute = null;

	/**
	 * Value of Pipeline::is_writing() inside snapshot().
	 *
	 * @var ?bool
	 */
	public ?bool $writing_in_snapshot = null;

	/**
	 * What snapshot() returns.
	 *
	 * @var array{id: int, undoable: bool}
	 */
	public array $snapshot_result = array(
		'id'       => 0,
		'undoable' => false,
	);

	/**
	 * Whether snapshot() throws.
	 *
	 * @var bool
	 */
	public bool $throw_in_snapshot = false;

	/**
	 * What snapshot_after() returns. Typed as int by the parent, so a non-int here makes the call throw a TypeError.
	 *
	 * @var mixed
	 */
	public mixed $after_result = 0;

	/**
	 * Whether snapshot_after() throws.
	 *
	 * @var bool
	 */
	public bool $throw_in_snapshot_after = false;

	/**
	 * Whether snapshot_after() ran, and the value of Pipeline::is_writing() inside it.
	 *
	 * @var ?bool
	 */
	public ?bool $writing_in_snapshot_after = null;

	/**
	 * Whether execute() throws, with a message that must never reach the caller.
	 *
	 * @var bool
	 */
	public bool $throw_in_execute = false;

	/**
	 * Whether guarded_content() throws.
	 *
	 * @var bool
	 */
	public bool $throw_in_guarded = false;

	/**
	 * What guarded_content() returns. Null means no content fields.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $guarded = null;

	/**
	 * Whether logged_object_after() throws.
	 *
	 * @var bool
	 */
	public bool $throw_in_logged_after = false;

	/**
	 * What logged_object_after() returns, or null for the default.
	 *
	 * @var array<string, mixed>|null
	 */
	public ?array $logged_after = null;

	/**
	 * What log_object() returns.
	 *
	 * @var array{type: string, id: int}
	 */
	public array $object = array(
		'type' => 'post',
		'id'   => 0,
	);

	/**
	 * A title the object check refuses, or null for none. Lets a test refuse a guardrail's fixed input.
	 *
	 * @var ?string
	 */
	public ?string $refuse_title = null;

	/**
	 * Callback that runs in execute() and returns the output. Null returns a plain success array.
	 *
	 * @var Closure|null
	 */
	public ?Closure $on_execute = null;

	/**
	 * Constructor.
	 *
	 * @param string         $capability Steadhand capability the call requires.
	 * @param SpyLogger|null $spy        Receives the stage marks, when given.
	 */
	public function __construct( private string $capability = Capabilities::WRITE_DRAFTS, private ?SpyLogger $spy = null ) {}

	/**
	 * Returns the ability name.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'steadhand/probe-write';
	}

	/**
	 * Returns the label.
	 *
	 * @return string
	 */
	public function label(): string {
		return 'Probe write';
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
	 * Input schema: one bounded string.
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'title' => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
			),
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
	 * Annotations of a write.
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
	 * Steadhand capability the call requires.
	 *
	 * @return string
	 */
	public function capability(): string {
		return $this->capability;
	}

	/**
	 * Allows every object, except a title the test has refused.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return bool
	 */
	public function authorize_object( array $input ): bool {
		return null === $this->refuse_title || ( $input['title'] ?? null ) !== $this->refuse_title;
	}

	/**
	 * Returns the object the call names before it runs.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array{type: string, id: int}
	 */
	public function log_object( array $input ): array {
		unset( $input );
		return $this->object;
	}

	/**
	 * Returns the object the call names after it ran, or throws.
	 *
	 * @param array<string, mixed> $input  Input.
	 * @param array<string, mixed> $output Output.
	 * @return array{type: string, id: int}
	 * @throws \RuntimeException When throw_in_logged_after is set.
	 */
	public function logged_object_after( array $input, array $output ): array {
		unset( $input, $output );
		if ( $this->throw_in_logged_after ) {
			throw new \RuntimeException( 'Probe logged_object_after failed.' );
		}

		return $this->logged_after ?? parent::logged_object_after( array(), array() );
	}

	/**
	 * Returns the content fields, or throws.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array<string, mixed>
	 * @throws \RuntimeException When throw_in_guarded is set.
	 */
	public function guarded_content( array $input ): array {
		unset( $input );
		if ( $this->throw_in_guarded ) {
			throw new \RuntimeException( 'Probe guarded_content failed.' );
		}

		return $this->guarded ?? array();
	}

	/**
	 * Records the stage, then returns the snapshot or throws.
	 *
	 * @param array<string, mixed> $input Input.
	 * @return array{id: int, undoable: bool}
	 * @throws \RuntimeException When throw_in_snapshot is set.
	 */
	public function snapshot( array $input ): array {
		unset( $input );
		$this->writing_in_snapshot = Pipeline::is_writing();
		$this->spy?->mark( 'snapshot' );

		if ( $this->throw_in_snapshot ) {
			throw new \RuntimeException( 'Probe snapshot failed.' );
		}

		return $this->snapshot_result;
	}

	/**
	 * Records the write bracket, then returns the after-state snapshot, throws, or returns a value the parent's int
	 * return type refuses.
	 *
	 * @param array<string, mixed> $input       Input.
	 * @param mixed                $output      Output.
	 * @param int                  $snapshot_id The before-snapshot ID.
	 * @return int
	 * @throws \RuntimeException When throw_in_snapshot_after is set.
	 */
	public function snapshot_after( array $input, mixed $output, int $snapshot_id ): int {
		unset( $input, $output, $snapshot_id );
		$this->writing_in_snapshot_after = Pipeline::is_writing();
		if ( $this->throw_in_snapshot_after ) {
			throw new \RuntimeException( 'Probe snapshot_after failed.' );
		}

		return $this->after_result;
	}

	/**
	 * Records what it was given, then runs the callback, or throws.
	 *
	 * @param array<string, mixed> $input Final input.
	 * @return array<string, mixed>|\WP_Error
	 * @throws \RuntimeException When throw_in_execute is set.
	 */
	public function execute( array $input ): array|\WP_Error {
		$this->executed           = true;
		$this->seen_in_execute    = $input;
		$this->running_in_execute = Pipeline::is_running();
		$this->writing_in_execute = Pipeline::is_writing();
		$this->spy?->mark( 'execute' );

		if ( $this->throw_in_execute ) {
			throw new \RuntimeException( 'Probe internal detail: secret-probe-text' );
		}

		if ( null === $this->on_execute ) {
			return array( 'done' => true );
		}

		return ( $this->on_execute )( $input );
	}
}
