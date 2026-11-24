<?php
/**
 * The guardrails see an undo as the direct call it amounts to (Ability::equivalent_call()), and a failure to determine that call
 * refuses the write.
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
use Steadhand\Log\Schema;
use Steadhand\Pipeline\Pipeline;

/**
 * Covers the second guardrail evaluation of an undo (SPEC §5, docs/HOOKS.md). Every rule that limits the direct call limits the
 * undo too; a rewrite of that call refuses the undo; a read that fails while the call is determined refuses it, and nothing is
 * written; an ability without an equivalent call is evaluated once.
 */
final class UndoGuardrailTest extends PostWriteTestCase {

	private const UNDO     = 'steadhand/undo-change';
	private const TERMS    = 'steadhand/set-post-terms';
	private const FEATURED = 'steadhand/set-featured-image';
	private const UPDATE   = 'steadhand/update-post';
	private const EDIT     = 'steadhand/edit-blocks';

	/** The message a rewritten call gets, as the engine gives it. */
	private const REWRITTEN = 'A guardrail changed this call into one that is not allowed, so it was refused.';

	/** The message a rewrite to another object gets, as the engine gives it. */
	private const OTHER_OBJECT = 'A guardrail changed which object this call is about, so it was refused.';

	/**
	 * Every context the test rules saw, in order: ability, capability, user_id, object_type, object_id, input and content.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $seen = array();

	protected function tearDown(): void {
		remove_all_actions( 'steadhand_register_guardrails' );
		remove_all_filters( 'steadhand_rate_limit_per_minute' );
		remove_all_filters( 'query' );
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
					'ability'     => $context->ability,
					'capability'  => $context->capability,
					'user_id'     => $context->user_id,
					'object_type' => $context->object_type,
					'object_id'   => $context->object_id,
					'input'       => $context->input,
					'content'     => $context->content,
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

	private function paragraph( string $text ): string {
		return "<!-- wp:paragraph -->\n<p>$text</p>\n<!-- /wp:paragraph -->";
	}

	private function assert_blocked( mixed $result ): void {
		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_guardrail_blocked', $result->get_error_code() );
	}

	/**
	 * Asserts that the rules saw the undo, and then the direct call, with the given ability and object.
	 *
	 * @param int $post Post ID.
	 */
	private function assert_second_context_is_the_direct_call( int $post ): void {
		$this->assertCount( 2, $this->seen, 'An undo is evaluated twice: as itself, then as the direct call.' );
		$this->assertSame( self::UNDO, $this->seen[0]['ability'] );
		$this->assertSame( 'post', $this->seen[1]['object_type'] );
		$this->assertSame( $post, $this->seen[1]['object_id'] );
		$this->assertSame( get_current_user_id(), $this->seen[1]['user_id'] );
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
		$this->assertSame( array( self::UNDO, self::TERMS ), array_column( $this->seen, 'ability' ) );
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

	public function test_a_terms_undo_is_seen_as_set_post_terms_with_the_entries_user_post_and_before_terms(): void {
		$first  = $this->tag( 'First' );
		$second = $this->tag( 'Second' );
		$agent  = $this->act_as_agent();
		$post   = $this->admin_post( array( 'post_status' => 'draft' ) );
		wp_set_object_terms( $post, array( $first ), 'post_tag', false );
		$this->set_terms( $post, array( $second ) );
		$entry = (int) $this->latest_row( $agent )['id'];

		$this->rule( 'vendor/observer', static fn(): Result => Result::allow() );
		$this->seen = array();

		$this->assertIsArray( $this->execute_ability( self::UNDO, array( 'id' => $entry ) ) );

		$this->assert_second_context_is_the_direct_call( $post );
		$this->assertSame(
			array(
				'ability'     => self::TERMS,
				'capability'  => Capabilities::WRITE_DRAFTS,
				'user_id'     => $agent,
				'object_type' => 'post',
				'object_id'   => $post,
				'input'       => array(
					'id'       => $post,
					'taxonomy' => 'post_tag',
					'terms'    => array( $first ),
					'mode'     => 'replace',
				),
				'content'     => array(),
			),
			$this->seen[1]
		);
	}

	public function test_a_featured_image_undo_is_seen_as_set_featured_image_with_the_before_image(): void {
		$agent = $this->act_as_agent( array( Capabilities::MEDIA => true ) );
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$old   = $this->image();
		$new   = $this->image();
		set_post_thumbnail( $post, $old );
		$this->execute_ability(
			self::FEATURED,
			array(
				'id'       => $post,
				'media_id' => $new,
			)
		);
		$entry = (int) $this->latest_row( $agent )['id'];

		$this->rule( 'vendor/observer', static fn(): Result => Result::allow() );
		$this->seen = array();

		$this->assertIsArray( $this->execute_ability( self::UNDO, array( 'id' => $entry ) ) );

		$this->assert_second_context_is_the_direct_call( $post );
		$this->assertSame( self::FEATURED, $this->seen[1]['ability'] );
		$this->assertSame( Capabilities::MEDIA, $this->seen[1]['capability'] );
		$this->assertSame(
			array(
				'id'       => $post,
				'media_id' => $old,
			),
			$this->seen[1]['input']
		);
		$this->assertSame( array(), $this->seen[1]['content'] );
	}

	public function test_an_edit_blocks_undo_is_seen_as_edit_blocks_with_only_the_id_and_no_content(): void {
		$original = $this->paragraph( 'One' ) . "\n\n" . $this->paragraph( 'Two' );
		$agent    = $this->act_as_agent();
		$post     = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => $original,
			)
		);
		$this->execute_ability(
			self::EDIT,
			array(
				'id'         => $post,
				'operations' => array(
					array(
						'op'     => 'replace',
						'path'   => '1',
						'name'   => 'core/paragraph',
						'markup' => $this->paragraph( 'Changed' ),
					),
				),
			)
		);
		$entry = (int) $this->latest_row( $agent )['id'];

