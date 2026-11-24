<?php
/**
 * The administrator's undo: reverses one change-log entry from the admin log screen.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Admin;

use Steadhand\Abilities\Ability;
use Steadhand\Abilities\PostFields;
use Steadhand\Abilities\PostReader;
use Steadhand\Abilities\SetFeaturedImage;
use Steadhand\Abilities\SetPostTerms;
use Steadhand\Abilities\TermReader;
use Steadhand\Abilities\UndoRules;
use Steadhand\Abilities\UpdatePost;
use Steadhand\Agents;
use Steadhand\Capabilities;
use Steadhand\Guardrails\Engine;
use Steadhand\Guardrails\Rules\EditLock;
use Steadhand\Log\DbLogger;
use Steadhand\Pipeline\AdminAction;
use Steadhand\Pipeline\MediaWriter;
use Steadhand\Pipeline\Pipeline;
use Steadhand\Pipeline\PostWriter;
use Steadhand\Pipeline\Snapshots;
use Steadhand\Pipeline\TermWriter;
use Steadhand\PostTypes;
use Steadhand\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Reverses one change-log entry for an administrator. It is run only by Pipeline::run_admin(), from the log screen's Undo button
 * (UndoHandler). It is NOT registered with the Abilities API or the MCP server, and no agent can reach it: Pipeline::run() and
 * Pipeline::check() refuse an AdminAction.
 *
 * It undoes the same four kinds of change as steadhand/undo-change (UndoRules), and it restores each kind the same way: post text
 * from the core revisions, terms and the featured image from the saved states. The rules that are not about the saved state are
 * the administrator's own: the caller must hold manage_options and no agent role, and the native capabilities are checked directly
 * (edit_post for the post; the taxonomy's assign_terms for terms). The Steadhand agent rules do not apply: no Steadhand
 * capability, no own-entry rule, no guardrail engine, no publish-capability rule. The "unchanged since" rules do apply, exactly as
 * for the agent.
 *
 * The undo is not undoable. It takes no snapshot, and its own row is stored with snapshot 0 and undoable 0. The change-log entry
 * it reverses is marked with the undo's row ID, under the entry owner's user ID.
 *
 * The writes go through PostWriter, TermWriter and MediaWriter only.
 */
final class UndoEntry extends Ability implements AdminAction {

	/** The ability name. */
	public const NAME = 'steadhand/admin-undo';

	/**
	 * Constructor.
	 *
	 * @param DbLogger $logger The change log: read for the entry, and marked when the change is undone.
	 */
	public function __construct( private DbLogger $logger ) {}

	/**
	 * Returns the ability name.
	 *
	 * @return string
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * Returns the human-readable label.
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Undo change (administrator)', 'steadhand' );
	}

	/**
	 * Returns the description. It is for the administrator's log, not for an agent.
	 *
	 * @return string
	 */
	public function description(): string {
		return __( 'Reverses one change-log entry, for an administrator. Not available to agents.', 'steadhand' );
	}

