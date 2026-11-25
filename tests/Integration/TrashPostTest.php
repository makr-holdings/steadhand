<?php
/**
 * The steadhand/trash-post ability: what it trashes, what it refuses, the markers it stores, the change log, and the refusals
 * that keep WordPress from deleting a post permanently.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Admin\UndoEntry;
use Steadhand\Capabilities;
use Steadhand\Log\DbLogger;
use Steadhand\Log\Schema;
use Steadhand\Pipeline\Pipeline;
use Steadhand\Pipeline\PostWriter;
use Steadhand\Pipeline\TrashRules;
use Steadhand\Plugin;
use Steadhand\Roles;

/**
 * Covers SPEC §5 (trash-post), §7 (not undoable), §14.1 (the object check) and the writer's refusals. Every refusal test checks
 * that nothing changed: the status, the text, the slug and the markers.
 */
final class TrashPostTest extends TrashTestCase {

	/** The refusal for a site with the trash turned off. */
	private const TRASH_OFF = 'This site has the trash turned off (EMPTY_TRASH_DAYS), so WordPress would delete the post permanently. Steadhand never deletes permanently. Nothing was changed.';

	/** The refusal for a protected page. */
	private const PROTECTED = 'This page is the site\'s front page, posts page or privacy policy page, and Steadhand does not trash it. Nothing was changed.';

	/** The refusal for a published post without steadhand_publish. */
	/** The refusal for a sticky post. */
	private const STICKY = 'This post is sticky. Trashing it would remove it from the sticky posts, and restoring it would not put it back. Unstick it in WordPress first. Nothing was changed.';

	private const NEEDS_PUBLISH = 'Trashing a published, scheduled or private post needs steadhand_publish as well as steadhand_trash, and this agent does not hold steadhand_publish. Nothing was changed.';

	/** The refusal for a post with an edit lock held by someone else. */
	private const LOCKED_CODE = 'steadhand_guardrail_blocked';

	/** The refusal for an entry that cannot be undone. */
	private const NOT_UNDOABLE = 'Steadhand cannot undo this change. Only a change that update-post, edit-blocks, set-post-terms or set-featured-image made, and that was saved with the state it needs, can be undone. Nothing was changed.';

	/**
	 * Removes the filters and options this file sets.
	 */
	protected function tearDown(): void {
		update_option( 'show_on_front', 'posts' );
		update_option( 'page_on_front', 0 );
		update_option( 'page_for_posts', 0 );
		update_option( 'wp_page_for_privacy_policy', 0 );

		parent::tearDown();
	}

