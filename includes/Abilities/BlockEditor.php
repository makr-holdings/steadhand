<?php
/**
 * Finds the byte span of each block in stored post content, and splices operations into it by block path.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Abilities;

use Steadhand\Guardrails\Rules\BlockMarkup;

defined( 'ABSPATH' ) || exit;

/**
 * The pure logic behind steadhand/edit-blocks. Holds no state and makes no WordPress writes.
 *
 * Paths are the ones PostReader::walk() gives: dot-separated indexes, whitespace-only freeform text is not counted, and
 * freeform nodes exist only at the root. Offsets are bytes.
 *
 * Trust: spans() scans the content with core's own delimiter grammar (BlockMarkup::TOKEN) and then compares its tree with
 * parse_blocks(), name by name and level by level. Any disagreement, or any markup that is not balanced, makes spans() return
 * null, and apply() refuses the call as unsupported. Each span is computed from the same tokens core reads, so a per-block
 * check against core adds nothing the whole-tree comparison does not already prove.
 */
final class BlockEditor {

	/** The operations an agent may send. */
	public const OPERATIONS = array( 'replace', 'insert_before', 'insert_after', 'remove', 'append' );

	/** Most operations one call may send. */
	public const MAX_OPERATIONS = 20;

	/** Longest path, in bytes. */
	public const MAX_PATH_BYTES = 100;

	/** Longest block name, in bytes. */
	public const MAX_NAME_BYTES = 200;

	/**
	 * The path grammar without anchors: an index, or dot-separated indexes with no leading zero, at most 20 levels. Used
	 * with \A and \z in code, and with ^ and $ in the schema.
	 */
	public const PATH_BODY = '(0|[1-9][0-9]*)(\.(0|[1-9][0-9]*)){0,19}';

	/** The HTML comments WordPress reads as post breaks: "<!--more-->" (as core's own pattern), "<!--nextpage-->", "<!--noteaser-->". */
	private const ALLOWED_COMMENT = '/<!--more(.*?)?-->|<!--nextpage-->|<!--noteaser-->/';

	/**
	 * The pattern get_the_content() uses to remove core/more delimiters when it shows a post. Copied from
	 * wp-includes/post-template.php, get_the_content() (WordPress 7.x, the same line in 6.9): '/<!-- \/?wp:more(.*?) -->/'. Keep it
	 * identical, so that Steadhand checks what WordPress does at display time.
	 */
	public const DISPLAY_MORE = '/<!-- \/?wp:more(.*?) -->/';

	/** Bytes that count as whitespace when deciding whether a separator is needed. */
	private const WHITESPACE = " \t\n\r\f\v";

	/** The separator added between inserted markup and adjacent content that is not whitespace. */
	private const SEPARATOR = "\n\n";

	/** Order of edits that start at the same byte: insert_after, then insert_before, then spans, then append. */
	private const RANK_AFTER  = 0;
	private const RANK_BEFORE = 1;
	private const RANK_SPAN   = 2;
	private const RANK_APPEND = 3;

	/**
	 * Scans the content into a tree of blocks, each with its path and byte span. Root freeform text that is not
	 * whitespace-only is a node with name null. Returns null when the markup is not balanced, when a delimiter's attributes
	 * are not JSON, when the content is not UTF-8, or when core's parser disagrees with the scan.
	 *
	 * @param string $content Stored post content.
	 * @return array<int, array{path: string, name: string|null, start: int, end: int, children: array<int, mixed>}>|null
	 */
	public static function spans( string $content ): ?array {
		if ( ! mb_check_encoding( $content, 'UTF-8' ) ) {
			return null;
		}

		$frames = array();
		$roots  = array();
		$offset = 0;

		while ( true ) {
			$matches = null;
			$found   = preg_match( BlockMarkup::TOKEN, $content, $matches, PREG_OFFSET_CAPTURE, $offset );
			if ( false === $found || PREG_NO_ERROR !== preg_last_error() ) {
				return null;
			}
			if ( 0 === $found ) {
				break;
			}

			list( $text, $start ) = $matches[0];
			$end                  = $start + strlen( $text );
			$is_closer            = null !== self::group( $matches, 'closer' );
			$is_void              = null !== self::group( $matches, 'void' );
			$attrs                = self::group( $matches, 'attrs' );
			$namespace            = self::group( $matches, 'namespace' );
			$name                 = ( null === $namespace ? 'core/' : $namespace[0] ) . $matches['name'][0];

			if ( null !== $attrs && ! $is_closer && null === json_decode( $attrs[0], true ) ) {
				return null;
			}

			if ( $is_void ) {
				self::attach(
					$frames,
					$roots,
					array(
						'name'     => $name,
						'start'    => $start,
						'end'      => $end,
						'children' => array(),
					)
				);
			} elseif ( $is_closer ) {
				if ( array() === $frames ) {
					return null;
				}

				$frame = array_pop( $frames );
				if ( $frame['name'] !== $name ) {
					return null;
				}

				self::attach(
					$frames,
					$roots,
					array(
						'name'     => $name,
						'start'    => $frame['start'],
						'end'      => $end,
						'children' => $frame['children'],
					)
				);
			} else {
				$frames[] = array(
					'name'     => $name,
					'start'    => $start,
					'children' => array(),
				);
			}

			$offset = $end;
		}

		if ( array() !== $frames ) {
			return null;
		}

		$nodes  = array();
		$cursor = 0;
		foreach ( $roots as $root ) {
			if ( '' !== trim( substr( $content, $cursor, $root['start'] - $cursor ) ) ) {
				$nodes[] = self::freeform( $cursor, $root['start'] );
			}
			$nodes[] = $root;
			$cursor  = $root['end'];
		}
		if ( '' !== trim( substr( $content, $cursor ) ) ) {
			$nodes[] = self::freeform( $cursor, strlen( $content ) );
		}

		$tree = self::number( $nodes, '' );

		return self::same( self::kept( parse_blocks( $content ) ), $tree ) ? $tree : null;
	}