	/**
	 * Returns the JSON Schema for the input.
	 *
	 * @return array<string, mixed>
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'id' ),
			'properties'           => array(
				'id' => array_merge(
					PostReader::id_schema(),
					array( 'description' => __( 'The change-log entry to undo.', 'steadhand' ) )
				),
			),
		);
	}

	/**
	 * Returns the JSON Schema for the output.
	 *
	 * @return array<string, mixed>
	 */
	public function output_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'entry_id', 'object_type', 'object_id', 'undone' ),
			'properties'           => array(
				'entry_id'        => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => PostReader::MAX_ID,
				),
				'object_type'     => array(
					'type' => 'string',
					'enum' => array( 'post' ),
				),
				'object_id'       => PostReader::id_schema(),
				'undone'          => array( 'type' => 'boolean' ),
				'guardrail_notes' => array(
					'type'     => 'array',
					'items'    => array( 'type' => 'string' ),
					'maxItems' => Engine::MAX_NOTES,
				),
			),
		);
	}

	/**
	 * Returns the annotations: a write, destructive (it overwrites the post's text, terms or image), not idempotent (the second undo
	 * is refused), the same as steadhand/undo-change.
	 *
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	public function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => true,
			'idempotent'  => false,
		);
	}

	/**
	 * The capability this ability is labelled with. It is used only so that the pipeline treats the call as a write. No
	 * authorization reads it: the administrator's checks are in authorize_object().
	 *
	 * @return string
	 */
	public function capability(): string {
		return Capabilities::WRITE_DRAFTS;
	}

	/**
	 * Names the post the entry is about, for the change log, when the entry exists and is about a post. Otherwise no object.
	 *
	 * @param array<string, mixed> $input Validated, sanitized input.
	 * @return array{type: string, id: int}
	 */
	public function log_object( array $input ): array {
		$row = $this->logger->find_any( (int) $input['id'] );
		if ( null === $row || 'post' !== (string) $row['object_type'] || (int) $row['object_id'] <= 0 ) {
			return array(
				'type' => '',
				'id'   => 0,
			);
		}

		return array(
			'type' => 'post',
			'id'   => (int) $row['object_id'],
		);
	}

	/**
	 * Whether the administrator may undo this entry now. See check().
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return bool
	 */
	public function authorize_object( array $input ): bool {
		return is_array( $this->check( (int) $input['id'] ) );
	}

	/**
	 * The refusal that says why the undo is refused, for the handler to map to a notice. Null never occurs for a refused undo.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return \WP_Error|null
	 */
	public function object_refusal_detail( array $input ): ?\WP_Error {
		$checked = $this->check( (int) $input['id'] );

		return is_wp_error( $checked ) ? $checked : null;
	}

	/**
	 * Reverses the entry. Every check is repeated first, because the post may have changed since authorization. Then the restore
	 * for the entry's kind runs, and the entry is marked with this call's row.
	 *
	 * @param array<string, mixed> $input Validated, sanitized input.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function execute( array $input ): array|\WP_Error {
		$entry_id = (int) $input['id'];
		$row      = $this->check( $entry_id );
		if ( is_wp_error( $row ) ) {
			return $row;
		}

		return match ( UndoRules::kind_of( $row ) ) {
			UndoRules::KIND_TERMS    => $this->restore_terms( $entry_id, $row ),
			UndoRules::KIND_FEATURED => $this->restore_featured( $entry_id, $row ),
			default                  => $this->restore_text( $entry_id, $row ),
		};
	}

	/**
	 * The whole decision: the caller, the entry, the post, and the state the entry's kind needs. Returns the entry row when every
	 * check holds, otherwise the refusal. It reads only; the restore checks that cannot be made before the write are in
	 * restore_text() (the revision that was saved after the change) and in the writers.
	 *
	 * @param int $entry_id Change-log entry ID.
	 * @return array<string, mixed>|\WP_Error
	 */
	private function check( int $entry_id ): array|\WP_Error {
		$caller = $this->caller_allowed();
		if ( is_wp_error( $caller ) ) {
			return $caller;
		}

		$row = $this->logger->find_any( $entry_id );
		if ( null === $row ) {
			return $this->forbidden();
		}

		// An entry with no user could be restored and then fail to be marked, so it is refused before any write.
		if ( ! UndoRules::structurally_eligible( $row ) || (int) $row['user_id'] < 1 ) {
			return $this->not_undoable();
		}

		// The post's status must be one the agent path edits (UpdatePost::authorize_object), so a post in another status is not changed.
		$post = get_post( (int) $row['object_id'] );
		if ( ! $post instanceof \WP_Post || 'trash' === $post->post_status || ! PostTypes::is_allowed( (string) $post->post_type ) || ! current_user_can( 'edit_post', $post->ID ) || ! in_array( (string) $post->post_status, PostFields::EDITABLE_STATUSES, true ) ) {
			return $this->forbidden();
		}

		// Any live edit lock refuses, whoever holds it, the administrator's own included. EditLock::is_locked() is the rule's own
		// definition of a live lock, so the two paths cannot drift. The agent's exemptions stay in the rule.
		if ( EditLock::is_locked( $post->ID ) ) {
			return $this->locked();
		}

		$decision = match ( UndoRules::kind_of( $row ) ) {
			UndoRules::KIND_TERMS    => $this->terms_decision( $row, $post ),
			UndoRules::KIND_FEATURED => $this->featured_decision( $row, $post ),
			default                  => $this->text_decision( $row, $post ),
		};

		return is_wp_error( $decision ) ? $decision : $row;
	}

	/**
	 * Whether the caller is an administrator who holds no agent role. Repeated in check() and in the pipeline's run_admin().
	 *
	 * @return bool|\WP_Error
	 */
	private function caller_allowed(): bool|\WP_Error {
		$user = wp_get_current_user();
		if ( ! $user->exists() || ! current_user_can( 'manage_options' ) || Agents::holds_agent_role( $user ) ) {
			return $this->forbidden();
		}

		return true;
	}

	/**
	 * The checks for post text: both revisions are revisions of the post and not autosaves, they differ, revisions are kept,
	 * no revision was saved after the change, and the theme still has the post's template.
	 *
	 * @param array<string, mixed> $row  The entry.
	 * @param \WP_Post             $post The post.
	 * @return bool|\WP_Error
	 */
	private function text_decision( array $row, \WP_Post $post ): bool|\WP_Error {
		$before = get_post( (int) $row['snapshot_id'] );
		$after  = get_post( (int) $row['after_snapshot_id'] );
		if ( ! UndoRules::is_revision_of( $before, $post->ID ) || ! UndoRules::is_revision_of( $after, $post->ID ) ) {
			return $this->no_saved_state();
		}

		if ( UndoRules::text_of( $before ) === UndoRules::text_of( $after ) ) {
			return $this->no_saved_state();
		}

		if ( ! wp_revisions_enabled( $post ) || ! UpdatePost::keeps_enough_revisions( $post->ID ) ) {
			return $this->no_saved_state();
		}

		if ( Snapshots::revision_after( $post->ID, (int) $after->ID ) ) {
			return $this->changed_since();
		}

		$refusal = UpdatePost::refuse_before_write( $post->ID );

		return null === $refusal ? true : $refusal;
	}

	/**
	 * The checks for terms: the saved states are valid, the taxonomy is still allowed and attached to the post's type, the caller
	 * may assign its terms, each saved term is still a term of the taxonomy, a post format is one term, and the post does not hold
	 * more terms in it than a set-post-terms call allows.
	 *
	 * @param array<string, mixed> $row  The entry.
	 * @param \WP_Post             $post The post.
	 * @return bool|\WP_Error
	 */
	private function terms_decision( array $row, \WP_Post $post ): bool|\WP_Error {
		$states = UndoRules::saved_states( $row );
		if ( null === $states ) {
			return $this->no_saved_state();
		}

		$taxonomy = (string) $states['before']['taxonomy'];
		$object   = get_taxonomy( $taxonomy );
		if ( ! $object instanceof \WP_Taxonomy || ! Taxonomies::is_allowed( $taxonomy ) || ! in_array( $taxonomy, get_object_taxonomies( $post->post_type ), true ) ) {
			return $this->restore_blocked();
		}

		if ( ! current_user_can( $object->cap->assign_terms ) ) {
			return $this->forbidden();
		}

		// The rules SetPostTerms::validate_input() applies to a set of term IDs, applied to the set this undo would restore. The
		// count and the uniqueness of the IDs are already checked by UndoRules::valid_state().
		foreach ( $states['before']['term_ids'] as $term_id ) {
			$term = get_term( (int) $term_id, $taxonomy );
			if ( ! $term instanceof \WP_Term || $term->taxonomy !== $taxonomy ) {
				return $this->restore_blocked();
			}
		}

		// The post-format rule (SetPostTerms::validate_input()): a post format is one term, so a saved set of more than one is refused.
		if ( 'post_format' === $taxonomy && count( $states['before']['term_ids'] ) > 1 ) {
			return $this->restore_blocked();
		}

		if ( TermReader::assigned_count( $post->ID, $taxonomy ) > TermReader::MAX_TERMS_PER_TAXONOMY ) {
			return $this->restore_blocked();
		}

		return true;
	}

	/**
	 * The checks for the featured image: the saved states are valid; the post's type can hold a featured image, as SetFeaturedImage
	 * requires (thumbnail support and the meta mapping), so a restore cannot delete the stored one; and the image to restore is
	 * none, or an existing attachment that is not in the trash and is still an image. Each refusal is restore_blocked().
	 *
	 * @param array<string, mixed> $row  The entry.
	 * @param \WP_Post             $post The post.
	 * @return bool|\WP_Error
	 */
	private function featured_decision( array $row, \WP_Post $post ): bool|\WP_Error {
		$states = UndoRules::saved_states( $row );
		if ( null === $states ) {
			return $this->no_saved_state();
		}

		if ( ! PostTypes::uses_meta_caps( (string) $post->post_type ) || ! post_type_supports( (string) $post->post_type, 'thumbnail' ) ) {
			return $this->restore_blocked();
		}

		$before = (int) $states['before']['featured_media'];
		if ( 0 === $before ) {
			return true;
		}

		$image = get_post( $before );
		if ( ! $image instanceof \WP_Post || 'attachment' !== $image->post_type || 'trash' === $image->post_status || ! wp_attachment_is_image( $image ) ) {
			return $this->restore_blocked();
		}

		return true;
	}

	/**
	 * Restores the title, content and excerpt to the before-revision, then marks the entry. The same sequence as undo-change's
	 * restore_text(): the after-state is checked for newer revisions, the save is PostWriter::restore(), and the row is read again.
	 *
	 * @param int                  $entry_id Change-log entry ID.
	 * @param array<string, mixed> $row      The entry, already checked by check().
	 * @return array<string, mixed>|\WP_Error
	 */
	private function restore_text( int $entry_id, array $row ): array|\WP_Error {
		$post_id = (int) $row['object_id'];
		$before  = get_post( (int) $row['snapshot_id'] );
		$after   = get_post( (int) $row['after_snapshot_id'] );
		if ( ! $before instanceof \WP_Post || ! $after instanceof \WP_Post ) {
			return $this->no_saved_state();
		}

		$restore  = UndoRules::text_of( $before );
		$expected = UndoRules::text_of( $after );

		$saved = PostWriter::restore( $post_id, $expected, $restore );
		if ( is_wp_error( $saved ) ) {
			if ( in_array( $saved->get_error_code(), array( 'steadhand_refused_changed_since', 'steadhand_forbidden', 'steadhand_update_partial' ), true ) ) {
				return $saved;
			}

			// WordPress reported an error. Nothing changed only when the post still holds the text it held before the call.
			return PostWriter::holds_text( $post_id, $expected ) ? $this->update_failed() : $this->partial_error();
		}

		if ( ! PostWriter::holds_text( $post_id, $restore ) ) {
			return $this->partial_error();
		}

		$unrecorded = $this->mark_restored( $entry_id, $row );
		if ( null !== $unrecorded ) {
			return $unrecorded;
		}

		return $this->output( $entry_id, $post_id );
	}

	/**
	 * Sets the post's terms back to the before-set, through TermWriter, then marks the entry. The writer runs check() again,
	 * right before the write, through $still_allowed.
	 *
	 * @param int                  $entry_id Change-log entry ID.
	 * @param array<string, mixed> $row      The entry, already checked by check().
	 * @return array<string, mixed>|\WP_Error
	 */
	private function restore_terms( int $entry_id, array $row ): array|\WP_Error {
		$post_id = (int) $row['object_id'];
		$states  = UndoRules::saved_states( $row );
		if ( null === $states ) {
			return $this->no_saved_state();
		}

		$taxonomy = (string) $states['before']['taxonomy'];
		$before   = UndoRules::sorted( $states['before']['term_ids'] );
		$after    = UndoRules::sorted( $states['after']['term_ids'] );

		$refusal = null;
		$written = TermWriter::set(
			$post_id,
			$taxonomy,
			$before,
			$after,
			$this->still_allowed( $entry_id, $post_id, $refusal )
		);

		if ( is_wp_error( $written ) ) {
			// The writer's own check refused: pass on the reason check() gave, so a lock or a lost right is named as such.
			if ( null !== $refusal ) {
				return $refusal;
			}

			$code = $written->get_error_code();
			if ( 'steadhand_refused_content_changed' === $code ) {
				return $this->terms_changed_since();
			}

			if ( 'steadhand_invalid_input' === $code ) {
				return $this->restore_blocked();
			}

			$now = TermReader::assigned_ids( $post_id, $taxonomy );

			return SetPostTerms::decide_write_error( $after, is_wp_error( $now ) ? null : $now, $written );
		}

		if ( $written !== $before ) {
			return $this->partial_error();
		}

		$unrecorded = $this->mark_restored( $entry_id, $row );
		if ( null !== $unrecorded ) {
			return $unrecorded;
		}

		return $this->output( $entry_id, $post_id );
	}

	/**
	 * Sets the post's featured image back to the before-image (0 removes it), through MediaWriter, then marks the entry.
	 *
	 * @param int                  $entry_id Change-log entry ID.
	 * @param array<string, mixed> $row      The entry, already checked by check().
	 * @return array<string, mixed>|\WP_Error
	 */
	private function restore_featured( int $entry_id, array $row ): array|\WP_Error {
		$post_id = (int) $row['object_id'];
		$states  = UndoRules::saved_states( $row );
		if ( null === $states ) {
			return $this->no_saved_state();
		}

		$before = (int) $states['before']['featured_media'];
		$after  = (int) $states['after']['featured_media'];

		$refusal = null;
		$written = MediaWriter::set_featured_image(
			$post_id,
			$before,
			$after,
			$this->still_allowed( $entry_id, $post_id, $refusal )
		);

		if ( is_wp_error( $written ) ) {
			if ( null !== $refusal ) {
				return $refusal;
			}

			$code = $written->get_error_code();
			if ( 'steadhand_refused_content_changed' === $code ) {
				return $this->featured_changed_since();
			}

			if ( 'steadhand_invalid_input' === $code ) {
				return $this->restore_blocked();
			}

			return SetFeaturedImage::decide_write_error( $after, MediaWriter::stored_thumbnail( $post_id ), $written );
		}

		if ( (int) $written['featured_media'] !== $before ) {
			return $this->partial_error();
		}

		$unrecorded = $this->mark_restored( $entry_id, $row );
		if ( null !== $unrecorded ) {
			return $unrecorded;
		}

		return $this->output( $entry_id, $post_id );
	}

	/**
	 * The callback a writer runs right before its write, on the post as it is now. It runs check() again, and keeps the refusal
	 * check() gives, so that the writer's refusal can be passed on. The writer receives only true or false.
	 *
	 * @param int            $entry_id Change-log entry ID.
	 * @param int            $post_id  The post the write is about.
	 * @param \WP_Error|null $refusal Set to the refusal when the check fails. Passed by reference.
	 * @return \Closure
	 */
	private function still_allowed( int $entry_id, int $post_id, ?\WP_Error &$refusal ): \Closure {
		return function ( \WP_Post $post ) use ( $entry_id, $post_id, &$refusal ): bool {
			if ( $post->ID !== $post_id ) {
				return false;
			}

			$checked = $this->check( $entry_id );
			if ( is_wp_error( $checked ) ) {
				$refusal = $checked;

				return false;
			}

			return true;
		};
	}

	/**
	 * Marks the entry as undone by this call's own row, under the entry owner's user ID (DbLogger::mark_undone() matches the row's
	 * user). Null when marked.
	 *
	 * @param int                  $entry_id Change-log entry ID.
	 * @param array<string, mixed> $row      The entry.
	 * @return \WP_Error|null
	 */
	private function mark_restored( int $entry_id, array $row ): ?\WP_Error {
		if ( $this->logger->mark_undone( $entry_id, (int) $row['user_id'], Pipeline::current_log_id() ) ) {
			return null;
		}

		return new \WP_Error(
			'steadhand_update_partial',
			__( 'The change was reverted, but the change log could not record the undo. Check the post and the change log before you continue.', 'steadhand' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * The output of a successful undo.
	 *
	 * @param int $entry_id Change-log entry ID.
	 * @param int $post_id  The post.
	 * @return array<string, mixed>
	 */
	private function output( int $entry_id, int $post_id ): array {
		return array(
			'entry_id'    => $entry_id,
			'object_type' => 'post',
			'object_id'   => $post_id,
			'undone'      => true,
		);
	}

	/**
	 * The refusal for an entry the administrator may not undo, or that does not exist. The generic text.
	 *
	 * @return \WP_Error
	 */
	private function forbidden(): \WP_Error {
		return new \WP_Error(
			'steadhand_forbidden',
			__( 'You may not undo this change. It does not exist, or you are not an administrator who may edit its post. Nothing was changed.', 'steadhand' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * The refusal for an entry that cannot be undone: not eligible, already undone, or with no usable saved state.
	 *
	 * @return \WP_Error
	 */
	private function not_undoable(): \WP_Error {
		return new \WP_Error(
			'steadhand_refused_not_undoable',
			__( 'This change cannot be undone: it was not saved with the state Steadhand needs, or it was already undone. Nothing was changed.', 'steadhand' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * The refusal for a change whose saved state is missing or unusable.
	 *
	 * @return \WP_Error
	 */
	private function no_saved_state(): \WP_Error {
		return new \WP_Error(
			'steadhand_refused_not_undoable',
			__( 'This change has no saved state that Steadhand can restore, so it cannot be undone. Nothing was changed.', 'steadhand' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * The refusal for a state that names a term or image that is missing or unusable, or that the post can no longer hold.
	 *
	 * @return \WP_Error
	 */
	private function restore_blocked(): \WP_Error {
		return new \WP_Error(
			'steadhand_refused_not_undoable',
			__( 'Steadhand cannot undo this change, because the state it would restore is no longer valid: a term or image it names is missing or not usable, or the post can no longer hold it. Nothing was changed.', 'steadhand' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * The refusal for a post edited after the change.
	 *
	 * @return \WP_Error
	 */
	private function changed_since(): \WP_Error {
		return PostWriter::changed_since();
	}

	/**
	 * The refusal for a post that has a live edit lock, held by any user, the administrator included. Nothing is written.
	 *
	 * @return \WP_Error
	 */
	private function locked(): \WP_Error {
		return new \WP_Error(
			'steadhand_refused_locked',
			__( 'This post is open in the editor right now. Close the editor, wait a few minutes, and try again. Nothing was changed.', 'steadhand' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * The refusal for a post whose terms changed after the change.
	 *
	 * @return \WP_Error
	 */
	private function terms_changed_since(): \WP_Error {
		return new \WP_Error(
			'steadhand_refused_changed_since',
			__( "The post's terms were changed after that change, so it cannot be undone. Nothing was changed.", 'steadhand' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * The refusal for a post whose featured image changed after the change.
	 *
	 * @return \WP_Error
	 */
	private function featured_changed_since(): \WP_Error {
		return new \WP_Error(
			'steadhand_refused_changed_since',
			__( "The post's featured image was changed after that change, so it cannot be undone. Nothing was changed.", 'steadhand' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * The error for a restore that WordPress reported as failed, when the post still holds the text it held before the call.
	 *
	 * @return \WP_Error
	 */
	private function update_failed(): \WP_Error {
		return new \WP_Error(
			'steadhand_update_failed',
			__( 'WordPress could not restore the post, so nothing was changed. Try again.', 'steadhand' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * The error for a restore that changed the post, or that was changed after it, without the call being able to say what was
	 * saved. The administrator must check the post.
	 *
	 * @return \WP_Error
	 */
	private function partial_error(): \WP_Error {
		return new \WP_Error(
			'steadhand_update_partial',
			__( 'The post was changed, but the save did not finish as expected, so the change was at least partly applied. Check the post before you continue.', 'steadhand' ),
			array( 'status' => 500 )
		);
	}
}
