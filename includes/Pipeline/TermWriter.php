<?php
/**
 * The only place Steadhand assigns terms to a post.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Pipeline;

use Steadhand\Abilities\TermReader;
use Steadhand\PostTypes;
use Steadhand\Taxonomies;

defined( 'ABSPATH' ) || exit;

/**
 * Sets the terms of one taxonomy on one post, for the write abilities (SPEC §5, §14.1). Every term assignment in Steadhand
 * goes through here, and only while Pipeline::is_writing() is true.
 *
 * It takes term IDs only. A string is never passed to WordPress, because core creates a term for a name that matches none,
 * and a term that does not exist is never created here. Every ID is checked against the taxonomy again, so a term of another
 * taxonomy with the same number is refused.
 */
final class TermWriter {

	/**
	 * Sets the terms of one taxonomy on one post, replacing the ones it has. The ability works out the final set (replace,
	 * add or remove) and passes it in full, with the set it computed from.
	 *
	 * Right before the write, the post is read again and checked: the caller's own permission check runs again on that post
	 * ($still_allowed), and the assigned IDs must still be $expected_current. A change made since the caller read the terms
	 * would otherwise be lost, because the write is a full replace.
	 *
	 * @param int      $post_id          Post ID.
	 * @param string   $taxonomy         Taxonomy name.
	 * @param int[]    $term_ids         Term IDs to set, each a positive integer, with no repeats. May be empty.
	 * @param int[]    $expected_current The term IDs the caller computed the new set from, each a positive integer, with no repeats.
	 * @param callable $still_allowed    Receives the freshly read post, returns true when the caller may still make this change.
	 * @return int[]|\WP_Error The term IDs the post has in the taxonomy after the write, read back from the database. Or an error:
	 *                         steadhand_forbidden or steadhand_invalid_input before any write, steadhand_refused_content_changed
	 *                         when the terms changed since the caller read them, and steadhand_update_failed when WordPress
	 *                         reported an error from the write itself. Only the last one may have changed the post.
	 * @throws \LogicException           When called outside a write.
	 * @throws \InvalidArgumentException When an ID is not a positive integer, or is given twice.
	 */
	public static function set( int $post_id, string $taxonomy, array $term_ids, array $expected_current, callable $still_allowed ): array|\WP_Error {
		self::assert_writing();
		self::assert_ids( $term_ids );
		self::assert_ids( $expected_current );

		// Read again, uncached: the post may have been trashed or changed since the caller looked at it.
		clean_post_cache( $post_id );
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'trash' === $post->post_status || ! PostTypes::is_allowed( $post->post_type ) ) {
			return new \WP_Error( 'steadhand_forbidden', __( 'Not found, or this agent may not access it.', 'steadhand' ), array( 'status' => 403 ) );
		}

		if ( ! Taxonomies::is_allowed( $taxonomy ) || ! in_array( $taxonomy, get_object_taxonomies( $post->post_type ), true ) ) {
			return new \WP_Error( 'steadhand_invalid_input', __( 'This taxonomy is not one Steadhand allows for this post.', 'steadhand' ), array( 'status' => 400 ) );
		}

		foreach ( $term_ids as $term_id ) {
			$term = get_term( $term_id, $taxonomy );
			if ( ! $term instanceof \WP_Term || $term->taxonomy !== $taxonomy ) {
				return new \WP_Error(
					'steadhand_invalid_input',
					__( 'At least one term ID is not a term of this taxonomy. Use steadhand/list-terms to find the IDs.', 'steadhand' ),
					array( 'status' => 400 )
				);
			}
		}

		// The permission check, run again on the post as it is now, immediately before the write.
		if ( ! $still_allowed( $post ) ) {
			return new \WP_Error( 'steadhand_forbidden', __( 'Not found, or this agent may not access it.', 'steadhand' ), array( 'status' => 403 ) );
		}

		$current = TermReader::assigned_ids( $post_id, $taxonomy );
		if ( is_wp_error( $current ) ) {
			return $current;
		}

		sort( $expected_current, SORT_NUMERIC );
		if ( $current !== $expected_current ) {
			return new \WP_Error(
				'steadhand_refused_content_changed',
				__( "The post's terms changed while this call was running, so nothing was changed. Call steadhand/get-post-terms again, then send the change again.", 'steadhand' ),
				array( 'status' => 409 )
			);
		}

		// Integers only: WordPress skips an integer that matches no term, and creates a term for a string that matches none.
		$result = wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
		if ( is_wp_error( $result ) ) {
			// Core's own error is replaced by Steadhand's, so the message is ours and the caller sees one code for it.
			return new \WP_Error(
				'steadhand_update_failed',
				__( 'WordPress could not set the terms, so nothing was changed. Try again.', 'steadhand' ),
				array( 'status' => 500 )
			);
		}

		return TermReader::assigned_ids( $post_id, $taxonomy );
	}

	/**
	 * Refuses a call made outside a write.
	 *
	 * @throws \LogicException When Pipeline::is_writing() is false.
	 */
	private static function assert_writing(): void {
		if ( ! Pipeline::is_writing() ) {
			throw new \LogicException( 'TermWriter may only be called from a write ability.' );
		}
	}

	/**
	 * Checks that every term ID is a positive integer, and that none is given twice.
	 *
	 * @param array<mixed, mixed> $term_ids Term IDs.
	 * @throws \InvalidArgumentException When an ID is not a positive integer, or is given twice.
	 */
	private static function assert_ids( array $term_ids ): void {
		$seen = array();
		foreach ( $term_ids as $term_id ) {
			if ( ! is_int( $term_id ) || $term_id < 1 ) {
				throw new \InvalidArgumentException( 'TermWriter needs every term ID to be a positive integer.' );
			}

			if ( isset( $seen[ $term_id ] ) ) {
				throw new \InvalidArgumentException( 'TermWriter does not accept a term ID twice.' );
			}
			$seen[ $term_id ] = true;
		}
	}
}
