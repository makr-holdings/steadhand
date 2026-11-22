<?php
/**
 * How the pipeline tells read calls from write calls, and the write names the rate limit counts.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\Registry;
use Steadhand\Capabilities;
use Steadhand\Pipeline\Pipeline;

/**
 * A call is a read only when both its readonly annotation and its steadhand_read capability say so.
 */
final class ReadOnlyClassificationTest extends TestCase {

	public function test_a_readonly_probe_that_needs_steadhand_read_is_a_read(): void {
		$this->assertTrue( Pipeline::is_read_only_ability( new ProbeAbility() ) );
	}

	public function test_an_annotation_alone_does_not_make_a_write_a_read(): void {
		$probe = new WriteProbeAbility( Capabilities::READ );

		$this->assertFalse( Pipeline::is_read_only_ability( $probe ), 'A write annotation is not read-only.' );
	}

	public function test_a_read_only_annotation_on_a_write_capability_is_not_a_read(): void {
		$probe = new ProbeAbility( Capabilities::WRITE_DRAFTS );

		$this->assertFalse( Pipeline::is_read_only_ability( $probe ) );
	}

	public function test_write_names_are_exactly_the_abilities_that_are_not_reads(): void {
		$registry = new Registry( new Pipeline( new SpyLogger() ) );
		$writes   = $registry->write_names();

		foreach ( $registry->abilities() as $ability ) {
			$this->assertSame(
				! Pipeline::is_read_only_ability( $ability ),
				in_array( $ability->name(), $writes, true ),
				$ability->name() . ' is listed as a write exactly when it is not a read.'
			);
		}
	}
}
