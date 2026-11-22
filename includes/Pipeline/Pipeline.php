<?php
/**
 * The shared pipeline every ability call goes through: validate, authorize, execute, log.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Pipeline;

use Steadhand\Abilities\Ability;
use Steadhand\Agents;
use Steadhand\Capabilities;
use Steadhand\Log\Entry;
use Steadhand\Log\Logger;

defined( 'ABSPATH' ) || exit;

/**
 * Runs an ability call through the shared pipeline.
 *
 * Two entry points, and each logs what it sees:
 *
 * - run() is the full path: normalize, fill defaults, validate, sanitize, authorize,
 *   execute, log. Every outcome it reaches writes exactly one log entry (ok, blocked or
 *   error). If that entry cannot be written, the result is withheld and the call fails
 *   with steadhand_log_failed; logging never fails open. The ability receives only the
 *   sanitized input.
 * - check() is the permission callback path. Core and the MCP Adapter call it before
 *   execute_callback, so run() is never reached for a refused call. check() prepares the
 *   input the same way run() does, logs a refusal as one blocked entry and returns the
 *   refusal even if that write fails. It logs nothing when the call is allowed, because
 *   run() logs the outcome then.
 *
 * Pipeline::is_running() is true from the start of authorize() until the end of execute()
 * in run(), and around authorize() in check(). MetaCaps uses it to limit the post
 * capability mapping to Steadhand calls (SPEC §14.1).
 *
 * Input rejected by core's own schema validation, before the permission callback runs,
 * is not logged yet. That gap closes in M2.
 *
 * Guardrails and snapshot stages are added in M2, between authorize and execute.
 */
final class Pipeline {

	/**
	 * How many Steadhand calls are inside authorize() or execute() right now. A counter, not a flag, so a nested
	 * call cannot clear the outer call's context. Changed only by enter() and leave().
	 *
	 * @var int
	 */
	private static int $depth = 0;

	/**
	 * Whether a Steadhand call is authorizing or executing an ability right now.
	 *
	 * The metacap mapping (MetaCaps) applies only while this is true (SPEC §14.1, D6). It is true only between
	 * enter() and leave(), which run() and check() call around authorize() and execute(). Nothing else can set it.
	 *
	 * @return bool
	 */
	public static function is_running(): bool {
		// A wp_die() or exit inside an ability can leave the depth set while shutdown hooks run. Those hooks must not
		// see a Steadhand call in progress, so the mapping is off once shutdown has begun.
		return self::$depth > 0 && ! ( doing_action( 'shutdown' ) || did_action( 'shutdown' ) > 0 );
	}

	/**
	 * Marks the start of a call's authorize or execute step.
	 */
	private static function enter(): void {
		++self::$depth;
	}

	/**
	 * Marks the end of a call's authorize or execute step. Always paired with enter() in a finally block.
	 */
	private static function leave(): void {
		if ( self::$depth > 0 ) {
			--self::$depth;
		}
	}

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Where entries are written.
	 */
	public function __construct( private Logger $logger ) {}

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

		$checked = $ability->validate_input( $input );
		if ( is_wp_error( $checked ) ) {
			return $checked;
		}

		// One text for every object refusal (missing, trashed, disallowed type, no access), so a caller cannot use
		// the refusal to learn which IDs exist. Do not add detail here.
		if ( ! $ability->authorize_object( $input ) ) {
			return $this->forbidden( __( 'Not found, or this agent may not access it.', 'steadhand' ) );
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

		$capability = $ability->capability();
		if ( ! Capabilities::user_has( $user, $capability ) ) {
			return $this->forbidden(
				/* translators: 1: Steadhand capability name, e.g. steadhand_read. 2: Ability name, e.g. steadhand/get-site-info. */
				sprintf( __( 'This user needs the %1$s capability to call %2$s.', 'steadhand' ), $capability, $ability->name() )
			);
		}

		return true;
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
			$this->write_entry( $ability, Entry::RESULT_BLOCKED, $prepared->get_error_message() );
			return $prepared;
		}

		self::enter();
		try {
			$authorized = $this->authorize( $ability, $prepared );
		} finally {
			self::leave();
		}

		if ( is_wp_error( $authorized ) ) {
			$this->write_entry( $ability, Entry::RESULT_BLOCKED, $authorized->get_error_message() );
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
			return $this->finish( $ability, Entry::RESULT_BLOCKED, $prepared );
		}

		// The context flag is set from authorize() to the end of execute(), including the log write. It is cleared on
		// every exit path.
		self::enter();
		try {
			// (c) Authorize.
			$authorized = $this->authorize( $ability, $prepared );
			if ( is_wp_error( $authorized ) ) {
				return $this->finish( $ability, Entry::RESULT_BLOCKED, $authorized );
			}

			// (d) Execute. Never leak an exception message to the caller.
			try {
				$output = $ability->execute( $prepared );
			} catch ( \Throwable ) {
				return $this->finish(
					$ability,
					Entry::RESULT_ERROR,
					new \WP_Error( 'steadhand_error', __( 'The ability failed to complete. Try again, or check the site error log.', 'steadhand' ), array( 'status' => 500 ) )
				);
			}

			if ( is_wp_error( $output ) ) {
				return $this->finish( $ability, Entry::RESULT_ERROR, $output );
			}

			// (e) and (f) Log exactly once, then return the outcome only if it was logged.
			return $this->finish( $ability, Entry::RESULT_OK, $output );
		} finally {
			self::leave();
		}
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
	 * Writes the log entry for this outcome and returns the outcome, or a log failure.
	 *
	 * @param Ability $ability Ability that was called.
	 * @param string  $result  One of the Entry::RESULT_* constants.
	 * @param mixed   $outcome Output or WP_Error to return if the entry is stored.
	 * @return mixed $outcome, or a steadhand_log_failed WP_Error if the entry was not stored.
	 */
	private function finish( Ability $ability, string $result, mixed $outcome ): mixed {
		$message = is_wp_error( $outcome ) ? $outcome->get_error_message() : '';

		if ( ! $this->write_entry( $ability, $result, $message ) ) {
			return new \WP_Error( 'steadhand_log_failed', __( 'The action was not performed because it could not be recorded in the change log.', 'steadhand' ), array( 'status' => 500 ) );
		}

		return $outcome;
	}

	/**
	 * Builds and stores the log entry. Treats a thrown exception as a failed write.
	 *
	 * @param Ability $ability Ability that was called.
	 * @param string  $result  One of the Entry::RESULT_* constants.
	 * @param string  $message Error message, or ''.
	 * @return bool True only if the logger reported success.
	 */
	private function write_entry( Ability $ability, string $result, string $message ): bool {
		$user_id = get_current_user_id();
		$entry   = new Entry( $user_id, $this->agent_label( $user_id ), $ability->name(), $result, $message );

		try {
			return true === $this->logger->log( $entry );
		} catch ( \Throwable ) {
			return false;
		}
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
	 * Builds a steadhand_forbidden error.
	 *
	 * @param string $message Message for the caller.
	 * @return \WP_Error
	 */
	private function forbidden( string $message ): \WP_Error {
		return new \WP_Error( 'steadhand_forbidden', $message, array( 'status' => 403 ) );
	}
}
