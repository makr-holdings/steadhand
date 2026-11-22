<?php
/**
 * The shared pipeline every ability call goes through: validate, authorize, guardrails, log, execute, log.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Pipeline;

use Steadhand\Abilities\Ability;
use Steadhand\Agents;
use Steadhand\Capabilities;
use Steadhand\Guardrails\Context;
use Steadhand\Guardrails\Engine;
use Steadhand\Log\Entry;
use Steadhand\Log\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Runs an ability call through the shared pipeline.
 *
 * Two entry points, and each logs what it sees:
 *
 * - run() is the full path. For every call it normalizes the input, fills defaults, validates it against the strict
 *   schema, sanitizes it, and authorizes it (the caller's identity and Steadhand capabilities, then the ability's input
 *   rule, then the object check). A read call then executes and is logged as one entry (see should_log()). A write call
 *   continues in this fixed order:
 *     1. guardrails: the Engine runs the rules over the call, and a rule may refuse it or rewrite its input;
 *     2. log reservation: begin() stores a row before anything changes, and if that fails the write is not performed;
 *     3. snapshot: the ability saves what it needs to undo the call, immediately before execute();
 *     4. execute: the ability runs with the final input, while Pipeline::is_writing() is true;
 *     5. log completion: complete() records the outcome on the reserved row.
 *   If the outcome cannot be recorded after the write, the caller gets steadhand_log_incomplete, because the change has
 *   happened and cannot be undone by the pipeline.
 *   Every outcome reached writes a log entry, except a successful read (see should_log()). Logging never fails open: if
 *   a required entry cannot be written, the result is withheld and the call fails with steadhand_log_failed. The ability
 *   receives only the sanitized (and, for writes, guardrail-fixed) input. Each entry carries the input and the object
 *   the call is about (see Ability::log_object()), recorded only when the input passed validation.
 * - check() is the permission callback path. Core and the MCP Adapter call it before execute_callback, so run() is never
 *   reached for a refused call. check() prepares the input the same way run() does, and authorizes it. It logs a
 *   refusal as one blocked entry and returns the refusal even if that write fails. It logs nothing when the call is
 *   allowed, because run() logs the outcome then. check() never runs guardrails and never writes a reservation: those
 *   belong to run().
 *
 * A successful call to a read-only ability that needs steadhand_read is not logged by default. The steadhand_log_read_calls
 * filter turns that on. Refused and failed read calls are always logged.
 *
 * Pipeline::is_running() is true from the start of authorize() until the end of execute() in run(), and around
 * authorize() in check(). MetaCaps uses it to limit the post capability mapping to Steadhand calls (SPEC §14.1).
 * Pipeline::is_writing() is true only while a write ability's snapshot() or execute() runs. PostWriter and Snapshots
 * refuse to run outside it.
 *
 * Input rejected by core's own schema validation, before the permission callback runs, is not logged. This gap is still
 * open.
 */
final class Pipeline {

	/**
	 * The object recorded for a call whose input did not pass validation, or whose ability names no object.
	 */
	private const NO_TARGET = array(
		'type' => '',
		'id'   => 0,
	);

	/**
	 * One entry per Steadhand call that is inside authorize() or execute() right now: true for a write call, false for a
	 * read call. Changed only by enter() and leave(), which always pair in a finally block, so a nested call cannot
	 * clear the outer call's entry. The innermost call decides: is_write_call() reads the last entry (see the class
	 * comment for the rule).
	 *
	 * @var bool[]
	 */
	private static array $calls = array();

	/**
	 * How many write snapshot() or execute() calls are running right now. Changed only by enter_write() and leave_write().
	 *
	 * @var int
	 */
	private static int $writing = 0;

	/**
	 * Whether a Steadhand call is authorizing or executing an ability right now.
	 *
	 * The metacap mapping (MetaCaps) applies only while this is true (SPEC §14.1, D6). It is true only between
	 * enter() and leave(), which run() and check() call around authorize() and execute(). Nothing else can set it.
	 *
	 * @return bool
	 */
	public static function is_running(): bool {
		// A wp_die() or exit inside an ability can leave a call entered while shutdown hooks run. Those hooks must not
		// see a Steadhand call in progress, so the mapping is off once shutdown has begun.
		return array() !== self::$calls && ! self::shutting_down();
	}

