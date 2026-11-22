<?php
/**
 * Redaction: removes secrets from free text and data before they are returned to an agent or written to a log.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand;

defined( 'ABSPATH' ) || exit;

/**
 * The redaction helper. Pure functions: no WordPress state and no I/O.
 *
 * Redaction is best-effort pattern matching. It reduces the chance that a secret leaks into output or a log, and
 * it cannot guarantee that none does. A secret in a shape that no rule knows, such as a short password in a sentence
 * with no label, can pass through. Callers must therefore still avoid collecting secrets in the first place, and must
 * not return whole logs or request bodies. Redaction errs toward over-redaction: a key that merely contains a
 * sensitive word is treated as sensitive, and a matching string of letters and digits is masked.
 *
 * What text() masks: HTTP Authorization and Cookie header values; URL userinfo, up to the last @ before the host;
 * authentication schemes (Basic, Bearer, Negotiate, Digest) wherever they appear; sensitive query, form, JSON, PHP
 * array-dump and serialized key/value pairs, with bracketed and collection values masked whole; the cookies WordPress
 * uses for a session; JSON Web Tokens; PEM private-key blocks; password hashes (phpass, bcrypt, WordPress bcrypt,
 * argon2 and crypt); GitHub, AWS, Slack, Stripe, Google, GitLab, Twilio, Mailgun, SendGrid, npm, Hugging Face, OpenAI
 * and Anthropic tokens; WordPress application passwords, with spaces or other single separators; the value of a
 * define() constant whose name is sensitive, including values with quotes; long opaque strings of letters and digits
 * (32 characters or more); payment card numbers that pass the Luhn check (as [card]); the database user and host in
 * connection errors; and email addresses, including internationalised domains (replaced with an email marker, because
 * they are personal data in logs). Control characters and ANSI escape sequences are removed.
 *
 * Known not caught, so callers must not rely on the rules for these:
 * - an unlabelled short password in free text, such as "the password is hunter2";
 * - a secret under a key in a non-Latin script, or under a key with look-alike or zero-width characters;
 * - a key whose name is URL-encoded (password%3D);
 * - a password given to curl with -u user:pass, or any other command-line form without a key;
 * - base64 of user:pass without a scheme name in front of it;
 * - the short lines of a PGP private key block, which are not in the PEM form the block rule matches;
 * - a secret that is shorter than 32 characters and has no label, prefix or known shape;
 * - phone numbers and IP addresses, which are not masked by design.
 *
 * Failure: text() never returns the original text when a rule cannot run. If a regular expression fails, it returns
 * MASK for the whole string. Every pattern is written to run in linear time on hostile input: bounded repetitions, no
 * nested quantifiers, and possessive matching where a run could otherwise backtrack. A key and its value are found in
 * two steps, so a value that holds another key/value pair is still searched. A serialized pair keeps its s:N length
 * label as it was: the masked value no longer has that length, and the label is not a secret, so it is not recomputed.
 */
final class Redactor {

	/**
	 * Replacement for a masked secret.
	 */
	public const MASK = '[redacted]';

	/**
	 * Replacement for an email address.
	 */
	public const EMAIL_MASK = '[email]';

	/**
	 * Replacement for a payment card number.
	 */
	public const CARD_MASK = '[card]';

	/**
	 * Deepest nesting value() walks. Anything deeper is replaced with MASK.
	 */
	public const MAX_DEPTH = 20;

	/**
	 * Words that make a key sensitive when they appear as a whole word after splitting the key.
	 */
	private const KEY_WORDS = array(
		'password',
		'passwd',
		'pwd',
		'pw',
		'pswd',
		'pass',
		'passphrase',
		'secret',
		'token',
		'auth',
		'authorization',
		'credential',
		'cookie',
		'session',
		'sessid',
		'sid',
		'nonce',
		'salt',
		'signature',
		'sig',
		'sas',
		'apikey',
		'private',
		'key',
		'hash',
		'dsn',
		'connstr',
		'jwt',
		'bearer',
		'otp',
		'totp',
		'mfa',
		'recovery',
		'license',
		'licence',
	);

	/**
	 * Fragments that make a key sensitive anywhere in it. Only long, distinctive words are used, so that short words
	 * do not match inside ordinary names.
	 */
	private const KEY_FRAGMENTS = array(
		'password',
		'passwd',
		'secret',
		'token',
		'credential',
		'authorization',
		'apikey',
		'cookie',
		'signature',
		'sessionid',
		'sessid',
		'nonce',
		'passphrase',
		'connstr',
		'connectionstring',
	);

