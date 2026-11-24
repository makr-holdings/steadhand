<?php
/**
 * Saves the undo information for post writes: a core revision of the post's state before a change, and the state after.
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
 *
 * The state after a write is named only when this request created the revision. post_revision() opens a capture for the
 * post, and a listener on core's _wp_put_post_revision action records each revision this request makes for a post whose
 * capture is open. post_revision_after() accepts only a revision that was captured, so a save by another request between
 * the write and the check is never recorded as this write's state after the change.
 */
final class Snapshots {

	/** How many of the newest revisions the lookup examines. */
	private const LOOKUP_LIMIT = 5;

	/**
	 * Revision IDs this request has created during the current write, by parent post ID. A post has a key while its
	 * capture is open: from post_revision() until post_revision_after() ends it.
	 *
	 * @var array<int, int[]>
	 */
	private static array $captures = array();

	/**
	 * Makes sure a core revision of the post's current state exists, and returns its ID.
	 *
	 * Core saves a revision only when the post differs from its latest one. Autosaves are revisions too, and they are
	 * never returned: a revision is returned only when it is not an autosave and holds exactly the post's current title,
	 * content and excerpt. The newest such revision is looked up directly, with the revision limit ignored, so an older
	 * revision is found even when none is saved now. Returns 0 when revisions are not available for the post, when
	 * no matching revision exists, or when a save failed.
	 *
	 * This also opens the post's capture (see the class docblock), which post_revision_after() closes.
	 *
	 * @param int $post_id Post ID.
	 * @return int The revision ID, or 0.
	 * @throws \LogicException When called outside a write.
	 */
	public static function post_revision( int $post_id ): int {
		if ( ! Pipeline::is_writing() ) {
			throw new \LogicException( 'Snapshots::post_revision() may only be called from a write ability.' );
		}

		self::open_capture( $post_id );

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return 0;
		}

		self::save_current_revision( $post );

		$revision = self::newest_revision( $post );

		return null !== $revision && self::same_text( $revision, $post ) ? (int) $revision->ID : 0;
	}

	/**
	 * Names the revision that holds the post's state after the write, or 0 when there is none that this write can use.
	 *
	 * The revision must be one this request created during the write (captured by post_revision()), be newer than the
	 * before-revision, and hold exactly the post's current title, content and excerpt. The before-revision must still
	 * exist and must not be an autosave, and the two must differ in title, content or excerpt. The capture for the post
	 * is ended on every path, including a throw.
	 *
	 * @param int $post_id   Post ID.
	 * @param int $before_id The snapshot post_revision() returned for this write.
	 * @return int The revision ID, or 0.
	 * @throws \LogicException When called outside a write.
	 */
	public static function post_revision_after( int $post_id, int $before_id ): int {
		if ( ! Pipeline::is_writing() ) {
			throw new \LogicException( 'Snapshots::post_revision_after() may only be called from a write ability.' );
		}

		try {
			return self::after_revision( $post_id, $before_id );
		} finally {
			unset( self::$captures[ $post_id ] );
		}
	}

	/**
	 * Core's _wp_put_post_revision action: records a revision this request made, under its parent, while the parent's
	 * capture is open. Called by WordPress; it has no other use.
	 *
	 * @internal
	 * @param int $revision_id The new revision's ID.
	 * @return void
	 */
	public static function capture_revision( int $revision_id ): void {
		$revision = get_post( $revision_id );
		if ( ! $revision instanceof \WP_Post ) {
			return;
		}

		$parent = (int) $revision->post_parent;
		if ( isset( self::$captures[ $parent ] ) ) {
			self::$captures[ $parent ][] = (int) $revision->ID;
		}
	}

	/**
	 * The checks of post_revision_after(). The capture is ended by the caller.
	 *
	 * @param int $post_id   Post ID.
	 * @param int $before_id The before-revision ID.
	 * @return int The revision ID, or 0.
	 */
	private static function after_revision( int $post_id, int $before_id ): int {
		// No capture: post_revision() was not called for this post during this write, so nothing here can be this write's.
		if ( ! isset( self::$captures[ $post_id ] ) ) {
			return 0;
		}

		clean_post_cache( $post_id );
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return 0;
		}

		// A revision of the current state is saved here if the latest one differs, so it is captured too.
		self::save_current_revision( $post );

		$revision = self::newest_revision( $post );
		if ( null === $revision || ! self::same_text( $revision, $post ) ) {
			return 0;
		}

		$after_id = (int) $revision->ID;
		if ( $after_id <= $before_id || ! in_array( $after_id, self::$captures[ $post_id ], true ) ) {
			return 0;
		}

		// The before-revision may have been removed by core during the save, so it is looked up again.
		$before = get_post( $before_id );
		if ( ! $before instanceof \WP_Post || 'revision' !== $before->post_type || (int) $before->post_parent !== $post_id || wp_is_post_autosave( $before ) ) {
			return 0;
		}

		// A change that leaves the text as it was (for example one that the save filters turn back) is not undoable.
		return self::same_text( $before, $revision ) ? 0 : $after_id;
	}

	/**
	 * Opens the capture for a post: an empty list, and the listener on core's revision action, added once.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function open_capture( int $post_id ): void {
		self::$captures[ $post_id ] = array();

		if ( ! has_action( '_wp_put_post_revision', array( self::class, 'capture_revision' ) ) ) {
			add_action( '_wp_put_post_revision', array( self::class, 'capture_revision' ), 10, 1 );
		}
	}

	/**
	 * Saves a revision of the post's current row unless the latest revision already matches it. Used exactly the same way
	 * by the before-lookup and the after-lookup, so both see the post the same way.
	 *
	 * @param \WP_Post $post The post, as it is stored now.
	 * @return void
	 */
	private static function save_current_revision( \WP_Post $post ): void {
		// Its return value is not used: the revision looked up afterwards is checked either way.
		PostWriter::with_revision_mirror(
			$post->ID,
			static fn(): mixed => wp_save_post_revision( $post->ID )
		);
	}

	/**
	 * The newest revision of the post that is not an autosave, among the newest few, with the revision limit ignored.
	 *
	 * @param \WP_Post $post The post.
	 * @return \WP_Post|null The revision, or null when there is none.
	 */
	private static function newest_revision( \WP_Post $post ): ?\WP_Post {
		$revisions = wp_get_post_revisions(
			$post->ID,
			array(
				'posts_per_page' => self::LOOKUP_LIMIT,
				'check_enabled'  => false,
			)
		);

		foreach ( $revisions as $revision ) {
			if ( ! $revision instanceof \WP_Post || 'revision' !== $revision->post_type || wp_is_post_autosave( $revision ) ) {
				continue;
			}

			return $revision;
		}

		return null;
	}

	/**
	 * Whether two posts hold the same title, content and excerpt, byte for byte.
	 *
	 * @param \WP_Post $a One post.
	 * @param \WP_Post $b The other.
	 * @return bool
	 */
	private static function same_text( \WP_Post $a, \WP_Post $b ): bool {
		return $a->post_title === $b->post_title
			&& $a->post_content === $b->post_content
			&& $a->post_excerpt === $b->post_excerpt;
	}
}