	/**
	 * Whether the innermost Steadhand call is a write call, and is authorizing or executing right now.
	 *
	 * The mapping of edit_post and publish_post (MetaCaps) applies only while this is true. It follows the innermost call:
	 * a read call nested in a write call answers false, and a write call nested in a read call answers true. It is false
	 * after every exit path, and once shutdown has begun, as is_running().
	 *
	 * @return bool
	 */
	public static function is_write_call(): bool {
		return array() !== self::$calls && true === end( self::$calls ) && ! self::shutting_down();
	}

	/**
	 * Whether a write ability is taking a snapshot or executing right now. PostWriter and Snapshots require it.
	 *
	 * True only between enter_write() and leave_write(), and never once shutdown has begun, as is_running().
	 *
	 * @return bool
	 */
	public static function is_writing(): bool {
		return self::$writing > 0 && ! self::shutting_down();
	}

	/**
	 * Whether a call to this ability only reads. Both must hold: the ability's readonly annotation is true, and it needs
	 * the steadhand_read capability. An annotation alone does not make a call a read, so a write-capability call is never
	 * treated as one.
	 *
	 * @param Ability $ability Ability to check.
	 * @return bool
	 */
	public static function is_read_only_ability( Ability $ability ): bool {
		return true === ( $ability->annotations()['readonly'] ?? false ) && Capabilities::READ === $ability->capability();
	}

	/**
	 * Whether WordPress has begun shutting down.
	 *
	 * @return bool
	 */
	private static function shutting_down(): bool {
		return doing_action( 'shutdown' ) || did_action( 'shutdown' ) > 0;
	}

	/**
	 * Marks the start of a call's authorize or execute step.
	 *
	 * @param bool $write True when the call is a write (not a read-only ability).
	 */
	private static function enter( bool $write ): void {
		self::$calls[] = $write;
	}

	/**
	 * Marks the end of a call's authorize or execute step. Always paired with enter() in a finally block.
	 */
	private static function leave(): void {
		array_pop( self::$calls );
	}

	/**
	 * Marks the start of a write's snapshot or execute step.
	 */
	private static function enter_write(): void {
		++self::$writing;
	}

	/**
	 * Marks the end of a write's snapshot or execute step. Always paired with enter_write() in a finally block.
	 */
	private static function leave_write(): void {
		if ( self::$writing > 0 ) {
			--self::$writing;
		}
	}

	/**
	 * The guardrail engine that every write runs through.
	 *
	 * @var Engine
	 */
	private Engine $engine;

	/**
	 * Constructor.
	 *
	 * @param Logger      $logger Where entries are written.
	 * @param Engine|null $engine The guardrails for writes. Null means no rules: an empty engine.
	 */
	public function __construct( private Logger $logger, ?Engine $engine = null ) {
		$this->engine = $engine ?? new Engine( array(), array() );
	}

	/**
	 * Checks whether the current user may call an ability with this input.
	 *
	 * The order is fixed (SPEC §5): the caller's identity and Steadhand capabilities first, then the ability's own
	 * input rule, then the object check. A caller who lacks the capability therefore learns nothing from the input
	 * rule. Does not log. Uses Capabilities::user_has() for Steadhand capabilities, never current_user_can().
	 *
	 * @param Ability              $ability Ability being called.
	 * @param array<string, mixed> $input   Validated input.
	 * @return bool|\WP_Error True if allowed. Otherwise a steadhand_forbidden WP_Error (status 403), or the
	 *                        steadhand_invalid_input WP_Error from validate_input().
	 */
	public function authorize( Ability $ability, array $input ): bool|\WP_Error {
		$caller = $this->authorize_caller( $ability );
		if ( is_wp_error( $caller ) ) {
			return $caller;
		}

		return $this->check_object( $ability, $input );
	}

	/**
	 * Runs the ability's input rule and then its object check. Shared by authorize() and by the revalidation of a
	 * guardrail's fix, so that a fixed input meets the same rules as the original.
	 *
	 * @param Ability              $ability Ability being called.
	 * @param array<string, mixed> $input  Validated input.
	 * @return bool|\WP_Error
	 */
	private function check_object( Ability $ability, array $input ): bool|\WP_Error {
		$checked = $ability->validate_input( $input );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		// One text for every object refusal (missing, trashed, disallowed type, no access), so a caller cannot use
		// the refusal to learn which IDs exist. Do not add detail here.
		if ( ! $ability->authorize_object( $input ) ) {
			return $this->object_refusal();
		}

		return true;
	}

