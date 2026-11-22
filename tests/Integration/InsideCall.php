<?php
/**
 * Runs a check inside a real Steadhand call, so the answer is the one a call sees.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Closure;
use Steadhand\Capabilities;
use Steadhand\Pipeline\Pipeline;

/**
 * For tests that must ask a question from inside a call: a read call (a read-only ability) or a write call (an ability that
 * is not read-only). The probe runs through Pipeline::run(), so the same enter and leave rules apply as in production.
 */
trait InsideCall {

	/**
	 * The answer of a check that runs inside a read call (a read-only ability, such as get-rendered).
	 *
	 * @param Closure $check Returns the value to report.
	 * @return mixed
	 */
	protected function inside_read_call( Closure $check ): mixed {
		return $this->run_inside( $check, false );
	}

	/**
	 * The answer of a check that runs inside a write call (an ability that is not read-only).
	 *
	 * @param Closure $check Returns the value to report.
	 * @return mixed
	 */
	protected function inside_write_call( Closure $check ): mixed {
		return $this->run_inside( $check, true );
	}

	/**
	 * Runs the check through a probe ability and returns what it reported.
	 *
	 * @param Closure $check Returns the value to report.
	 * @param bool    $write Whether the probe is a write call.
	 * @return mixed
	 */
	private function run_inside( Closure $check, bool $write ): mixed {
		$annotations = $write
			? array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => false,
			)
			: null;

		$result = ( new Pipeline( new SpyLogger() ) )->run(
			new ProbeAbility(
				Capabilities::READ,
				static fn( array $sanitised ): array => array( 'answer' => $check( $sanitised ) ),
				$annotations
			),
			array()
		);

		$this->assertIsArray( $result, 'The probe call must be allowed to run.' );

		return $result['answer'];
	}
}