	/**
	 * The name of a cookie that WordPress uses for a session. Its whole value is masked, the user name included.
	 */
	private const COOKIE_NAME = '~^(?:wordpress_logged_in_|wordpress_sec_|wp-settings-|wordpress_test_cookie)~i';

	/**
	 * A double-quoted string, with backslash escapes. Possessive, so it is linear.
	 */
	private const DQ = '"(?:[^"\\\\\r\n]|\\\\.)*+"';

	/**
	 * A single-quoted string, with backslash escapes. Possessive, so it is linear.
	 */
	private const SQ = '\'(?:[^\'\\\\\r\n]|\\\\.)*+\'';

	/**
	 * A key, found first and on its own. Groups: 1 quote that opens the key (or ''), 2 key, 3 optional closing bracket
	 * of a PHP array key (or ''), 4 separator. The key's closing quote is group 1 again.
	 */
	private const KEY = '~(?<![A-Za-z0-9_])(["\']?)([A-Za-z_][A-Za-z0-9_.\-]{0,63})\1(\]?)([ \t]*(?:=>|=|:)[ \t]*)~';

	/**
	 * The value after a sensitive key, anchored where the key ended. A quoted value is masked to its quotes, however
	 * long it is. A bracketed or collection value is masked whole, with its brackets. Anything else runs to whitespace
	 * or a delimiter, and may hold = : ? and #. A bracket with no closing bracket is masked to the end of the line.
	 */
	private const VALUE = '~\G(?:' . self::DQ . '|' . self::SQ
		. '|(?:array\s*+)?+\((?:[^()\r\n]++|\([^()\r\n]*+\))*+\)'
		. '|\[(?:[^\[\]\r\n]++|\[[^\[\]\r\n]*+\])*+\]'
		. '|\{(?:[^{}\r\n]++|\{[^{}\r\n]*+\})*+\}'
		. '|[^\s&;,\'")\[\]}]++'
		. '|[\[({][^\r\n]*+)~';

	/**
	 * A define() of a constant with a value. Groups: 1 prefix, 2 quote of the name, 3 name, 4 separator, 5 the value
	 * with its quotes.
	 */
	private const DEFINE = '~(\bdefine\(\s{0,8})([\'"])([A-Za-z0-9_]{1,64})\2(\s{0,8},\s{0,8})(' . self::DQ . '|' . self::SQ . ')~i';

	/**
	 * A serialized pair with a string value. Groups: 1 the key and the value's opening, 2 key, 3 value, 4 closing.
	 */
	private const SERIALIZED = '~(s:\d{1,10}:"([A-Za-z0-9_.\-]{1,64})";s:\d{1,10}:")((?:[^"\\\\\r\n]|\\\\.)*+)(";)~';

	/**
	 * A UUID, which is kept even though it is a long run of letters, digits and hyphens.
	 */
	private const UUID = '~\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z~i';

	/**
	 * A host name after a resolver or database message, up to 255 characters. It is counted in characters, not bytes,
	 * so the limit never falls inside a multi-byte character.
	 */
	private const HOST = '(?:[^\s\'"\x80-\xff]|[\xc2-\xf4][\x80-\xbf]{1,3}+){1,255}+';

	/**
	 * An ANSI escape sequence: a control sequence, an operating system command, or a two-character escape.
	 */
	private const ANSI_SEQUENCES = '#\x1b(?:\[[0-?]*+[ -/]*+[@-~]|\][^\x07\x1b]*+(?:\x07|\x1b\\\\)|[@-Z\\\\-_])#';

	/**
	 * Control characters other than tab and newline, and the C1 controls written in UTF-8.
	 */
	private const CONTROL_CHARACTERS = '~[\x00-\x08\x0b-\x1f\x7f]|\xc2[\x80-\x9f]~';

	/**
	 * Returns the text with every recognised secret replaced by MASK, with control characters and ANSI escape sequences
	 * removed, and with invalid UTF-8 removed.
	 *
	 * The result is always valid UTF-8. Any failure returns MASK for the whole string.
	 *
	 * @param string $text Text to redact, for example a log message or a header block.
	 * @return string Redacted text.
	 */
	public static function text( string $text ): string {
		try {
			return self::valid_utf8( self::redact( $text ) );
		} catch ( \Throwable ) {
			return self::MASK;
		}
	}