	/**
	 * Checks who is calling and whether they hold the Steadhand capabilities for the ability.
	 *
	 * @param Ability $ability Ability being called.
	 * @return bool|\WP_Error True if the caller may proceed, otherwise a steadhand_forbidden WP_Error.
	 */
	private function authorize_caller( Ability $ability ): bool|\WP_Error {
		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return $this->forbidden( __( 'You must be signed in as an agent user to use Steadhand.', 'steadhand' ) );
		}

		if ( ! Agents::is_agent( $user ) ) {
			return $this->forbidden(
				__( 'This user is not a dedicated Steadhand agent. Only a user whose sole role is steadhand_agent can use Steadhand.', 'steadhand' )
			);
		}

		if ( ! Capabilities::user_has( $user, Capabilities::CONNECT ) ) {
			return $this->forbidden(
				/* translators: %s: Steadhand capability name, e.g. steadhand_connect. */
				sprintf( __( 'This user is not connected to Steadhand. It needs the %s capability.', 'steadhand' ), Capabilities::CONNECT )
			);
		}

		$capabilities = $this->capabilities_for( $ability );
		$held         = array_filter(
			$capabilities,
			static fn( string $capability ): bool => Capabilities::user_has( $user, $capability )
		);
		if ( array() === $held ) {
			return $this->forbidden(
				/* translators: 1: Steadhand capability names joined by " or ", e.g. steadhand_read. 2: Ability name, e.g. steadhand/get-site-info. */
				sprintf( __( 'This user needs the %1$s capability to call %2$s.', 'steadhand' ), implode( ' or ', $capabilities ), $ability->name() )
			);
		}

