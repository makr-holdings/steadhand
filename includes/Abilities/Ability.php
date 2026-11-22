<?php
/**
 * Base class for Steadhand abilities.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * One ability: a named, schema-described action an agent may request.
 *
 * Abilities never run on their own. They are invoked through the Pipeline, which
 * validates, authorizes and logs every call.
 */
abstract class Ability {

	/**
	 * Returns the ability name.
	 *
	 * @return string Name in the form steadhand/<verb>-<noun>, e.g. steadhand/get-site-info.
	 */
	abstract public function name(): string;

	/**
	 * Returns the human-readable label.
	 *
	 * @return string Translatable label.
	 */
	abstract public function label(): string;

	/**
	 * Returns the description shown to the agent.
	 *
	 * Written for an LLM: what the ability does, when to use it, and what it refuses.
	 *
	 * @return string Translatable description.
	 */
	abstract public function description(): string;

	/**
	 * Returns the JSON Schema for the input.
	 *
	 * Must be strict: types, enums, maximum lengths and additionalProperties false.
	 *
	 * @return array<string, mixed> JSON Schema.
	 */
	abstract public function input_schema(): array;

	/**
	 * Returns the JSON Schema for the output.
	 *
	 * @return array<string, mixed> JSON Schema.
	 */
	abstract public function output_schema(): array;

	/**
	 * Returns the behavioral annotations.
	 *
	 * @return array{readonly: bool, destructive: bool, idempotent: bool} Annotation flags.
	 */
	abstract public function annotations(): array;

	/**
	 * Returns the Steadhand capability an agent needs to call this ability.
	 *
	 * @return string One of the Steadhand\Capabilities constants.
	 */
	abstract public function capability(): string;

	/**
	 * Checks the native WordPress capability for the specific object the input targets.
	 *
	 * For example, edit_post for a given post ID. Runs after the Steadhand capability check.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return bool True if the current user may act on the object.
	 */
	abstract public function authorize_object( array $input ): bool;

	/**
	 * Checks an input rule that the schema cannot express, such as a value that depends on a filter.
	 *
	 * Runs after the schema check and sanitization, before authorization. A refusal is logged as blocked and names the
	 * rule. The default accepts every input that passed the schema.
	 *
	 * @param array<string, mixed> $input Validated, sanitized input.
	 * @return bool|\WP_Error True to accept, or a steadhand_invalid_input WP_Error that says what is allowed.
	 */
	public function validate_input( array $input ): bool|\WP_Error {
		unset( $input );
		return true;
	}

	/**
	 * Names the object this call is about, for the change log.
	 *
	 * Returns '' and 0 by default, for a call that names no object. An ability that works on one object overrides it.
	 * It is called only with validated, sanitized input.
	 *
	 * @param array<string, mixed> $input Validated, sanitized input.
	 * @return array{type: string, id: int} The object type and its ID.
	 */
	public function log_object( array $input ): array {
		unset( $input );
		return array(
			'type' => '',
			'id'   => 0,
		);
	}

	/**
	 * Performs the ability. Called only after validation and authorization have passed.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Output matching output_schema(), or an error.
	 */
	abstract public function execute( array $input ): array|\WP_Error;
}
