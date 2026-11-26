<?php
/**
 * Tests for OptionWriter::set() called directly: it runs only inside a write, it checks the caller again, it honours expected, it writes
 * nothing for the same value, and it keeps the autoload setting. Most tests are negative.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Pipeline\OptionWriter;

/**
 * Tests for OptionWriter::set() called directly. Covers the writer's own checks, the expected value, the no-op and the autoload setting.
 */
final class OptionWriterTest extends OptionTestCase {

	/**
	 * The writer refuses to run outside a write: it throws, so nothing can reach it except through a pipeline write.
	 */
	public function test_the_writer_throws_outside_a_write_and_changes_nothing(): void {
		$before = $this->stored_value( 'blogname' );
		$this->option_agent();

		$this->expectException( \LogicException::class );
		try {
			OptionWriter::set( 'blogname', 'Changed', null, static fn(): bool => true );
		} finally {
			$this->assertSame( $before, $this->stored_value( 'blogname' ) );
		}
	}

	/**
	 * When the caller's check fails at the writer, the call is refused with the generic text, and nothing changes.
	 */
	public function test_the_writer_refuses_when_the_caller_is_no_longer_allowed(): void {
		$before = $this->stored_value( 'blogname' );
		$this->option_agent();

		$result = $this->in_write(
			fn(): mixed => OptionWriter::set( 'blogname', 'Changed', null, static fn(): bool => false ),
			Capabilities::SETTINGS
		);

		$this->assert_refused_with( $result, 'steadhand_forbidden', 403 );
		$this->assertSame( $before, $this->stored_value( 'blogname' ) );
	}

	/**
	 * The writer refuses a core option the allowlist never lists, with no filter at all, called directly. The refusal is forbidden, and nothing
	 * is written. (The allowlist decides here; the writer's own hard_blocked() call is the second layer for the same name.)
	 */
	public function test_the_writer_refuses_core_options_with_no_filter(): void {
		$this->option_agent();

		foreach ( array( 'home', 'posts_per_page' ) as $name ) {
			$before = get_option( $name );

			$result = $this->in_write(
				fn(): mixed => OptionWriter::set( $name, 'changed', null, static fn(): bool => true ),
				Capabilities::SETTINGS
			);

			$this->assert_refused_with( $result, 'steadhand_forbidden', 403 );
			$this->assertSame( $before, get_option( $name ), $name );
		}
	}

	/**
	 * The read-back decision: the stored form is success; the serialized form of the stored form is reported as a different form, not as
	 * another writer's change (without that case, the row would be called "something other than Steadhand changed it"); the row unchanged is
	 * "not changed"; anything else is other.
	 *
	 * @dataProvider read_back_cases
	 *
	 * @param string|null $after  The row read back.
	 * @param string      $stored The stored form of the value sent.
	 * @param string      $before The raw row before the write.
	 * @param string      $want   The outcome expected.
	 */
	public function test_the_read_back_decision( ?string $after, string $stored, string $before, string $want ): void {
		$this->assertSame( $want, OptionWriter::read_back_outcome( $after, $stored, $before ) );
	}

	/**
	 * Read-back cases.
	 *
	 * @return array<string, array{0: string|null, 1: string, 2: string, 3: string}>
	 */
	public static function read_back_cases(): array {
		return array(
			'the stored form'            => array( 'Joe&#039;s', 'Joe&#039;s', 'old', 'stored' ),
			'the serialized stored form' => array( 's:6:"i:5; x";', 'i:5; x', 'old', 'different_form' ),
			'the row unchanged'          => array( 'old', 'new', 'old', 'not_changed' ),
			'some other value'           => array( 'other', 'new', 'old', 'other' ),
			'no row'                     => array( null, 'new', 'old', 'other' ),
		);
	}

	/**
	 * An expected value that differs from the stored one is refused with 409 and nothing is written; a matching one is written.
	 */
	public function test_expected_is_honoured_by_the_writer(): void {
		$before = $this->stored_value( 'blogname' );
		$this->option_agent();

		$mismatch = $this->in_write(
			fn(): mixed => OptionWriter::set( 'blogname', 'Changed', 'Not the stored value', static fn(): bool => true ),
			Capabilities::SETTINGS
		);
		$this->assert_refused_with( $mismatch, 'steadhand_refused_content_changed', 409 );
		$this->assertSame( $before, $this->stored_value( 'blogname' ) );

		$match = $this->in_write(
			fn(): mixed => OptionWriter::set( 'blogname', 'Changed', $before, static fn(): bool => true ),
			Capabilities::SETTINGS
		);
		$this->assertIsArray( $match );
		$this->assertTrue( $match['changed'] );
		$this->assertSame( 'Changed', $this->stored_value( 'blogname' ) );
	}

	/**
	 * The same value writes nothing: update_option() is not called, and the result says so.
	 */
	public function test_the_same_value_calls_no_update(): void {
		$current = $this->stored_value( 'blogname' );
		$calls   = 0;
		$this->on(
			'pre_update_option_blogname',
			static function ( mixed $value ) use ( &$calls ): mixed {
				++$calls;

				return $value;
			}
		);
		$this->option_agent();

		$result = $this->in_write(
			fn(): mixed => OptionWriter::set( 'blogname', $current, null, static fn(): bool => true ),
			Capabilities::SETTINGS
		);

		$this->assertSame(
			array(
				'option'   => 'blogname',
				'value'    => $current,
				'previous' => $current,
				'changed'  => false,
			),
			$result
		);
		$this->assertSame( 0, $calls );
	}

	/**
	 * A change keeps the autoload setting of the row, and returns the stored value and the previous one.
	 */
	public function test_a_change_keeps_the_autoload_setting(): void {
		$previous = $this->stored_value( 'blogdescription' );
		$autoload = $this->autoload_of( 'blogdescription' );
		$this->option_agent();

		$result = $this->in_write(
			fn(): mixed => OptionWriter::set( 'blogdescription', 'A new tagline', null, static fn(): bool => true ),
			Capabilities::SETTINGS
		);

		$this->assertSame(
			array(
				'option'   => 'blogdescription',
				'value'    => 'A new tagline',
				'previous' => $previous,
				'changed'  => true,
			),
			$result
		);
		$this->assertSame( $autoload, $this->autoload_of( 'blogdescription' ) );
	}
}
