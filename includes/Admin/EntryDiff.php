<?php
/**
 * Builds the before and after data of one change-log entry, for the admin log screen.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Admin;

use Steadhand\Log\SnapshotStore;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the saved before and after states of one entry into plain data. It returns no HTML and escapes nothing: the screen
 * escapes every value at output. The viewer must be allowed to edit the post the entry names; otherwise no content, term or
 * image is returned. Never throws.
 */
final class EntryDiff {

	/** The entry has no saved before and after state to show. */
	public const STATUS_NONE = 'none';

	/** The saved states cannot be read, or do not belong to this entry. */
	public const STATUS_UNAVAILABLE = 'unavailable';

	/** The viewer may not edit the post, so nothing is shown. */
	public const STATUS_FORBIDDEN = 'forbidden';

	/** The data is in sections. */
	public const STATUS_OK = 'ok';

	/** A text change: title, content and excerpt, from core revisions. */
	public const KIND_TEXT = 'text';

	/** A change of one post's terms in one taxonomy. */
	public const KIND_TERMS = 'terms';

	/** A change of one post's featured image. */
	public const KIND_FEATURED_IMAGE = 'featured_image';

	/** The abilities that have a diff, and the kind each one has. */
	private const KINDS = array(
		'steadhand/update-post'        => self::KIND_TEXT,
		'steadhand/edit-blocks'        => self::KIND_TEXT,
		'steadhand/set-post-terms'     => self::KIND_TERMS,
		'steadhand/set-featured-image' => self::KIND_FEATURED_IMAGE,
	);

	/**
	 * Builds the diff of one entry.
	 *
	 * @param array<string, mixed> $row A change-log row, as DbLogger::find_any() returns it.
	 * @return array{status: string, kind: string, sections: array<int, array<string, mixed>>}
	 */
	public static function for_entry( array $row ): array {
		$kind = self::kind_of( $row );
		if ( '' === $kind ) {
			return self::result( self::STATUS_NONE, '', array() );
		}

		try {
			$object_id = (int) ( $row['object_id'] ?? 0 );
			$post      = $object_id > 0 ? get_post( $object_id ) : null;
			if ( ! $post instanceof \WP_Post ) {
				return self::result( self::STATUS_UNAVAILABLE, $kind, array() );
			}

			if ( ! current_user_can( 'edit_post', $object_id ) ) {
				return self::result( self::STATUS_FORBIDDEN, $kind, array() );
			}

			return match ( $kind ) {
				self::KIND_TEXT => self::text( $row, $object_id ),
				self::KIND_TERMS => self::terms( $row, $object_id ),
				default => self::featured_image( $row, $object_id ),
			};
		} catch ( \Throwable ) {
			return self::result( self::STATUS_UNAVAILABLE, $kind, array() );
		}
	}

	/**
	 * The kind of diff an entry has, or '' when it has none: an ability without a diff, an object that is not a post, a
	 * blocked row, or a row without both a before and an after state that differ.
	 *
	 * @param array<string, mixed> $row The change-log row.
	 * @return string
	 */
	private static function kind_of( array $row ): string {
		$kind = self::KINDS[ (string) ( $row['ability'] ?? '' ) ] ?? '';
		if ( '' === $kind || 'post' !== ( $row['object_type'] ?? '' ) || 'blocked' === ( $row['result'] ?? '' ) ) {
			return '';
		}

		$before = (int) ( $row['snapshot_id'] ?? 0 );
		$after  = (int) ( $row['after_snapshot_id'] ?? 0 );
		if ( $before <= 0 || $after <= 0 || $before === $after ) {
			return '';
		}

		return $kind;
	}

	/**
	 * The text diff: the title, content and excerpt of two core revisions of the post, where they differ.
	 *
	 * @param array<string, mixed> $row       The change-log row.
	 * @param int                  $object_id The post.
	 * @return array{status: string, kind: string, sections: array<int, array<string, mixed>>}
	 */
	private static function text( array $row, int $object_id ): array {
		$before = self::revision( (int) $row['snapshot_id'], $object_id );
		$after  = self::revision( (int) $row['after_snapshot_id'], $object_id );
		if ( null === $before || null === $after ) {
			return self::result( self::STATUS_UNAVAILABLE, self::KIND_TEXT, array() );
		}

		$fields = array(
			'post_title'   => __( 'Title', 'steadhand' ),
			'post_content' => __( 'Content', 'steadhand' ),
			'post_excerpt' => __( 'Excerpt', 'steadhand' ),
		);

		$sections = array();
		foreach ( $fields as $field => $label ) {
			$old = (string) $before->{$field};
			$new = (string) $after->{$field};
			if ( $old === $new ) {
				continue;
			}

			$sections[] = array(
				'label'  => $label,
				'before' => $old,
				'after'  => $new,
			);
		}

		return self::result( self::STATUS_OK, self::KIND_TEXT, $sections );
	}

	/**
	 * A core revision of the post, or null when it is missing, is not a revision of this post, or is an autosave.
	 *
	 * @param int $id        Revision ID.
	 * @param int $object_id The post the revision must belong to.
	 * @return \WP_Post|null
	 */
	private static function revision( int $id, int $object_id ): ?\WP_Post {
		if ( $id <= 0 ) {
			return null;
		}

		$revision = get_post( $id );
		if ( ! $revision instanceof \WP_Post || 'revision' !== $revision->post_type || $object_id !== (int) $revision->post_parent ) {
			return null;
		}

		if ( wp_is_post_autosave( $revision ) ) {
			return null;
		}

		return $revision;
	}

