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
	 * Returns the Steadhand capabilities of which the caller must hold at least one to call this ability.
	 *
	 * The caller must hold at least one of these. The object check then decides which one this call actually needs. An
	 * ability whose rule depends on the object (for example, a post that is a draft needs steadhand_write_drafts and a
	 * published post needs steadhand_publish) overrides this. The default is capability() alone.
	 *
	 * @return string[] Steadhand capability names.
	 */
	public function capabilities_any(): array {
		return array( $this->capability() );
	}

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
	 * Returns a more specific refusal for a call whose object check failed.
	 *
	 * Called only after authorize_object() returned false. An ability may return a steadhand_forbidden WP_Error that says
	 * why the object check failed. It must return null unless the detail reveals nothing the caller could not already
	 * read through a read tool. The default returns null, so the generic "Not found" refusal is used.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return \WP_Error|null The refusal to return instead of the generic one, or null for the generic one.
	 */
	public function object_refusal_detail( array $input ): ?\WP_Error {
		unset( $input );
		return null;
	}

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
	 * Names the object a call is about once it has run, for the change log. Only write tools that cannot name the object
	 * before they run need this. Create is the example: the new post's ID exists only after execute().
	 *
	 * The default returns log_object( $input ). Called only when execute() returned an array, with the output it returned.
	 *
	 * @param array<string, mixed> $input  Validated, sanitized input.
	 * @param array<string, mixed> $output The output execute() returned.
	 * @return array{type: string, id: int} The object type and its ID.
	 */
	public function logged_object_after( array $input, array $output ): array {
		unset( $output );
		return $this->log_object( $input );
	}

	/**
	 * The copy of the input kept in the change log. The default is the input itself. A write ability that stores a large
	 * field (post content, for one) returns a short summary of it instead, so that the log is not a second copy of the
	 * content. The revisions hold the content. Called with prepared input, or, when prepare() refused the call, with the
	 * input as the caller sent it. So an implementation must accept any value in any field, and must not keep a text
	 * field that it would replace with a digest once it is prepared.
	 *
	 * @param array<string, mixed> $input Prepared input, or the input as sent.
	 * @return array<string, mixed> The input to store in the change log.
	 */
	public function log_input( array $input ): array {
		return $input;
	}

	/**
	 * Names the input fields that will be stored as post content, so that the content guardrails can check them.
	 *
	 * Each value is block markup the call will store. The default returns no fields, so a read ability never needs this.
	 * A write ability that stores content must return every such field, or the content rules cannot see it.
	 *
	 * @param array<string, mixed> $input Validated, sanitized input.
	 * @return array<string, string> The input fields that will be stored as post content, keyed by field name.
	 */
	public function guarded_content( array $input ): array {
		unset( $input );
		return array();
	}

	/**
	 * Saves what is needed to undo this call. Runs after the call is authorized, guard-railed and reserved in the log,
	 * immediately before execute().
	 *
	 * The default saves nothing: id 0, not undoable. Its result is checked only after it returns; a throw is an error,
	 * and execute() does not run.
	 *
	 * @param array<string, mixed> $input Validated, sanitized input.
	 * @return array{id: int, undoable: bool} The snapshot's ID (0 for none) and whether the call can be undone.
	 */
	public function snapshot( array $input ): array {
		unset( $input );
		return array(
			'id'       => 0,
			'undoable' => false,
		);
	}

	/**
	 * Names the snapshot that holds the object's state after the call. Runs after execute() returned a non-error result,
	 * still inside the write, so the state it reads is the state the call left.
	 *
	 * Only called when snapshot() reported the call as undoable. Returns the ID of the snapshot that holds the object's
	 * state after the call (for posts, a core revision), or 0 for none. A throw is treated as 0. The result is stored with
	 * the change log entry, and the entry is undoable only when this returns an ID other than the snapshot's.
	 *
	 * $snapshot_id is the ID snapshot() returned for this call (the before-state). An ability that names the after-state
	 * should check that it is newer than that and was made by this call.
	 *
	 * @param array<string, mixed> $input       Validated, sanitized input, as execute() received it.
	 * @param mixed                $output      What execute() returned.
	 * @param int                  $snapshot_id The snapshot ID snapshot() returned for this call.
	 * @return int The snapshot ID, or 0.
	 */
	public function snapshot_after( array $input, mixed $output, int $snapshot_id ): int {
		unset( $input, $output, $snapshot_id );
		return 0;
	}

	/**
	 * Names the direct call that this call amounts to, for the guardrails. Runs inside the write, after the guardrails have
	 * evaluated the call as itself and passed it. When it returns an array, the guardrails evaluate that direct call too, with
	 * its ability name, capability, input and content, on the same user and object, and the call is refused when any rule blocks
	 * that call or rewrites it.
	 *
	 * The default returns null: the call is evaluated once, as itself. Only an ability that does something else with the same
	 * effect (undo-change, which puts back what a set-post-terms call did) overrides it. A throw or a malformed value refuses the
	 * call, because the guardrails cannot see it.
	 *
	 * @param array<string, mixed> $input Validated, sanitized input.
	 * @return array{ability: string, capability: string, input: array<string, mixed>, content: array<string, string>}|null
	 */
	public function equivalent_call( array $input ): ?array {
		unset( $input );
		return null;
	}

	/**
	 * Performs the ability. Called only after validation and authorization have passed.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return array<string, mixed>|\WP_Error Output matching output_schema(), or an error.
	 */
	abstract public function execute( array $input ): array|\WP_Error;
}
