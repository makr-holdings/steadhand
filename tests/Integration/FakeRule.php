<?php
/**
 * Test guardrail rule: decides with a closure and records that it ran.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Closure;
use Steadhand\Guardrails\Context;
use Steadhand\Guardrails\Result;
use Steadhand\Guardrails\Rule;

/**
 * A rule whose behaviour a test chooses. Each evaluation is recorded on the spy, as 'rule:<id>', so a test can see
 * which rules ran and in what order.
 */
final class FakeRule implements Rule {

	/**
	 * Constructor.
	 *
	 * @param string         $rule_id The rule's ID.
	 * @param Closure        $decide  Receives the context, returns a Result.
	 * @param SpyLogger|null $spy     Records the evaluation, when given.
	 */
	public function __construct( private string $rule_id, private Closure $decide, private ?SpyLogger $spy = null ) {}

	/**
	 * Returns the rule's ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->rule_id;
	}

	/**
	 * Records the evaluation and returns what the closure decides.
	 *
	 * @param Context $context The call.
	 * @return Result
	 */
	public function evaluate( Context $context ): Result {
		$this->spy?->mark( 'rule:' . $this->rule_id );

		return ( $this->decide )( $context );
	}
}
