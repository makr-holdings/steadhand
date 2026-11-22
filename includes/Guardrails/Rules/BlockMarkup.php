<?php
/**
 * Checks that the block comment delimiters in content are structurally valid.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Guardrails\Rules;

use Steadhand\Guardrails\Context;
use Steadhand\Guardrails\Result;
use Steadhand\Guardrails\Rule;

defined( 'ABSPATH' ) || exit;

/**
 * Blocks block markup whose delimiters WordPress would not read as blocks, or that do not nest properly (SPEC §6.1).
 *
 * What it checks, for each content field:
 * - Every delimiter is tokenized with the same grammar as core's parser, WP_Block_Parser::next_token() in
 *   wp-includes/class-wp-block-parser.php (WordPress 7.1). A delimiter is an opener, a closer or a void block. Its name
 *   gets the core/ prefix when it has no namespace. A delimiter's JSON attributes must decode to an object.
 * - A loose count of the comments that look like delimiters (an opening comment, optional whitespace, then wp:, and the
 *   same with a slash before wp:) must equal the strict count of openers plus voids, and of closers. A mismatch means a delimiter is malformed, so WordPress would show it as
 *   plain text.
 * - Opening and closing are balanced: a closer must name the block on top of the stack, and the stack must be empty
 *   at the end.
 * - Content with no delimiters at all (classic or plain HTML) passes.
 *
 * What it does NOT check:
 * - Whether a block's saved HTML matches what its JavaScript save() function would produce. That is the editor's
 *   "This block contains unexpected or invalid content" check, which needs the block's JavaScript.
 * - Whether a block name is registered on the site, or whether its attributes match its schema.
 *
 * The token regular expression is run with preg_match() at an offset, as core does, and a PCRE error blocks the call.
 */
final class BlockMarkup implements Rule {

	/**
	 * Core's block delimiter grammar, copied from WP_Block_Parser::next_token() in wp-includes/class-wp-block-parser.php
	 * (WordPress 7.1). Keep it identical to core's, so that the rule agrees with what WordPress reads.
	 */
	private const TOKEN = '/<!--[ \t\f\r\n]+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)[ \t\f\r\n]+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}[ \t\f\r\n]+\/?-->).)*+)?}[ \t\f\r\n]+)?(?P<void>\/)?-->/s';

	/** Every comment that looks like an opener or a void block, however it is spelled. */
	private const LOOSE_OPENER = '/<!--\s*wp:/i';

	/** Every comment that looks like a closer, however it is spelled. */
	private const LOOSE_CLOSER = '/<!--\s*\/wp:/i';

	/** Token kinds. */
	private const OPENER = 'opener';
	private const CLOSER = 'closer';
	private const VOID   = 'void';

	/**
	 * Returns the rule's ID.
	 *
	 * @return string
	 */
	public function id(): string {
		return 'steadhand/block-markup';
	}

	/**
	 * Checks every content field.
	 *
	 * @param Context $context The call.
	 * @return Result
	 */
	public function evaluate( Context $context ): Result {
		foreach ( $context->content as $field => $markup ) {
			if ( ! is_string( $markup ) ) {
				return Result::block(
					sprintf(
						/* translators: %s: name of a content field, such as post_content. */
						__( 'The %s field must be text, so it was refused.', 'steadhand' ),
						(string) $field
					)
				);
			}

			$problem = $this->problem( $markup );
			if ( null !== $problem ) {
				return Result::block(
					sprintf(
						/* translators: 1: name of a content field, such as post_content. 2: what is wrong with its block markup. */
						__( 'The %1$s field has invalid block markup: %2$s', 'steadhand' ),
						(string) $field,
						$problem
					)
				);
			}
		}

		return Result::allow();
	}

	/**
	 * Returns what is wrong with one field's markup, or null if it is structurally valid.
	 *
	 * @param string $markup Content.
	 * @return string|null
	 */
	private function problem( string $markup ): ?string {
		$failure = null;
		$tokens  = $this->tokens( $markup, $failure );
		if ( null === $tokens ) {
			return $failure;
		}

		$loose_openers = preg_match_all( self::LOOSE_OPENER, $markup );
		$loose_closers = preg_match_all( self::LOOSE_CLOSER, $markup );
		if ( false === $loose_openers || false === $loose_closers || PREG_NO_ERROR !== preg_last_error() ) {
			return $this->unchecked();
		}

		$strict_openers = 0;
		$strict_closers = 0;
		foreach ( $tokens as $token ) {
			if ( self::CLOSER === $token['kind'] ) {
				++$strict_closers;
			} else {
				++$strict_openers;
			}
		}

		if ( $loose_openers !== $strict_openers || $loose_closers !== $strict_closers ) {
			return __( 'a block comment is malformed, so WordPress would show it as plain text. Every block comment must read <!-- wp:name --> or <!-- wp:name {"valid":"JSON"} -->, with exactly one space after <!-- and after wp:, and each block needs a matching <!-- /wp:name -->. Check the attributes are valid JSON inside the braces.', 'steadhand' );
		}

		return $this->balance( $tokens );
	}