	/**
	 * The change of one post's terms: the term IDs removed and added, with their names. One section.
	 *
	 * @param array<string, mixed> $row       The change-log row.
	 * @param int                  $object_id The post.
	 * @return array{status: string, kind: string, sections: array<int, array<string, mixed>>}
	 */
	private static function terms( array $row, int $object_id ): array {
		$before = self::terms_state( SnapshotStore::find( (int) $row['snapshot_id'] ), $object_id );
		$after  = self::terms_state( SnapshotStore::find( (int) $row['after_snapshot_id'] ), $object_id );
		if ( null === $before || null === $after || $before['taxonomy'] !== $after['taxonomy'] ) {
			return self::result( self::STATUS_UNAVAILABLE, self::KIND_TERMS, array() );
		}

		$taxonomy = $before['taxonomy'];
		$removed  = array_values( array_diff( $before['term_ids'], $after['term_ids'] ) );
		$added    = array_values( array_diff( $after['term_ids'], $before['term_ids'] ) );

		return self::result(
			self::STATUS_OK,
			self::KIND_TERMS,
			array(
				array(
					'taxonomy' => $taxonomy,
					'removed'  => self::named_terms( $removed, $taxonomy ),
					'added'    => self::named_terms( $added, $taxonomy ),
				),
			)
		);
	}

	/**
	 * The taxonomy and term IDs of a saved terms state, or null when the snapshot is not a terms state of this post.
	 *
	 * @param array<string, mixed>|null $snapshot  The saved state, as SnapshotStore::find() returns it.
	 * @param int                       $object_id The post.
	 * @return array{taxonomy: string, term_ids: int[]}|null
	 */
	private static function terms_state( ?array $snapshot, int $object_id ): ?array {
		if ( null === $snapshot || SnapshotStore::KIND_POST_TERMS !== $snapshot['kind'] || 'post' !== $snapshot['object_type'] || $object_id !== $snapshot['object_id'] ) {
			return null;
		}

		$state = $snapshot['state'];
		if ( ! isset( $state['taxonomy'] ) || ! is_string( $state['taxonomy'] ) || ! isset( $state['term_ids'] ) || ! is_array( $state['term_ids'] ) || ! array_is_list( $state['term_ids'] ) ) {
			return null;
		}

		foreach ( $state['term_ids'] as $term_id ) {
			if ( ! is_int( $term_id ) ) {
				return null;
			}
		}

		return array(
			'taxonomy' => $state['taxonomy'],
			'term_ids' => $state['term_ids'],
		);
	}

	/**
	 * Each term ID with its name. The name is null when the term no longer exists.
	 *
	 * @param int[]  $term_ids Term IDs.
	 * @param string $taxonomy Taxonomy of the terms.
	 * @return array<int, array{id: int, name: string|null}>
	 */
	private static function named_terms( array $term_ids, string $taxonomy ): array {
		$named = array();
		foreach ( $term_ids as $term_id ) {
			$term    = get_term( $term_id, $taxonomy );
			$named[] = array(
				'id'   => $term_id,
				'name' => $term instanceof \WP_Term ? $term->name : null,
			);
		}

		return $named;
	}

	/**
	 * The change of one post's featured image: the attachment before and after, with titles. One section.
	 *
	 * @param array<string, mixed> $row       The change-log row.
	 * @param int                  $object_id The post.
	 * @return array{status: string, kind: string, sections: array<int, array<string, mixed>>}
	 */
	private static function featured_image( array $row, int $object_id ): array {
		$before = self::image_state( SnapshotStore::find( (int) $row['snapshot_id'] ), $object_id );
		$after  = self::image_state( SnapshotStore::find( (int) $row['after_snapshot_id'] ), $object_id );
		if ( null === $before || null === $after ) {
			return self::result( self::STATUS_UNAVAILABLE, self::KIND_FEATURED_IMAGE, array() );
		}

		return self::result(
			self::STATUS_OK,
			self::KIND_FEATURED_IMAGE,
			array(
				array(
					'before' => self::image( $before ),
					'after'  => self::image( $after ),
				),
			)
		);
	}

	/**
	 * The featured image ID of a saved image state, or null when the snapshot is not an image state of this post.
	 *
	 * @param array<string, mixed>|null $snapshot  The saved state, as SnapshotStore::find() returns it.
	 * @param int                       $object_id The post.
	 * @return int|null
	 */
	private static function image_state( ?array $snapshot, int $object_id ): ?int {
		if ( null === $snapshot || SnapshotStore::KIND_FEATURED_IMAGE !== $snapshot['kind'] || 'post' !== $snapshot['object_type'] || $object_id !== $snapshot['object_id'] ) {
			return null;
		}

		$media = $snapshot['state']['featured_media'] ?? null;

		return is_int( $media ) && $media >= 0 ? $media : null;
	}

	/**
	 * An attachment ID with its title. The title is null for 0 (no image) and for an attachment that no longer exists.
	 *
	 * @param int $id Attachment ID, or 0.
	 * @return array{id: int, title: string|null}
	 */
	private static function image( int $id ): array {
		$post = $id > 0 ? get_post( $id ) : null;

		return array(
			'id'    => $id,
			'title' => $post instanceof \WP_Post ? (string) $post->post_title : null,
		);
	}

	/**
	 * Builds the result array.
	 *
	 * @param string                           $status   One of the STATUS_ constants.
	 * @param string                           $kind     One of the KIND_ constants, or '' for none.
	 * @param array<int, array<string, mixed>> $sections The data.
	 * @return array{status: string, kind: string, sections: array<int, array<string, mixed>>}
	 */
	private static function result( string $status, string $kind, array $sections ): array {
		return array(
			'status'   => $status,
			'kind'     => $kind,
			'sections' => $sections,
		);
	}
}