	/**
	 * Whether a key names a value that must be hidden: a password, token, key, cookie, session or similar.
	 *
	 * The key is split on non-alphanumeric characters and camelCase boundaries, and each part is compared with a
	 * list of words (matching a plural too). A few long words are also matched anywhere in the key, and the names of
	 * WordPress session cookies always are. The check is case-insensitive. Over-redaction is the safe direction:
	 * "key_count" is sensitive because it contains the word "key", while "keyword", "monkey" and "author" are not.
	 *
	 * @param string $key Key name: an array key, a query parameter, a header or a constant name.
	 * @return bool True if values under this key must be masked.
	 */
	public static function is_sensitive_key( string $key ): bool {
		if ( 1 === preg_match( self::COOKIE_NAME, $key ) ) {
			return true;
		}

		$words = preg_split( '/[^A-Za-z0-9]+|(?<=[a-z0-9])(?=[A-Z])/', $key, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $words ) ) {
			return true;
		}

		foreach ( $words as $word ) {
			$word = strtolower( $word );
			if ( in_array( $word, self::KEY_WORDS, true ) ) {
				return true;
			}
			if ( str_ends_with( $word, 's' ) && in_array( substr( $word, 0, -1 ), self::KEY_WORDS, true ) ) {
				return true;
			}
		}