	/**
	 * The trash ability's registration: strict schemas, annotations, the capability, and that it is not public.
	 */
	public function test_is_registered_with_strict_schemas_annotations_and_is_not_public(): void {
		$ability = $this->ability( self::TRASH );
		$args    = ( new Plugin() )->registry()->args_for( $ability );

		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => true,
				'idempotent'  => false,
			),
			$args['meta']['annotations']
		);
		$this->assertFalse( $args['meta']['mcp']['public'] );
		$this->assertSame( Capabilities::TRASH, $ability->capability() );
		$this->assertSame( array( Capabilities::TRASH ), $ability->capabilities_any() );

		$input = $ability->input_schema();
		$this->assertFalse( $input['additionalProperties'] );
		$this->assertSame( array( 'id' ), $input['required'] );
		$this->assertSame( array( 'id' ), array_keys( $input['properties'] ) );
		$this->assertSame( 1, $input['properties']['id']['minimum'] );
	}

	/**
	 * The ability object from the registry.
	 *
	 * @param string $name Ability name.
	 */
	private function ability( string $name ): \Steadhand\Abilities\Ability {
		foreach ( ( new Plugin() )->registry()->abilities() as $ability ) {
			if ( $ability->name() === $name ) {
				return $ability;
			}
		}

		$this->fail( "No ability named $name." );
	}

	/**
	 * A draft is trashed by an agent with steadhand_trash and steadhand_write_drafts. The output, the status, and the markers are as
	 * the writer stores them, and the trash is the one WordPress keeps for the trash period.
	 */
	public function test_trashes_a_draft_for_an_agent_with_trash_and_write_drafts(): void {
		$post  = $this->draft_post();
		$agent = $this->act_as_agent( array( Capabilities::TRASH => true ) );

		$result = $this->trash( $post );

		$this->assertIsArray( $result );
		$this->assertSame( $post, $result['id'] );
		$this->assertSame( 'post', $result['post_type'] );
		$this->assertSame( 'Fixture post', $result['title'] );
		$this->assertSame( 'draft', $result['previous_status'] );
		$this->assertSame( 'trash', $result['status'] );
		$this->assertSame( 'trash', $this->status_of( $post ) );
		$this->assertSame( (string) $agent, $this->meta_of( $post, PostWriter::TRASHED_BY_META ), 'The agent that trashed it is stored.' );
		$this->assertSame( $this->meta_of( $post, PostWriter::TRASH_CORE_TIME_META ), $this->meta_of( $post, PostWriter::TRASH_TIME_META ), 'The trash time is the one WordPress stored.' );
		$this->assertSame( 'draft', $this->meta_of( $post, '_wp_trash_meta_status' ) );
		$this->assertSame( 0, $this->permanent_deletes );
	}

	/**
	 * A published post needs steadhand_publish as well. With it, the post is trashed and the warning says it left the public site.
	 */
	public function test_trashes_a_published_post_for_an_agent_with_trash_and_publish(): void {
		$post = $this->live_post();
		$this->act_as_agent(
			array(
				Capabilities::TRASH   => true,
				Capabilities::PUBLISH => true,
			)
		);

		$result = $this->trash( $post );

		$this->assertIsArray( $result );
		$this->assertSame( 'publish', $result['previous_status'] );
		$this->assertSame( 'trash', $this->status_of( $post ) );
		$this->assertContains( 'This removed a published post from the public site.', $result['warnings'] );
	}

	/**
	 * The date the output names is the trash time plus the trash period, in UTC, and the closing warning gives the same number of days.
	 */
	public function test_the_output_gives_the_date_wordpress_deletes_the_post_and_the_days(): void {
		$post = $this->draft_post();
		$this->act_as_agent( array( Capabilities::TRASH => true ) );

		$result = $this->trash( $post );
		$days   = PostWriter::retention_days();
		$time   = (int) $this->meta_of( $post, PostWriter::TRASH_TIME_META );

		$this->assertSame( gmdate( 'Y-m-d\TH:i:s\Z', $time + $days * DAY_IN_SECONDS ), $result['permanent_delete_after'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $result['permanent_delete_after'] );
		$this->assertContains(
			sprintf(
				'WordPress deletes a trashed post permanently after %d %s. Restore it with steadhand-restore-post before then to keep it.',
				$days,
				1 === $days ? 'day' : 'days'
			),
			$result['warnings']
		);
	}

	/**
	 * A scheduled and a private post each name their status in the warning. A draft gets only the deletion warning.
	 */
	public function test_a_scheduled_or_private_post_names_its_status_and_a_draft_gets_only_the_deletion_warning(): void {
		$this->act_as_agent(
			array(
				Capabilities::TRASH   => true,
				Capabilities::PUBLISH => true,
			)
		);
		$scheduled = $this->admin_post(
			array(
				'post_status'   => 'future',
				'post_date'     => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + 5 * DAY_IN_SECONDS ),
			)
		);
		$private   = $this->admin_post( array( 'post_status' => 'private' ) );
		$draft     = $this->draft_post();

		$this->assertContains( 'This removed a scheduled post from the public site.', $this->trash( $scheduled )['warnings'] );
		$this->assertContains( 'This removed a private post from the site.', $this->trash( $private )['warnings'] );

		$warnings = $this->trash( $draft )['warnings'];
		$this->assertCount( 1, $warnings, 'A draft has no status warning and no child pages.' );
	}

	/**
	 * Child pages that are not in the trash are counted, and the trashed ones are not.
	 */
	public function test_child_pages_that_are_not_trashed_are_counted_in_a_warning(): void {
		$parent = $this->live_post();
		$this->admin_post(
			array(
				'post_type'   => 'page',
				'post_parent' => $parent,
			)
		);
		$this->admin_post(
			array(
				'post_type'   => 'page',
				'post_parent' => $parent,
				'post_status' => 'trash',
			)
		);
		$this->act_as_agent(
			array(
				Capabilities::TRASH   => true,
				Capabilities::PUBLISH => true,
			)
		);

		$result = $this->trash( $parent );

		$this->assertContains( '1 child page keeps this page as its parent.', $result['warnings'] );
	}

	/**
	 * The trash is recorded in the change log, with the post and the ok result, and the entry is not undoable.
	 */
	public function test_the_trash_is_recorded_in_the_change_log_and_is_not_undoable(): void {
		$post  = $this->draft_post();
		$agent = $this->act_as_agent( array( Capabilities::TRASH => true ) );

		$this->trash( $post );
		$row = $this->latest_row( $agent );

		$this->assertSame( self::TRASH, $row['ability'] );
		$this->assertSame( 'ok', $row['result'] );
		$this->assertSame( 'post', $row['object_type'] );
		$this->assertSame( $post, (int) $row['object_id'] );
		$this->assertSame( 0, (int) $row['undoable'] );
	}

	/**
	 * A trashed post can be read through no tool: get-post refuses it, as it refuses every trashed post.
	 */
	public function test_an_agent_cannot_read_the_post_it_trashed(): void {
		$post = $this->draft_post();
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );

		$this->assert_generic( $this->execute_ability( 'steadhand/get-post', array( 'id' => $post ) ) );
	}

	/**
	 * An agent without steadhand_trash is refused, and nothing changes.
	 */
	public function test_an_agent_without_steadhand_trash_is_refused_and_nothing_changes(): void {
		$post = $this->draft_post();
		$this->act_as_agent();
		$before = $this->state( $post );

		$this->assert_refused(
			$this->trash( $post ),
			'steadhand_forbidden',
			'This user needs the steadhand_trash capability to call steadhand/trash-post.'
		);
		$this->assertSame( $before, $this->state( $post ) );
	}

	/**
	 * A draft needs steadhand_write_drafts as well. Without it the object check refuses with the generic text.
	 */
	public function test_a_draft_is_refused_without_steadhand_write_drafts_and_nothing_changes(): void {
		$post = $this->draft_post();
		$this->act_as_agent(
			array(
				Capabilities::TRASH        => true,
				Capabilities::WRITE_DRAFTS => false,
			)
		);
		$before = $this->state( $post );

		$this->assert_generic( $this->trash( $post ) );
		$this->assertSame( $before, $this->state( $post ) );
	}

	/**
	 * A published post needs steadhand_publish. Without it the refusal says so, and nothing changes.
	 */
	public function test_a_published_post_is_refused_without_steadhand_publish_and_the_refusal_says_why(): void {
		$post = $this->live_post();
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$before = $this->state( $post );

		$this->assert_refused( $this->trash( $post ), 'steadhand_forbidden', self::NEEDS_PUBLISH );
		$this->assertSame( $before, $this->state( $post ) );
	}

	/**
	 * An administrator is not an agent. The call is refused, and nothing changes.
	 */
	public function test_an_administrator_is_refused_and_nothing_changes(): void {
		$post = $this->draft_post();
		wp_set_current_user( $this->admin_id() );
		$before = $this->state( $post );

		$result = $this->trash( $post );

		$this->assert_refused( $result, 'steadhand_forbidden' );
		$this->assertSame( $before, $this->state( $post ) );
	}

	/**
	 * An agent that also holds another role is not a dedicated agent, so it is refused.
	 */
	public function test_an_agent_that_also_holds_another_role_is_refused_and_nothing_changes(): void {
		$post  = $this->draft_post();
		$agent = $this->act_as_agent( array( Capabilities::TRASH => true ) );
		get_userdata( $agent )->add_role( 'editor' );
		$before = $this->state( $post );

		$this->assert_refused( $this->trash( $post ), 'steadhand_forbidden' );
		$this->assertSame( $before, $this->state( $post ) );
	}

	/**
	 * Every object the agent may not trash gets the same generic refusal, and nothing changes: an attachment, a type that is not
	 * allowed, an auto-draft, a post already in the trash, and an ID that does not exist.
	 */
	public function test_an_attachment_a_disallowed_type_an_auto_draft_a_trashed_post_and_a_missing_id_all_get_the_same_refusal(): void {
		$attachment = $this->attachment();
		$disallowed = $this->disallowed_post();
		$auto_draft = self::factory()->post->create(
			array(
				'post_status' => 'auto-draft',
				'post_author' => $this->admin_id(),
			)
		);
		$trashed    = $this->draft_post();
		wp_trash_post( $trashed );
		$this->act_as_agent(
			array(
				Capabilities::TRASH   => true,
				Capabilities::PUBLISH => true,
			)
		);

		foreach ( array(
			'an attachment'     => $attachment,
			'a disallowed type' => $disallowed,
			'an auto-draft'     => $auto_draft,
			'a trashed post'    => $trashed,
			'a missing ID'      => self::MISSING_ID,
		) as $label => $id ) {
			$before = $this->state( $id );
			$this->assert_generic( $this->trash( $id ) );
			$this->assertSame( $before, $this->state( $id ), "Trashing $label must leave it as it was." );
		}
	}

	/**
	 * The front page, the posts page and the privacy policy page are refused with their own text, and nothing changes.
	 */
	public function test_the_front_page_the_posts_page_and_the_privacy_page_are_refused_and_nothing_changes(): void {
		$front   = $this->admin_post( array( 'post_type' => 'page' ) );
		$posts   = $this->admin_post( array( 'post_type' => 'page' ) );
		$privacy = $this->admin_post( array( 'post_type' => 'page' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );
		update_option( 'page_for_posts', $posts );
		update_option( 'wp_page_for_privacy_policy', $privacy );
		$this->act_as_agent(
			array(
				Capabilities::TRASH   => true,
				Capabilities::PUBLISH => true,
			)
		);

		foreach ( array(
			'the front page'          => $front,
			'the posts page'          => $posts,
			'the privacy policy page' => $privacy,
		) as $label => $page ) {
			$before = $this->state( $page );
			$this->assert_refused( $this->trash( $page ), 'steadhand_refused_protected_page', self::PROTECTED );
			$this->assertSame( $before, $this->state( $page ), "Trashing $label must leave it as it was." );
		}
	}

	/**
	 * A post that is not a protected page is trashed, even when the front page is a static page: the rule is the page's ID.
	 */
	public function test_a_page_that_is_not_the_front_page_can_still_be_trashed(): void {
		$front = $this->admin_post( array( 'post_type' => 'page' ) );
		$other = $this->admin_post( array( 'post_type' => 'page' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );
		$this->act_as_agent(
			array(
				Capabilities::TRASH   => true,
				Capabilities::PUBLISH => true,
			)
		);

		$this->assertIsArray( $this->trash( $other ) );
		$this->assertSame( 'trash', $this->status_of( $other ) );
	}

	/**
	 * With the trash turned off, WordPress would delete a post at once. The ability's own check refuses that. The setting is a PHP
	 * constant, which a test cannot change, so the decision is one pure method, tested here with each value it can take. The writer
	 * and the ability both use that method, and the constant is the only input.
	 */
	public function test_a_trash_setting_that_is_not_a_positive_number_turns_the_trash_off(): void {
		foreach ( array( 0, '0', false, null, -1, '', 'thirty', 0.0 ) as $value ) {
			$this->assertSame( 0, PostWriter::trash_retention_days( $value ), 'The value ' . wp_json_encode( $value ) . ' turns the trash off.' );
		}

		$this->assertSame( 30, PostWriter::trash_retention_days( 30 ) );
		$this->assertSame( 30, PostWriter::trash_retention_days( '30' ) );
		$this->assertSame( 1, PostWriter::trash_retention_days( 1 ) );
	}

	/**
	 * The refusal for a trash that is turned off has its own code and text, and it is the one the writer returns.
	 */
	public function test_the_trash_turned_off_refusal_has_its_code_and_text(): void {
		$error = PostWriter::trash_disabled();

		$this->assertSame( 'steadhand_refused_trash_disabled', $error->get_error_code() );
		$this->assertSame( self::TRASH_OFF, $error->get_error_message() );
	}

	/**
	 * The writer refuses to trash a post whose status changed after the caller checked it, and the post is unchanged.
	 */
	public function test_the_writer_refuses_a_post_whose_status_changed_after_the_check(): void {
		$post = $this->draft_post();
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$before = $this->state( $post );

		$result = $this->in_write( static fn() => PostWriter::trash( $post, 'publish' ) );

		$this->assert_refused( $result, 'steadhand_refused_changed_since' );
		$this->assertSame( $before, $this->state( $post ) );
	}

	/**
	 * The writer refuses a missing post, and a post the trash would not change, in the same generic text.
	 */
	public function test_the_writer_refuses_a_missing_post_with_the_generic_text(): void {
		$this->act_as_agent( array( Capabilities::TRASH => true ) );

		$result = $this->in_write( static fn() => PostWriter::trash( self::MISSING_ID, 'draft' ) );

		$this->assert_generic( $result );
	}

	/**
	 * The writer is not callable outside a write.
	 */
	public function test_the_writer_refuses_to_run_outside_a_write(): void {
		$post = $this->draft_post();
		$this->expectException( \LogicException::class );

		PostWriter::trash( $post, 'draft' );
	}

	/**
	 * A post someone else has open in the editor is refused by the edit lock, and nothing changes.
	 */
	public function test_a_post_open_in_the_editor_by_another_user_is_refused_by_the_edit_lock(): void {
		$post  = $this->draft_post();
		$other = self::factory()->user->create( array( 'role' => 'editor' ) );
		update_post_meta( $post, '_edit_lock', time() . ':' . $other );
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$before = $this->state( $post );

		$this->assert_refused( $this->trash( $post ), self::LOCKED_CODE );
		$this->assertSame( $before, $this->state( $post ) );
	}

	/**
	 * The rate limit applies to the trash like any other write: the second trash in the minute is refused.
	 */
	public function test_the_rate_limit_applies_to_the_trash(): void {
		$first  = $this->draft_post();
		$second = $this->draft_post();
		$this->on( 'steadhand_rate_limit_per_minute', static fn(): int => 1, 10, 2 );
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->assertIsArray( $this->trash( $first ) );
		$before = $this->state( $second );

		$this->assert_refused( $this->trash( $second ), self::LOCKED_CODE );
		$this->assertSame( $before, $this->state( $second ) );
	}

	/**
	 * A filter that stops WordPress from trashing the post makes the call fail honestly: nothing changes, and no marker is stored.
	 */
	public function test_a_filter_that_stops_the_trash_is_reported_and_stores_no_markers(): void {
		$post = $this->draft_post();
		$this->on( 'pre_trash_post', static fn(): bool => false, 10, 3 );
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$before = $this->state( $post );

		$this->assert_refused( $this->trash( $post ), 'steadhand_update_failed' );
		$this->assertSame( $before, $this->state( $post ) );
		$this->assertSame( '', $this->meta_of( $post, PostWriter::TRASHED_BY_META ) );
	}

	/**
	 * An earlier map_meta_cap filter that denies delete_post is never turned into a grant by Steadhand.
	 */
	public function test_a_do_not_allow_from_an_earlier_map_meta_cap_filter_is_never_turned_into_a_grant(): void {
		$post = $this->draft_post();
		$this->on(
			'map_meta_cap',
			static fn( array $caps, string $cap ): array => 'delete_post' === $cap ? array( 'do_not_allow' ) : $caps,
			1,
			4
		);
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$before = $this->state( $post );

		$this->assert_generic( $this->trash( $post ) );
		$this->assertSame( $before, $this->state( $post ) );
	}

	/**
	 * Undo refuses an entry of this ability. The entry is real, and its row says it is not undoable.
	 */
	public function test_undo_change_refuses_an_entry_of_trash_post_and_nothing_changes(): void {
		$post  = $this->draft_post();
		$agent = $this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );
		$entry  = (int) $this->latest_row( $agent )['id'];
		$before = $this->state( $post );

		$this->assert_refused( $this->execute_ability( 'steadhand/undo-change', array( 'id' => $entry ) ), 'steadhand_forbidden', self::NOT_UNDOABLE );
		$this->assertSame( $before, $this->state( $post ) );
	}

	/**
	 * The administrator's undo refuses an entry of this ability, with the not-undoable refusal, and nothing changes.
	 */
	public function test_the_administrator_undo_refuses_an_entry_of_trash_post_and_nothing_changes(): void {
		$post  = $this->draft_post();
		$agent = $this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );
		$entry  = (int) $this->latest_row( $agent )['id'];
		$before = $this->state( $post );

		wp_set_current_user( $this->admin_id() );
		$result = ( new Pipeline( new DbLogger() ) )->run_admin( new UndoEntry( new DbLogger() ), array( 'id' => $entry ) );

		$this->assert_refused( $result, 'steadhand_refused_not_undoable' );
		$this->assertSame( $before, $this->state( $post ) );
	}

	/**
	 * A trash stores all four markers, each with the value that its rule checks, and a restore clears all four. The modified-time marker is
	 * the post's own post_modified_gmt, read again after the trash, and the post-ID marker is the post's ID.
	 */
	public function test_a_trash_stores_all_four_markers_and_a_restore_clears_them(): void {
		$post  = $this->draft_post();
		$agent = $this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );

		$this->assertSame( (string) $agent, $this->meta_of( $post, PostWriter::TRASHED_BY_META ) );
		$this->assertSame( $this->meta_of( $post, PostWriter::TRASH_CORE_TIME_META ), $this->meta_of( $post, PostWriter::TRASH_TIME_META ) );
		$this->assertSame( TrashRules::current_modified_gmt( $post ), $this->meta_of( $post, PostWriter::TRASH_MODIFIED_META ) );
		$this->assertSame( (string) $post, $this->meta_of( $post, PostWriter::TRASH_POST_META ) );
		foreach ( TrashRules::MARKER_KEYS as $key ) {
			$this->assertSame( 1, $this->rows_of( $post, $key ), "The marker $key holds exactly one row." );
		}

		$this->assertIsArray( $this->restore( $post ) );
		foreach ( TrashRules::MARKER_KEYS as $key ) {
			$this->assertSame( 0, $this->rows_of( $post, $key ), "The restore removed $key." );
		}
	}

	/**
	 * Core's pre_trash_post filter refuses the trash. The stale core trash keys of an earlier trash stay as they were, one row each, and no
	 * marker is stored. Removing the stale keys happens only once core has allowed the trash, so a refused trash changes nothing.
	 */
	public function test_a_trash_that_core_refuses_leaves_the_stale_core_rows_and_stores_no_marker(): void {
		$post = $this->draft_post();
		add_post_meta( $post, TrashRules::CORE_STATUS_META, 'draft' );
		add_post_meta( $post, PostWriter::TRASH_CORE_TIME_META, '1700000000' );
		$this->on( 'pre_trash_post', static fn(): bool => false, 10, 3 );
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$before = $this->state( $post );

		$this->assert_refused( $this->trash( $post ), 'steadhand_update_failed' );

		$this->assertSame( $before, $this->state( $post ) );
		$this->assertSame( 1, $this->rows_of( $post, TrashRules::CORE_STATUS_META ), 'The stale stored-status row is still there.' );
		$this->assertSame( 1, $this->rows_of( $post, PostWriter::TRASH_CORE_TIME_META ), 'The stale trash-time row is still there.' );
		foreach ( TrashRules::MARKER_KEYS as $key ) {
			$this->assertSame( 0, $this->rows_of( $post, $key ), "No marker is stored for $key." );
		}
	}

	/**
	 * A trash that follows stale core rows ends with exactly one row of each core key, and with the markers, so the ownership rule can
	 * restore it.
	 */
	public function test_a_trash_after_stale_core_rows_ends_with_exactly_one_row_of_each_core_key(): void {
		$post = $this->draft_post();
		add_post_meta( $post, TrashRules::CORE_STATUS_META, 'draft' );
		add_post_meta( $post, PostWriter::TRASH_CORE_TIME_META, '1700000000' );
		$agent = $this->act_as_agent( array( Capabilities::TRASH => true ) );

		$this->assertIsArray( $this->trash( $post ) );

		$this->assertSame( 1, $this->rows_of( $post, TrashRules::CORE_STATUS_META ) );
		$this->assertSame( 1, $this->rows_of( $post, PostWriter::TRASH_CORE_TIME_META ) );
		$this->assertSame( (string) $agent, $this->meta_of( $post, PostWriter::TRASHED_BY_META ) );
		$this->assertSame( $this->meta_of( $post, PostWriter::TRASH_CORE_TIME_META ), $this->meta_of( $post, PostWriter::TRASH_TIME_META ) );
		$this->assertTrue( TrashRules::own_trash( $post, $agent ) );
	}

	/**
	 * The marker listeners take whatever another plugin passes: null, an object, a string, an array and numbers that are not positive IDs.
	 * None throws, and a value that is not a post ID changes no marker. A WP_Post is not a post ID either, so it is ignored by the
	 * trash and untrash listeners.
	 */
	public function test_the_marker_listeners_accept_any_value_a_caller_passes_without_throwing(): void {
		$post = $this->draft_post();
		update_post_meta( $post, PostWriter::TRASHED_BY_META, '5' );
		$post_object = get_post( $post );

		// Core's own listeners read the post from the value they are given: _reset_front_page_settings_for_post on wp_trash_post, and
		// _transition_post_status on transition_post_status. Both fail for a value that is not a post, so the test detaches the first
		// for its duration, and calls PostWriter::left_trash() directly for the transition, which is the method that hook is bound to.
		remove_action( 'wp_trash_post', '_reset_front_page_settings_for_post', 10 );
		try {
			// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's hooks, fired on purpose with values a plugin might pass.
			$values = array( null, $post_object, 'abc', array(), -4, 0, '', true );
			foreach ( $values as $value ) {
				do_action( 'wp_trash_post', $value );
				do_action( 'untrashed_post', $value );
			}

			// A WP_Post is a valid third argument for left_trash() (a post that really left the trash clears its markers), so it is
			// not passed here. The values that are not a post are.
			foreach ( array( null, 'abc', array(), -4, 0, '', true ) as $value ) {
				PostWriter::left_trash( 'draft', 'trash', $value );
			}
			// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		} finally {
			add_action( 'wp_trash_post', '_reset_front_page_settings_for_post', 10 );
		}

		$this->assertSame( '5', $this->meta_of( $post, PostWriter::TRASHED_BY_META ), 'No value that is not a post ID cleared the marker.' );
	}

	/**
	 * Uninstall removes all four trash markers from every post, and nothing else of the post. The file is run for real. Its role and
	 * change-log table are put back afterwards, as RegistrationTest and SchemaTest do.
	 */
	public function test_uninstall_removes_all_four_trash_markers_from_every_post(): void {
		$post  = $this->draft_post();
		$other = $this->live_post();
		update_post_meta( $post, PostWriter::TRASHED_BY_META, '5' );
		update_post_meta( $post, PostWriter::TRASH_MODIFIED_META, '2026-01-01 00:00:00' );
		update_post_meta( $post, PostWriter::TRASH_POST_META, (string) $post );
		update_post_meta( $other, PostWriter::TRASH_TIME_META, '1700000000' );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The constant core's uninstall files check.
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The constant core's uninstall files check.
			define( 'WP_UNINSTALL_PLUGIN', 'steadhand/steadhand.php' );
		}

		try {
			require dirname( __DIR__, 2 ) . '/uninstall.php';
		} finally {
			Roles::install();
			Schema::install();
		}

		foreach ( TrashRules::MARKER_KEYS as $key ) {
			$this->assertSame( 0, $this->rows_of( $post, $key ), "Uninstall removed $key from the post." );
			$this->assertSame( 0, $this->rows_of( $other, $key ), "Uninstall removed $key from the other post." );
		}
		$this->assertSame( 'Fixture post', get_post( $post )->post_title, 'The post itself is untouched.' );
	}

	/**
	 * The registry offers the trash ability with the others, and its capability is one the Agents screen offers.
	 */
	public function test_the_registry_lists_the_trash_ability_and_the_capability(): void {
		$registry = ( new Plugin() )->registry();

		$this->assertContains( self::TRASH, $registry->names() );
		$this->assertContains( self::RESTORE, $registry->names() );
		$this->assertContains( Capabilities::TRASH, $registry->capabilities_used() );
		$this->assertNotContains( Capabilities::TRASH, Capabilities::agent_defaults(), 'The trash is off by default.' );
	}

	/**
	 * The front page is protected whenever page_on_front holds its ID, even with show_on_front set to posts: core resets page_on_front to 0 when
	 * that page is trashed, so the saved selection would be lost.
	 */
	public function test_the_front_page_is_refused_when_show_on_front_is_posts_because_its_selection_is_saved_in_page_on_front(): void {
		$front = $this->admin_post( array( 'post_type' => 'page' ) );
		update_option( 'show_on_front', 'posts' );
		update_option( 'page_on_front', $front );
		$this->act_as_agent(
			array(
				Capabilities::TRASH   => true,
				Capabilities::PUBLISH => true,
			)
		);
		$before = $this->state( $front );

		$this->assert_refused( $this->trash( $front ), 'steadhand_refused_protected_page', self::PROTECTED );
		$this->assertSame( $before, $this->state( $front ) );
	}

	/**
	 * A sticky post is refused by the ability and by the writer: trashing it would take it out of the sticky posts, and a restore would not put it
	 * back. It stays published and sticky.
	 */
	public function test_a_sticky_post_is_refused_and_stays_sticky_and_published(): void {
		$post = $this->live_post();
		stick_post( $post );
		$this->act_as_agent(
			array(
				Capabilities::TRASH   => true,
				Capabilities::PUBLISH => true,
			)
		);
		$before = $this->state( $post );

		$this->assert_refused( $this->trash( $post ), 'steadhand_refused_sticky_post', self::STICKY );
		$this->assertSame( $before, $this->state( $post ) );
		$this->assertTrue( is_sticky( $post ), 'The post is still sticky.' );

		$writer = $this->in_write( static fn(): mixed => PostWriter::trash( $post, 'publish' ) );
		$this->assert_refused( $writer, 'steadhand_refused_sticky_post', self::STICKY );
		$this->assertSame( $before, $this->state( $post ) );
		$this->assertTrue( is_sticky( $post ), 'The writer leaves it sticky too.' );
	}
}
