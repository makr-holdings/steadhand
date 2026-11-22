<?php
/**
 * What a guardrail rule is told about one call.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Guardrails;

defined( 'ABSPATH' ) || exit;

/**
 * One call as the guardrails see it. Public API: rules read it, and never change it.
 */
final class Context {

	/**
	 * Constructor.
	 *
	 * @param string                $ability     Ability name, for example steadhand/update-post.
	 * @param string                $capability  The Steadhand capability the call needs.
	 * @param int                   $user_id     The agent user making the call.
	 * @param array<string, mixed>  $input       The schema-valid, sanitized input.
	 * @param string                $object_type The object the call is about (for example 'post'), or ''.
	 * @param int                   $object_id   The ID of that object, or 0.
	 * @param array<string, string> $content    Input fields that will be stored as post content, keyed by field name, each
	 *                                          holding block markup. May be empty.
	 */
	public function __construct(
		public readonly string $ability,
		public readonly string $capability,
		public readonly int $user_id,
		public readonly array $input,
		public readonly string $object_type,
		public readonly int $object_id,
		public readonly array $content = array()
	) {}
}
