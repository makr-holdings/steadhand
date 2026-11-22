<?php
/**
 * Saves the undo information for post writes: a core revision of the post's state before a change.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * Snapshots for write abilities (SPEC §7). Posts use core revisions: the snapshot is the revision ID.
 *
 * Only ever called from inside a write (Pipeline::is_writing()). Outside a write it throws, so no caller can take a
 * revision that the change log does not know about.
 */
final class Snapshots {

	/**
	 * Makes sure a core revision of the post's current state exists, and returns its ID.
	 *
	 * Core saves a revision only when the post differs from its latest one. In that case the latest revision already
	 * holds the current state, and it is returned. The returned revision is checked against the post's title, content
	 * and excerpt before it is trusted. Returns 0 when revisions are not kept for the post (no revision support, the
	 * wp_revisions_to_keep() limit is 0, or a save failed), or when the revision does not match the post.
	 *
	 * @param int $post_id Post ID.
	 * @return int The revision ID, or 0.
	 * @throws \LogicException When called outside a write.
	 */
	public static function post_revision( int $post_id ): int {
		if ( ! Pipeline::is_writing() ) {
			throw new \LogicException( 'Snapshots::post_revision() may only be called from a write ability.' );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return 0;
		}

		// Saves a revision of the current state, unless it matches the latest one. Its return value is not used: the
		// latest revision is checked below either way.
		wp_save_post_revision( $post->ID );

		$latest = wp_get_latest_revision_id_and_total_count( $post->ID );
		if ( is_wp_error( $latest ) ) {
			return 0;
		}

		$revision_id = (int) ( $latest['latest_id'] ?? 0 );
		if ( $revision_id <= 0 ) {
			return 0;
		}

		$revision = get_post( $revision_id );
		if ( ! $revision instanceof \WP_Post || 'revision' !== $revision->post_type ) {
			return 0;
		}

		$same = $revision->post_title === $post->post_title
			&& $revision->post_content === $post->post_content
			&& $revision->post_excerpt === $post->post_excerpt;

		return $same ? $revision_id : 0;
	}
}