		$this->rule( 'vendor/observer', static fn(): Result => Result::allow() );
		$this->seen = array();

		$this->assertIsArray( $this->execute_ability( self::UNDO, array( 'id' => $entry ) ) );

		$this->assertSame( self::EDIT, $this->seen[1]['ability'], 'The rules see the ability that made the entry.' );
		$this->assertSame( array( 'id' => $post ), $this->seen[1]['input'] );
		$this->assertSame( array(), $this->seen[1]['content'] );
		$this->assertSame( $original, get_post( $post )->post_content );
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

		$this->assertIsArray( $this->execute_ability( self::UNDO, array( 'id' => $entry ) ) );

		$this->assertSame( self::UPDATE, $this->seen[1]['ability'] );
		$this->assertSame( array( 'id' => $post ), $this->seen[1]['input'] );
		$this->assertSame( array(), $this->seen[1]['content'] );
	}

	public function test_a_rule_that_rewrites_the_direct_call_refuses_the_undo_with_the_engine_message_and_nothing_changes(): void {
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

		$result = $this->execute_ability( self::UNDO, array( 'id' => $entry ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_guardrail_blocked', $result->get_error_code() );
		$this->assertSame( self::REWRITTEN, $result->get_error_message() );
		$this->assertSame( array( $second ), $this->tag_ids( $post ) );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
	}

	public function test_a_rule_that_rewrites_the_undo_to_another_entry_of_the_agent_makes_the_second_evaluation_describe_that_entry(): void {
		$first  = $this->tag( 'First' );
		$second = $this->tag( 'Second' );
		$third  = $this->tag( 'Third' );
		$agent  = $this->act_as_agent();
		$post   = $this->admin_post( array( 'post_status' => 'draft' ) );
		wp_set_object_terms( $post, array( $first ), 'post_tag', false );
		$this->set_terms( $post, array( $second ) );
		$older = (int) $this->latest_row( $agent )['id'];
		$this->set_terms( $post, array( $third ) );
		$newer = (int) $this->latest_row( $agent )['id'];

		$this->rule(
			'vendor/redirect',
			static fn( Context $context ): Result => self::UNDO === $context->ability && $older === (int) $context->input['id']
				? Result::fix( array( 'id' => $newer ), 'Redirected to the newer change.' )
				: Result::allow()
		);
		$this->seen = array();

		$result = $this->execute_ability( self::UNDO, array( 'id' => $older ) );

		$this->assertIsArray( $result );
		$this->assertSame( $newer, $result['entry_id'], 'The write is for the entry the rules were given.' );
		$this->assertSame( array( $second ), $this->tag_ids( $post ), 'The newer change is the one restored.' );
		$this->assertSame( array( self::UNDO, self::TERMS ), array_column( $this->seen, 'ability' ), 'The rule saw the undo once; the rewrite is not seen again by the rules.' );
		$this->assertSame( array( $second ), $this->seen[1]['input']['terms'], 'The second evaluation describes the new entry.' );
	}

	public function test_a_rule_that_rewrites_the_undo_to_another_agents_entry_refuses_it(): void {
		$first  = $this->tag( 'First' );
		$second = $this->tag( 'Second' );
		$other  = $this->tag( 'Other' );
		$post   = $this->admin_post( array( 'post_status' => 'draft' ) );
		$mine   = $this->act_as_agent();
		wp_set_object_terms( $post, array( $first ), 'post_tag', false );
		$this->set_terms( $post, array( $second ) );
		$entry = (int) $this->latest_row( $mine )['id'];

		$stranger = $this->act_as_agent();
		$this->set_terms( $post, array( $other ) );
		$theirs = (int) $this->latest_row( $stranger )['id'];

		wp_set_current_user( $mine );
		$this->rule(
			'vendor/redirect',
			static fn( Context $context ): Result => self::UNDO === $context->ability ? Result::fix( array( 'id' => $theirs ), 'Redirected.' ) : Result::allow()
		);

		$result = $this->execute_ability( self::UNDO, array( 'id' => $entry ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_guardrail_blocked', $result->get_error_code() );
		$this->assertSame( self::REWRITTEN, $result->get_error_message() );
		$this->assertSame( array( $other ), $this->tag_ids( $post ) );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
	}

	public function test_a_rule_that_rewrites_the_undo_to_an_entry_of_another_post_refuses_it(): void {
		$first     = $this->tag( 'First' );
		$second    = $this->tag( 'Second' );
		$agent     = $this->act_as_agent();
		$post      = $this->admin_post( array( 'post_status' => 'draft' ) );
		$elsewhere = $this->admin_post( array( 'post_status' => 'draft' ) );
		wp_set_object_terms( $post, array( $first ), 'post_tag', false );
		$this->set_terms( $post, array( $second ) );
		$entry = (int) $this->latest_row( $agent )['id'];

		wp_set_object_terms( $elsewhere, array( $first ), 'post_tag', false );
		$this->set_terms( $elsewhere, array( $second ) );
		$other_post_entry = (int) $this->latest_row( $agent )['id'];

		$this->rule(
			'vendor/redirect',
			static fn( Context $context ): Result => self::UNDO === $context->ability ? Result::fix( array( 'id' => $other_post_entry ), 'Redirected.' ) : Result::allow()
		);

		$result = $this->execute_ability( self::UNDO, array( 'id' => $entry ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_guardrail_blocked', $result->get_error_code() );
		$this->assertSame( self::OTHER_OBJECT, $result->get_error_message() );
		$this->assertSame( array( $second ), $this->tag_ids( $post ) );
		$this->assertSame( array( $second ), $this->tag_ids( $elsewhere ) );
	}

	public function test_the_undo_is_refused_and_writes_nothing_when_the_direct_call_cannot_be_read_at_that_moment(): void {
		$first  = $this->tag( 'First' );
		$second = $this->tag( 'Second' );
		$agent  = $this->act_as_agent();
		$post   = $this->admin_post( array( 'post_status' => 'draft' ) );
		wp_set_object_terms( $post, array( $first ), 'post_tag', false );
		$this->set_terms( $post, array( $second ) );
		$entry = (int) $this->latest_row( $agent )['id'];

		// The first evaluation of the undo arms a query filter that makes the next snapshot read fail. That read is made by the
		// equivalent call, after the first evaluation and before the write, and the read that the authorization made is not affected.
		$failed = false;
		$this->rule(
			'vendor/fail-next-read',
			static function ( Context $context ) use ( &$failed ): Result {
				if ( self::UNDO === $context->ability && ! $failed ) {
					add_filter(
						'query',
						static function ( string $query ) use ( &$failed ): string {
							if ( ! $failed && str_starts_with( $query, 'SELECT * FROM' ) && str_contains( $query, 'steadhand_snapshots' ) ) {
								$failed = true;

								return str_replace( 'steadhand_snapshots', 'steadhand_no_such_table', $query );
							}

							return $query;
						}
					);
				}

				return Result::allow();
			}
		);
		$before = $this->count_rows( $agent );

		$result = $this->execute_ability( self::UNDO, array( 'id' => $entry ) );
		remove_all_filters( 'query' );

		$this->assertTrue( $failed, 'The read that the equivalent call makes must have been the one that failed.' );
		$this->assert_blocked( $result );
		$this->assertSame( array( $second ), $this->tag_ids( $post ), 'Nothing is written when the direct call cannot be determined.' );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
		$this->assertSame( $before + 1, $this->count_rows( $agent ), 'Only the refusal is logged: no reserved row.' );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
	}

	/**
	 * Replaces the state of a saved row, as another request could after the guardrails checked it.
	 *
	 * @param int                  $id    Snapshot row ID.
	 * @param array<string, mixed> $state The new state.
	 */
	private function replace_saved_state( int $id, array $state ): void {
		global $wpdb;

		$wpdb->update( Schema::snapshots_table(), array( 'state' => wp_json_encode( $state ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture: the saved row is changed as another request would.
	}

	public function test_a_saved_state_that_changes_after_the_guardrails_checked_it_refuses_the_undo_and_writes_nothing(): void {
		$first  = $this->tag( 'First' );
		$second = $this->tag( 'Second' );
		$third  = $this->tag( 'Third' );
		$agent  = $this->act_as_agent();
		$post   = $this->admin_post( array( 'post_status' => 'draft' ) );
		wp_set_object_terms( $post, array( $first ), 'post_tag', false );
		$this->set_terms( $post, array( $second ) );
		$entry = (int) $this->latest_row( $agent )['id'];
		$saved = (int) $this->stored( $entry )['snapshot_id'];

		// The direct call is evaluated, then the saved state is changed, as another request could. The write checks the digest of the
		// direct call again, finds it changed, and refuses before it writes.
		$this->rule(
			'vendor/tamper',
			function ( Context $context ) use ( $saved, $third ): Result {
				if ( self::TERMS === $context->ability ) {
					$this->replace_saved_state(
						$saved,
						array(
							'taxonomy' => 'post_tag',
							'term_ids' => array( $third ),
						)
					);
				}

				return Result::allow();
			}
		);

		$result = $this->execute_ability( self::UNDO, array( 'id' => $entry ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_refused_changed_since', $result->get_error_code() );
		$this->assertSame( array( $second ), $this->tag_ids( $post ), 'Nothing is written when the checked call changed.' );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
	}

	public function test_a_block_note_survives_a_long_list_of_notes_from_the_undo_itself(): void {
		$first  = $this->tag( 'First' );
		$second = $this->tag( 'Second' );
		$agent  = $this->act_as_agent();
		$post   = $this->admin_post( array( 'post_status' => 'draft' ) );
		wp_set_object_terms( $post, array( $first ), 'post_tag', false );
		$this->set_terms( $post, array( $second ) );
		$entry = (int) $this->latest_row( $agent )['id'];

		// Nineteen notes on the undo itself, then a direct call that warns once and then blocks.
		for ( $i = 0; $i < 19; $i++ ) {
			$this->rule( "vendor/own-$i", static fn( Context $context ): Result => self::UNDO === $context->ability ? Result::warn( "own note $i" ) : Result::allow() );
		}
		$this->rule( 'vendor/direct-warn', static fn( Context $context ): Result => self::TERMS === $context->ability ? Result::warn( 'direct warning' ) : Result::allow() );
		$this->rule( 'vendor/direct-block', static fn( Context $context ): Result => self::TERMS === $context->ability ? Result::block( 'Direct call blocked.' ) : Result::allow() );

		$this->assert_blocked( $this->execute_ability( self::UNDO, array( 'id' => $entry ) ) );

		$notes = explode( "\n", (string) $this->latest_row( $agent )['guardrail_notes'] );
		$this->assertLessThanOrEqual( 20, count( $notes ) );
		$this->assertSame( 'vendor/direct-block: blocked', end( $notes ), 'The block note of the direct call is kept, last.' );
		$this->assertContains( 'vendor/direct-warn: direct warning', $notes );
	}

	public function test_a_note_that_both_evaluations_produce_is_recorded_once(): void {
		$first  = $this->tag( 'First' );
		$second = $this->tag( 'Second' );
		$agent  = $this->act_as_agent();
		$post   = $this->admin_post( array( 'post_status' => 'draft' ) );
		wp_set_object_terms( $post, array( $first ), 'post_tag', false );
		$this->set_terms( $post, array( $second ) );
		$entry = (int) $this->latest_row( $agent )['id'];

		$this->rule( 'vendor/always', static fn(): Result => Result::warn( 'Looks fine.' ) );

		$result = $this->execute_ability( self::UNDO, array( 'id' => $entry ) );

		$this->assertIsArray( $result );
		$this->assertSame( 1, count( array_keys( $result['guardrail_notes'], 'vendor/always: Looks fine.', true ) ) );
	}

	public function test_an_equivalent_call_that_throws_refuses_the_write_before_it_runs(): void {
		$agent                         = $this->act_as_agent();
		$before                        = $this->count_rows( $agent );
		EquivalentCallProbe::$executed = 0;

		$result = ( new Pipeline( new DbLogger() ) )->run( new EquivalentCallProbe( 'throw' ), array() );

		$this->assert_blocked( $result );
		$this->assertSame( 0, EquivalentCallProbe::$executed, 'The write must not run when the direct call cannot be checked.' );
		$this->assertSame( $before + 1, $this->count_rows( $agent ), 'Exactly one row: the refusal. No reserved row.' );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
	}

	public function test_a_malformed_equivalent_call_refuses_the_write_before_it_runs(): void {
		$agent                         = $this->act_as_agent();
		$before                        = $this->count_rows( $agent );
		EquivalentCallProbe::$executed = 0;

		$result = ( new Pipeline( new DbLogger() ) )->run( new EquivalentCallProbe( 'malformed' ), array() );

		$this->assert_blocked( $result );
		$this->assertSame( 0, EquivalentCallProbe::$executed );
		$this->assertSame( $before + 1, $this->count_rows( $agent ), 'Exactly one row: the refusal. No reserved row.' );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
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

	public function test_the_rate_limit_is_checked_once_per_evaluation_and_a_stored_write_counts_once(): void {
		$first  = $this->tag( 'First' );
		$second = $this->tag( 'Second' );
		$agent  = $this->act_as_agent();
		$post   = $this->admin_post( array( 'post_status' => 'draft' ) );
		wp_set_object_terms( $post, array( $first ), 'post_tag', false );
		$this->set_terms( $post, array( $second ) );
		$entry = (int) $this->latest_row( $agent )['id'];
		// One stored write, and a limit of 2. Each evaluation sees that one row: the undo is allowed, and the second evaluation runs.
		add_filter( 'steadhand_rate_limit_per_minute', static fn(): int => 2 );
		$this->seen = array();
		$this->rule( 'vendor/observer', static fn(): Result => Result::allow() );

		$result = $this->execute_ability( self::UNDO, array( 'id' => $entry ) );

		$this->assertIsArray( $result, 'One stored write, counted once, is below a limit of 2.' );
		$this->assertSame( array( $first ), $this->tag_ids( $post ) );
		$this->assertSame( array( self::UNDO, self::TERMS ), array_column( $this->seen, 'ability' ), 'The second evaluation ran.' );
	}

	public function test_the_rate_limit_refuses_the_undo_at_its_first_evaluation_and_the_second_never_runs(): void {
		$first  = $this->tag( 'First' );
		$second = $this->tag( 'Second' );
		$agent  = $this->act_as_agent();
		$post   = $this->admin_post( array( 'post_status' => 'draft' ) );
		wp_set_object_terms( $post, array( $first ), 'post_tag', false );
		$this->set_terms( $post, array( $second ) );
		$entry = (int) $this->latest_row( $agent )['id'];
		// The limit is reached by the one stored write, so the undo is refused before any evaluation of the direct call.
		add_filter( 'steadhand_rate_limit_per_minute', static fn(): int => 1 );
		$this->seen = array();
		$this->rule( 'vendor/observer', static fn(): Result => Result::allow() );

		$this->assert_blocked( $this->execute_ability( self::UNDO, array( 'id' => $entry ) ) );

		// The built-in rate limit runs before the third-party rules, so no rule sees the undo, and the direct call is never evaluated.
		$this->assertSame( array(), array_column( $this->seen, 'ability' ) );
		$this->assertSame( array( $second ), $this->tag_ids( $post ) );
	}
}
