<?php
/**
 * The outcome of all guardrails for one call.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Guardrails;

defined( 'ABSPATH' ) || exit;

/**
 * What the pipeline does next: refuse the call, or run it with the final input.
 */
final class Verdict {

	/**
	 * Constructor.
	 *
	 * @param bool                 $blocked Whether the call is refused.
	 * @param string               $message The refusal text, when blocked.
	 * @param array<string, mixed> $input   The final input.
	 * @param string[]             $notes   What the rules recorded, in order.
	 * @param bool                 $fixed   Whether a rule rewrote the input.
	 */
	public function __construct(
		public readonly bool $blocked,
		public readonly string $message,
		public readonly array $input,
		public readonly array $notes,
		public readonly bool $fixed
	) {}
}
