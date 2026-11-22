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
	 * Plain-text description of a term, cut on a UTF-8 boundary.
	 *
	 * @param string $raw Description as stored.
	 */
	public static function description( string $raw ): string {
		return PostReader::cut_utf8( PostReader::plain_text( wp_strip_all_tags( $raw ) ), self::MAX_DESCRIPTION_BYTES );
	}
}
