<?php
/**
 * The guardrail engine, its registry and its results: order, blocking, fixes, failures and caps.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Guardrails\Context;
use Steadhand\Guardrails\Engine;
use Steadhand\Guardrails\Registry;
use Steadhand\Guardrails\Result;
use Steadhand\Guardrails\Rule;
use Steadhand\Guardrails\Verdict;

/**
 * Covers the public guardrail API that add-ons implement against (docs/HOOKS.md).
 */
final class GuardrailEngineTest extends TestCase {

	/**
	 * A call to evaluate: a write to a post, with a title.
	 *
	 * @param array<string, mixed>  $input   Input.
	 * @param array<string, string> $content Content fields.
	 */
	private function context( array $input = array( 'title' => 'original' ), array $content = array() ): Context {
		return new Context( 'steadhand/update-post', 'steadhand_write_drafts', 7, $input, 'post', 12, $content );
	}

	/**
	 * A revalidation callable that accepts every input, as the pipeline would for a valid one.
	 *
	 * @return callable
	 */
	private function accepting(): callable {
		return fn( array $input ): Context => $this->context( $input );
	}

	public function test_rules_run_first_then_registered_then_last(): void {
		$order  = array();
		$record = static function ( string $id ) use ( &$order ): callable {
			return static function ( Context $c ) use ( &$order, $id ): Result {
				unset( $c );
				$order[] = $id;
				return Result::allow();
			};
		};
		add_action(
			'steadhand_register_guardrails',
			function ( Registry $registry ) use ( $record ): void {
				$registry->add( new FakeRule( 'vendor/third', $record( 'third' ) ) );
			}
		);
		$engine = new Engine(
			array( new FakeRule( 'steadhand/first', $record( 'first' ) ) ),
			array( new FakeRule( 'steadhand/last', $record( 'last' ) ) )
		);

		$verdict = $engine->evaluate( $this->context(), $this->accepting() );

		$this->assertFalse( $verdict->blocked );
		$this->assertSame( array( 'first', 'third', 'last' ), $order );
	}

	public function test_a_block_stops_every_later_rule(): void {
		$later  = false;
		$engine = new Engine(
			array(
				new FakeRule( 'steadhand/stop', static fn(): Result => Result::block( 'Not today.' ) ),
				new FakeRule(
					'steadhand/later',
					static function () use ( &$later ): Result {
						$later = true;
						return Result::allow();
					}
				),
			),
			array()
		);

		$verdict = $engine->evaluate( $this->context(), $this->accepting() );

		$this->assertTrue( $verdict->blocked );
		$this->assertSame( 'Not today.', $verdict->message );
		$this->assertSame( array( 'steadhand/stop: blocked' ), $verdict->notes );
		$this->assertFalse( $later, 'A rule after a block must not run.' );
	}

	public function test_a_third_party_allow_never_prevents_a_built_in_block(): void {
		add_action(
			'steadhand_register_guardrails',
			static function ( Registry $registry ): void {
				$registry->add( new FakeRule( 'vendor/allow-all', static fn(): Result => Result::allow() ) );
			}
		);
		$engine = new Engine(
			array(),
			array( new FakeRule( 'steadhand/last-word', static fn(): Result => Result::block( 'Built-in says no.' ) ) )
		);

		$verdict = $engine->evaluate( $this->context(), $this->accepting() );

		$this->assertTrue( $verdict->blocked );
		$this->assertSame( 'Built-in says no.', $verdict->message );
	}

	public function test_a_third_party_rule_cannot_run_before_a_built_in_first_rule(): void {
		$seen = array();
		add_action(
			'steadhand_register_guardrails',
			static function ( Registry $registry ) use ( &$seen ): void {
				$registry->add(
					new FakeRule(
						'vendor/early',
						static function () use ( &$seen ): Result {
							$seen[] = 'vendor';
							return Result::allow();
						}
					)
				);
			}
		);
		$engine = new Engine(
			array(
				new FakeRule(
					'steadhand/built-in',
					static function () use ( &$seen ): Result {
						$seen[] = 'built-in';
						return Result::block( 'Stopped first.' );
					}
				),
			),
			array()
		);

		$engine->evaluate( $this->context(), $this->accepting() );

		$this->assertSame( array( 'built-in' ), $seen, 'The built-in rule blocks before any third-party rule is reached.' );
	}