	/**
	 * Checks the shape of the operations: the count, the fields each operation needs or refuses, the path and name
	 * grammar, markup text, and the rule that no path names a block another operation names, or lies inside one.
	 *
	 * @param array<int, mixed> $operations Operations as sent.
	 * @return \WP_Error|null Null when they are valid, otherwise a steadhand_invalid_input error.
	 */
	public static function check_operations( array $operations ): ?\WP_Error {
		$count = count( $operations );
		if ( $count < 1 || $count > self::MAX_OPERATIONS ) {
			return self::invalid(
				/* translators: %d: the most operations one call may send. */
				sprintf( __( 'Send between 1 and %d operations.', 'steadhand' ), self::MAX_OPERATIONS )
			);
		}

		$paths = array();
		foreach ( array_values( $operations ) as $number => $operation ) {
			$where = $number + 1;
			if ( ! is_array( $operation ) ) {
				return self::invalid(
					/* translators: %d: the number of the operation, counting from 1. */
					sprintf( __( 'Operation %d must be an object with op and the fields that op takes.', 'steadhand' ), $where )
				);
			}

			$op = $operation['op'] ?? null;
			if ( ! is_string( $op ) || ! in_array( $op, self::OPERATIONS, true ) ) {
				return self::invalid(
					/* translators: %d: the number of the operation, counting from 1. */
					sprintf( __( 'Operation %d needs op: replace, insert_before, insert_after, remove or append.', 'steadhand' ), $where )
				);
			}

			if ( 'append' === $op ) {
				if ( array_key_exists( 'path', $operation ) || array_key_exists( 'name', $operation ) ) {
					return self::invalid(
						/* translators: %d: the number of the operation, counting from 1. */
						sprintf( __( 'Operation %d is append, which takes no path and no name. It adds its markup to the end of the content.', 'steadhand' ), $where )
					);
				}
			} else {
				$path = $operation['path'] ?? null;
				if ( ! is_string( $path ) || strlen( $path ) > self::MAX_PATH_BYTES || 1 !== preg_match( '/\A' . self::PATH_BODY . '\z/', $path ) ) {
					return self::invalid(
						/* translators: %d: the number of the operation, counting from 1. */
						sprintf( __( 'Operation %d needs a path such as "0" or "2.1", as steadhand/get-blocks returns it.', 'steadhand' ), $where )
					);
				}

				$name = $operation['name'] ?? null;
				if ( ! array_key_exists( 'name', $operation ) || ( null !== $name && ( ! is_string( $name ) || strlen( $name ) > self::MAX_NAME_BYTES ) ) ) {
					return self::invalid(
						/* translators: %d: the number of the operation, counting from 1. */
						sprintf( __( 'Operation %d needs name: the block name get-blocks returned for its path, or null for classic HTML.', 'steadhand' ), $where )
					);
				}

				$paths[] = $path;
			}

			if ( 'remove' === $op ) {
				if ( array_key_exists( 'markup', $operation ) ) {
					return self::invalid(
						/* translators: %d: the number of the operation, counting from 1. */
						sprintf( __( 'Operation %d is remove, which takes no markup.', 'steadhand' ), $where )
					);
				}
				continue;
			}

			$markup = $operation['markup'] ?? null;
			if ( ! is_string( $markup ) || '' === trim( $markup ) ) {
				return self::invalid(
					/* translators: %d: the number of the operation, counting from 1. */
					sprintf( __( 'Operation %d needs markup: block markup or classic HTML that is not blank.', 'steadhand' ), $where )
				);
			}

			if ( ! mb_check_encoding( $markup, 'UTF-8' ) ) {
				return self::invalid(
					/* translators: %d: the number of the operation, counting from 1. */
					sprintf( __( 'The markup of operation %d must be valid UTF-8 text.', 'steadhand' ), $where )
				);
			}

			if ( null === self::spans( $markup ) ) {
				return self::invalid(
					/* translators: %d: the number of the operation, counting from 1. */
					sprintf( __( 'The markup of operation %d must be complete blocks: every block it opens must be closed in the same markup, and it must not close a block it did not open.', 'steadhand' ), $where )
				);
			}

			if ( true !== self::has_comment_check( $markup ) ) {
				return self::invalid(
					/* translators: %d: the number of the operation, counting from 1. */
					sprintf( __( 'The markup of operation %d contains an HTML comment that is not a block comment. Send complete blocks only; use the core/more or core/nextpage block for a break.', 'steadhand' ), $where )
				);
			}

			if ( false !== self::display_split_problem(
				$markup,
				array(
					array(
						'at'   => 0,
						'text' => $markup,
					),
				)
			) ) {
				return self::invalid(
					/* translators: %d: the number of the operation, counting from 1. */
					sprintf( __( 'The markup of operation %d would make WordPress drop part of the content when it shows the post. Send the core/more block with its opening and closing comments, not as a self-closing block.', 'steadhand' ), $where )
				);
			}
		}

		foreach ( $paths as $i => $path ) {
			foreach ( $paths as $j => $other ) {
				if ( $i !== $j && ( $path === $other || str_starts_with( $other, $path . '.' ) ) ) {
					return self::invalid(
						__( 'Two operations name the same path, or one path is inside a block another operation names. Name each block once, and do not name a block inside another operation\'s block.', 'steadhand' )
					);
				}
			}
		}

		return null;
	}

