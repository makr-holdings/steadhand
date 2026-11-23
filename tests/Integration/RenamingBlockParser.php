<?php
/**
 * A block parser that renames the first root block, for testing the scan's cross-check against core.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

defined( 'ABSPATH' ) || exit;

/**
 * Core's parser, with the first root block renamed. Installed through core's block_parser_class filter, which parse_blocks()
 * applies, so the parser the scan is checked against disagrees with the scan. It is the only way to reach the cross-check
 * from a test, because the scan reads the same grammar as core.
 */
final class RenamingBlockParser extends \WP_Block_Parser {

	/**
	 * Parses as core does, then renames the first root block.
	 *
	 * @param string $document Content.
	 * @return array<int, array<string, mixed>>
	 */
	public function parse( $document ) {
		$blocks = parent::parse( $document );

		foreach ( $blocks as $index => $block ) {
			if ( is_string( $block['blockName'] ?? null ) ) {
				$blocks[ $index ]['blockName'] = 'core/heading';
				break;
			}
		}

		return $blocks;
	}
}