	public function test_warn_adds_a_note_and_the_call_goes_on(): void {
		$engine = new Engine(
			array( new FakeRule( 'steadhand/noisy', static fn(): Result => Result::warn( 'Looks unusual.' ) ) ),
			array()
		);

		$verdict = $engine->evaluate( $this->context(), $this->accepting() );

		$this->assertFalse( $verdict->blocked );
		$this->assertSame( array( 'steadhand/noisy: Looks unusual.' ), $verdict->notes );
		$this->assertFalse( $verdict->fixed );
	}

	public function test_a_fix_feeds_the_new_input_to_later_rules_and_to_the_built_in_last_rules(): void {
		$seen_by_later = null;
		$seen_by_last  = null;
		$engine        = new Engine(
			array(
				new FakeRule(
					'steadhand/fixer',
					static fn(): Result => Result::fix( array( 'title' => 'fixed' ), 'Trimmed the title.' )
				),
				new FakeRule(
					'steadhand/after-fix',
					static function ( Context $c ) use ( &$seen_by_later ): Result {
						$seen_by_later = $c->input;
						return Result::allow();
					}
				),
			),
			array(
				new FakeRule(
					'steadhand/last-rule',
					static function ( Context $c ) use ( &$seen_by_last ): Result {
						$seen_by_last = $c->input;
						return Result::allow();
					}
				),
			)
		);

		$verdict = $engine->evaluate( $this->context(), $this->accepting() );

		$this->assertFalse( $verdict->blocked );
		$this->assertTrue( $verdict->fixed );
		$this->assertSame( array( 'title' => 'fixed' ), $verdict->input );
		$this->assertSame( array( 'title' => 'fixed' ), $seen_by_later );
		$this->assertSame( array( 'title' => 'fixed' ), $seen_by_last );
		$this->assertSame( array( 'steadhand/fixer: Trimmed the title.' ), $verdict->notes );
	}

	public function test_a_fix_that_fails_revalidation_blocks_the_call(): void {
		$engine = new Engine(
			array( new FakeRule( 'steadhand/fixer', static fn(): Result => Result::fix( array( 'title' => 'bad' ), 'Changed.' ) ) ),
			array()
		);

		$verdict = $engine->evaluate(
			$this->context(),
			static fn(): \WP_Error => new \WP_Error( 'steadhand_invalid_input', 'nope' )
		);

		$this->assertTrue( $verdict->blocked );
		$this->assertSame( 'A guardrail changed this call into one that is not allowed, so it was refused.', $verdict->message );
		$this->assertSame( array( 'steadhand/fixer: blocked' ), $verdict->notes );
	}

	public function test_a_fix_whose_revalidation_throws_blocks_the_call(): void {
		$engine = new Engine(
			array( new FakeRule( 'steadhand/fixer', static fn(): Result => Result::fix( array( 'title' => 'bad' ), 'Changed.' ) ) ),
			array()
		);

		$verdict = $engine->evaluate(
			$this->context(),
			static function (): never {
				throw new \RuntimeException( 'Revalidation failed.' );
			}
		);

		$this->assertTrue( $verdict->blocked );
	}

	public function test_a_throwing_rule_blocks_the_call_and_names_the_rule(): void {
		$later  = false;
		$engine = new Engine(
			array(
				new FakeRule(
					'steadhand/broken',
					static function (): Result {
						throw new \RuntimeException( 'Internal detail.' );
					}
				),
				new FakeRule(
					'steadhand/later',
					static function () use ( &$later ): Result {
						$later = true;
						return Result::allow();
					}
				),
			),
			array()
		);

		$verdict = $engine->evaluate( $this->context(), $this->accepting() );

		$this->assertTrue( $verdict->blocked, 'A rule that throws fails closed.' );
		$this->assertSame( 'A guardrail could not check this call, so it was refused.', $verdict->message );
		$this->assertSame( array( 'steadhand/broken: blocked' ), $verdict->notes );
		$this->assertStringNotContainsString( 'Internal detail', $verdict->message );
		$this->assertFalse( $later );
	}

