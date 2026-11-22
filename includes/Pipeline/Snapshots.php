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
	 * Core saves a revision only when the post differs from its latest one. Autosaves are revisions too, and they are
	 * never returned: a revision is returned only when it is not an autosave and holds exactly the post's current title,
	 * content and excerpt. The newest such revision is looked up directly, with the revision limit ignored, so an older
	 * revision is found even when none is saved now. Returns 0 when revisions are not available for the post, when
	 * no matching revision exists, or when a save failed.
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
		// revision looked up below is checked either way.
		wp_save_post_revision( $post->ID );

		$revisions = wp_get_post_revisions(
			$post->ID,
			array(
				'posts_per_page' => 5,
				'check_enabled'  => false,
			)
		);

		foreach ( $revisions as $revision ) {
			if ( ! $revision instanceof \WP_Post || 'revision' !== $revision->post_type || wp_is_post_autosave( $revision ) ) {
				continue;
			}

			$same = $revision->post_title === $post->post_title
				&& $revision->post_content === $post->post_content
				&& $revision->post_excerpt === $post->post_excerpt;

			return $same ? (int) $revision->ID : 0;
		}

		return 0;
	}
}