	/**
	 * Applies the operations to the stored content and returns the result as one string, with the sent bytes as they were
	 * sent. Nothing that is stored is built this way: storage goes through PostWriter::assemble(), which filters the sent
	 * bytes. Tests use this to compare spans and splices.
	 *
	 * @param string            $content    Stored post content.
	 * @param array<int, mixed> $operations Operations, as checked by check_operations().
	 * @return string|\WP_Error The raw result, or a refusal (see segments()).
	 */
	public static function apply( string $content, array $operations ): string|\WP_Error {
		$segments = self::segments( $content, $operations );
		if ( is_wp_error( $segments ) ) {
			return $segments;
		}

		$out = '';
		foreach ( $segments as $segment ) {
			$out .= 'kept' === $segment['type'] ? substr( $content, $segment['start'], $segment['length'] ) : $segment['text'];
		}

		return $out;
	}

	/**
	 * Describes the new content as segments, in document order. Every path refers to the content as stored before the call,
	 * so the order of the operations does not change the result.
	 *
	 * Each operation is resolved against the scanned tree and checked against its name. Everything outside the spans the
	 * operations replace or remove is a kept segment: a byte range of the stored content. Markup the call sends, and the
	 * "\n\n" separators between it and adjacent content, are sent segments. Inserted markup and appended markup get one "\n\n"
	 * between them and an adjacent piece only when neither side of that boundary is whitespace and at least one side is the
	 * inserted markup. Two inserted pieces that meet get one "\n\n" too. Replacement markup never gets a separator. Adjacent
	 * sent segments are one run: PostWriter filters each run as a whole.
	 *
	 * @param string            $content    Stored post content.
	 * @param array<int, mixed> $operations Operations, as checked by check_operations().
	 * @return array<int, array{type: string, start?: int, length?: int, text?: string}>|\WP_Error Segments, or a refusal:
	 *                          steadhand_refused_unsupported_markup, steadhand_refused_block_mismatch, or steadhand_invalid_input.
	 */
	public static function segments( string $content, array $operations ): array|\WP_Error {
		$invalid = self::check_operations( $operations );
		if ( null !== $invalid ) {
			return $invalid;
		}

		$tree = self::spans( $content );
		if ( null === $tree ) {
			return self::unsupported();
		}

		$nodes = array();
		self::index( $tree, $nodes );

		$edits = array();
		foreach ( array_values( $operations ) as $number => $operation ) {
			$op = (string) $operation['op'];

			if ( 'append' === $op ) {
				$edits[] = self::edit( strlen( $content ), strlen( $content ), self::RANK_APPEND, (string) $operation['markup'], true, $number );
				continue;
			}

			$node = $nodes[ (string) $operation['path'] ] ?? null;
			if ( null === $node || $node['name'] !== $operation['name'] ) {
				return self::mismatch();
			}

			$markup = (string) ( $operation['markup'] ?? '' );
			switch ( $op ) {
				case 'replace':
					$edits[] = self::edit( $node['start'], $node['end'], self::RANK_SPAN, $markup, false, $number );
					break;
				case 'remove':
					$edits[] = self::edit( $node['start'], $node['end'], self::RANK_SPAN, '', false, $number );
					break;
				case 'insert_before':
					$edits[] = self::edit( $node['start'], $node['start'], self::RANK_BEFORE, $markup, true, $number );
					break;
				default:
					$edits[] = self::edit( $node['end'], $node['end'], self::RANK_AFTER, $markup, true, $number );
					break;
			}
		}

		usort(
			$edits,
			static fn( array $a, array $b ): int => array( $a['start'], $a['end'], $a['rank'], $a['order'] ) <=> array( $b['start'], $b['end'], $b['rank'], $b['order'] )
		);

		$pieces = array();
		$cursor = 0;
		foreach ( $edits as $edit ) {
			if ( $edit['start'] < $cursor ) {
				// Cannot happen once check_operations() has refused overlapping paths. Fail closed if it does.
				return self::unsupported();
			}

			if ( $edit['start'] > $cursor ) {
				$pieces[] = self::kept_piece( $content, $cursor, $edit['start'] - $cursor );
			}

			$pieces[] = array(
				'kind'     => 'sent',
				'text'     => $edit['text'],
				'inserted' => $edit['inserted'],
			);
			$cursor   = max( $cursor, $edit['end'] );
		}

		if ( $cursor < strlen( $content ) ) {
			$pieces[] = self::kept_piece( $content, $cursor, strlen( $content ) - $cursor );
		}

		return self::join( $pieces );
	}