	public function test_a_rule_that_does_not_return_a_result_blocks_the_call(): void {
		$engine = new Engine(
			array(
				new FakeRule(
					'steadhand/wrong-type',
					// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- The closure returns the wrong type on purpose.
					static fn( Context $c ): Result => 'allow',
				),
			),
			array()
		);

		$verdict = $engine->evaluate( $this->context(), $this->accepting() );

		$this->assertTrue( $verdict->blocked );
		$this->assertSame( array( 'steadhand/wrong-type: blocked' ), $verdict->notes );
	}

	public function test_a_listener_that_throws_keeps_the_rules_added_before_it(): void {
		$ran = false;
		add_action(
			'steadhand_register_guardrails',
			static function ( Registry $registry ) use ( &$ran ): void {
				$registry->add(
					new FakeRule(
						'vendor/added-first',
						static function () use ( &$ran ): Result {
							$ran = true;
							return Result::allow();
						}
					)
				);
			},
			10
		);
		add_action(
			'steadhand_register_guardrails',
			static function (): void {
				throw new \RuntimeException( 'Listener failed.' );
			},
			20
		);
		$engine = new Engine( array(), array( new FakeRule( 'steadhand/last', static fn(): Result => Result::allow() ) ) );

		$verdict = $engine->evaluate( $this->context(), $this->accepting() );

		$this->assertFalse( $verdict->blocked, 'A listener that throws does not break evaluation.' );
		$this->assertTrue( $ran, 'The rule added before the throw still runs.' );
	}

	public function test_third_party_rules_are_collected_once(): void {
		$calls = 0;
		add_action(
			'steadhand_register_guardrails',
			static function () use ( &$calls ): void {
				++$calls;
			}
		);
		$engine = new Engine( array(), array() );

		$engine->evaluate( $this->context(), $this->accepting() );
		$engine->evaluate( $this->context(), $this->accepting() );

		$this->assertSame( 1, $calls );
	}

	public function test_the_block_message_is_cut_to_500_bytes_on_a_character_boundary(): void {
		$engine = new Engine(
			array( new FakeRule( 'steadhand/long', static fn(): Result => Result::block( str_repeat( 'é', 400 ) ) ) ),
			array()
		);

		$verdict = $engine->evaluate( $this->context(), $this->accepting() );

		$this->assertLessThanOrEqual( Engine::MAX_MESSAGE_BYTES, strlen( $verdict->message ) );
		$this->assertTrue( mb_check_encoding( $verdict->message, 'UTF-8' ) );
	}

	public function test_an_empty_block_message_gets_a_default(): void {
		$engine = new Engine( array( new FakeRule( 'steadhand/silent', static fn(): Result => Result::block( '' ) ) ), array() );

		$verdict = $engine->evaluate( $this->context(), $this->accepting() );

		$this->assertSame( 'Refused by a guardrail.', $verdict->message );
	}

	public function test_notes_are_cut_to_300_bytes_and_at_most_20_are_kept(): void {
		$rules = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$rules[] = new FakeRule( 'steadhand/warn-' . $i, static fn(): Result => Result::warn( str_repeat( 'n', 400 ) ) );
		}
		$verdict = ( new Engine( $rules, array() ) )->evaluate( $this->context(), $this->accepting() );

