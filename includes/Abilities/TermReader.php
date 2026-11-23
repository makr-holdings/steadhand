<?php
/**
 * Shared code for the term tools: term shapes, the description rule and the term schemas.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * Helpers shared by list-terms and get-post-terms. Holds no state.
 *
 * Output rules that the term tools follow, the same as the post tools:
 * - Names and labels are plain text (entities decoded), through PostReader::plain_text().
 * - A description is plain text with tags removed, cut to MAX_DESCRIPTION_BYTES on a UTF-8 boundary.
 * - Term meta is never read or returned.
 */
final class TermReader {

	/**
	 * Largest description returned, in bytes. Longer descriptions are cut.
	 */
	public const MAX_DESCRIPTION_BYTES = 2000;

	/**
	 * Most terms get-post-terms returns for one taxonomy on one post. The rest are counted, not loaded.
	 */
	public const MAX_TERMS_PER_TAXONOMY = 500;

	/**
	 * Schema for a term reference: id, name, slug and parent term.
	 *
	 * @return array<string, mixed>
	 */
	public static function ref_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'id', 'name', 'slug', 'parent' ),
			'properties'           => array(
				'id'     => PostReader::id_schema(),
				'name'   => array( 'type' => 'string' ),
				'slug'   => array( 'type' => 'string' ),
				'parent' => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
			),
		);
	}

	/**
	 * Builds a term reference.
	 *
	 * @param \WP_Term $term Term the caller may read.
	 * @return array{id: int, name: string, slug: string, parent: int}
	 */
	public static function ref( \WP_Term $term ): array {
		return array(
			'id'     => (int) $term->term_id,
			'name'   => PostReader::plain_text( (string) $term->name ),
			'slug'   => PostReader::utf8( (string) $term->slug ),
			'parent' => (int) $term->parent,
		);
	}

	/**
	 * How many terms of one taxonomy a post has, counted in the database. wp_get_object_terms() cannot return a count
	 * (fields => count fails in core), and the terms themselves are not loaded for it.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy name, already checked against the allowlist.
	 */
	public static function assigned_count( int $post_id, string $taxonomy ): int {
		global $wpdb;

		// Not cached on purpose: a count must reflect the term assignments made just before this call.
		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- See the comment above.
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->term_relationships} AS tr INNER JOIN {$wpdb->term_taxonomy} AS tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s",
				$post_id,
				$taxonomy
			)
		);
	}

	/**
	 * The term IDs a post has in one taxonomy, ascending, with no repeats. Read from the database, not from a
	 * cache that a write could leave behind. Callers must bound the count first (see assigned_count()).
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy name, already checked against the allowlist.
	 * @return int[]|\WP_Error
	 */
	public static function assigned_ids( int $post_id, string $taxonomy ): array|\WP_Error {
		$ids = wp_get_object_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}

		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		sort( $ids, SORT_NUMERIC );

		return $ids;
	}

	/**
	 * Plain-text description of a term, cut on a UTF-8 boundary.
	 *
	 * @param string $raw Description as stored.
	 */
	public static function description( string $raw ): string {
		return PostReader::cut_utf8( PostReader::plain_text( wp_strip_all_tags( $raw ) ), self::MAX_DESCRIPTION_BYTES );
	}
}
