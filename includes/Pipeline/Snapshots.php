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
 * capture is open. post_revision_after() accepts only a revision that was captured and that holds exactly the text the
 * write stored (PostWriter::written_text()), so a save by another request between the write and the check is never
 * recorded as this write's state after the change.
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
	 * The checks, in the order the code runs them:
	 * 1. post_revision() opened a capture for the post during this write.
	 * 2. The post row holds exactly the text this write stored (PostWriter::written_text(), recorded when the write saved the
	 *    row). Once the row differs from that, for example because another request or a plugin in this request changed it, the
	 *    call names nothing, and nothing is saved.
	 * 3. The latest non-autosave revision holds the row's title, content and excerpt, and the text this write stored. The
	 *    after-side save (save_current_revision) runs here, and only here, when core did not save the post-save revision.
	 * 4. That revision was captured during this write, and is newer than the before-revision.
	 * 5. The before-revision still exists, is not an autosave, and belongs to the post.
	 * 6. The before-revision and the after-revision differ in title, content or excerpt.
	 *
	 * The capture for the post, and the text recorded for it, are ended on every path, including a throw.
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
			PostWriter::forget_written_text( $post_id );
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

		// The text this write stored. Without a record of it, nothing can be named.
		$written = PostWriter::written_text( $post_id );
		if ( null === $written ) {
			return 0;
		}

		// The row must still hold that text. Once another request, or a plugin in this request, has changed it, no revision can
		// be this write's state after the change. Nothing is saved then, so no revision of the other text is made here.
		if ( ! self::holds( $post, $written ) ) {
			return 0;
		}

		// Core saves the post-save revision itself. This saves one only when core did not (a plugin removed its hook). The row
		// was checked to hold the written text just above, but the revision mirror reads the row again when the revision is
		// inserted. A change by another request between that check and the insert can leave a revision of other text. The checks
		// below reject it: it is saved, but never named.
		self::save_current_revision( $post );

		$revision = self::newest_revision( $post );
		// holds( $revision, $written ) is defence in depth: the row check above and same_text() together already imply it.
		if ( null === $revision || ! self::same_text( $revision, $post ) || ! self::holds( $revision, $written ) ) {
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
	 * Whether a revision of the post that is not an autosave was created after the given revision. Creation order is the
	 * revision's ID, so this compares IDs and never dates (a date is site-local time, and can move with a clock or a time zone
	 * change).
	 *
	 * The autosave test is core's: wp_is_post_autosave() (wp-includes/revision.php) calls a revision an autosave when its
	 * post_name contains "{parent}-autosave". This query excludes exactly those rows, with the same substring rule, so an
	 * autosave never counts. The query is a direct prepared read of the posts table, and it is not limited by the revision
	 * limit, unlike wp_get_post_revisions().
	 *
	 * Fails closed: when the query fails, the answer is true, so the caller refuses.
	 *
	 * @param int $post_id     Post ID.
	 * @param int $revision_id The revision whose successors are looked for.
	 * @return bool True when a newer non-autosave revision exists, or when the query failed.
	 */
	public static function revision_after( int $post_id, int $revision_id ): bool {
		global $wpdb;

		$pattern = '%' . $wpdb->esc_like( $post_id . '-autosave' ) . '%';

		$previous         = $wpdb->suppress_errors( true );
		$wpdb->last_error = '';
		$count            = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A live count of the revisions of one post, which must not be cached.
			$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d AND ID > %d AND post_name NOT LIKE %s", 'revision', $post_id, $revision_id, $pattern ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is core's; every value is bound by prepare().
		);

		$failed = '' !== (string) $wpdb->last_error || null === $count;
		$wpdb->suppress_errors( $previous );

		if ( $failed ) {
			return true;
		}

		return (int) $count > 0;
	}

	/**
	 * Opens the capture for a post: an empty list, and the listener on core's revision action, added once.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private static function open_capture( int $post_id ): void {
		self::$captures[ $post_id ] = array();
		PostWriter::forget_written_text( $post_id );

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
		return self::holds(
			$a,
			array(
				'post_title'   => $b->post_title,
				'post_content' => $b->post_content,
				'post_excerpt' => $b->post_excerpt,
			)
		);
	}

	/**
	 * Whether a post holds exactly the given title, content and excerpt, byte for byte.
	 *
	 * @param \WP_Post                                                              $post The post.
	 * @param array{post_title: string, post_content: string, post_excerpt: string} $text Title, content and excerpt.
	 * @return bool
	 */
	private static function holds( \WP_Post $post, array $text ): bool {
		return $post->post_title === $text['post_title']
			&& $post->post_content === $text['post_content']
			&& $post->post_excerpt === $text['post_excerpt'];
	}
}
