<?php
/**
 * The steadhand/restore-post ability: it restores only a post this agent trashed, and only while that trash is the current one.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Pipeline\PostWriter;
use Steadhand\Plugin;

/**
 * Covers SPEC §5 (restore-post) and §14.1 (the trashed-post exception of delete_post). Every refusal gives one generic text, so
 * the tests check the code and the text, and that the post is still in the trash with its markers.
 */
final class RestorePostTest extends TrashTestCase {

	/** The warning for a published post restored as a draft. */
	private const AS_DRAFT = 'The post was publish before it was trashed. It was restored as a draft because this agent does not hold steadhand_publish.';

	/**
	 * Denies a capability to an agent at user level and reloads it as the current user, so the change reaches the current user's allcaps.
	 *
	 * @param int    $agent      Agent user ID.
	 * @param string $capability Steadhand capability.
	 */
	private function without_capability( int $agent, string $capability ): void {
		get_userdata( $agent )->add_cap( $capability, false );
		wp_set_current_user( 0 );
		wp_set_current_user( $agent );
	}

	/**
	 * The restore ability's registration: strict schemas, annotations, the capability, and that it is not public.
	 */
	public function test_is_registered_with_strict_schemas_annotations_and_is_not_public(): void {
		$ability = null;
		foreach ( ( new Plugin() )->registry()->abilities() as $candidate ) {
			if ( self::RESTORE === $candidate->name() ) {
				$ability = $candidate;
			}
		}
		$this->assertNotNull( $ability );
		$args = ( new Plugin() )->registry()->args_for( $ability );

		$this->assertSame(
			array(
				'readonly'    => false,
				'destructive' => false,
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
	}

	/**
	 * An agent restores its own trashed draft: it comes back as a draft, with its slug, and the markers are gone.
	 */
	public function test_restores_its_own_trashed_draft_as_a_draft_with_its_slug_and_clears_the_markers(): void {
		$post = $this->draft_post();
		$slug = (string) get_post( $post )->post_name;
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );

		$result = $this->restore( $post );

		$this->assertIsArray( $result );
		$this->assertSame( $post, $result['id'] );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( 'draft', $result['previous_status'] );
		$this->assertSame( $slug, $result['slug'] );
		$this->assertSame( 'draft', $this->status_of( $post ) );
		$this->assertSame( $slug, (string) get_post( $post )->post_name, 'The slug has no trashed suffix any more.' );
		$this->assertSame( '', $this->meta_of( $post, PostWriter::TRASHED_BY_META ) );
		$this->assertSame( '', $this->meta_of( $post, PostWriter::TRASH_TIME_META ) );
		$this->assertSame( '', $this->meta_of( $post, '_wp_trash_meta_time' ), 'WordPress removes its own trash keys too.' );
		$this->assertSame( array(), $result['warnings'] );
	}

	/**
	 * A post with a slug gets its slug back exactly, with no trashed suffix.
	 */
	public function test_a_real_slug_comes_back_exactly_without_the_trashed_suffix(): void {
		$post = $this->admin_post(
			array(
				'post_status' => 'draft',
				'post_name'   => 'spring-notes',
			)
		);
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );
		$this->assertStringEndsWith( '__trashed', (string) get_post( $post )->post_name, 'Control: the trash adds the suffix.' );

		$this->restore( $post );

		$this->assertSame( 'spring-notes', (string) get_post( $post )->post_name );
	}

	/**
	 * A published post comes back as published for an agent that holds steadhand_publish.
	 */
	public function test_restores_a_published_post_as_published_for_an_agent_with_steadhand_publish(): void {
		$post = $this->live_post();
		$this->act_as_agent(
			array(
				Capabilities::TRASH   => true,
				Capabilities::PUBLISH => true,
			)
		);
		$this->trash( $post );

		$result = $this->restore( $post );

		$this->assertIsArray( $result );
		$this->assertSame( 'publish', $result['status'] );
		$this->assertSame( 'publish', $result['previous_status'] );
		$this->assertSame( 'publish', $this->status_of( $post ) );
		$this->assertSame( array(), $result['warnings'] );
	}