	/**
	 * Whether a break comment is the sole inner content of its block: it lies between the block's opening and closing delimiters,
	 * and the whole inner content (whitespace trimmed) is the break as core serialises it. A core/more block holds "<!--more-->" or
	 * "<!--more text-->", optionally followed by "<!--noteaser-->" (core's more block, with customText and noTeaser). A core/nextpage
	 * block holds "<!--nextpage-->". Anything else inside the block, or a comment inside a delimiter's attributes, is refused.
	 *
	 * @param string                                                $text  The text.
	 * @param array<int, array{start: int, end: int, text: string}> $tokens The block delimiters of the text.
	 * @param array<string, mixed>                                  $owner The innermost node that holds the comment.
	 * @param string                                                $kind  The block name the comment needs: core/more or core/nextpage.
	 * @param int                                                   $start Start of the comment.
	 * @param int                                                   $end   End of the comment.
	 * @return bool
	 */
	private static function sole_break_content( string $text, array $tokens, array $owner, string $kind, int $start, int $end ): bool {
		$open  = null;
		$close = null;
		foreach ( $tokens as $token ) {
			if ( $token['start'] === $owner['start'] ) {
				$open = $token;
			}
			if ( $token['end'] === $owner['end'] && $token['start'] > $owner['start'] ) {
				$close = $token;
			}
		}
		if ( null === $open || null === $close || $start < $open['end'] || $end > $close['start'] ) {
			return false;
		}

		$inner   = substr( $text, $open['end'], $close['start'] - $open['end'] );
		$pattern = 'core/nextpage' === $kind
			? '/\A\s*<!--nextpage-->\s*\z/'
			: '/\A\s*<!--more( [^>\n]*)?-->(\s*<!--noteaser-->)?\s*\z/';

		return 1 === preg_match( $pattern, $inner );
	}