		$this->assertCount( Engine::MAX_NOTES, $verdict->notes );
		foreach ( $verdict->notes as $note ) {
			$this->assertLessThanOrEqual( Engine::MAX_NOTE_BYTES, strlen( $note ) );
		}
	}

	public function test_the_block_note_is_kept_even_when_the_notes_are_full(): void {
		$rules = array();
		for ( $i = 0; $i < 25; $i++ ) {
			$rules[] = new FakeRule( 'steadhand/warn-' . $i, static fn(): Result => Result::warn( 'Noted.' ) );
		}
		$rules[] = new FakeRule( 'steadhand/stop', static fn(): Result => Result::block( 'Stopped.' ) );

		$verdict = ( new Engine( $rules, array() ) )->evaluate( $this->context(), $this->accepting() );

		$this->assertTrue( $verdict->blocked );
		$this->assertCount( Engine::MAX_NOTES, $verdict->notes );
		$notes = $verdict->notes;
		$this->assertSame( 'steadhand/stop: blocked', end( $notes ) );
	}

	public function test_an_empty_engine_allows_the_call_unchanged(): void {
		$context = $this->context();

		$verdict = ( new Engine( array(), array() ) )->evaluate( $context, $this->accepting() );

		$this->assertInstanceOf( Verdict::class, $verdict );
		$this->assertFalse( $verdict->blocked );
		$this->assertSame( array(), $verdict->notes );
		$this->assertFalse( $verdict->fixed );
		$this->assertSame( $context->input, $verdict->input );
	}

	public function test_registry_accepts_a_well_formed_third_party_id(): void {
		$registry = new Registry();

		$this->assertTrue( $registry->add( new FakeRule( 'vendor-x/rule-1', static fn(): Result => Result::allow() ) ) );
		$this->assertCount( 1, $registry->rules() );
	}

	/**
	 * Malformed IDs, reserved IDs and duplicates are refused and add nothing.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function refused_ids(): array {
		return array(
			'no slash'           => array( 'vendorrule' ),
			'empty'              => array( '' ),
			'uppercase'          => array( 'Vendor/rule' ),
			'space'              => array( 'vendor/my rule' ),
			'two slashes'        => array( 'vendor/rule/extra' ),
			'vendor too long'    => array( str_repeat( 'v', 41 ) . '/rule' ),
			'name too long'      => array( 'vendor/' . str_repeat( 'r', 61 ) ),
			'reserved steadhand' => array( 'steadhand/rate-limit' ),
			'reserved again'     => array( 'steadhand/anything' ),
			'trailing newline'   => array( "vendor/rule\n" ),
		);
	}

	/**
	 * Runs the registry over an ID it must refuse.
	 *
	 * @dataProvider refused_ids
	 *
	 * @param string $id The rule ID to try.
	 */
	public function test_registry_refuses_a_malformed_or_reserved_id( string $id ): void {
		$registry = new Registry();

		$this->assertFalse( $registry->add( new FakeRule( $id, static fn(): Result => Result::allow() ) ), "The ID $id must be refused." );
		$this->assertSame( array(), $registry->rules() );
	}

	public function test_registry_refuses_a_duplicate_id_and_keeps_the_first(): void {
		$registry = new Registry();
		$first    = new FakeRule( 'vendor/dup', static fn(): Result => Result::allow() );

		$this->assertTrue( $registry->add( $first ) );
		$this->assertFalse( $registry->add( new FakeRule( 'vendor/dup', static fn(): Result => Result::block( 'Second.' ) ) ) );
		$this->assertSame( array( $first ), $registry->rules() );
	}

	public function test_registry_keeps_rules_in_the_order_they_were_added(): void {
		$registry = new Registry();
		$a        = new FakeRule( 'vendor/a', static fn(): Result => Result::allow() );
		$b        = new FakeRule( 'vendor/b', static fn(): Result => Result::allow() );

		$registry->add( $a );
		$registry->add( $b );

		$this->assertSame( array( $a, $b ), $registry->rules() );
	}

	public function test_registry_refuses_a_rule_whose_id_cannot_be_read(): void {
		$broken = new class() implements Rule {
			public function id(): string {
				throw new \RuntimeException( 'No ID.' );
			}

			public function evaluate( Context $context ): Result {
				unset( $context );
				return Result::allow();
			}
		};

		$this->assertFalse( ( new Registry() )->add( $broken ) );
	}

	public function test_result_named_constructors_carry_their_mode_and_payload(): void {
		$this->assertSame( Result::MODE_ALLOW, Result::allow()->mode() );

		$block = Result::block( 'No.' );
		$this->assertSame( Result::MODE_BLOCK, $block->mode() );
		$this->assertSame( 'No.', $block->message() );

		$warn = Result::warn( 'Noted.' );
		$this->assertSame( Result::MODE_WARN, $warn->mode() );
		$this->assertSame( 'Noted.', $warn->note() );

		$fix = Result::fix( array( 'title' => 'x' ), 'Fixed.' );
		$this->assertSame( Result::MODE_FIX, $fix->mode() );
		$this->assertSame( array( 'title' => 'x' ), $fix->input() );
		$this->assertSame( 'Fixed.', $fix->note() );
	}
}