		return true;
	}

	/**
	 * The Steadhand capabilities that let a caller reach an ability's object check: the ability's capabilities_any(), with
	 * every name that is not a known Steadhand capability removed. An empty result, or a result that is not a list, falls
	 * back to the ability's capability().
	 *
	 * @param Ability $ability Ability being called.
	 * @return string[] Non-empty list of Steadhand capability names.
	 */
	private function capabilities_for( Ability $ability ): array {
		$declared = $ability->capabilities_any();
		$known    = array();
		if ( is_array( $declared ) ) {
			foreach ( $declared as $capability ) {
				if ( is_string( $capability ) && in_array( $capability, Capabilities::all(), true ) && ! in_array( $capability, $known, true ) ) {
					$known[] = $capability;
				}
			}
		}

		return array() === $known ? array( $ability->capability() ) : $known;
	}

	/**
	 * Permission-callback entry point: authorizes a call and logs a refusal.
	 *
	 * Logs one blocked entry when the call is refused, and nothing when it is allowed.
	 * The refusal is returned whether or not the entry was stored.
	 *
	 * @param Ability              $ability Ability being called.
	 * @param array<string, mixed> $input   Validated input.
	 * @return bool|\WP_Error True if allowed, otherwise the steadhand_forbidden WP_Error from authorize().
	 */
	public function check( Ability $ability, array $input ): bool|\WP_Error {
		$prepared = $this->prepare( $ability, $input );
		if ( is_wp_error( $prepared ) ) {
			$this->write_entry( $ability, Entry::RESULT_BLOCKED, $prepared->get_error_message(), $this->loggable( $ability, $input ) );
			return $prepared;
		}

		self::enter( ! self::is_read_only_ability( $ability ) );
		try {
			$authorized = $this->authorize( $ability, $prepared );
		} finally {
			self::leave();
		}

		if ( is_wp_error( $authorized ) ) {
			$this->write_entry( $ability, Entry::RESULT_BLOCKED, $authorized->get_error_message(), $this->loggable( $ability, $prepared ), $this->target_for( $ability, $prepared ) );
			return $authorized;
		}

		return true;
	}

	/**
	 * Runs an ability call through the pipeline.
	 *
	 * @param Ability $ability Ability being called.
	 * @param mixed   $input   Raw input from the caller. Null means no arguments.
	 * @return mixed The ability's output, or a WP_Error describing why the call was refused or failed.
	 */
	public function run( Ability $ability, mixed $input ): mixed {
		// (a) Normalize. (b) Fill defaults, validate against the strict schema, then sanitize.
		$input = $input ?? array();
		if ( ! is_array( $input ) ) {
			return $this->finish(
				$ability,
				Entry::RESULT_BLOCKED,
				new \WP_Error( 'steadhand_invalid_input', __( 'The input must be an object.', 'steadhand' ), array( 'status' => 400 ) )
			);
		}

		$prepared = $this->prepare( $ability, $input );
		if ( is_wp_error( $prepared ) ) {
			return $this->finish( $ability, Entry::RESULT_BLOCKED, $prepared, $this->loggable( $ability, $input ) );
		}

		$target = $this->target_for( $ability, $prepared );

		// The context flag is set from authorize() to the end of execute(), including the log write. It is cleared on
		// every exit path.
		self::enter( ! self::is_read_only_ability( $ability ) );
		try {
			// (c) Authorize.
			$authorized = $this->authorize( $ability, $prepared );
			if ( is_wp_error( $authorized ) ) {
				return $this->finish( $ability, Entry::RESULT_BLOCKED, $authorized, $this->loggable( $ability, $prepared ), $target );
			}

			if ( ! self::is_read_only_ability( $ability ) ) {
				return $this->run_write( $ability, $prepared );
			}

			// (d) Execute. Never leak an exception message to the caller.
			try {
				$output = $ability->execute( $prepared );
			} catch ( \Throwable ) {
				return $this->finish(
					$ability,
					Entry::RESULT_ERROR,
					$this->generic_error(),
					$this->loggable( $ability, $prepared ),
					$target
				);
			}

			if ( is_wp_error( $output ) ) {
				return $this->finish( $ability, Entry::RESULT_ERROR, $output, $this->loggable( $ability, $prepared ), $target );
			}

			// (e) and (f) Log exactly once, then return the outcome only if it was logged.
			return $this->finish( $ability, Entry::RESULT_OK, $output, $this->loggable( $ability, $prepared ), $target );
		} finally {
			self::leave();
		}
	}

	/**
	 * Runs a write call after it has been authorized: guardrails, log reservation, snapshot, execute, log completion.
	 *
	 * Called only from run(), inside the enter() bracket.
	 *
	 * @param Ability              $ability Ability being called.
	 * @param array<string, mixed> $input   Prepared input, as the caller sent it.
	 * @return mixed The ability's output with guardrail_notes added, or a WP_Error.
	 */
	private function run_write( Ability $ability, array $input ): mixed {
		// (1) Guardrails. The verdict decides whether the call goes on, and with which input.
		$context = $this->context_for( $ability, $input );
		if ( $context instanceof \WP_Error ) {
			return $this->refuse_write(
				$ability,
				$input,
				$this->target_for( $ability, $input ),
				$context->get_error_message(),
				array( __( 'The content of this call could not be read for the guardrails.', 'steadhand' ) )
			);
		}

		$latest     = $context;
		$revalidate = function ( array $fixed ) use ( $ability, &$latest ): Context|\WP_Error {
			$prepared = $this->prepare( $ability, $fixed );
			if ( is_wp_error( $prepared ) ) {
				return $prepared;
			}

			$allowed = $this->check_object( $ability, $prepared );
			if ( is_wp_error( $allowed ) ) {
				return $allowed;
			}

			$revised = $this->context_for( $ability, $prepared );
			if ( $revised instanceof Context ) {
				$latest = $revised;
			}

			return $revised;
		};

		$verdict = $this->engine->evaluate( $context, $revalidate );
		if ( $verdict->blocked ) {
			// The object the caller named. A guardrail rewrite that moved the call is refused, and logged under the caller's object.
			return $this->refuse_write( $ability, $input, $this->target_for( $ability, $input ), $verdict->message, $verdict->notes );
		}

		$final_input = $verdict->input;
		$target      = array(
			'type' => $latest->object_type,
			'id'   => $latest->object_id,
		);
		$notes       = $verdict->notes;
		$outcome     = $verdict->fixed ? Entry::RESULT_FIXED : Entry::RESULT_OK;

		// (2) Reserve the log row. No row, no write.
		$logged = $this->loggable( $ability, $final_input );
		$id     = $this->reserve( $this->entry_for( $ability, Entry::RESULT_ERROR, '', $logged, $target, $this->join_notes( $notes ) ) );
		if ( 0 === $id ) {
			return $this->log_failed();
		}

		// (3) Snapshot, immediately before execute(). A throw means nothing was changed, so the row is completed as an
		// error and execute() does not run.
		try {
			$snapshot = $this->within_write(
				fn(): array => $this->snapshot_for( $ability, $final_input )
			);
		} catch ( \Throwable ) {
			$error = $this->generic_error();
			return $this->complete_write( $id, $ability, Entry::RESULT_ERROR, $error, $logged, $target, $notes, 0, false )
				? $error
				: $this->log_incomplete();
		}

		// (4) Execute with the final input.
		try {
			$output = $this->within_write(
				fn(): mixed => $ability->execute( $final_input )
			);
		} catch ( \Throwable ) {
			$error = $this->write_error();
			return $this->complete_write( $id, $ability, Entry::RESULT_ERROR, $error, $logged, $target, $notes, $snapshot['id'], false )
				? $error
				: $this->log_incomplete();
		}

		// (5) Complete the row with the outcome.
		if ( is_wp_error( $output ) ) {
			return $this->complete_write( $id, $ability, Entry::RESULT_ERROR, $output, $logged, $target, $notes, $snapshot['id'], false )
				? $output
				: $this->log_incomplete();
		}

		$object = $this->object_after( $ability, $final_input, $output, $target );
		if ( ! $this->complete_write( $id, $ability, $outcome, null, $logged, $object, $notes, $snapshot['id'], $snapshot['undoable'] ) ) {
			return $this->log_incomplete();
		}

		// (6) Return the output, with what the guardrails said.
		if ( is_array( $output ) ) {
			$output['guardrail_notes'] = $notes;
		}

		return $output;
	}

	/**
	 * Fills defaults, validates against the input schema, then sanitizes, so the ability sees the same shapes from
	 * run() and from check(). The ability's own input rules (validate_input()) run later, inside authorize(), after the
	 * caller's capabilities are checked.
	 *
	 * Core's WP_Ability::normalize_input() fills a default only when the whole input is null, and check() gets input
	 * that core has not defaulted. So defaults are filled here, for top-level properties that declare one and are
	 * absent. rest_validate_value_from_schema() runs before rest_sanitize_value_from_schema(), so refused input is
	 * never sanitized and the result is always a sanitized copy of valid input.
	 *
	 * @param Ability              $ability Ability whose schema applies.
	 * @param array<string, mixed> $input   Input from the caller.
	 * @return array<string, mixed>|\WP_Error The sanitized input, or a steadhand_invalid_input WP_Error (status 400).
	 */
	private function prepare( Ability $ability, array $input ): array|\WP_Error {
		$schema = $ability->input_schema();

		foreach ( (array) ( $schema['properties'] ?? array() ) as $name => $property ) {
			if ( is_array( $property ) && array_key_exists( 'default', $property ) && ! array_key_exists( $name, $input ) ) {
				$input[ $name ] = $property['default'];
			}
		}

		$valid = rest_validate_value_from_schema( $input, $schema, 'input' );
		if ( is_wp_error( $valid ) ) {
			return new \WP_Error( 'steadhand_invalid_input', $valid->get_error_message(), array( 'status' => 400 ) );
		}

		$sanitized = rest_sanitize_value_from_schema( $input, $schema, 'input' );
		if ( is_wp_error( $sanitized ) || ! is_array( $sanitized ) ) {
			return new \WP_Error( 'steadhand_invalid_input', __( 'The input could not be sanitized.', 'steadhand' ), array( 'status' => 400 ) );
		}

		return $sanitized;
	}

	/**
	 * Builds the guardrail context for a write. Returns a WP_Error, which blocks the call, when the ability's content
	 * cannot be read or is not text (fail closed).
	 *
	 * @param Ability              $ability Ability being called.
	 * @param array<string, mixed> $input   Prepared input.
	 * @return Context|\WP_Error
	 */
	private function context_for( Ability $ability, array $input ): Context|\WP_Error {
		$target = $this->target_for( $ability, $input );

		try {
			$content = $ability->guarded_content( $input );
		} catch ( \Throwable ) {
			return $this->guard_unavailable();
		}

		foreach ( $content as $markup ) {
			if ( ! is_string( $markup ) ) {
				return $this->guard_unavailable();
			}
		}

		return new Context(
			$ability->name(),
			$ability->capability(),
			get_current_user_id(),
			$input,
			$target['type'],
			$target['id'],
			$content
		);
	}

	/**
	 * Takes the snapshot for a write, and coerces what the ability returned like target_for(): a malformed snapshot is
	 * replaced by "nothing saved".
	 *
	 * @param Ability              $ability Ability being called.
	 * @param array<string, mixed> $input   Final input.
	 * @return array{id: int, undoable: bool}
	 */
	private function snapshot_for( Ability $ability, array $input ): array {
		$snapshot = $ability->snapshot( $input );
		$id       = $snapshot['id'] ?? 0;
		$id       = is_int( $id ) && $id > 0 ? $id : 0;
		$undoable = ( $snapshot['undoable'] ?? false ) === true && $id > 0;

		return array(
			'id'       => $id,
			'undoable' => $undoable,
		);
	}

	/**
	 * The object a completed write names: the ability's logged_object_after() for an array output, otherwise the
	 * object the call named before it ran. A throw keeps the object the call named before it ran.
	 *
	 * @param Ability                      $ability Ability being called.
	 * @param array<string, mixed>         $input   Final input.
	 * @param mixed                        $output  What execute() returned.
	 * @param array{type: string, id: int} $before The object before execute().
	 * @return array{type: string, id: int}
	 */
	private function object_after( Ability $ability, array $input, mixed $output, array $before ): array {
		if ( ! is_array( $output ) ) {
			return $before;
		}

		try {
			return $this->coerce_object( $ability->logged_object_after( $input, $output ) );
		} catch ( \Throwable ) {
			return $before;
		}
	}

	/**
	 * Refuses a write at the guardrails: writes one blocked entry and returns the refusal. A failed entry means the
	 * refusal is steadhand_log_failed instead, as for every other refused call.
	 *
	 * @param Ability                      $ability Ability being called.
	 * @param array<string, mixed>         $input   Prepared input, as the caller sent it.
	 * @param array{type: string, id: int} $target  The object the call is about.
	 * @param string                       $message Refusal text.
	 * @param string[]                     $notes   What the guardrails recorded.
	 * @return \WP_Error
	 */
	private function refuse_write( Ability $ability, array $input, array $target, string $message, array $notes ): \WP_Error {
		$entry = $this->entry_for( $ability, Entry::RESULT_BLOCKED, $message, $this->loggable( $ability, $input ), $target, $this->join_notes( $notes ) );
		if ( ! $this->store( $entry ) ) {
			return $this->log_failed();
		}

		return new \WP_Error( 'steadhand_guardrail_blocked', $message, array( 'status' => 422 ) );
	}

	/**
	 * Writes the reservation row for a write. Treats a throw as a failed write.
	 *
	 * @param Entry $entry The call, with its outcome still to come.
	 * @return int The row ID, or 0.
	 */
	private function reserve( Entry $entry ): int {
		try {
			return $this->logger->begin( $entry );
		} catch ( \Throwable ) {
			return 0;
		}
	}

	/**
	 * Records the outcome of a reserved write. Returns false if the outcome was not recorded.
	 *
	 * @param int                          $id          Row ID from reserve().
	 * @param Ability                      $ability     Ability that ran.
	 * @param string                       $result      One of the Entry::RESULT_* constants.
	 * @param mixed                        $outcome     The error, for an error outcome. Null otherwise.
	 * @param array<string, mixed>         $input       Final input.
	 * @param array{type: string, id: int} $target      The object.
	 * @param string[]                     $notes       Guardrail notes.
	 * @param int                          $snapshot_id Snapshot ID, or 0.
	 * @param bool                         $undoable    Whether the call can be undone.
	 * @return bool
	 */
	private function complete_write( int $id, Ability $ability, string $result, mixed $outcome, array $input, array $target, array $notes, int $snapshot_id, bool $undoable ): bool {
		$message = is_wp_error( $outcome ) ? $outcome->get_error_message() : '';
		$entry   = $this->entry_for( $ability, $result, $message, $input, $target, $this->join_notes( $notes ), $snapshot_id, $undoable );

		try {
			return true === $this->logger->complete( $id, $entry );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Returns the output of a snapshot or execute step, run inside the write bracket. Exceptions propagate.
	 *
	 * @param callable $work The step.
	 * @return mixed What the step returned.
	 */
	private function within_write( callable $work ): mixed {
		self::enter_write();
		try {
			return $work();
		} finally {
			self::leave_write();
		}
	}

	/**
	 * Joins the guardrail notes into the single text the log stores. An empty list gives an empty string.
	 *
	 * @param string[] $notes Notes.
	 * @return string
	 */
	private function join_notes( array $notes ): string {
		return implode( "\n", $notes );
	}

	/**
	 * Coerces a target like target_for(): a non-array becomes the empty object; the type must be a string and the ID an
	 * integer of at least 0. Anything else is replaced by its default.
	 *
	 * @param mixed $target The object as the ability returned it.
	 * @return array{type: string, id: int}
	 */
	private function coerce_object( mixed $target ): array {
		if ( ! is_array( $target ) ) {
			return self::NO_TARGET;
		}

		$type = $target['type'] ?? '';
		$id   = $target['id'] ?? 0;

		return array(
			'type' => is_string( $type ) ? $type : '',
			'id'   => is_int( $id ) && $id >= 0 ? $id : 0,
		);
	}

	/**
	 * Names the object a call is about, for the change log. An ability that throws, or that
	 * returns a malformed object, gets the empty object, so the entry is still written. The type must be a string and the
	 * ID an integer of at least 0; any other value is replaced by its default.
	 *
	 * @param Ability              $ability Ability that was called.
	 * @param array<string, mixed> $input   Validated, sanitized input.
	 * @return array{type: string, id: int}
	 */
	private function target_for( Ability $ability, array $input ): array {
		try {
			return $this->coerce_object( $ability->log_object( $input ) );
		} catch ( \Throwable ) {
			return self::NO_TARGET;
		}
	}

	/**
	 * Decides whether an outcome is logged. Every outcome is, except a successful read: a successful call to an ability
	 * that is read-only and needs steadhand_read (see is_read_only_ability()). Such a call is logged only when the
	 * steadhand_log_read_calls filter returns exactly true. A filter that throws counts as false.
	 *
	 * @param Ability $ability Ability that was called.
	 * @param string  $result  One of the Entry::RESULT_* constants.
	 * @return bool
	 */
	private function should_log( Ability $ability, string $result ): bool {
		if ( Entry::RESULT_OK !== $result || ! self::is_read_only_ability( $ability ) ) {
			return true;
		}

		try {
			return true === apply_filters( 'steadhand_log_read_calls', false, $ability->name() );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Writes the log entry for this outcome and returns the outcome, or a log failure.
	 *
	 * When should_log() says the outcome is not logged, returns the outcome without writing anything.
	 *
	 * @param Ability                      $ability Ability that was called.
	 * @param string                       $result  One of the Entry::RESULT_* constants.
	 * @param mixed                        $outcome Output or WP_Error to return if the entry is stored.
	 * @param array<string, mixed>         $input   The input to record (see run()).
	 * @param array{type: string, id: int} $target  The object the call is about, or the empty target.
	 * @return mixed $outcome, or a steadhand_log_failed WP_Error if the entry was not stored.
	 */
	private function finish( Ability $ability, string $result, mixed $outcome, array $input = array(), array $target = self::NO_TARGET ): mixed {
		if ( ! $this->should_log( $ability, $result ) ) {
			return $outcome;
		}

		$message = is_wp_error( $outcome ) ? $outcome->get_error_message() : '';

		if ( ! $this->write_entry( $ability, $result, $message, $input, $target ) ) {
			return $this->log_failed();
		}

		return $outcome;
	}

	/**
	 * Builds and stores the log entry. Treats a thrown exception as a failed write.
	 *
	 * @param Ability                      $ability Ability that was called.
	 * @param string                       $result  One of the Entry::RESULT_* constants.
	 * @param string                       $message Error message, or ''.
	 * @param array<string, mixed>         $input   The input to record.
	 * @param array{type: string, id: int} $target  The object the call is about, or the empty target.
	 * @return bool True only if the logger reported success.
	 */
	private function write_entry( Ability $ability, string $result, string $message, array $input = array(), array $target = self::NO_TARGET ): bool {
		return $this->store( $this->entry_for( $ability, $result, $message, $input, $target ) );
	}

	/**
	 * Stores one entry with log(). Treats a thrown exception as a failed write.
	 *
	 * @param Entry $entry Entry to store.
	 * @return bool
	 */
	private function store( Entry $entry ): bool {
		try {
			return true === $this->logger->log( $entry );
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Builds the entry for one call, for the calling user.
	 *
	 * @param Ability                      $ability     Ability that was called.
	 * @param string                       $result      One of the Entry::RESULT_* constants.
	 * @param string                       $message     Error message, or ''.
	 * @param array<string, mixed>         $input       The input to record.
	 * @param array{type: string, id: int} $target      The object the call is about.
	 * @param string                       $notes       Guardrail notes, joined, or ''.
	 * @param int                          $snapshot_id The snapshot ID, or 0.
	 * @param bool                         $undoable    Whether the call can be undone.
	 * @return Entry
	 */
	private function entry_for( Ability $ability, string $result, string $message, array $input = array(), array $target = self::NO_TARGET, string $notes = '', int $snapshot_id = 0, bool $undoable = false ): Entry {
		$user_id = get_current_user_id();

		return new Entry(
			$user_id,
			$this->agent_label( $user_id ),
			$ability->name(),
			$result,
			$message,
			$input,
			(string) $target['type'],
			(int) $target['id'],
			$notes,
			$snapshot_id,
			$undoable
		);
	}

	/**
	 * Returns the name of the application password used for this request.
	 *
	 * @param int $user_id Agent user ID.
	 * @return string The application password name, or '' if the request did not use one.
	 */
	private function agent_label( int $user_id ): string {
		$uuid = rest_get_authenticated_app_password();
		if ( ! is_string( $uuid ) || '' === $uuid ) {
			return '';
		}

		$item = \WP_Application_Passwords::get_user_application_password( $user_id, $uuid );
		return (string) ( $item['name'] ?? '' );
	}

	/**
	 * The refusal for a call whose object is missing, trashed, of a disallowed type, or not accessible.
	 *
	 * @return \WP_Error
	 */
	private function object_refusal(): \WP_Error {
		return $this->forbidden( __( 'Not found, or this agent may not access it.', 'steadhand' ) );
	}

	/**
	 * The input to store in the change log for a call whose input passed prepare(). An ability may replace large fields
	 * (see Ability::log_input()). A throw, or a value that is not an array, stores the empty input instead, so the entry is
	 * still written.
	 *
	 * @param Ability              $ability Ability that was called.
	 * @param array<string, mixed> $input   Prepared input.
	 * @return array<string, mixed>
	 */
	private function loggable( Ability $ability, array $input ): array {
		try {
			$logged = $ability->log_input( $input );
		} catch ( \Throwable ) {
			return array();
		}

		return is_array( $logged ) ? $logged : array();
	}

	/**
	 * The error for a write whose execute() threw. The post may have been partly changed, so the caller must check it.
	 *
	 * @return \WP_Error
	 */
	private function write_error(): \WP_Error {
		return new \WP_Error(
			'steadhand_error',
			__( 'The change could not be completed and may have been partly applied. Check the result before you continue.', 'steadhand' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * The generic error for an ability that failed. It never carries the exception's text.
	 *
	 * @return \WP_Error
	 */
	private function generic_error(): \WP_Error {
		return new \WP_Error( 'steadhand_error', __( 'The ability failed to complete. Try again, or check the site error log.', 'steadhand' ), array( 'status' => 500 ) );
	}

	/**
	 * The refusal for a write that could not be recorded in the log before it ran, so it did not run.
	 *
	 * @return \WP_Error
	 */
	private function log_failed(): \WP_Error {
		return new \WP_Error( 'steadhand_log_failed', __( 'The action was not performed because it could not be recorded in the change log.', 'steadhand' ), array( 'status' => 500 ) );
	}

	/**
	 * The error for a write that ran, but whose outcome could not be recorded in the log.
	 *
	 * @return \WP_Error
	 */
	private function log_incomplete(): \WP_Error {
		return new \WP_Error( 'steadhand_log_incomplete', __( 'The change was made, but its outcome could not be recorded in the change log. Check the result before you continue.', 'steadhand' ), array( 'status' => 500 ) );
	}

	/**
	 * The refusal for a guardrail that could not read a call. It is the same text the engine uses.
	 *
	 * @return \WP_Error
	 */
	private function guard_unavailable(): \WP_Error {
		return new \WP_Error( 'steadhand_guardrail_blocked', __( 'A guardrail could not check this call, so it was refused.', 'steadhand' ), array( 'status' => 422 ) );
	}

	/**
	 * Builds a steadhand_forbidden error.
	 *
	 * @param string $message Message for the caller.
	 * @return \WP_Error
	 */
	private function forbidden( string $message ): \WP_Error {
		return new \WP_Error( 'steadhand_forbidden', $message, array( 'status' => 403 ) );
	}
}