	/**
	 * Whether a text has no stray HTML comment (see has_stray_comment()). Returns true when it has none, and false when it has
	 * one or when PCRE fails, so a caller refuses in either case.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function has_comment_check( string $text ): bool {
		return false === self::has_stray_comment( $text );
	}

	/**
	 * Whether a match of core's display-time pattern overlaps a sent run without being exactly one whole block delimiter token.
	 *
	 * Core's get_the_content() removes the core/more delimiters from the content it shows (see DISPLAY_MORE), and the pattern is not
	 * anchored to the end of a delimiter, so a void delimiter can run on to the next " -->" and take the bytes in between with it.
	 * A match that overlaps a sent run is allowed only when it is exactly one whole delimiter token of the content. Null when PCRE
	 * fails, and the caller refuses.
	 *
	 * @param string                                   $content The content to check.
	 * @param array<int, array{at: int, text: string}> $runs    Sent runs, with the offset each starts at in $content.
	 * @return bool|null True when a match is a problem.
	 */
	public static function display_split_problem( string $content, array $runs ): ?bool {
		$matches = null;
		if ( false === preg_match_all( self::DISPLAY_MORE, $content, $matches, PREG_OFFSET_CAPTURE ) || PREG_NO_ERROR !== preg_last_error() ) {
			return null;
		}
		$tokens = self::tokens( $content );
		if ( null === $tokens ) {
			return null;
		}

		foreach ( $matches[0] as $match ) {
			$start   = $match[1];
			$end     = $start + strlen( $match[0] );
			$overlap = false;
			foreach ( $runs as $run ) {
				$run_end = $run['at'] + strlen( $run['text'] );
				if ( $start < $run_end && $run['at'] < $end ) {
					$overlap = true;
					break;
				}
			}
			if ( ! $overlap ) {
				continue;
			}

			$whole = false;
			foreach ( $tokens as $token ) {
				if ( $token['start'] === $start && $token['end'] === $end ) {
					$whole = true;
					break;
				}
			}
			if ( ! $whole ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Every block delimiter in a text, as core's grammar reads it (BlockMarkup::TOKEN), with its byte span. Null when PCRE fails.
	 *
	 * @param string $text Text.
	 * @return array<int, array{start: int, end: int, text: string}>|null
	 */
	public static function tokens( string $text ): ?array {
		$matches = null;
		$found   = preg_match_all( BlockMarkup::TOKEN, $text, $matches, PREG_OFFSET_CAPTURE );
		if ( false === $found || PREG_NO_ERROR !== preg_last_error() ) {
			return null;
		}

		$out = array();
		foreach ( $matches[0] as $match ) {
			$out[] = array(
				'start' => $match[1],
				'end'   => $match[1] + strlen( $match[0] ),
				'text'  => $match[0],
			);
		}

		return $out;
	}

	/**
	 * Whether a text holds an HTML comment that is not a block comment, or a break comment outside its block. Every "<!--" must start
	 * a block delimiter, or be a break comment inside its break block: "<!--more…-->" and "<!--noteaser-->" inside a core/more block,
	 * "<!--nextpage-->" inside a core/nextpage block. Every "-->" must lie inside one of those. Core splits content at a break comment
	 * when it displays it, and removes the comment, so a bare one would make bytes that were not adjacent become adjacent. Null when
	 * PCRE fails or the text does not read as blocks, and the caller refuses.
	 *
	 * @param string $text Text.
	 * @return bool|null
	 */
	public static function has_stray_comment( string $text ): ?bool {
		$tokens = self::tokens( $text );
		$tree   = self::spans( $text );
		if ( null === $tokens || null === $tree ) {
			return null;
		}

		$matches = null;
		$found   = preg_match_all( self::ALLOWED_COMMENT, $text, $matches, PREG_OFFSET_CAPTURE );
		if ( false === $found || PREG_NO_ERROR !== preg_last_error() ) {
			return null;
		}

		$nodes  = self::flatten( $tree );
		$marked = array();
		foreach ( $tokens as $token ) {
			$marked[] = array(
				'start' => $token['start'],
				'end'   => $token['end'],
			);
		}
		foreach ( $matches[0] as $match ) {
			$start = $match[1];
			$end   = $start + strlen( $match[0] );
			$owner = null;
			foreach ( $nodes as $node ) {
				if ( $node['start'] <= $start && $end <= $node['end'] ) {
					$owner = $node;
				}
			}

			$wanted = str_starts_with( $match[0], '<!--nextpage' ) ? 'core/nextpage' : 'core/more';
			if ( null !== $owner && $wanted === $owner['name'] && self::sole_break_content( $text, $tokens, $owner, $wanted, $start, $end ) ) {
				$marked[] = array(
					'start' => $start,
					'end'   => $end,
				);
			}
		}

		$opens = null;
		if ( false === preg_match_all( '/<!--/', $text, $opens, PREG_OFFSET_CAPTURE ) || PREG_NO_ERROR !== preg_last_error() ) {
			return null;
		}
		foreach ( $opens[0] as $open ) {
			$starts_marked = false;
			foreach ( $marked as $span ) {
				if ( $span['start'] === $open[1] ) {
					$starts_marked = true;
					break;
				}
			}
			if ( ! $starts_marked ) {
				return true;
			}
		}

		$closes = null;
		if ( false === preg_match_all( '/-->/', $text, $closes, PREG_OFFSET_CAPTURE ) || PREG_NO_ERROR !== preg_last_error() ) {
			return null;
		}
		foreach ( $closes[0] as $close ) {
			$inside = false;
			foreach ( $marked as $span ) {
				if ( $span['start'] <= $close[1] && $close[1] + 3 <= $span['end'] ) {
					$inside = true;
					break;
				}
			}
			if ( ! $inside ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the final content's block delimiters are exactly the ones that can be accounted for: the stored delimiters that
	 * were kept, and the delimiters core finds in each sent run when it reads that run alone. Three conditions, all required:
	 *
	 * 1. every delimiter of the final content lies wholly inside one kept range or one sent run;
	 * 2. the delimiters inside each kept range are the stored delimiters that lie wholly inside that range: the same count, the same
	 *    order, the same offset from the start of the range, and the same bytes. A stored delimiter that is cut by a kept range's
	 *    edge, without lying wholly inside a kept range, is refused;
	 * 3. the delimiters inside each sent run are the delimiters found by scanning that run alone, with the same offsets and bytes.
	 *
	 * Kept ranges are verbatim copies of the stored bytes, and the sent runs are the filtered text, so the final delimiters are the
	 * stored ones that were kept plus the ones core would find in each run. A delimiter that is only whole once bytes from two
	 * places are joined (the two halves of a split delimiter, for example) fails condition 1 or 3.
	 *
	 * @param string                                                    $stored Stored content.
	 * @param array<int, array{start: int, end: int, final_start: int}> $kept   Kept ranges in stored offsets, with the offset each starts at in the final content.
	 * @param array<int, array{at: int, text: string}>                  $runs   Sent runs as they were filtered, with the offset each starts at in the final content.
	 * @param string                                                    $result_content The final content.
	 * @return bool
	 */
	public static function aligned( string $stored, array $kept, array $runs, string $result_content ): bool {
		$stored_tokens = self::tokens( $stored );
		$final_tokens  = self::tokens( $result_content );
		if ( null === $stored_tokens || null === $final_tokens ) {
			return false;
		}

		$regions = array();
		foreach ( $kept as $range ) {
			$regions[] = array(
				'from' => $range['final_start'],
				'to'   => $range['final_start'] + $range['end'] - $range['start'],
			);
		}
		foreach ( $runs as $run ) {
			if ( substr( $result_content, $run['at'], strlen( $run['text'] ) ) !== $run['text'] ) {
				return false;
			}
			$regions[] = array(
				'from' => $run['at'],
				'to'   => $run['at'] + strlen( $run['text'] ),
			);
		}

		// Condition 1: every final delimiter lies wholly inside one region.
		foreach ( $final_tokens as $token ) {
			$inside = false;
			foreach ( $regions as $region ) {
				if ( $region['from'] <= $token['start'] && $token['end'] <= $region['to'] ) {
					$inside = true;
					break;
				}
			}
			if ( ! $inside ) {
				return false;
			}
		}

		// Condition 2: a stored delimiter is wholly kept or wholly outside every kept range, and each kept range holds the same
		// delimiters, at the same places and with the same bytes, as the stored content.
		foreach ( $stored_tokens as $token ) {
			$inside = false;
			$touch  = false;
			foreach ( $kept as $range ) {
				if ( $range['start'] <= $token['start'] && $token['end'] <= $range['end'] ) {
					$inside = true;
				} elseif ( $token['start'] < $range['end'] && $range['start'] < $token['end'] ) {
					$touch = true;
				}
			}
			if ( $touch && ! $inside ) {
				return false;
			}
		}
		foreach ( $kept as $range ) {
			$expected = array();
			foreach ( $stored_tokens as $token ) {
				if ( $range['start'] <= $token['start'] && $token['end'] <= $range['end'] ) {
					$expected[] = array( $token['start'] - $range['start'], $token['text'] );
				}
			}
			$actual = array();
			$length = $range['end'] - $range['start'];
			foreach ( $final_tokens as $token ) {
				if ( $range['final_start'] <= $token['start'] && $token['end'] <= $range['final_start'] + $length ) {
					$actual[] = array( $token['start'] - $range['final_start'], $token['text'] );
				}
			}
			if ( $expected !== $actual ) {
				return false;
			}
		}

		// Condition 3: each sent run holds the delimiters core finds in it alone. It is kept, although conditions 1 and 2 seem to imply
		// it: the delimiter grammar has a negative lookahead, which can read past the end of a run.
		foreach ( $runs as $run ) {
			$alone = self::tokens( $run['text'] );
			if ( null === $alone ) {
				return false;
			}
			$expected = array();
			foreach ( $alone as $token ) {
				$expected[] = array( $token['start'], $token['text'] );
			}
			$actual = array();
			foreach ( $final_tokens as $token ) {
				if ( $run['at'] <= $token['start'] && $token['end'] <= $run['at'] + strlen( $run['text'] ) ) {
					$actual[] = array( $token['start'] - $run['at'], $token['text'] );
				}
			}
			if ( $expected !== $actual ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether the content the segments produce keeps every stored block the segments keep: the same name, at the offset the kept
	 * bytes moved to. A block whose whole span is kept must also keep its length. A block that is replaced or removed, or that
	 * lies inside one, is not checked. A block that only has bytes added inside it (an ancestor of an insert) is checked for its
	 * name and its start only.
	 *
	 * The final content must itself be complete blocks that agree with core (spans() is not null).
	 *
	 * @param string                                                    $stored         Stored content.
	 * @param array<int, array{start: int, end: int, final_start: int}> $kept           Kept ranges, in stored offsets, with the
	 *                                                                                  offset each starts at in the result.
	 * @param string                                                    $result_content The content the segments produce.
	 *
	 * @return bool
	 */
	public static function preserved( string $stored, array $kept, string $result_content ): bool {
		$stored_tree = self::spans( $stored );
		$final_tree  = self::spans( $result_content );
		if ( null === $stored_tree || null === $final_tree ) {
			return false;
		}

		$by_start = array();
		foreach ( self::flatten( $final_tree ) as $candidate ) {
			$by_start[ $candidate['start'] ][] = $candidate;
		}

		foreach ( self::flatten( $stored_tree ) as $node ) {
			$run = null;
			foreach ( $kept as $range ) {
				if ( $range['start'] <= $node['start'] && $node['start'] < $range['end'] ) {
					$run = $range;
					break;
				}
			}
			if ( null === $run ) {
				continue;
			}

			$at     = $run['final_start'] + $node['start'] - $run['start'];
			$whole  = $node['end'] <= $run['end'];
			$length = $node['end'] - $node['start'];

			$found = false;
			foreach ( $by_start[ $at ] ?? array() as $candidate ) {
				if ( $candidate['name'] === $node['name'] && ( ! $whole || $candidate['end'] - $candidate['start'] === $length ) ) {
					$found = true;
					break;
				}
			}

			if ( ! $found ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Every node of a span tree, parents before children, in document order.
	 *
	 * @param array<int, array<string, mixed>> $tree Spans.
	 * @return array<int, array<string, mixed>>
	 */
	private static function flatten( array $tree ): array {
		$out = array();
		foreach ( $tree as $node ) {
			$out[] = $node;
			foreach ( self::flatten( $node['children'] ) as $child ) {
				$out[] = $child;
			}
		}

		return $out;
	}

	/**
	 * A piece of stored content that is kept.
	 *
	 * @param string $content Stored content.
	 * @param int    $start   Start offset.
	 * @param int    $length  Length in bytes.
	 * @return array{kind: string, start: int, length: int, text: string, inserted: bool}
	 */
	private static function kept_piece( string $content, int $start, int $length ): array {
		return array(
			'kind'     => 'kept',
			'start'    => $start,
			'length'   => $length,
			'text'     => substr( $content, $start, $length ),
			'inserted' => false,
		);
	}

	/**
	 * Joins the pieces into segments, adding the separator where an inserted piece meets content that is not whitespace. A
	 * separator is a sent segment. Adjacent kept ranges that are contiguous are merged. Adjacent sent text is merged, so a
	 * run of sent text is one segment.
	 *
	 * @param array<int, array{kind: string, text: string, inserted: bool, start?: int, length?: int}> $pieces Pieces in output order.
	 * @return array<int, array{type: string, start?: int, length?: int, text?: string}>
	 */
	private static function join( array $pieces ): array {
		$segments = array();
		$prev     = null;

		foreach ( $pieces as $piece ) {
			if ( '' === $piece['text'] ) {
				continue;
			}

			if ( null !== $prev && ( $prev['inserted'] || $piece['inserted'] ) && ! self::is_space( substr( $prev['text'], -1 ) ) && ! self::is_space( $piece['text'][0] ) ) {
				self::add_sent( $segments, self::SEPARATOR );
			}

			if ( 'kept' === $piece['kind'] ) {
				$last = count( $segments ) > 0 ? $segments[ count( $segments ) - 1 ] : null;
				if ( null !== $last && 'kept' === $last['type'] && $last['start'] + $last['length'] === $piece['start'] ) {
					$segments[ count( $segments ) - 1 ]['length'] += $piece['length'];
				} else {
					$segments[] = array(
						'type'   => 'kept',
						'start'  => $piece['start'],
						'length' => $piece['length'],
					);
				}
			} else {
				self::add_sent( $segments, $piece['text'] );
			}

			$prev = $piece;
		}

		return $segments;
	}

	/**
	 * Adds sent text to the segment list, extending the last segment when that is sent text too.
	 *
	 * @param array<int, array<string, mixed>> $segments Segments, by reference.
	 * @param string                           $text     Sent text.
	 */
	private static function add_sent( array &$segments, string $text ): void {
		$last = count( $segments ) > 0 ? $segments[ count( $segments ) - 1 ] : null;
		if ( null !== $last && 'sent' === $last['type'] ) {
			$segments[ count( $segments ) - 1 ]['text'] .= $text;

			return;
		}

		$segments[] = array(
			'type' => 'sent',
			'text' => $text,
		);
	}

	/**
	 * Whether one byte is whitespace.
	 *
	 * @param string $byte One byte.
	 */
	private static function is_space( string $byte ): bool {
		return false !== strpos( self::WHITESPACE, $byte );
	}

	/**
	 * Builds one edit.
	 *
	 * @param int    $start  Byte offset where the edit starts.
	 * @param int    $end    Byte offset where the edit ends. Equal to $start for an insert.
	 * @param int    $rank   Order among edits at the same offset.
	 * @param string $text   Text that replaces the span.
	 * @param bool   $inserted Whether the text is inserted markup, which takes separators.
	 * @param int    $order  The operation's position in the call, for a stable sort.
	 * @return array{start: int, end: int, rank: int, text: string, inserted: bool, order: int}
	 */
	private static function edit( int $start, int $end, int $rank, string $text, bool $inserted, int $order ): array {
		return array(
			'start'    => $start,
			'end'      => $end,
			'rank'     => $rank,
			'text'     => $text,
			'inserted' => $inserted,
			'order'    => $order,
		);
	}

	/**
	 * Adds a closed block to the innermost open block, or to the root.
	 *
	 * @param array<int, array<string, mixed>> $frames Open blocks, by reference.
	 * @param array<int, array<string, mixed>> $roots  Root blocks, by reference.
	 * @param array<string, mixed>             $node   The closed block.
	 */
	private static function attach( array &$frames, array &$roots, array $node ): void {
		if ( array() === $frames ) {
			$roots[] = $node;
			return;
		}

		$frames[ array_key_last( $frames ) ]['children'][] = $node;
	}

	/**
	 * A root freeform node with no path yet.
	 *
	 * @param int $start Start offset.
	 * @param int $end   End offset.
	 * @return array{name: null, start: int, end: int, children: array<int, mixed>}
	 */
	private static function freeform( int $start, int $end ): array {
		return array(
			'name'     => null,
			'start'    => $start,
			'end'      => $end,
			'children' => array(),
		);
	}

	/**
	 * Gives each node its path, counting only the nodes that are kept.
	 *
	 * @param array<int, array<string, mixed>> $nodes  Nodes at one level, all kept.
	 * @param string                           $prefix Path of the parent, or '' at the root.
	 * @return array<int, array<string, mixed>>
	 */
	private static function number( array $nodes, string $prefix ): array {
		$out = array();
		foreach ( array_values( $nodes ) as $index => $node ) {
			$path = '' === $prefix ? (string) $index : $prefix . '.' . $index;

			$node['path']     = $path;
			$node['children'] = self::number( $node['children'], $path );
			$out[]            = $node;
		}

		return $out;
	}

	/**
	 * Maps every path to its node.
	 *
	 * @param array<int, array<string, mixed>>    $tree  Numbered nodes.
	 * @param array<string, array<string, mixed>> $map Filled in place.
	 */
	private static function index( array $tree, array &$map ): void {
		foreach ( $tree as $node ) {
			$map[ (string) $node['path'] ] = $node;
			self::index( $node['children'], $map );
		}
	}

	/**
	 * The nodes get-blocks keeps: parse_blocks() output with whitespace-only freeform text removed, at every level.
	 *
	 * @param array<int, mixed> $parsed Output of parse_blocks().
	 * @return array<int, array{name: string|null, children: array<int, mixed>}>
	 */
	private static function kept( array $parsed ): array {
		$out = array();
		foreach ( $parsed as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$name = $node['blockName'] ?? null;
			$html = (string) ( $node['innerHTML'] ?? '' );
			if ( null === $name && '' === trim( $html ) ) {
				continue;
			}

			$out[] = array(
				'name'     => null === $name ? null : (string) $name,
				'children' => self::kept( (array) ( $node['innerBlocks'] ?? array() ) ),
			);
		}

		return $out;
	}

	/**
	 * Whether two trees have the same names at every position.
	 *
	 * @param array<int, array{name: string|null, children: array<int, mixed>}> $a One tree.
	 * @param array<int, array<string, mixed>>                                  $b The other tree.
	 */
	private static function same( array $a, array $b ): bool {
		if ( count( $a ) !== count( $b ) ) {
			return false;
		}

		foreach ( array_values( $a ) as $i => $node ) {
			$other = $b[ $i ];
			if ( $node['name'] !== $other['name'] || ! self::same( $node['children'], $other['children'] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The match for a named group of a preg_match() with PREG_OFFSET_CAPTURE, or null when the group did not take part.
	 *
	 * @param array<string, array{0: string, 1: int}> $matches Matches.
	 * @param string                                  $group   Group name.
	 * @return array{0: string, 1: int}|null
	 */
	private static function group( array $matches, string $group ): ?array {
		return isset( $matches[ $group ] ) && -1 !== $matches[ $group ][1] ? $matches[ $group ] : null;
	}

	/**
	 * A refusal for a non-whitespace shape the scan cannot trust.
	 *
	 * @return \WP_Error
	 */
	private static function unsupported(): \WP_Error {
		return new \WP_Error(
			'steadhand_refused_unsupported_markup',
			__( 'Steadhand cannot read the block structure of this post the way WordPress does, so it cannot edit single blocks in it. Nothing was changed. Use steadhand/update-post with the complete new content instead.', 'steadhand' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * A refusal for a path that names no block with the name the call sent.
	 *
	 * @return \WP_Error
	 */
	private static function mismatch(): \WP_Error {
		return new \WP_Error(
			'steadhand_refused_block_mismatch',
			__( 'The path does not name a block with the name you sent, in the post as it is stored. The post may have changed, or the path is wrong. Nothing was changed. Call steadhand/get-blocks again, and use the paths and names it returns.', 'steadhand' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * A steadhand_invalid_input refusal, with the message that says what is allowed.
	 *
	 * @param string $message Message for the caller.
	 * @return \WP_Error
	 */
	private static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'steadhand_invalid_input', $message, array( 'status' => 400 ) );
	}
}
