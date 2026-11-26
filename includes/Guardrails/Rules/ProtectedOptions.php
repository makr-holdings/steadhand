<?php
/**
 * Blocks an update-option call that names an option Steadhand may not change (SPEC §6.1).
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Guardrails\Rules;

use Steadhand\Guardrails\Context;
use Steadhand\Guardrails\Result;
use Steadhand\Guardrails\Rule;
use Steadhand\Pipeline\OptionRules;

defined( 'ABSPATH' ) || exit;

/**
 * The third layer of the option rules. The ability and OptionWriter refuse the same names on their own; this rule refuses a call that
 * names a hard-blocked option, or one that is not on the allowlist, before anything is reserved or written.
 *
 * It evaluates only update-option calls. It is built in and cannot be removed, as RateLimit is.
 */
final class ProtectedOptions implements Rule {

	/**
	 * Returns the rule's ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'steadhand/protected-options';
	}

	/**
	 * Blocks the call when its option is not a string, is hard-blocked, or is not on the allowlist.
	 *
	 * @param Context $context The call.
	 * @return Result
	 */
	public function evaluate( Context $context ): Result {
		if ( 'steadhand/update-option' !== $context->ability ) {
			return Result::allow();
		}

		$name = $context->input['option'] ?? null;
		if ( ! is_string( $name ) || ! OptionRules::valid_name( $name ) || OptionRules::hard_blocked( $name ) || ! array_key_exists( $name, OptionRules::allowed() ) ) {
			return Result::block(
				__( 'This option is protected and cannot be changed through Steadhand. Nothing was changed.', 'steadhand' )
			);
		}

		return Result::allow();
	}
}