	/**
	 * Walks the tokens with a stack and reports the first block that does not nest properly.
	 *
	 * @param array<int, array{kind: string, name: string}> $tokens Tokens in document order.
	 * @return string|null
	 */
	private function balance( array $tokens ): ?string {
		$open = array();

		foreach ( $tokens as $token ) {
			if ( self::OPENER === $token['kind'] ) {
				$open[] = $token['name'];
				continue;
			}

			if ( self::CLOSER !== $token['kind'] ) {
				continue;
			}

			if ( array() === $open ) {
				return sprintf(
					/* translators: %s: block name, such as paragraph, without the core/ prefix. */
					__( 'the closing comment <!-- /wp:%1$s --> has no opening comment before it. Remove it, or add <!-- wp:%1$s --> before the block it closes.', 'steadhand' ),
					$this->short( $token['name'] ),
					$this->short( $token['name'] )
				);
			}

			if ( ! in_array( $token['name'], $open, true ) ) {
				return sprintf(
					/* translators: %s: block name, such as paragraph, without the core/ prefix. */
					__( 'the closing comment <!-- /wp:%s --> closes a block that is not open. Check that its name matches its opening comment, or remove it.', 'steadhand' ),
					$this->short( $token['name'] )
				);
			}

			$top = array_pop( $open );
			if ( $top !== $token['name'] ) {
				return sprintf(
					/* translators: 1: the block opened after the one named in the closing comment, such as columns. 2: the block the closing comment names, such as group. */
					__( 'the block %1$s was opened after %2$s and is still open, so <!-- /wp:%2$s --> is out of order. Close %1$s first with <!-- /wp:%1$s -->, then close %2$s.', 'steadhand' ),
					$this->short( $top ),
					$this->short( $token['name'] )
				);
			}
		}

		if ( array() !== $open ) {
			$name = $this->short( (string) end( $open ) );

			return sprintf(
				/* translators: %s: block name, such as group, without the core/ prefix. */
				__( 'the block %1$s is opened with <!-- wp:%1$s --> but never closed. Add <!-- /wp:%1$s --> after its content, after any blocks inside it.', 'steadhand' ),
				$name
			);
		}

		return null;
	}

	/**
	 * Tokenizes the delimiters, as core does. Stops at the first delimiter whose attributes are not valid JSON, and at a
	 * PCRE failure. On failure returns null and sets $failure.
	 *
	 * @param string      $markup  Content.
	 * @param string|null $failure What is wrong, set when the result is null.
	 * @return array<int, array{kind: string, name: string}>|null
	 */
	private function tokens( string $markup, ?string &$failure ): ?array {
		$tokens = array();
		$offset = 0;

		while ( true ) {
			$matches = null;
			$found   = preg_match( self::TOKEN, $markup, $matches, PREG_OFFSET_CAPTURE, $offset );

			if ( false === $found || PREG_NO_ERROR !== preg_last_error() ) {
				$failure = $this->unchecked();
				return null;
			}

			if ( 0 === $found ) {
				return $tokens;
			}

			list( $text, $start ) = $matches[0];

			$is_closer = isset( $matches['closer'] ) && -1 !== $matches['closer'][1];
			$is_void   = isset( $matches['void'] ) && -1 !== $matches['void'][1];
			$has_attrs = isset( $matches['attrs'] ) && -1 !== $matches['attrs'][1];
			$namespace = isset( $matches['namespace'] ) && -1 !== $matches['namespace'][1] ? $matches['namespace'][0] : 'core/';
			$name      = $namespace . $matches['name'][0];

			// Core ignores the attributes of a closer, so they are not checked here.
			if ( $has_attrs && ! $is_closer && null === json_decode( $matches['attrs'][0], true ) ) {
				$failure = sprintf(
					/* translators: %s: block name, such as paragraph, without the core/ prefix. */
					__( 'the attributes of <!-- wp:%s --> are not valid JSON. Write them as one JSON object between { and }, with double quotes around every key and string.', 'steadhand' ),
					$this->short( $name )
				);
				return null;
			}

			if ( $is_void ) {
				$tokens[] = array(
					'kind' => self::VOID,
					'name' => $name,
				);
			} elseif ( $is_closer ) {
				$tokens[] = array(
					'kind' => self::CLOSER,
					'name' => $name,
				);
			} else {
				$tokens[] = array(
					'kind' => self::OPENER,
					'name' => $name,
				);
			}

			$offset = $start + strlen( $text );
		}
	}

	/**
	 * The message for markup that could not be checked at all. Fails closed.
	 *
	 * @return string
	 */
	private function unchecked(): string {
		return __( 'the markup could not be checked, so it was refused. Simplify it and try again.', 'steadhand' );
	}

	/**
	 * A block name as an author writes it: the core/ prefix is dropped.
	 *
	 * @param string $name Block name, such as core/paragraph or my-plugin/card.
	 * @return string
	 */
	private function short( string $name ): string {
		return str_starts_with( $name, 'core/' ) ? substr( $name, 5 ) : $name;
	}
}