		$compact = preg_replace( '/[^A-Za-z0-9]/', '', $key );
		if ( ! is_string( $compact ) ) {
			return true;
		}
		$compact = strtolower( $compact );
		foreach ( self::KEY_FRAGMENTS as $fragment ) {
			if ( str_contains( $compact, $fragment ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Redacts a value of any shape. Arrays are walked. Values under a sensitive key become MASK whatever their type.
	 * Strings go through text(). Objects are cast to arrays. Scalars and null pass through. Anything else, and any
	 * value nested deeper than MAX_DEPTH, becomes MASK. Never throws.
	 *
	 * @param mixed $value Value to redact.
	 * @param int   $depth Current nesting level. Callers leave this at 0.
	 * @return mixed The redacted value, with the same shape where it can keep one.
	 */
	public static function value( mixed $value, int $depth = 0 ): mixed {
		try {
			if ( $depth > self::MAX_DEPTH ) {
				return self::MASK;
			}

			if ( is_string( $value ) ) {
				return self::text( $value );
			}

			if ( is_object( $value ) ) {
				return self::value( (array) $value, $depth + 1 );
			}

			if ( is_array( $value ) ) {
				$redacted = array();
				foreach ( $value as $key => $child ) {
					if ( self::is_sensitive_key( (string) $key ) ) {
						$redacted[ $key ] = self::MASK;
						continue;
					}
					$redacted[ $key ] = self::value( $child, $depth + 1 );
				}

				return $redacted;
			}

			if ( null === $value || is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				return $value;
			}

			return self::MASK;
		} catch ( \Throwable ) {
			return self::MASK;
		}
	}

	/**
	 * Applies every rule, in order. Throws if a regular expression fails, so text() can return MASK.
	 *
	 * @param string $text Text to redact.
	 * @return string Redacted text.
	 */
	private static function redact( string $text ): string {
		$text = self::valid_utf8( $text );
		$text = self::replace( self::ANSI_SEQUENCES, '', $text );
		$text = self::replace( self::CONTROL_CHARACTERS, '', $text );

		// Multi-line blocks first, so that their contents are not half-matched by the single-line rules.
		$text = self::replace( '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----(?:[A-Za-z0-9+\/=:,\s]|-(?!----))*+(?:-----END [A-Z0-9 ]*PRIVATE KEY-----)?/', self::MASK, $text );

		// Header values: the whole value after the header name is masked, whatever its scheme.
		$text = self::replace( '/(\bAuthorization[ \t]*:[ \t]*)[^\r\n]*/i', '${1}' . self::MASK, $text );
		$text = self::replace( '/(\b(?:Set-)?Cookie[ \t]*:[ \t]*)[^\r\n]*/i', '${1}' . self::MASK, $text );

		// URL userinfo: scheme://user:pass@host becomes scheme://[redacted]@host. The userinfo has a colon, holds no
		// slash or space, and ends at the last @ before the first slash or space, so ? and # may be in a password and
		// an email address in a path is not taken for it. A possessive run cannot find the last @, so the alternation
		// consumes an @ only when another @ follows before any slash or space.
		$text = self::replace( '~(\b[a-z][a-z0-9+.\-]{0,15}:\/\/)(?=[^\s\/@]*?:)(?:[^\s\/@]|@(?=[^\s\/@]*+@))*+@~i', '${1}' . self::MASK . '@', $text );

		$text = self::replace( '/(?<![A-Za-z0-9_\-])eyJ[A-Za-z0-9_\-]{4,1024}\.[A-Za-z0-9_\-]{4,1024}\.[A-Za-z0-9_\-]{0,1024}/', self::MASK, $text );

		// Authentication schemes outside a header: a credential-shaped token, or the rest of a Digest parameter list.
		$text = self::replace( '#\b(?:Basic|Bearer|Negotiate)[ \t]++(?=[A-Za-z0-9+\/._~=\-]*?[0-9+\/=._~])[A-Za-z0-9+\/._~\-]{8,1024}+=*+#i', self::MASK, $text );
		$text = self::replace( '~\bDigest[ \t]++(?=[A-Za-z]{1,32}=)[^\r\n]++~i', self::MASK, $text );

		foreach ( self::token_patterns() as $pattern ) {
			$text = self::replace( $pattern, self::MASK, $text );
		}

		// Password hashes: phpass, bcrypt (also WordPress's wp-prefixed bcrypt), argon2 and crypt.
		$text = self::replace( '~\$[PH]\$[./0-9A-Za-z]{20,64}+~', self::MASK, $text );
		$text = self::replace( '~\$(?:wp\$)?2[aby]\$[0-9]{2}\$[./0-9A-Za-z]{40,60}+~', self::MASK, $text );
		$text = self::replace( '~\$argon2(?:i|d|id)\$[A-Za-z0-9+\/=$,._\-]{10,300}+~', self::MASK, $text );
		$text = self::replace( '~\$[1562]\$[./0-9A-Za-z=,$]{11,200}+~', self::MASK, $text );

		// Database user and host in connection errors, in the forms MySQL, PDO and the resolver print.
		$text = self::replace( "~(\\bfor user )'[^'\\r\\n]{1,128}'@'[^'\\r\\n]{1,255}'~", "\${1}'" . self::MASK . "'@'" . self::MASK . "'", $text );
		$text = self::replace( "~(\\bserver host )'[^'\\r\\n]{1,255}'~i", "\${1}'" . self::MASK . "'", $text );
		$text = self::replace( "~(\\bMySQL server on )'[^'\\r\\n]{1,255}'~", "\${1}'" . self::MASK . "'", $text );
		$text = self::replace( '~(\bgetaddrinfo for )' . self::HOST . '~i', '${1}' . self::MASK, $text );
		$text = self::replace( '~(\bgetaddrinfo failed(?: for|:)? )(?=[A-Za-z0-9\-]+(?:\.[A-Za-z0-9\-]+)+)' . self::HOST . '~i', '${1}' . self::MASK, $text );

		// WordPress application passwords: 24 characters, or six groups of four separated by one space or other mark.
		$text = self::replace( '/(?<![A-Za-z0-9])[A-Za-z0-9]{24}(?![A-Za-z0-9])/', self::MASK, $text );
		$text = self::replace( '/(?<![A-Za-z0-9])[A-Za-z0-9]{4}(?:[ ._\-:|][A-Za-z0-9]{4}){5}(?![A-Za-z0-9])/', self::MASK, $text );

		$text = self::replace_callback( self::DEFINE, array( self::class, 'define_pair' ), $text );
		$text = self::replace_callback( self::SERIALIZED, array( self::class, 'serialized_pair' ), $text );
		$text = self::key_values( $text );

		// Payment cards: 13 to 19 digits, optionally separated by single spaces or dashes, that pass the Luhn check.
		$text = self::replace_callback( '/(?<![0-9])[0-9](?:[ \-]?[0-9]){12,18}+(?![0-9])/', array( self::class, 'card' ), $text );

		$text = self::replace( '/(?<![\pL\pN._%+\-])[\pL\pN._%+\-]{1,64}+@[\pL\pN.\-]{1,255}\.(?:xn--[\pL\pN\-]{1,59}+|\pL{2,24}+)/u', self::EMAIL_MASK, $text );

		// Long opaque strings: masked only when they mix letters and digits, which secrets do and words and paths do not.
		return self::replace_callback( '/[A-Za-z0-9+\/=_\-]{32,}/', array( self::class, 'opaque_run' ), $text );
	}

	/**
	 * Token formats with a known prefix or length. Each is bounded so that it runs in linear time.
	 *
	 * @return string[]
	 */
	private static function token_patterns(): array {
		return array(
			'/(?<![A-Za-z0-9_])gh[pousr]_[A-Za-z0-9]{10,255}/',
			'/(?<![A-Za-z0-9_])github_pat_[A-Za-z0-9_]{10,255}/',
			'/(?<![A-Za-z0-9])(?:AKIA|ASIA)[A-Z0-9]{16}(?![A-Za-z0-9])/',
			'/(?<![A-Za-z0-9])xox[baprs]-[A-Za-z0-9\-]{10,255}/',
			'/(?<![A-Za-z0-9])(?:sk|rk)_(?:live|test)_[A-Za-z0-9]{8,255}/',
			'/(?<![A-Za-z0-9_\-])AIza[0-9A-Za-z_\-]{35}(?![0-9A-Za-z_\-])/',
			'/(?<![A-Za-z0-9_])glpat-[A-Za-z0-9_\-]{20,255}/',
			'/(?<![A-Za-z0-9_])whsec_[A-Za-z0-9]{24,255}/',
			'/(?<![A-Za-z0-9])(?:SK|AC)[0-9a-fA-F]{32}(?![0-9A-Za-z])/',
			'/(?<![A-Za-z0-9_\-])key-[0-9a-zA-Z]{32}(?![0-9A-Za-z])/',
			'/(?<![A-Za-z0-9_])SG\.[A-Za-z0-9_\-]{10,255}\.[A-Za-z0-9_\-]{10,255}/',
			'/(?<![A-Za-z0-9_])npm_[A-Za-z0-9]{20,255}/',
			'/(?<![A-Za-z0-9_])hf_[A-Za-z0-9]{30,255}/',
			'/(?<![A-Za-z0-9])sk-[A-Za-z0-9_\-]{20,255}/',
		);
	}

	/**
	 * Walks the text for key/value pairs. A pair whose key is not sensitive is passed over at its separator, so its
	 * value is searched in turn. A sensitive key has its value masked, with the quotes kept when the value had them.
	 *
	 * @param string $text Text to redact.
	 * @return string Result.
	 * @throws \RuntimeException If a regular expression fails.
	 */
	private static function key_values( string $text ): string {
		$out = '';
		$pos = 0;

		while ( 1 === self::match_at( self::KEY, $text, $m, $pos ) ) {
			$separator_end = $m[4][1] + strlen( $m[4][0] );
			$out          .= substr( $text, $pos, $separator_end - $pos );
			$pos           = $separator_end;

			if ( ! self::is_sensitive_key( $m[2][0] ) ) {
				continue;
			}

			if ( 1 !== self::match_at( self::VALUE, $text, $v, $pos ) ) {
				continue;
			}

			$value = $v[0][0];
			$quote = ( '"' === $value[0] || "'" === $value[0] ) ? $value[0] : '';
			$out  .= $quote . self::MASK . $quote;
			$pos  += strlen( $value );
		}

		return $out . substr( $text, $pos );
	}

	/**
	 * Callback for define( 'NAME', 'value' ). Masks the value when the constant's name is sensitive, and keeps its
	 * quotes. A value with the other quote character, or with escaped quotes, is masked whole.
	 *
	 * @param array<int, string> $m Match groups: 1 prefix, 2 quote, 3 name, 4 separator, 5 value with quotes.
	 * @return string Replacement for the match.
	 */
	private static function define_pair( array $m ): string {
		if ( ! self::is_sensitive_key( $m[3] ) ) {
			return $m[0];
		}

		$quote = $m[5][0];

		return $m[1] . $m[2] . $m[3] . $m[2] . $m[4] . $quote . self::MASK . $quote;
	}

	/**
	 * Callback for a serialized pair. The value of a sensitive key is masked. The s:N length label is left as it was.
	 *
	 * @param array<int, string> $m Match groups: 1 key and value opening, 2 key, 3 value, 4 closing.
	 * @return string Replacement for the match.
	 */
	private static function serialized_pair( array $m ): string {
		if ( ! self::is_sensitive_key( $m[2] ) ) {
			return $m[0];
		}

		return $m[1] . self::MASK . $m[4];
	}

	/**
	 * Callback for a run of digits that may be a card number. Masks it when it is 13 to 19 digits and passes Luhn. A
	 * 13-digit run with no separators that starts with 1 is a millisecond time stamp, and is kept.
	 *
	 * @param array<int, string> $m Match groups.
	 * @return string Replacement.
	 * @throws \RuntimeException If the digits cannot be extracted.
	 */
	private static function card( array $m ): string {
		$digits = preg_replace( '/\D/', '', $m[0] );
		if ( ! is_string( $digits ) ) {
			throw new \RuntimeException( 'Card pattern failed.' );
		}

		$length = strlen( $digits );
		if ( $length < 13 || $length > 19 || ! self::luhn( $digits ) ) {
			return $m[0];
		}

		if ( 13 === $length && $digits === $m[0] && '1' === $digits[0] ) {
			return $m[0];
		}

		return self::CARD_MASK;
	}

	/**
	 * Whether a string of digits passes the Luhn check.
	 *
	 * @param string $digits Digits only.
	 * @return bool
	 */
	private static function luhn( string $digits ): bool {
		$sum    = 0;
		$double = false;

		for ( $i = strlen( $digits ) - 1; $i >= 0; $i-- ) {
			$digit = (int) $digits[ $i ];
			if ( $double ) {
				$digit *= 2;
				if ( $digit > 9 ) {
					$digit -= 9;
				}
			}
			$sum   += $digit;
			$double = ! $double;
		}

		return 0 === $sum % 10;
	}

	/**
	 * Callback for a long run of opaque characters. Masks it only if it has both a letter and a digit, and keeps a UUID.
	 *
	 * @param array<int, string> $m Match groups.
	 * @return string Replacement for the match.
	 */
	private static function opaque_run( array $m ): string {
		$run = $m[0];
		if ( 1 === preg_match( self::UUID, $run ) ) {
			return $run;
		}

		return ( 1 === preg_match( '/[A-Za-z]/', $run ) && 1 === preg_match( '/[0-9]/', $run ) ) ? self::MASK : $run;
	}

	/**
	 * Finds the next match of a pattern at or after an offset, with offsets.
	 *
	 * @param string $pattern PCRE pattern.
	 * @param string $subject Text.
	 * @param array  $matches Set to the match, with offsets.
	 * @param int    $offset  Byte offset to start at.
	 * @return int 1 on a match, 0 on none.
	 * @throws \RuntimeException If the regular expression fails.
	 */
	private static function match_at( string $pattern, string $subject, ?array &$matches, int $offset ): int {
		$result = preg_match( $pattern, $subject, $matches, PREG_OFFSET_CAPTURE, $offset );
		if ( false === $result ) {
			throw new \RuntimeException( 'Redaction pattern failed.' );
		}

		return $result;
	}

	/**
	 * Replaces every match of a pattern with a fixed string.
	 *
	 * @param string $pattern     PCRE pattern.
	 * @param string $replacement Replacement.
	 * @param string $subject     Text.
	 * @return string Result.
	 * @throws \RuntimeException If the regular expression fails.
	 */
	private static function replace( string $pattern, string $replacement, string $subject ): string {
		$result = preg_replace( $pattern, $replacement, $subject );
		if ( ! is_string( $result ) ) {
			throw new \RuntimeException( 'Redaction pattern failed.' );
		}

		return $result;
	}

	/**
	 * Replaces every match of a pattern with the result of a callback.
	 *
	 * @param string   $pattern  PCRE pattern.
	 * @param callable $callback Receives the match groups and returns the replacement.
	 * @param string   $subject  Text.
	 * @return string Result.
	 * @throws \RuntimeException If the regular expression fails.
	 */
	private static function replace_callback( string $pattern, callable $callback, string $subject ): string {
		$result = preg_replace_callback( $pattern, $callback, $subject );
		if ( ! is_string( $result ) ) {
			throw new \RuntimeException( 'Redaction pattern failed.' );
		}

		return $result;
	}

	/**
	 * Removes every byte sequence that is not valid UTF-8. Valid multi-byte characters are kept.
	 *
	 * Written without mbstring, and without the /u modifier, so that it runs on any bytes.
	 *
	 * @param string $text Text of any encoding.
	 * @return string Valid UTF-8 text.
	 * @throws \RuntimeException If the regular expression fails.
	 */
	private static function valid_utf8( string $text ): string {
		if ( 1 === preg_match( '//u', $text ) ) {
			return $text;
		}

		$result = preg_replace_callback(
			'/((?:[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2}))|[\x80-\xFF]/',
			static fn( array $m ): string => $m[1] ?? '',
			$text
		);
		if ( ! is_string( $result ) ) {
			throw new \RuntimeException( 'UTF-8 repair failed.' );
		}

		return $result;
	}
}
