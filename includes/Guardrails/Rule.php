<?php
/**
 * Contract for a guardrail rule: one check that runs before a write.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Guardrails;

defined( 'ABSPATH' ) || exit;

/**
 * A guardrail rule. Public API: add-ons implement it and add rules through the steadhand_register_guardrails action
 * (docs/HOOKS.md).
 *
 * A rule must not change the call itself. It returns a Result, and the engine acts on it. A rule that throws, or that
 * returns anything other than a Result, blocks the call.
 */
interface Rule {

	/**
	 * Returns the rule's ID, in the form vendor/name: lowercase letters, digits and hyphens. Built-in IDs start with
	 * steadhand/. A third-party ID must not.
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Checks one call.
	 *
	 * @param Context $context The call, with the input as the rules before this one left it.
	 * @return Result What to do with the call.
	 */
	public function evaluate( Context $context ): Result;
}
