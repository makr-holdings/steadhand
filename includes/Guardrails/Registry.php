<?php
/**
 * The list of third-party guardrail rules, as an add-on sees it while registering.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Guardrails;

defined( 'ABSPATH' ) || exit;

/**
 * Handed to listeners of the steadhand_register_guardrails action. Public API.
 */
final class Registry {

	/** Shape of a rule ID: vendor/name in lowercase letters, digits and hyphens. */
	private const ID_PATTERN = '/^[a-z0-9-]{1,40}\/[a-z0-9-]{1,60}\z/';

	/**
	 * Rules in the order they were added.
	 *
	 * @var Rule[]
	 */
	private array $rules = array();

	/**
	 * Adds a rule.
	 *
	 * Returns false, and adds nothing, when the rule's ID is malformed, starts with steadhand/ (reserved for built-in
	 * rules), is already used, or cannot be read. Never throws.
	 *
	 * @param Rule $rule The rule.
	 * @return bool True if the rule was added.
	 */
	public function add( Rule $rule ): bool {
		try {
			$id = $rule->id();
		} catch ( \Throwable ) {
			return false;
		}

		if ( 1 !== preg_match( self::ID_PATTERN, $id ) || str_starts_with( $id, 'steadhand/' ) ) {
			return false;
		}

		foreach ( $this->rules as $existing ) {
			if ( $this->id_of( $existing ) === $id ) {
				return false;
			}
		}

		$this->rules[] = $rule;

		return true;
	}

	/**
	 * The rules added so far, in the order they were added.
	 *
	 * @return Rule[]
	 */
	public function rules(): array {
		return $this->rules;
	}

	/**
	 * The ID of a rule that is already in the list.
	 *
	 * @param Rule $rule A rule that was accepted by add().
	 * @return string
	 */
	private function id_of( Rule $rule ): string {
		try {
			return $rule->id();
		} catch ( \Throwable ) {
			return '';
		}
	}
}
