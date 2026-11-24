<?php
/**
 * The rules that decide whether a change-log entry can be undone, and what kind of change it is. Shared by the agent's
 * undo-change ability and by the administrator's undo on the log screen, so the two cannot drift.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Abilities;

use Steadhand\Log\Schema;
use Steadhand\Log\SnapshotStore;

defined( 'ABSPATH' ) || exit;

/**
 * Pure rules over a change-log row and its saved states. Nothing here depends on who is asking, and nothing here writes. The
 * caller's own identity (the entry belongs to the current user) and every capability check stay with the callers.
 */
final class UndoRules {

	/** The abilities whose changes can be undone. */
	public const UNDOABLE_ABILITIES = array( 'steadhand/update-post', 'steadhand/edit-blocks', 'steadhand/set-post-terms', 'steadhand/set-featured-image' );

	/** The kinds of change an entry can be: post text, terms, or the featured image. */
	public const KIND_TEXT     = 'text';
	public const KIND_TERMS    = 'terms';
	public const KIND_FEATURED = 'featured';

	/** The results that mean the change was made. */
	public const MADE_RESULTS = array( 'ok', 'fixed' );

	/**
	 * Whether a change-log row can be undone, without looking at who asks or at the post. Every rule must hold: the schema is
	 * ready; the ability is one of UNDOABLE_ABILITIES; the object is a post; the result is ok or fixed; the row is undoable and
	 * not yet undone; the before-snapshot and the after-snapshot are both named, and they differ.
	 *
	 * The caller must still check that the row is its own (UndoChange does), and every capability.
	 *
	 * @param array<string, mixed> $row A row from DbLogger::find() or DbLogger::find_any().
	 * @return bool
	 */
	public static function structurally_eligible( array $row ): bool {
		return Schema::ready()
			&& in_array( (string) $row['ability'], self::UNDOABLE_ABILITIES, true )
			&& 'post' === (string) $row['object_type']
			&& (int) $row['object_id'] > 0
			&& in_array( (string) $row['result'], self::MADE_RESULTS, true )
			&& 1 === (int) $row['undoable']
			&& 0 === (int) $row['undone_by']
			&& (int) $row['snapshot_id'] > 0
			&& (int) $row['after_snapshot_id'] > 0
			&& (int) $row['after_snapshot_id'] !== (int) $row['snapshot_id'];
	}

	/**
	 * The kind of change an entry is.
	 *
	 * @param array<string, mixed> $row A change-log row.
	 * @return string One of the KIND_ constants.
	 */
	public static function kind_of( array $row ): string {
		return match ( (string) $row['ability'] ) {
			'steadhand/set-post-terms'     => self::KIND_TERMS,
			'steadhand/set-featured-image' => self::KIND_FEATURED,
			default                        => self::KIND_TEXT,
		};
	}

	/**
	 * The two saved states of a terms or featured-image entry, decoded, when every rule holds: both rows exist; each has the
	 * kind the entry needs, names the entry's post, belongs to the entry's user, and decodes to exactly the expected shape; and
	 * the two states differ (and, for terms, name the same taxonomy). Returns null otherwise.
	 *
	 * @param array<string, mixed> $row A terms or featured-image row.
	 * @return array{before: array<string, mixed>, after: array<string, mixed>}|null
	 */
	public static function saved_states( array $row ): ?array {
		$kind     = self::kind_of( $row );
		$expected = self::KIND_TERMS === $kind ? SnapshotStore::KIND_POST_TERMS : SnapshotStore::KIND_FEATURED_IMAGE;

		$before = SnapshotStore::find( (int) $row['snapshot_id'] );
		$after  = SnapshotStore::find( (int) $row['after_snapshot_id'] );
		if ( null === $before || null === $after ) {
			return null;
		}

		foreach ( array( $before, $after ) as $snapshot ) {
			if (
				$expected !== $snapshot['kind']
				|| 'post' !== $snapshot['object_type']
				|| (int) $row['object_id'] !== $snapshot['object_id']
				|| (int) $row['user_id'] !== $snapshot['user_id']
				|| ! self::valid_state( $kind, $snapshot['state'] )
			) {
				return null;
			}
		}

		$b = $before['state'];
		$a = $after['state'];

		if ( self::KIND_TERMS === $kind ) {
			if ( $b['taxonomy'] !== $a['taxonomy'] || self::sorted( $b['term_ids'] ) === self::sorted( $a['term_ids'] ) ) {
				return null;
			}

			return array(
				'before' => $b,
				'after'  => $a,
			);
		}

		if ( $b['featured_media'] === $a['featured_media'] ) {
			return null;
		}

		return array(
			'before' => $b,
			'after'  => $a,
		);
	}

	/**
	 * Whether a decoded saved state has exactly the shape the entry's kind needs. Terms: exactly the keys taxonomy (a string) and
	 * term_ids (a list of distinct positive integers, at most set-post-terms' cap). Featured image: exactly the key featured_media,
	 * an integer of at least 0.
	 *
	 * @param string               $kind  The entry's kind.
	 * @param array<string, mixed> $state The decoded state.
	 * @return bool
	 */
	public static function valid_state( string $kind, array $state ): bool {
		$keys = array_keys( $state );
		sort( $keys );

		if ( self::KIND_TERMS !== $kind ) {
			return array( 'featured_media' ) === $keys
				&& is_int( $state['featured_media'] )
				&& $state['featured_media'] >= 0
				&& $state['featured_media'] <= PostReader::MAX_ID;
		}

		if ( array( 'taxonomy', 'term_ids' ) !== $keys || ! is_string( $state['taxonomy'] ) || '' === $state['taxonomy'] ) {
			return false;
		}

		$ids = $state['term_ids'];
		if ( ! is_array( $ids ) || ! array_is_list( $ids ) || count( $ids ) > SetPostTerms::MAX_RESULT_TERMS ) {
			return false;
		}

		foreach ( $ids as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				return false;
			}
		}

		return count( array_unique( $ids ) ) === count( $ids );
	}

	/**
	 * The integers of a list, ascending. Used only on values valid_state() has checked.
	 *
	 * @param mixed $ids The IDs.
	 * @return int[]
	 */
	public static function sorted( mixed $ids ): array {
		$ids = array_values( array_map( 'intval', (array) $ids ) );
		sort( $ids, SORT_NUMERIC );

		return $ids;
	}

	/**
	 * Whether a loaded revision is a usable saved state of the post: a revision of that post, and not an autosave.
	 *
	 * @param mixed $revision The loaded post, or false or null.
	 * @param int   $post_id  The post.
	 * @return bool
	 */
	public static function is_revision_of( mixed $revision, int $post_id ): bool {
		return $revision instanceof \WP_Post
			&& 'revision' === $revision->post_type
			&& $post_id === (int) $revision->post_parent
			&& ! wp_is_post_autosave( $revision );
	}

	/**
	 * The title, content and excerpt of a revision, as stored.
	 *
	 * @param \WP_Post $revision The revision.
	 * @return array{post_title: string, post_content: string, post_excerpt: string}
	 */
	public static function text_of( \WP_Post $revision ): array {
		return array(
			'post_title'   => (string) $revision->post_title,
			'post_content' => (string) $revision->post_content,
			'post_excerpt' => (string) $revision->post_excerpt,
		);
	}
}
