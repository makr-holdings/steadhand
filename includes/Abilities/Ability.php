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
	 * Performs the ability. Called only after validation and authorization have passed.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Output matching output_schema(), or an error.
	 */
	abstract public function execute( array $input ): array|\WP_Error;
}
