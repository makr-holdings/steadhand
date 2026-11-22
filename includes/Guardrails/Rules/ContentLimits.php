<?php
/**
 * Limits the size, block count and nesting of the content a write stores.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Guardrails\Rules;

use Steadhand\Abilities\PostReader;
use Steadhand\Guardrails\Context;
use Steadhand\Guardrails\Result;
use Steadhand\Guardrails\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks content that is too large, not UTF-8, has too many blocks, or nests too deeply (SPEC §6.1). The limits are
 * the ones get-blocks and get-post can read back whole: PostReader::MAX_CONTENT_BYTES, PostReader::MAX_BLOCKS and
 * PostReader::MAX_DEPTH. Exactly at a limit passes; one over is refused.
 *
 * The byte size is checked before anything is parsed. Nesting is counted with an explicit stack, and the walk stops as
 * soon as a limit is passed.
 */
final class ContentLimits implements Rule {

	/** Result of the shape walk: within every limit. */
	private const SHAPE_OK = 'ok';

	/** Result of the shape walk: more blocks than allowed. */
	private const SHAPE_TOO_MANY = 'too_many';

	/** Result of the shape walk: nested deeper than allowed. */
	private const SHAPE_TOO_DEEP = 'too_deep';

	/**
	 * Returns the rule's ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'steadhand/content-limits';
	}

	/**
	 * Checks every content field.
	 *
	 * @param Context $context The call.
	 * @return Result
	 */
	public function evaluate( Context $context ): Result {
		foreach ( $context->content as $field => $markup ) {
			$problem = $this->problem( (string) $field, $markup );
			if ( null !== $problem ) {
				return Result::block( $problem );
			}
		}

		return Result::allow();
	}

	/**
	 * Returns the refusal for one field, or null when it is within every limit.
	 *
	 * @param string $field  Field name, for the message.
	 * @param mixed  $markup The field's value.
	 * @return string|null
	 */
	private function problem( string $field, mixed $markup ): ?string {
		if ( ! is_string( $markup ) ) {
			return sprintf(
				/* translators: %s: name of a content field, such as post_content. */
				__( 'The %s field must be text, so it was refused.', 'steadhand' ),
				$field
			);
		}

		if ( ! mb_check_encoding( $markup, 'UTF-8' ) ) {
			return sprintf(
				/* translators: %s: name of a content field, such as post_content. */
				__( 'The %s field is not valid UTF-8 text, so it was refused. Send the text as UTF-8.', 'steadhand' ),
				$field
			);
		}

		$bytes = strlen( $markup );
		if ( $bytes > PostReader::MAX_CONTENT_BYTES ) {
			return sprintf(
				/* translators: 1: name of a content field, such as post_content. 2: the byte size sent. 3: the byte limit. */
				__( 'The %1$s field is %2$d bytes, which is over the limit of %3$d bytes, so it was refused. Make it shorter.', 'steadhand' ),
				$field,
				$bytes,
				PostReader::MAX_CONTENT_BYTES
			);
		}

		$shape = $this->shape( $markup );
		if ( self::SHAPE_TOO_MANY === $shape ) {
			return sprintf(
				/* translators: 1: name of a content field, such as post_content. 2: the block limit. */
				__( 'The %1$s field has more than %2$d blocks, counting nested blocks, so it was refused. Use fewer blocks.', 'steadhand' ),
				$field,
				PostReader::MAX_BLOCKS
			);
		}

		if ( self::SHAPE_TOO_DEEP === $shape ) {
			return sprintf(
				/* translators: 1: name of a content field, such as post_content. 2: the nesting limit. */
				__( 'The %1$s field nests blocks more than %2$d levels deep, so it was refused. Nest blocks less deeply.', 'steadhand' ),
				$field,
				PostReader::MAX_DEPTH
			);
		}

		return null;
	}

	/**
	 * Parses the content and walks its blocks. Returns as soon as a limit is passed.
	 *
	 * @param string $markup Content, already checked for size and encoding.
	 * @return string One of the SHAPE_* constants.
	 */
	private function shape( string $markup ): string {
		$pending = array();
		foreach ( parse_blocks( $markup ) as $block ) {
			$pending[] = array( $block, 1 );
		}

		$count = 0;
		while ( array() !== $pending ) {
			list( $node, $level ) = array_pop( $pending );
			if ( ! is_array( $node ) ) {
				continue;
			}

			if ( null !== ( $node['blockName'] ?? null ) ) {
				++$count;
				if ( $count > PostReader::MAX_BLOCKS ) {
					return self::SHAPE_TOO_MANY;
				}

				if ( $level > PostReader::MAX_DEPTH ) {
					return self::SHAPE_TOO_DEEP;
				}
			}

			foreach ( (array) ( $node['innerBlocks'] ?? array() ) as $child ) {
				$pending[] = array( $child, $level + 1 );
			}
		}

		return self::SHAPE_OK;
	}
}
