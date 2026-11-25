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
use Steadhand\Pipeline\TrashRules;
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

	/** The warning for a post with no single stored status: it comes back as a draft. */
	private const NO_RECORD = 'WordPress holds no single record of the status the post had before the trash, so it was restored as a draft.';

	/** The warning for a post WordPress restored live, which Steadhand set to draft (the backstop case). */
	private const DEMOTED = 'WordPress restored the post as publish, so it was live for a moment and its publish actions ran. This agent may not publish, so Steadhand set it to draft.';

	/** The refusal for a draft or pending restore without steadhand_write_drafts. */
	private const NEEDS_WRITE_DRAFTS = 'Restoring a draft needs steadhand_write_drafts as well as steadhand_trash.';

	/** The partial-restore text for a post that WordPress left in the trash with its trash record removed. */
	private const RESTORE_FAILED_PART_WAY = 'The restore failed part-way. The post is still in the trash, and WordPress\'s trash record for it was removed, so WordPress will not delete it automatically and Steadhand can no longer restore it. An administrator should restore it from the Posts → Trash screen.';

	/**
	 * Runs the work with the three trash-marker listeners removed, so that only the rules in TrashRules::own_trash() can refuse a restore.
	 * They are added back in finally, with the priorities and argument counts Plugin::boot() gave them.
	 *
	 * @param callable $work The work to run.
	 * @return mixed What the work returned.
	 */
	private function without_marker_listeners( callable $work ): mixed {
		$hooks = array(
			array( 'wp_trash_post', 'trash_starting', 10, 1 ),
			array( 'transition_post_status', 'left_trash', 10, 3 ),
			array( 'untrashed_post', 'untrashed', 10, 1 ),
		);

		foreach ( $hooks as $hook ) {
			remove_action( $hook[0], array( PostWriter::class, $hook[1] ), $hook[2] );
		}

		try {
			return $work();
		} finally {
			foreach ( $hooks as $hook ) {
				add_action( $hook[0], array( PostWriter::class, $hook[1] ), $hook[2], $hook[3] );
			}
		}
	}

	/**
	 * The marker listeners are registered by Plugin::boot(), so the tests below that remove them are testing the real wiring.
	 */
	public function test_the_marker_listeners_are_registered_while_steadhand_is_loaded(): void {
		$this->assertIsInt( has_action( 'wp_trash_post', array( PostWriter::class, 'trash_starting' ) ) );
		$this->assertIsInt( has_action( 'transition_post_status', array( PostWriter::class, 'left_trash' ) ) );
		$this->assertIsInt( has_action( 'untrashed_post', array( PostWriter::class, 'untrashed' ) ) );
	}

	/**
	 * An agent trashes a draft. An administrator moves it out of the trash with a plain status update, which fires no untrashed_post, and
	 * trashes it again. The status change cleared the agent's markers, so the agent cannot restore that trash.
	 */
	public function test_a_status_update_out_of_the_trash_then_a_new_trash_by_an_administrator_is_refused_for_the_agent(): void {
		$post  = $this->draft_post();
		$agent = $this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );

		wp_set_current_user( $this->admin_id() );
		wp_update_post(
			array(
				'ID'          => $post,
				'post_status' => 'draft',
			)
		);
		wp_trash_post( $post );
		wp_set_current_user( $agent );

		$this->assertSame( '', $this->meta_of( $post, PostWriter::TRASHED_BY_META ), 'The status update cleared the agent\'s marker.' );
		$before = $this->state( $post );
		$this->assertSame( 'trash', $before['status'] );

		$this->assert_generic( $this->restore( $post ) );
		$this->assertSame( $before, $this->state( $post ) );
	}

	/**
	 * The same route with the listeners removed. The status update leaves the agent's markers, and the new trash adds a second row of each
	 * core key. The row-count rule in TrashRules::own_trash() alone refuses the restore.
	 */
	public function test_without_the_listeners_the_row_count_rule_alone_refuses_a_restore_after_a_status_update_and_a_new_trash(): void {
		$post  = $this->draft_post();
		$agent = $this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );

		$this->without_marker_listeners(
			function () use ( $post, $agent ): void {
				wp_set_current_user( $this->admin_id() );
				wp_update_post(
					array(
						'ID'          => $post,
						'post_status' => 'draft',
					)
				);
				wp_trash_post( $post );
				wp_set_current_user( $agent );
			}
		);

		$this->assertSame( (string) $agent, $this->meta_of( $post, PostWriter::TRASHED_BY_META ), 'Control: the agent\'s marker survived.' );
		$this->assertSame( 1, $this->rows_of( $post, PostWriter::TRASHED_BY_META ), 'Control: one marker row.' );
		$this->assertSame( 2, $this->rows_of( $post, PostWriter::TRASH_CORE_TIME_META ), 'Control: two core trash-time rows.' );

		$before = $this->state( $post );
		$this->assert_generic( $this->restore( $post ) );
		$this->assertSame( $before, $this->state( $post ) );
	}

	/**
	 * An agent trashes a post, an administrator restores and trashes it again, with the listeners removed. Each core key then has one row, so
	 * the time comparison is what refuses. The agent's marker time is changed to a different value, to show that comparison alone decides.
	 */
	public function test_without_the_listeners_the_time_comparison_alone_refuses_a_restore_after_an_administrator_restored_and_trashed_again(): void {
		$post  = $this->draft_post();
		$agent = $this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );

		$this->without_marker_listeners(
			function () use ( $post, $agent ): void {
				wp_set_current_user( $this->admin_id() );
				wp_untrash_post( $post );
				wp_trash_post( $post );
				wp_set_current_user( $agent );

				$core = $this->meta_of( $post, PostWriter::TRASH_CORE_TIME_META );
				update_post_meta( $post, PostWriter::TRASH_TIME_META, (string) ( (int) $core + 1000 ) );
			}
		);

		$this->assertSame( 1, $this->rows_of( $post, PostWriter::TRASHED_BY_META ), 'Control: one marker row.' );
		$this->assertSame( 1, $this->rows_of( $post, PostWriter::TRASH_TIME_META ), 'Control: one marker time row.' );
		$this->assertSame( 1, $this->rows_of( $post, PostWriter::TRASH_CORE_TIME_META ), 'Control: one core trash-time row.' );
		$this->assertNotSame(
			$this->meta_of( $post, PostWriter::TRASH_CORE_TIME_META ),
			$this->meta_of( $post, PostWriter::TRASH_TIME_META ),
			'Control: the marker time differs from WordPress\'s.'
		);

		$before = $this->state( $post );
		$this->assertSame( 'trash', $before['status'] );
		$this->assert_generic( $this->restore( $post ) );
		$this->assertSame( $before, $this->state( $post ) );
	}

	/**
	 * A published post is trashed by an agent that holds steadhand_publish, and a second status row is added. With two rows of the stored status
	 * there is no single record, so the post comes back as a draft even for an agent that holds steadhand_publish.
	 */
	public function test_duplicate_stored_status_rows_restore_the_post_as_a_draft_even_with_steadhand_publish(): void {
		$post = $this->live_post();
		$this->act_as_agent(
			array(
				Capabilities::TRASH   => true,
				Capabilities::PUBLISH => true,
			)
		);
		$this->trash( $post );
		add_post_meta( $post, '_wp_trash_meta_status', 'publish' );
		$this->assertSame( 2, $this->rows_of( $post, '_wp_trash_meta_status' ), 'Control: two stored-status rows.' );

		$result = $this->restore( $post );

		$this->assertIsArray( $result );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( '', $result['previous_status'] );
		$this->assertSame( array( self::NO_RECORD ), $result['warnings'] );
		$this->assertSame( 'draft', $this->status_of( $post ) );
	}

	/**
	 * The backstop. A plugin's wp_insert_post_data filter that runs after Steadhand's own first layer (added during the restore, at
	 * PHP_INT_MAX) makes the restore save live, so the publish actions run. Steadhand then sets the post to draft with a status-only
	 * save, and the warning says the post was live for a moment. The filter is removed when untrashed_post fires, so the draft save is
	 * not forced.
	 */
	public function test_a_plugin_that_publishes_past_the_first_layer_is_demoted_and_the_warning_says_it_was_live_for_a_moment(): void {
		$post = $this->draft_post();
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );

		$force = null;
		$this->on(
			'pre_untrash_post',
			static function () use ( &$force, $post ): ?bool {
				// Added during the restore, after Steadhand's own filter, so it runs last.
				$force = static function ( array $data, array $postarr ) use ( $post ): array {
					if ( (int) ( $postarr['ID'] ?? 0 ) === $post ) {
						$data['post_status'] = 'publish';
					}

					return $data;
				};
				add_filter( 'wp_insert_post_data', $force, PHP_INT_MAX, 2 );

				return null;
			},
			10,
			3
		);
		$this->on(
			'untrashed_post',
			static function () use ( &$force ): void {
				if ( null !== $force ) {
					remove_filter( 'wp_insert_post_data', $force, PHP_INT_MAX );
					$force = null;
				}
			},
			5,
			2
		);

		$went_live = array();
		$this->on(
			'transition_post_status',
			static function ( string $new_status, string $old_status, \WP_Post $saved ) use ( &$went_live, $post ): void {
				unset( $old_status );
				if ( $saved->ID === $post ) {
					$went_live[] = $new_status;
				}
			},
			10,
			3
		);

		try {
			$result = $this->restore( $post );
		} finally {
			if ( null !== $force ) {
				remove_filter( 'wp_insert_post_data', $force, PHP_INT_MAX );
			}
		}

		$this->assertIsArray( $result );
		$this->assertContains( 'publish', $went_live, 'Control: the publish actions ran during the restore.' );
		// The backstop runs before the slug save, which is held to the requested draft, so the live save happens once and the slug save
		// does not make the post live again.
		$this->assertSame( 1, count( array_keys( $went_live, 'publish', true ) ), 'A transition to publish fires once for the post.' );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( array( self::DEMOTED ), $result['warnings'] );
		$this->assertSame( 'draft', $this->status_of( $post ) );
	}

	/**
	 * The first layer. A plugin forces publish on the restore's save, at a normal priority, so it runs before Steadhand's filter. The
	 * save is held to the requested draft, so the post is never live: no transition to publish fires for it, and nothing is demoted.
	 */
	public function test_a_plugin_that_forces_publish_on_the_restore_cannot_make_the_post_live(): void {
		$post = $this->draft_post();
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );
		$this->on(
			'wp_insert_post_data',
			static function ( array $data, array $postarr ) use ( $post ): array {
				if ( (int) ( $postarr['ID'] ?? 0 ) === $post ) {
					$data['post_status'] = 'publish';
				}

				return $data;
			},
			10,
			2
		);

		$went_to = array();
		$this->on(
			'transition_post_status',
			static function ( string $new_status, string $old_status, \WP_Post $saved ) use ( &$went_to, $post ): void {
				unset( $old_status );
				if ( $saved->ID === $post ) {
					$went_to[] = $new_status;
				}
			},
			10,
			3
		);

		$result = $this->restore( $post );

		$this->assertIsArray( $result );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( array(), $result['warnings'], 'Nothing was demoted, so no warning.' );
		$this->assertNotContains( 'publish', $went_to, 'No transition to publish fires for the post.' );
		$this->assertSame( 'draft', $this->status_of( $post ) );
	}

	/**
	 * The hold never raises a status and never overrides a plugin's choice for a status that is not live. An agent that may publish
	 * restores a published post, and a plugin lowers the save to pending at a normal priority. The hold does not put publish back: the
	 * post ends pending, the output says so, and no transition to publish fires for the post.
	 */
	public function test_a_plugin_that_lowers_a_live_restore_to_pending_is_not_overridden_by_the_hold(): void {
		$post = $this->live_post();
		$this->act_as_agent(
			array(
				Capabilities::TRASH   => true,
				Capabilities::PUBLISH => true,
			)
		);
		$this->trash( $post );
		$this->on(
			'wp_insert_post_data',
			static function ( array $data, array $postarr ) use ( $post ): array {
				if ( (int) ( $postarr['ID'] ?? 0 ) === $post && 'publish' === ( $data['post_status'] ?? '' ) ) {
					$data['post_status'] = 'pending';
				}

				return $data;
			},
			10,
			2
		);

		$went_to = array();
		$this->on(
			'transition_post_status',
			static function ( string $new_status, string $old_status, \WP_Post $saved ) use ( &$went_to, $post ): void {
				unset( $old_status );
				if ( $saved->ID === $post ) {
					$went_to[] = $new_status;
				}
			},
			10,
			3
		);

		$result = $this->restore( $post );

		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertSame( 'pending', $this->status_of( $post ) );
		$this->assertSame( array( 'WordPress restored the post as pending, not publish.' ), $result['warnings'] );
		$this->assertNotContains( 'publish', $went_to, 'No transition to publish fires for the post.' );
	}

	/**
	 * The hold leaves a status that is not live alone. A draft is restored for an agent that may write drafts, and a plugin changes the
	 * save from draft to pending. Neither status is live, so the plugin's choice stands: the post ends pending, and the output reports it.
	 */
	public function test_a_plugin_that_changes_a_restored_draft_to_pending_keeps_pending(): void {
		$post = $this->draft_post();
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );
		$this->on(
			'wp_insert_post_data',
			static function ( array $data, array $postarr ) use ( $post ): array {
				if ( (int) ( $postarr['ID'] ?? 0 ) === $post && 'draft' === ( $data['post_status'] ?? '' ) ) {
					$data['post_status'] = 'pending';
				}

				return $data;
			},
			10,
			2
		);

		$result = $this->restore( $post );

		$this->assertIsArray( $result );
		$this->assertSame( 'pending', $result['status'] );
		$this->assertSame( 'pending', $this->status_of( $post ) );
		$this->assertSame( array( 'WordPress restored the post as pending, not draft.' ), $result['warnings'] );
	}

	/**
	 * A scheduled post whose date has passed. The agent may publish, so the requested status is future, which is live, and the hold does
	 * not act. Core's own handling of a future post with a past date then decides the status, and the restore must end where the same post
	 * ends with only the wp_untrash_post_status filter, and not as future with a past date.
	 */
	public function test_a_scheduled_post_with_a_past_date_restores_where_core_puts_it(): void {
		$agent = $this->act_as_agent(
			array(
				Capabilities::TRASH   => true,
				Capabilities::PUBLISH => true,
			)
		);

		$post    = $this->trashed_scheduled_post_with_past_date( $agent );
		$control = $this->trashed_scheduled_post_with_past_date( $agent );

		// Control: core alone. Only the wp_untrash_post_status filter is in place, as the first layer would put it.
		wp_set_current_user( $this->admin_id() );
		$to_future = static fn(): string => 'future';
		add_filter( 'wp_untrash_post_status', $to_future, PHP_INT_MAX, 3 );
		try {
			wp_untrash_post( $control );
		} finally {
			remove_filter( 'wp_untrash_post_status', $to_future, PHP_INT_MAX );
		}
		$expected = $this->status_of( $control );
		wp_set_current_user( $agent );

		$result = $this->restore( $post );

		$this->assertSame( 'publish', $expected, 'Control: core makes a future post with a past date published.' );
		$this->assertIsArray( $result );
		$this->assertSame( $expected, $result['status'] );
		$this->assertSame( $expected, $this->status_of( $post ) );
		$this->assertSame( array( 'WordPress restored the post as publish, not future.' ), $result['warnings'] );
	}

	/**
	 * A trashed scheduled post, trashed by the agent, whose date is then moved into the past. It is made as a future post with a date ahead,
	 * because core makes a future post with a past date published when it is saved. The date is changed with a direct update, which leaves the
	 * modified time alone, so the post is still the agent's own trash.
	 *
	 * @param int $agent The agent, who trashes the post.
	 * @return int The post ID.
	 */
	private function trashed_scheduled_post_with_past_date( int $agent ): int {
		wp_set_current_user( $agent );
		$post = $this->admin_post(
			array(
				'post_status'   => 'future',
				'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ) ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() + HOUR_IN_SECONDS ),
			)
		);
		$this->trash( $post );

		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture: a scheduled post whose date has passed, which the API cannot store.
			$wpdb->posts,
			array(
				'post_date'     => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ),
				'post_date_gmt' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ),
			),
			array( 'ID' => $post )
		);
		clean_post_cache( $post );

		return $post;
	}

	/**
	 * A plugin's restore-status filter that makes the restore live, with the first layer in place, is held to the requested draft too.
	 * This is the route the older test used. It now stays in the trash-to-draft path and does not reach the demotion.
	 */
	public function test_a_restore_status_filter_that_asks_for_publish_is_held_to_draft_by_the_first_layer(): void {
		$post = $this->draft_post();
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );
		$this->on(
			'wp_untrash_post_status',
			static fn(): string => 'publish',
			PHP_INT_MAX,
			3
		);

		$result = $this->restore( $post );

		$this->assertIsArray( $result );
		$this->assertSame( 'draft', $result['status'] );
		$this->assertSame( array(), $result['warnings'] );
		$this->assertSame( 'draft', $this->status_of( $post ) );
	}

	/**
	 * A restore that WordPress completes with a trash record it has removed, but that Steadhand cannot store, is not reported as a plain
	 * failure. Here the restore's own save fails, so wp_untrash_post() removes WordPress's trash time, makes no update, and returns false
	 * before its untrashed_post action. The post stays in the trash with no trash record. WordPress will not delete it, so Steadhand says so,
	 * clears its markers itself (no listener does, because untrashed_post did not fire), and asks an administrator to act.
	 *
	 * The failure is a real one in core: wp_update_post() returns 0 when wp_insert_post_empty_content is true for the save, which makes
	 * wp_insert_post() return before it writes anything.
	 */
	public function test_a_restore_that_fails_after_wordpress_removed_its_trash_record_says_so_and_clears_the_markers(): void {
		$post = $this->draft_post();
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );
		$this->assertSame( 1, $this->rows_of( $post, PostWriter::TRASH_CORE_TIME_META ), 'Control: WordPress holds its trash time.' );

		$untrashed = 0;
		$this->on(
			'untrashed_post',
			static function () use ( &$untrashed ): void {
				++$untrashed;
			},
			PHP_INT_MAX,
			2
		);

		$empty_save = static function ( bool $maybe_empty, array $postarr ) use ( $post ): bool {
			return (int) ( $postarr['ID'] ?? 0 ) === $post ? true : $maybe_empty;
		};
		add_filter( 'wp_insert_post_empty_content', $empty_save, 10, 2 );
		try {
			$result = $this->restore( $post );
		} finally {
			remove_filter( 'wp_insert_post_empty_content', $empty_save, 10 );
		}

		$this->assertSame( 0, $untrashed, 'The restore did not finish, so untrashed_post did not fire and no listener cleared the markers.' );
		$this->assert_refused( $result, 'steadhand_update_partial', self::RESTORE_FAILED_PART_WAY );
		$this->assertSame( 'trash', $this->status_of( $post ) );
		$this->assertSame( 0, $this->rows_of( $post, PostWriter::TRASH_CORE_TIME_META ), 'WordPress removed its trash time.' );
		$this->assertSame( '', $this->meta_of( $post, PostWriter::TRASHED_BY_META ), 'The agent\'s markers are cleared.' );
		$this->assertSame( '', $this->meta_of( $post, PostWriter::TRASH_TIME_META ) );
		$this->assertSame( '', $this->meta_of( $post, PostWriter::TRASH_MODIFIED_META ) );
		$this->assertSame( '', $this->meta_of( $post, PostWriter::TRASH_POST_META ) );
	}

	/**
	 * The restore needs steadhand_write_drafts for a draft: an own trashed draft, with the capability revoked, is refused with its text,
	 * and stays in the trash.
	 */
	public function test_restoring_an_own_draft_needs_steadhand_write_drafts_and_the_post_stays_in_the_trash(): void {
		$post  = $this->draft_post();
		$agent = $this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );
		$this->without_capability( $agent, Capabilities::WRITE_DRAFTS );
		$before = $this->state( $post );

		$this->assert_refused( $this->restore( $post ), 'steadhand_forbidden', self::NEEDS_WRITE_DRAFTS );
		$this->assertSame( $before, $this->state( $post ) );
	}

	/**
	 * A live post comes back as a draft when the agent lacks steadhand_publish, so the restore also needs steadhand_write_drafts. With both
	 * revoked it is refused with the draft text.
	 */
	public function test_a_live_post_that_would_come_back_as_a_draft_needs_steadhand_write_drafts_too(): void {
		$post  = $this->live_post();
		$agent = $this->act_as_agent(
			array(
				Capabilities::TRASH   => true,
				Capabilities::PUBLISH => true,
			)
		);
		$this->trash( $post );
		$this->without_capability( $agent, Capabilities::PUBLISH );
		$this->without_capability( $agent, Capabilities::WRITE_DRAFTS );
		$before = $this->state( $post );

		$this->assert_refused( $this->restore( $post ), 'steadhand_forbidden', self::NEEDS_WRITE_DRAFTS );
		$this->assertSame( $before, $this->state( $post ) );
	}

	/**
	 * Without steadhand_write_drafts, a post that is not the agent's own trash gets the one generic text, not the draft text.
	 */
	public function test_a_draft_trashed_by_an_administrator_gets_the_generic_text_without_steadhand_write_drafts(): void {
		$post = $this->draft_post();
		wp_trash_post( $post );
		$agent = $this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->without_capability( $agent, Capabilities::WRITE_DRAFTS );
		$before = $this->state( $post );

		$this->assert_generic( $this->restore( $post ) );
		$this->assertSame( $before, $this->state( $post ) );
	}

	/**
	 * Without the listeners, a status update out of the trash and a direct update back into it leave the core trash keys with one row each,
	 * and the agent's trashed-by and trash-time markers match. Only the modified-time marker no longer matches the post, so only it refuses
	 * the restore. The row counts show that each of the others held.
	 */
	public function test_without_the_listeners_the_modified_time_marker_refuses_a_status_update_out_of_the_trash_then_a_direct_trash(): void {
		$post  = $this->draft_post();
		$agent = $this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );

		// The status update and the direct trash must fall in a later second, so post_modified_gmt is a different value.
		sleep( 1 );

		$this->without_marker_listeners(
			function () use ( $post, $agent ): void {
				wp_set_current_user( $this->admin_id() );
				wp_update_post(
					array(
						'ID'          => $post,
						'post_status' => 'draft',
					)
				);
				wp_update_post(
					array(
						'ID'          => $post,
						'post_status' => 'trash',
					)
				);
				wp_set_current_user( $agent );
			}
		);

		$this->assertSame( 'trash', $this->status_of( $post ), 'Control: the post is in the trash again.' );
		$this->assertSame( (string) $agent, $this->meta_of( $post, PostWriter::TRASHED_BY_META ), 'Control: the trashed-by marker survived.' );
		$this->assertSame( 1, $this->rows_of( $post, PostWriter::TRASHED_BY_META ), 'Control: one trashed-by row.' );
		$this->assertSame( 1, $this->rows_of( $post, PostWriter::TRASH_TIME_META ), 'Control: one trash-time row.' );
		$this->assertSame( 1, $this->rows_of( $post, PostWriter::TRASH_CORE_TIME_META ), 'Control: one core trash-time row.' );
		$this->assertSame( 1, $this->rows_of( $post, PostWriter::TRASH_POST_META ), 'Control: one post-ID row.' );
		$this->assertSame( 1, $this->rows_of( $post, PostWriter::TRASH_MODIFIED_META ), 'Control: one modified-time row.' );
		$this->assertSame(
			$this->meta_of( $post, PostWriter::TRASH_CORE_TIME_META ),
			$this->meta_of( $post, PostWriter::TRASH_TIME_META ),
			'Control: the trash-time marker still matches WordPress.'
		);
		$this->assertNotSame(
			$this->meta_of( $post, PostWriter::TRASH_MODIFIED_META ),
			TrashRules::current_modified_gmt( $post ),
			'The modified-time marker no longer matches the post: the only rule left to refuse.'
		);

		$before = $this->state( $post );
		$this->assert_generic( $this->restore( $post ) );
		$this->assertSame( $before, $this->state( $post ) );
	}

	/**
	 * A copy of a trashed post, in the trash with every meta row of the original copied to it, is not restorable by the agent. The copy has a
	 * new ID, so the post-ID marker refuses it. Its modified time is set to the original's, so the other three conditions all hold, and the
	 * post-ID marker is the only one that refuses it.
	 */
	public function test_a_copy_of_a_trashed_post_with_all_its_meta_cannot_be_restored_by_the_agent(): void {
		$post  = $this->draft_post();
		$agent = $this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );

		wp_set_current_user( $this->admin_id() );
		$copy = (int) wp_insert_post(
			array(
				'post_title'  => 'Copy of the fixture',
				'post_status' => 'trash',
				'post_author' => $this->admin_id(),
			)
		);
		foreach ( get_post_meta( $post ) as $key => $values ) {
			foreach ( $values as $value ) {
				add_post_meta( $copy, (string) $key, $value );
			}
		}
		wp_set_current_user( $agent );

		// The copy gets the original's modified time, so its modified-time marker matches its post_modified_gmt, and only the post-ID marker can refuse it.
		$modified_gmt = $this->meta_of( $post, PostWriter::TRASH_MODIFIED_META );
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture: a copy whose modified time is the original's, which no API call sets.
			$wpdb->posts,
			array(
				'post_modified'     => get_date_from_gmt( $modified_gmt ),
				'post_modified_gmt' => $modified_gmt,
			),
			array( 'ID' => $copy )
		);
		clean_post_cache( $copy );
		clean_post_cache( $post );
		$this->assertSame( $modified_gmt, (string) get_post( $copy )->post_modified_gmt, 'Control: the copy has the original\'s modified time.' );
		$this->assertSame( $modified_gmt, (string) get_post( $post )->post_modified_gmt, 'Control: the original still has the modified time its marker holds.' );

		$this->assertSame( 1, $this->rows_of( $copy, PostWriter::TRASH_POST_META ), 'Control: the copy holds the original\'s post-ID marker.' );
		$this->assertSame( (string) $post, $this->meta_of( $copy, PostWriter::TRASH_POST_META ), 'Control: the marker names the original.' );
		$this->assertSame( 1, $this->rows_of( $copy, PostWriter::TRASHED_BY_META ), 'Control: one trashed-by row on the copy.' );

		// The other three conditions of TrashRules::own_trash(), checked one by one, so the refusal can only come from the post-ID marker.
		$this->assertSame( (string) $agent, $this->meta_of( $copy, PostWriter::TRASHED_BY_META ), 'Condition 1: the trashed-by marker is the agent.' );
		$this->assertSame(
			$this->meta_of( $copy, PostWriter::TRASH_CORE_TIME_META ),
			$this->meta_of( $copy, PostWriter::TRASH_TIME_META ),
			'Condition 2: the trash-time marker equals WordPress\'s trash time, with one row of each.'
		);
		$this->assertSame( 1, $this->rows_of( $copy, PostWriter::TRASH_CORE_TIME_META ) );
		$this->assertSame( 1, $this->rows_of( $copy, PostWriter::TRASH_TIME_META ) );
		$this->assertSame( $modified_gmt, $this->meta_of( $copy, PostWriter::TRASH_MODIFIED_META ), 'Condition 3: the modified-time marker equals the copy\'s post_modified_gmt.' );
		$this->assertSame( 1, $this->rows_of( $copy, PostWriter::TRASH_MODIFIED_META ) );

		$before = $this->state( $copy );
		$this->assert_generic( $this->restore( $copy ) );
		$this->assertSame( $before, $this->state( $copy ) );
		$this->assertSame( 'trash', $this->status_of( $post ), 'The original is still in the trash.' );
		$this->assertSame( (string) $agent, $this->meta_of( $post, PostWriter::TRASHED_BY_META ), 'The original keeps its markers.' );
	}

	/**
	 * The restore saves the post live, and the draft save that follows is forced live too. The post stays published, and the call is refused
	 * with steadhand_update_partial, which says the post is published and that an administrator should check it.
	 */
	public function test_a_post_that_stays_live_after_the_draft_save_is_refused_as_partial_and_says_an_administrator_should_check_it(): void {
		$post = $this->draft_post();
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );

		// Added during the restore, after Steadhand's first layer, so it outranks it: the restore save and the draft save are both live.
		$force = static function ( array $data, array $postarr ) use ( $post ): array {
			if ( (int) ( $postarr['ID'] ?? 0 ) === $post && 'draft' === ( $data['post_status'] ?? '' ) ) {
				$data['post_status'] = 'publish';
			}

			return $data;
		};
		$this->on(
			'pre_untrash_post',
			static function () use ( $force ): ?bool {
				add_filter( 'wp_insert_post_data', $force, PHP_INT_MAX, 2 );

				return null;
			},
			10,
			3
		);

		try {
			$result = $this->restore( $post );
		} finally {
			remove_filter( 'wp_insert_post_data', $force, PHP_INT_MAX );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_update_partial', $result->get_error_code() );
		$this->assertStringContainsString( 'The post was restored and is now publish.', $result->get_error_message() );
		$this->assertStringContainsString( 'an administrator should check it', $result->get_error_message() );
		$this->assertSame( 'publish', $this->status_of( $post ) );
	}
}
