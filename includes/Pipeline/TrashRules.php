<?php
/**
 * Read-only rules about the trash: whether this agent trashed a post and that trash is still the current one, whether a post is one
 * of the site's protected pages, and whether a post is sticky. Reads only; every write is in PostWriter.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * The shared trash rules. MetaCaps, the trash and restore abilities and PostWriter all ask these questions, so the answer is the
 * same wherever it is asked. Every read is uncached, so a marker written in the same request is seen.
 */
final class TrashRules {

	/**
	 * The status WordPress stores for a trashed post, before it was trashed (core's _wp_trash_meta_status).
	 */
	public const CORE_STATUS_META = '_wp_trash_meta_status';

	/**
	 * A string of digits, the form in which WordPress and Steadhand store a time and a user ID.
	 */
	private const DIGITS = '/^\d+\z/';

	/**
	 * The one value a post meta key holds, read uncached, or null when the key has no row or more than one. WordPress can keep
	 * several rows of a key when a post is trashed again without its markers being removed, and get_post_meta() with $single
	 * returns only the first of them. So a rule that needs exactly one value reads every row through this method.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return string|null The value, or null when the number of rows is not one.
	 */
	public static function value( int $post_id, string $key ): ?string {
		wp_cache_delete( $post_id, 'post_meta' );

		$rows = get_post_meta( $post_id, $key, false );
		if ( 1 !== count( $rows ) || ! is_scalar( $rows[0] ) ) {
			return null;
		}

		return (string) $rows[0];
	}

	/**
	 * Whether this user trashed the post through steadhand/trash-post, and that trash is still the current one. It is true only when
	 * each of the three keys has exactly one row, the single trashed-by marker is the user's ID, and the single trash-time marker is
	 * the same string of digits as the single trash time WordPress holds now. Any other count, or any value that is not digits, is
	 * false. So an administrator who restored the post and trashed it again is refused, even when both trashes happened in the
	 * same second, and so is a post whose markers were left behind by a status update.
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id The user who would restore the post.
	 * @return bool
	 */
	public static function own_trash( int $post_id, int $user_id ): bool {
		if ( $post_id < 1 || $user_id < 1 ) {
			return false;
		}

		$by    = self::value( $post_id, PostWriter::TRASHED_BY_META );
		$time  = self::value( $post_id, PostWriter::TRASH_TIME_META );
		$trash = self::value( $post_id, PostWriter::TRASH_CORE_TIME_META );

		if ( null === $by || null === $time || null === $trash ) {
			return false;
		}

		if ( 1 !== preg_match( self::DIGITS, $by ) || 1 !== preg_match( self::DIGITS, $time ) || 1 !== preg_match( self::DIGITS, $trash ) ) {
			return false;
		}

		return (int) $by === $user_id && $time === $trash;
	}

	/**
	 * The status a trashed post had before the trash, as WordPress stored it, or null when there is not exactly one stored record.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null
	 */
	public static function stored_status( int $post_id ): ?string {
		return self::value( $post_id, self::CORE_STATUS_META );
	}

	/**
	 * The reason a post is one of the site's protected pages, or null. The front page is protected whenever the page_on_front option
	 * holds a positive ID, whatever show_on_front says: core resets page_on_front to 0 when that page is trashed, so the saved
	 * selection would be lost. The posts page and the privacy policy page are protected by their own options.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null 'front', 'posts' or 'privacy', or null.
	 */
	public static function protected_page( int $post_id ): ?string {
		if ( $post_id < 1 ) {
			return null;
		}

		if ( (int) get_option( 'page_on_front' ) === $post_id ) {
			return 'front';
		}

		if ( (int) get_option( 'page_for_posts' ) === $post_id ) {
			return 'posts';
		}

		if ( (int) get_option( 'wp_page_for_privacy_policy' ) === $post_id ) {
			return 'privacy';
		}

		return null;
	}

	/**
	 * Whether the post is in the sticky_posts option. Core removes a post from it when the post is trashed, and does not put it back
	 * on a restore, so Steadhand refuses to trash a sticky post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function is_sticky( int $post_id ): bool {
		if ( $post_id < 1 ) {
			return false;
		}

		$stickies = get_option( 'sticky_posts', array() );

		return is_array( $stickies ) && in_array( $post_id, array_map( 'intval', $stickies ), true );
	}
}
