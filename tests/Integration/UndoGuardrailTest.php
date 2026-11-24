<?php
/**
 * The guardrails see an undo as the direct call it amounts to (Ability::equivalent_call()), and a failure to ask refuses the write.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Closure;
use Steadhand\Capabilities;
use Steadhand\Guardrails\Context;
use Steadhand\Guardrails\Registry;
use Steadhand\Guardrails\Result;
use Steadhand\Log\DbLogger;
use Steadhand\Pipeline\Pipeline;

/**
 * Covers the second guardrail evaluation of an undo (SPEC §5, docs/HOOKS.md). Every rule that limits the direct call also limits
 * the undo, a rewrite of that call refuses the undo, and an ability that does not override equivalent_call() is evaluated once.
 */
final class UndoGuardrailTest extends PostWriteTestCase {

	private const UNDO     = 'steadhand/undo-change';
	private const TERMS    = 'steadhand/set-post-terms';
	private const FEATURED = 'steadhand/set-featured-image';
	private const UPDATE   = 'steadhand/update-post';

	/**
	 * Every rule context the test rule saw, in order.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $seen = array();

	protected function tearDown(): void {
		remove_all_actions( 'steadhand_register_guardrails' );
		remove_all_filters( 'steadhand_rate_limit_per_minute' );
		parent::tearDown();
	}

	/**
	 * Registers a third-party rule for the test. It records every context it is given in $this->seen, and then decides.
	 *
	 * @param string  $id     Rule ID, in the vendor form.
	 * @param Closure $decide Receives the Context, returns a Result.
	 */
	private function rule( string $id, Closure $decide ): void {
		$seen = &$this->seen;
		$rule = new FakeRule(
			$id,
			static function ( Context $context ) use ( &$seen, $decide ): Result {
				$seen[] = array(
					'ability' => $context->ability,
					'input'   => $context->input,
					'content' => $context->content,
				);

				return $decide( $context );
			}
		);

		add_action(
			'steadhand_register_guardrails',
			static function ( Registry $registry ) use ( $rule ): void {
				$registry->add( $rule );
			}
		);
	}

