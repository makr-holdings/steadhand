<?php
/**
 * S5 and S6: a guardrail's fix may not change the object a call is about, and a fix must pass the input schema.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Guardrails\Context;
use Steadhand\Guardrails\Registry;
use Steadhand\Guardrails\Result;
use Steadhand\Capabilities;
use Steadhand\Pipeline\Pipeline;
use Steadhand\Log\DbLogger;

/**
 * Integration tests for guardrail fixes: object changes and schema checks (S5, S6).
 */
final class GuardrailObjectTest extends PostWriteTestCase {

	/**
	 * Registers a third-party rule for the calls the test makes.
	 *
	 * @param FakeRule $rule Rule to add.
	 */
	private function register( FakeRule $rule ): void {
		add_action(
			'steadhand_register_guardrails',
			static function ( Registry $registry ) use ( $rule ): void {
				$registry->add( $rule );
			}
		);
	}

	/**
	 * A rule that rewrites the input with the given changes, once, for a call that is not already rewritten.
	 *
	 * @param array<string, mixed> $changes Keys to set in the input.
	 */
	private function rewriting_rule( array $changes ): FakeRule {
		return new FakeRule(
			'zz/rewrite',
			static function ( Context $context ) use ( $changes ): Result {
				$input = $context->input;
				if ( array() !== array_diff_assoc( $changes, $input ) ) {
					foreach ( $changes as $key => $value ) {
						$input[ $key ] = $value;
					}

					return Result::fix( $input, 'rewritten' );
				}

				return Result::allow();
			}
		);
	}

	public function test_a_fix_that_moves_the_call_to_another_post_is_refused_and_the_locked_post_is_untouched(): void {
		$locked = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'locked',
			)
		);
		$other  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'other',
			)
		);
		update_post_meta( $locked, '_edit_lock', time() . ':' . $this->admin_id() );
		$this->register( $this->rewriting_rule( array( 'id' => $locked ) ) );
		$this->act_as_agent();
		$locked_before = $this->row( $locked );
		$other_before  = $this->row( $other );

		$result = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $other,
				'content' => 'agent wrote',
			)
		);

		$this->assertSame( 'steadhand_guardrail_blocked', $this->refusal_code( $result ) );
		$this->assertSame( 'A guardrail changed which object this call is about, so it was refused.', $result->get_error_message() );
		$this->assertSame( $locked_before, $this->row( $locked ) );
		$this->assertSame( $other_before, $this->row( $other ) );
	}

	public function test_a_fix_that_keeps_the_same_object_is_applied(): void {
		$post = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'original',
			)
		);
		$this->register( $this->rewriting_rule( array( 'content' => 'fixed by a rule' ) ) );
		$this->act_as_agent();

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'    => $post,
				'title' => 'Renamed',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 'fixed by a rule', get_post( $post )->post_content );
	}

	public function test_a_fix_with_a_status_that_is_not_in_the_schema_is_blocked(): void {
		$post = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'original',
			)
		);
		$this->register( $this->rewriting_rule( array( 'status' => 'Publish' ) ) );
		$this->act_as_agent( array( 'steadhand_publish' => true ) );
		$before = $this->row( $post );

		$result = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $post,
				'content' => 'agent',
			)
		);

		$this->assertSame( 'steadhand_guardrail_blocked', $this->refusal_code( $result ) );
		$this->assertSame( 'A guardrail changed this call into one that is not allowed, so it was refused.', $result->get_error_message() );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_a_fix_with_an_unknown_key_is_blocked(): void {
		$post = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'original',
			)
		);
		$this->register( $this->rewriting_rule( array( 'post_author' => 1 ) ) );
		$this->act_as_agent();
		$before = $this->row( $post );

		$result = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $post,
				'content' => 'agent',
			)
		);

		$this->assertSame( 'steadhand_guardrail_blocked', $this->refusal_code( $result ) );
		$this->assertSame( $before, $this->row( $post ) );
	}


	public function test_a_fix_that_keeps_the_object_id_but_changes_its_type_is_refused(): void {
		$this->act_as_agent( array( Capabilities::WRITE_DRAFTS => true ) );
		$this->register(
			new FakeRule(
				'zz/kind',
				static function ( Context $context ): Result {
					if ( 'post' !== ( $context->input['kind'] ?? '' ) ) {
						return Result::allow();
					}

					$input         = $context->input;
					$input['kind'] = 'page';

					return Result::fix( $input, 'moved to a page' );
				}
			)
		);

		$result = ( new Pipeline( new DbLogger() ) )->run( new KindWriteProbe(), array( 'kind' => 'post' ) );

		$this->assertSame( 'steadhand_guardrail_blocked', $this->refusal_code( $result ) );
		$this->assertSame( 'A guardrail changed which object this call is about, so it was refused.', $result->get_error_message() );
	}
}
