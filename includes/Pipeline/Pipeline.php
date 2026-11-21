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
 * - run() is the full path: normalize, validate input, authorize, execute, log. Every
 *   outcome it reaches writes exactly one log entry (ok, blocked or error). If that
 *   entry cannot be written, the result is withheld and the call fails with
 *   steadhand_log_failed; logging never fails open.
 * - check() is the permission callback path. Core and the MCP Adapter call it before
 *   execute_callback, so run() is never reached for a refused call. check() logs a
 *   refusal as one blocked entry and returns the refusal even if that write fails.
 *   It logs nothing when the call is allowed, because run() logs the outcome then.
 *
 * Input rejected by core's own schema validation, before the permission callback runs,
 * is not logged yet. That gap closes in M2.
 *
 * Guardrails and snapshot stages are added in M2, between authorize and execute.
 */
final class Pipeline {

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Where entries are written.
	 */
	public function __construct( private Logger $logger ) {}

	/**
	 * Checks whether the current user may call an ability with this input.
	 *
	 * Does not log. Uses Capabilities::user_has() for Steadhand capabilities, never current_user_can().
	 *
	 * @param Ability              $ability Ability being called.
	 * @param array<string, mixed> $input   Validated input.
	 * @return bool|\WP_Error True if allowed, otherwise a steadhand_forbidden WP_Error (status 403).
	 */
	public function authorize( Ability $ability, array $input ): bool|\WP_Error {
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

		if ( ! $ability->authorize_object( $input ) ) {
			return $this->forbidden( __( 'The site does not allow this user to act on that object.', 'steadhand' ) );
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
		$authorized = $this->authorize( $ability, $input );
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
		// (a) Normalize.
		$input = $input ?? array();
		if ( ! is_array( $input ) ) {
			return $this->finish(
				$ability,
				Entry::RESULT_BLOCKED,
				new \WP_Error( 'steadhand_invalid_input', __( 'The input must be an object.', 'steadhand' ), array( 'status' => 400 ) )
			);
		}

		// (b) Validate against the strict input schema.
		$valid = rest_validate_value_from_schema( $input, $ability->input_schema(), 'input' );
		if ( is_wp_error( $valid ) ) {
			return $this->finish(
				$ability,
				Entry::RESULT_BLOCKED,
				new \WP_Error( 'steadhand_invalid_input', $valid->get_error_message(), array( 'status' => 400 ) )
			);
		}

		// (c) Authorize.
		$authorized = $this->authorize( $ability, $input );
		if ( is_wp_error( $authorized ) ) {
			return $this->finish( $ability, Entry::RESULT_BLOCKED, $authorized );
		}

		// (d) Execute. Never leak an exception message to the caller.
		try {
			$output = $ability->execute( $input );
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