	private function tag( string $name ): int {
		return (int) self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => $name,
			)
		);
	}

	private function image(): int {
		return (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', 0 );
	}

	private function set_terms( int $post, array $terms ): mixed {
		return $this->execute_ability(
			self::TERMS,
			array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => $terms,
				'mode'     => 'replace',
			)
		);
	}

	private function tag_ids( int $post ): array {
		$ids = array_map( 'intval', (array) wp_get_object_terms( $post, 'post_tag', array( 'fields' => 'ids' ) ) );
		sort( $ids, SORT_NUMERIC );

		return $ids;
	}

	private function assert_blocked( mixed $result ): void {
		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_guardrail_blocked', $result->get_error_code() );
	}

	public function test_a_rule_that_blocks_the_direct_terms_call_also_blocks_the_undo_that_would_reassign_the_term(): void {
		$first  = $this->tag( 'First' );
		$second = $this->tag( 'Second' );
		$agent  = $this->act_as_agent();
		$post   = $this->admin_post( array( 'post_status' => 'draft' ) );
		wp_set_object_terms( $post, array( $first ), 'post_tag', false );
		$this->set_terms( $post, array( $second ) );
		$entry = (int) $this->latest_row( $agent )['id'];

		$this->rule(
			'vendor/no-first-term',
			static fn( Context $context ): Result => self::TERMS === $context->ability && in_array( $first, (array) ( $context->input['terms'] ?? array() ), true )
				? Result::block( 'First term is not allowed.' )
				: Result::allow()
		);

		$this->assert_blocked( $this->set_terms( $post, array( $first ) ) );

		$this->seen = array();
		$this->assert_blocked( $this->execute_ability( self::UNDO, array( 'id' => $entry ) ) );

		$this->assertSame( array( $second ), $this->tag_ids( $post ), 'The blocked undo must leave the terms as they are.' );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'], 'The blocked undo must not mark the entry.' );
		$this->assertSame( array( self::UNDO, self::TERMS ), array_column( $this->seen, 'ability' ), 'The rule sees the undo, then the direct call it amounts to.' );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
	}

	public function test_a_rule_that_blocks_the_direct_featured_image_call_also_blocks_the_undo_that_would_restore_the_image(): void {
		$agent   = $this->act_as_agent( array( Capabilities::MEDIA => true ) );
		$post    = $this->admin_post( array( 'post_status' => 'draft' ) );
		$blocked = $this->image();
		$other   = $this->image();
		set_post_thumbnail( $post, $blocked );
		$this->execute_ability(
			self::FEATURED,
			array(
				'id'       => $post,
				'media_id' => $other,
			)
		);
		$entry = (int) $this->latest_row( $agent )['id'];

		$this->rule(
			'vendor/no-blocked-image',
			static fn( Context $context ): Result => self::FEATURED === $context->ability && (int) ( $context->input['media_id'] ?? -1 ) === $blocked
				? Result::block( 'That image is not allowed.' )
				: Result::allow()
		);

		$this->assert_blocked(
			$this->execute_ability(
				self::FEATURED,
				array(
					'id'       => $post,
					'media_id' => $blocked,
				)
			)
		);

		$this->seen = array();
		$this->assert_blocked( $this->execute_ability( self::UNDO, array( 'id' => $entry ) ) );

		$this->assertSame( $other, (int) get_post_thumbnail_id( $post ) );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
		$this->assertSame( array( self::UNDO, self::FEATURED ), array_column( $this->seen, 'ability' ) );
	}

	public function test_a_rule_on_update_post_by_ability_name_also_blocks_the_undo_of_a_text_change(): void {
		$agent = $this->act_as_agent();
		$post  = $this->admin_post(
			array(
				'post_status' => 'draft',
				'post_title'  => 'Before',
			)
		);
		$this->execute_ability(
			self::UPDATE,
			array(
				'id'    => $post,
				'title' => 'After',
			)
		);
		$entry = (int) $this->latest_row( $agent )['id'];

		$this->rule(
			'vendor/no-updates',
			static fn( Context $context ): Result => self::UPDATE === $context->ability ? Result::block( 'Updates are closed.' ) : Result::allow()
		);

		$this->assert_blocked(
			$this->execute_ability(
				self::UPDATE,
				array(
					'id'    => $post,
					'title' => 'Again',
				)
			)
		);

		$this->seen = array();
		$this->assert_blocked( $this->execute_ability( self::UNDO, array( 'id' => $entry ) ) );

		$this->assertSame( 'After', get_post( $post )->post_title );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
		$this->assertSame( array( self::UNDO, self::UPDATE ), array_column( $this->seen, 'ability' ) );
	}

	public function test_a_text_undo_is_seen_by_the_rules_as_an_update_post_call_with_no_content(): void {
		$agent = $this->act_as_agent();
		$post  = $this->admin_post(
			array(
				'post_status' => 'draft',
				'post_title'  => 'Before',
			)
		);
		$this->execute_ability(
			self::UPDATE,
			array(
				'id'    => $post,
				'title' => 'After',
			)
		);
		$entry = (int) $this->latest_row( $agent )['id'];

		$this->rule( 'vendor/observer', static fn(): Result => Result::allow() );
		$this->seen = array();

		$result = $this->execute_ability( self::UNDO, array( 'id' => $entry ) );

		$this->assertIsArray( $result );
		$this->assertCount( 2, $this->seen, 'An undo is evaluated twice: as itself, then as the direct call.' );
		$this->assertSame(
			array(
				'ability' => self::UPDATE,
				'input'   => array( 'id' => $post ),
				'content' => array(),
			),
			$this->seen[1]
		);
	}

	public function test_a_rule_that_rewrites_the_direct_call_refuses_the_undo_and_nothing_changes(): void {
		$first  = $this->tag( 'First' );
		$second = $this->tag( 'Second' );
		$agent  = $this->act_as_agent();
		$post   = $this->admin_post( array( 'post_status' => 'draft' ) );
		wp_set_object_terms( $post, array( $first ), 'post_tag', false );
		$this->set_terms( $post, array( $second ) );
		$entry = (int) $this->latest_row( $agent )['id'];

		$this->rule(
			'vendor/rewrite-terms',
			static fn( Context $context ): Result => self::TERMS === $context->ability
				? Result::fix( array_merge( $context->input, array( 'terms' => array() ) ), 'Rewritten.' )
				: Result::allow()
		);

		$this->assert_blocked( $this->execute_ability( self::UNDO, array( 'id' => $entry ) ) );

		$this->assertSame( array( $second ), $this->tag_ids( $post ) );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
	}

	public function test_an_equivalent_call_that_throws_refuses_the_write_before_it_runs(): void {
		$this->act_as_agent();
		EquivalentCallProbe::$executed = 0;

		$result = ( new Pipeline( new DbLogger() ) )->run( new EquivalentCallProbe( 'throw' ), array() );

		$this->assert_blocked( $result );
		$this->assertSame( 0, EquivalentCallProbe::$executed, 'The write must not run when the direct call cannot be checked.' );
	}

	public function test_a_malformed_equivalent_call_refuses_the_write_before_it_runs(): void {
		$this->act_as_agent();
		EquivalentCallProbe::$executed = 0;

		$result = ( new Pipeline( new DbLogger() ) )->run( new EquivalentCallProbe( 'malformed' ), array() );

		$this->assert_blocked( $result );
		$this->assertSame( 0, EquivalentCallProbe::$executed );
	}

	public function test_an_ability_without_an_equivalent_call_is_evaluated_exactly_once(): void {
		$this->act_as_agent();
		$this->rule( 'vendor/counter', static fn(): Result => Result::allow() );
		$this->seen                    = array();
		EquivalentCallProbe::$executed = 0;

		$result = ( new Pipeline( new DbLogger() ) )->run( new EquivalentCallProbe( 'null' ), array() );

		$this->assertIsArray( $result );
		$this->assertSame( 1, EquivalentCallProbe::$executed );
		$this->assertCount( 1, $this->seen );
	}

	public function test_a_direct_terms_call_is_evaluated_exactly_once(): void {
		$agent = $this->act_as_agent();
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$this->rule( 'vendor/counter', static fn(): Result => Result::allow() );
		$this->seen = array();

		$this->set_terms( $post, array( $this->tag( 'Only' ) ) );

		$this->assertSame( array( self::TERMS ), array_column( $this->seen, 'ability' ) );
		$this->assertSame( 1, (int) $this->latest_row( $agent )['undoable'] );
	}

	public function test_the_rate_limit_counts_an_undo_once_not_twice(): void {
		$first  = $this->tag( 'First' );
		$second = $this->tag( 'Second' );
		$agent  = $this->act_as_agent();
		$post   = $this->admin_post( array( 'post_status' => 'draft' ) );
		wp_set_object_terms( $post, array( $first ), 'post_tag', false );
		$this->set_terms( $post, array( $second ) );
		$entry = (int) $this->latest_row( $agent )['id'];
		// One prior write is stored. With a limit of 2, the undo is allowed if its two evaluations count that one row, and only that.
		add_filter( 'steadhand_rate_limit_per_minute', static fn(): int => 2 );

		$result = $this->execute_ability( self::UNDO, array( 'id' => $entry ) );

		$this->assertIsArray( $result, 'One stored write, counted once, is below a limit of 2.' );
		$this->assertSame( array( $first ), $this->tag_ids( $post ) );
	}
}
