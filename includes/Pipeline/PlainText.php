<?php
/**
 * The strict plain-text rule for short text that a write stores as a title or a label (SPEC §5, update-menu and update-option).
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * Decides whether a short text is plain text that WordPress stores exactly as sent. It is read-only and pure, so the abilities that
 * write titles (update-menu) and site options (update-option) share one definition of the rule.
 */
final class PlainText {

	/**
	 * Patterns a text may not match. Markup, any entity other than &amp; (named, decimal and hex, and a bare &), control, format,
	 * private-use and unassigned characters, line and paragraph separators, every space separator other than U+0020, a few invisible
	 * filler and combining characters (U+034F, the Hangul fillers, U+2800), variation selectors, leading, trailing or repeated ASCII
	 * spaces, and a percent-encoded octet. A preg error counts as a match, so a text the check cannot read is refused.
	 *
	 * @var string[]
	 */
	private const REFUSED = array(
		'/[<>]/',
		'/&(?!amp;)/',
		'/[\p{Cc}\p{Cf}\p{Co}\p{Cn}\p{Zl}\p{Zp}\x{034F}\x{115F}\x{1160}\x{3164}\x{FFA0}\x{2800}\x{FE00}-\x{FE0F}\x{E0100}-\x{E01EF}]|(?!\x20)\p{Zs}/u',
		'/^\s|\s$|\s\s/u',
		'/%[0-9A-Fa-f]{2}/',
	);

	/**
	 * Whether a text is plain text of at most $max_chars characters that WordPress stores unchanged: valid UTF-8, not blank, no
	 * match for any refused pattern, and unchanged by sanitize_text_field() and by the entity normalisation a save applies. An
	 * empty text is not checked here, because its meaning depends on the caller.
	 *
	 * @param string $text      The text, as sent or as stored.
	 * @param int    $max_chars The most characters allowed (code points, as mb_strlen counts UTF-8 text).
	 */
	public static function valid( string $text, int $max_chars ): bool {
		if ( 1 !== preg_match( '//u', $text ) || '' === trim( $text ) ) {
			return false;
		}

		foreach ( self::REFUSED as $pattern ) {
			// A match refuses, and so does a preg error (false): the text is not checked any further.
			if ( 0 !== preg_match( $pattern, $text ) ) {
				return false;
			}
		}

		// Matches when the text is at most $max_chars characters. Zero means it is longer; false is a preg error. Both refuse.
		if ( 1 !== preg_match( '/^.{0,' . $max_chars . '}\z/su', $text ) ) {
			return false;
		}

		return sanitize_text_field( $text ) === $text && wp_kses_normalize_entities( $text ) === $text;
	}
}