	/**
	 * A published post trashed by an agent that had steadhand_publish comes back as a draft for an agent without it, with a warning.
	 */
	public function test_a_published_post_comes_back_as_a_draft_without_steadhand_publish_and_warns(): void {
		$post  = $this->live_post();
		$agent = $this->act_as_agent(
			array(
				Capabilities::TRASH   => true,
				Capabilities::PUBLISH => true,
			)
		);
		$this->trash( $post );
		$this->without_capability( $agent, Capabilities::PUBLISH );

		$result = $this->restore( $post );

		$this->assertIsArray( $result );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( 'publish', $result['previous_status'] );
		$this->assertSame( 'draft', $this->status_of( $post ) );
		$this->assertSame( array( self::AS_DRAFT ), $result['warnings'] );
	}

	/**
	 * A pending post comes back as pending, which needs no publish capability.
	 */
	public function test_a_pending_post_comes_back_as_pending(): void {
		$post = $this->admin_post( array( 'post_status' => 'pending' ) );
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );

		$result = $this->restore( $post );

		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertSame( 'pending', $this->status_of( $post ) );
	}

	/**
	 * The restore is recorded in the change log, and it is not undoable.
	 */
	public function test_the_restore_is_recorded_in_the_change_log_and_is_not_undoable(): void {
		$post  = $this->draft_post();
		$agent = $this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );

		$this->restore( $post );
		$row = $this->latest_row( $agent );

		$this->assertSame( self::RESTORE, $row['ability'] );
		$this->assertSame( 'ok', $row['result'] );
		$this->assertSame( 0, (int) $row['undoable'] );
	}

	/**
	 * A post trashed by an administrator is refused for an agent, with the generic text, and stays in the trash.
	 */
	public function test_a_post_trashed_by_an_administrator_is_refused_and_stays_in_the_trash(): void {
		$post = $this->draft_post();
		wp_trash_post( $post );
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$before = $this->state( $post );

		$this->assert_generic( $this->restore( $post ) );
		$this->assertSame( $before, $this->state( $post ) );
	}

	/**
	 * A post trashed by another agent is refused for this agent with the generic text.
	 */
	public function test_a_post_trashed_by_another_agent_is_refused_and_stays_in_the_trash(): void {
		$post = $this->draft_post();
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$before = $this->state( $post );

		$this->assert_generic( $this->restore( $post ) );
		$this->assertSame( $before, $this->state( $post ) );
	}

	/**
	 * An agent trashes a post, an administrator restores it and trashes it again. The agent cannot restore that trash, even when
	 * both trashes happen in the same second, because the restore clears the markers.
	 */
	public function test_a_post_the_agent_trashed_then_an_administrator_restored_and_trashed_again_is_refused(): void {
		$post = $this->draft_post();
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );

		wp_set_current_user( $this->admin_id() );
		wp_untrash_post( $post );
		wp_trash_post( $post );
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$before = $this->state( $post );

		$this->assertSame( 'trash', $before['status'] );
		$this->assert_generic( $this->restore( $post ) );
		$this->assertSame( $before, $this->state( $post ) );
	}

	/**
	 * A marker that no longer matches WordPress's trash time is refused. The markers are put back by hand, with WordPress's own trash
	 * time moved on, to test the comparison itself, separately from the restore's own clearing of the markers.
	 */
	public function test_a_trash_time_marker_that_no_longer_matches_wordpress_is_refused(): void {
		$post  = $this->draft_post();
		$agent = $this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );
		$marker = $this->meta_of( $post, PostWriter::TRASH_TIME_META );

		update_post_meta( $post, PostWriter::TRASH_CORE_TIME_META, (string) ( (int) $marker + 1000 ) );
		update_post_meta( $post, PostWriter::TRASHED_BY_META, (string) $agent );
		update_post_meta( $post, PostWriter::TRASH_TIME_META, $marker );

		$this->assert_generic( $this->restore( $post ) );
		$this->assertSame( 'trash', $this->status_of( $post ) );
	}

	/**
	 * A post that is not in the trash is refused with the generic text, and nothing changes.
	 */
	public function test_a_post_that_is_not_in_the_trash_is_refused_and_nothing_changes(): void {
		$post = $this->draft_post();
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$before = $this->state( $post );

		$this->assert_generic( $this->restore( $post ) );
		$this->assertSame( $before, $this->state( $post ) );
	}

	/**
	 * A missing ID, an attachment in the trash, and a post of a type that is not allowed in the trash are all refused with the same text.
	 * The attachment and the disallowed post carry this agent's markers, so only the object rule refuses them.
	 */
	public function test_a_missing_id_an_attachment_and_a_disallowed_type_in_the_trash_all_get_the_same_refusal(): void {
		$agent      = $this->act_as_agent( array( Capabilities::TRASH => true ) );
		$attachment = $this->attachment();
		wp_update_post(
			array(
				'ID'          => $attachment,
				'post_status' => 'trash',
			)
		);
		update_post_meta( $attachment, PostWriter::TRASHED_BY_META, (string) $agent );
		update_post_meta( $attachment, PostWriter::TRASH_TIME_META, '1700000000' );
		update_post_meta( $attachment, PostWriter::TRASH_CORE_TIME_META, '1700000000' );

		$disallowed = $this->disallowed_post();
		wp_update_post(
			array(
				'ID'          => $disallowed,
				'post_status' => 'trash',
			)
		);
		update_post_meta( $disallowed, PostWriter::TRASHED_BY_META, (string) $agent );
		update_post_meta( $disallowed, PostWriter::TRASH_TIME_META, '1700000000' );
		update_post_meta( $disallowed, PostWriter::TRASH_CORE_TIME_META, '1700000000' );

		foreach ( array(
			'a missing ID'      => self::MISSING_ID,
			'an attachment'     => $attachment,
			'a disallowed type' => $disallowed,
		) as $label => $id ) {
			$before = $this->state( $id );
			$this->assert_generic( $this->restore( $id ) );
			$this->assertSame( $before, $this->state( $id ), "Restoring $label must leave it as it was." );
		}
	}

	/**
	 * A caller without steadhand_trash is refused, and the post stays in the trash with its markers.
	 */
	public function test_a_caller_without_steadhand_trash_is_refused_and_the_post_stays_in_the_trash(): void {
		$post  = $this->draft_post();
		$agent = $this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );
		$this->without_capability( $agent, Capabilities::TRASH );
		$before = $this->state( $post );

		$this->assert_refused(
			$this->restore( $post ),
			'steadhand_forbidden',
			'This user needs the steadhand_trash capability to call steadhand/restore-post.'
		);
		$this->assertSame( $before, $this->state( $post ) );
	}

	/**
	 * The agent cannot read the trashed post through any tool: get-post refuses it, and so does a restore of a post it cannot see.
	 */
	public function test_a_trashed_post_cannot_be_read_by_the_agent_before_or_after_the_restore_is_refused(): void {
		$post = $this->draft_post();
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );

		$this->assert_generic( $this->execute_ability( 'steadhand/get-post', array( 'id' => $post ) ) );
		$this->restore( $post );
		$this->assertIsArray( $this->execute_ability( 'steadhand/get-post', array( 'id' => $post ) ) );
	}

	/**
	 * A restore that WordPress refuses (a filter stops it) changes nothing, and the markers stay for a later try.
	 */
	public function test_a_restore_that_wordpress_refuses_changes_nothing_and_keeps_the_markers(): void {
		$post = $this->draft_post();
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );
		$this->on( 'pre_untrash_post', static fn(): bool => false, 10, 3 );
		$before = $this->state( $post );

		$this->assert_refused( $this->restore( $post ), 'steadhand_update_failed' );
		$this->assertSame( $before, $this->state( $post ) );
		$this->assertNotSame( '', $this->meta_of( $post, PostWriter::TRASHED_BY_META ) );
	}

	/**
	 * The writer's restore refuses a post this agent did not trash, and writes nothing.
	 */
	public function test_the_writer_refuses_a_restore_of_a_post_that_is_not_its_own(): void {
		$post = $this->draft_post();
		wp_trash_post( $post );
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$before = $this->state( $post );

		$result = $this->in_write( static fn() => PostWriter::untrash( $post, 'draft' ) );

		$this->assert_generic( $result );
		$this->assertSame( $before, $this->state( $post ) );
	}
}
