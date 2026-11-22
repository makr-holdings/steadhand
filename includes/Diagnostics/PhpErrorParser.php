<?php
/**
 * Parses PHP engine error entries from a log. Pure: no files, no WordPress state.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Diagnostics;

use Steadhand\Abilities\PostReader;
use Steadhand\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps only the lines PHP writes for its own engine errors, and turns them into output items.
 *
 * Only a line in the standard form, "[DD-Mon-YYYY HH:MM:SS TZ] PHP <Level>:  <message> in <file> on line <N>" (or the
 * "<file>:<N>" form that fatal errors use), is kept, and only its first line. Every other line is skipped and counted,
 * including stack traces and all free-form output that plugins and themes write with error_log(). That output is where
 * dumped data, SQL and credentials end up, so it is never returned.
 *
 * A kept message has the WordPress directories removed, is redacted with Redactor::text(), and is cut to 500 bytes. A
 * kept file is made relative to the site: a path under WP_CONTENT_DIR becomes wp-content/…, one under ABSPATH becomes
 * its path relative to ABSPATH, and anything else becomes its base name.
 */
final class PhpErrorParser {

	/**
	 * Longest line that is parsed. A longer line is skipped and counted.
	 */
	public const MAX_LINE_BYTES = 8192;

	/**
	 * Longest message or file name returned, in bytes.
	 */
	public const MAX_TEXT_BYTES = 500;

	/**
	 * A standard PHP error line. Groups: 1 timestamp, 2 zone (optional), 3 engine label, 4 the rest.
	 */
	private const LINE = '/^\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2})(?: ([^\]\s]{1,64}))?\] PHP (Fatal error|Parse error|Recoverable fatal error|Catchable fatal error|Warning|Notice|Deprecated):  (.*)$/';

	/**
	 * The location at the end of a message. Groups: 1 message, 2 file, 3 line after "on line", 4 line after a colon.
	 */
	private const LOCATION = '/^(.*) in (.+?)(?: on line (\d{1,9})|:(\d{1,9}))$/';

	/**
	 * Absolute paths and URLs, as one alternation. Group 1 matches a URL, which is left alone. The others are a POSIX
	 * path (a leading slash, then segments), a Windows drive path and a UNC path. Segments exclude whitespace, quotes,
	 * brackets and both separators, and each is a possessive run, so the pattern is linear in the length of the text.
	 */
	private const PATH = '~([A-Za-z][A-Za-z0-9+.\\-]{1,15}:\\/\\/[^\\s\\x27"<>]*+)|(?<![A-Za-z0-9_.\\-\\/\\\\])\\/[^\\s\\/\\\\\\x27"<>()\\[\\]{}:|*?,;]++(?:\\/[^\\s\\/\\\\\\x27"<>()\\[\\]{}:|*?,;]++)*+\\/?|(?<![A-Za-z0-9_])[A-Za-z]:[\\\\\\/][^\\s\\\\\\/\\x27"<>()\\[\\]{}:|*?,;]++(?:[\\\\\\/][^\\s\\\\\\/\\x27"<>()\\[\\]{}:|*?,;]++)*+[\\\\\\/]?|\\\\\\\\[^\\s\\\\\\/\\x27"<>()\\[\\]{}:|*?,;]++(?:[\\\\\\/][^\\s\\\\\\/\\x27"<>()\\[\\]{}:|*?,;]++)*+[\\\\\\/]?~';

	/**
	 * Engine label to the level name the ability returns.
	 */
	private const LEVELS = array(
		'Fatal error'             => 'fatal',
		'Parse error'             => 'parse',
		'Recoverable fatal error' => 'recoverable',
		'Catchable fatal error'   => 'recoverable',
		'Warning'                 => 'warning',
		'Notice'                  => 'notice',
		'Deprecated'              => 'deprecated',
	);

	/**
	 * Directories of the WordPress content folder, as configured and resolved.
	 *
	 * @var string[]
	 */
	private array $content_roots;

	/**
	 * Directories of the WordPress root, as configured and resolved.
	 *
	 * @var string[]
	 */
	private array $abspath_roots;

	/**
	 * Constructor.
	 *
	 * @param string $content_dir WP_CONTENT_DIR.
	 * @param string $abspath     ABSPATH.
	 */
	public function __construct( string $content_dir, string $abspath ) {
		$this->content_roots = self::roots( $content_dir );
		$this->abspath_roots = self::roots( $abspath );
	}

	/**
	 * Parses a block of log text.
	 *
	 * @param string $bytes      The log text (already cut to the tail by the caller).
	 * @param int    $max_errors Most items to return.
	 * @return array{items: array<int, array{time: string|null, level: string, message: string, file: string, line: int|null, count: int}>, skipped_lines: int}
	 */
	public function parse( string $bytes, int $max_errors ): array {
		$skipped = 0;
		$seen    = array();
		$index   = 0;

		foreach ( explode( "\n", $bytes ) as $raw ) {
			$line = rtrim( $raw, "\r" );
			if ( '' === $line ) {
				continue;
			}

			$entry = $this->entry( $line );
			if ( null === $entry ) {
				++$skipped;
				continue;
			}

			$key = implode( "\0", array( $entry['level'], $entry['message'], $entry['file'], (string) $entry['line'] ) );
			if ( ! isset( $seen[ $key ] ) ) {
				$seen[ $key ] = array(
					'entry' => $entry,
					'count' => 0,
					'last'  => 0,
					'time'  => null,
				);
			}

			++$seen[ $key ]['count'];
			$seen[ $key ]['last'] = $index++;
			if ( null !== $entry['time'] ) {
				$seen[ $key ]['time'] = $entry['time'];
			}
		}

		$groups = array_values( $seen );
		usort( $groups, static fn( array $a, array $b ): int => $b['last'] <=> $a['last'] );

		$items = array();
		foreach ( array_slice( $groups, 0, max( 0, $max_errors ) ) as $group ) {
			$items[] = array(
				'time'    => $group['time'],
				'level'   => $group['entry']['level'],
				'message' => $group['entry']['message'],
				'file'    => $group['entry']['file'],
				'line'    => $group['entry']['line'],
				'count'   => $group['count'],
			);
		}

		return array(
			'items'         => $items,
			'skipped_lines' => $skipped,
		);
	}

	/**
	 * Parses one line into an entry, or returns null if the line is not a standard PHP engine error.
	 *
	 * @param string $line One line, without its line break.
	 * @return array{time: string|null, level: string, message: string, file: string, line: int|null}|null
	 */
	private function entry( string $line ): ?array {
		if ( strlen( $line ) > self::MAX_LINE_BYTES || str_contains( $line, "\0" ) ) {
			return null;
		}

		if ( 1 !== preg_match( self::LINE, $line, $m ) ) {
			return null;
		}

		$message = self::before_trace( $m[4] );
		$file    = '';
		$number  = null;

		if ( 1 === preg_match( self::LOCATION, $message, $loc ) ) {
			$message = $loc[1];
			$file    = $loc[2];
			$digits  = '' !== $loc[3] ? $loc[3] : $loc[4];
			$number  = '' !== $digits ? (int) $digits : null;
		}

		return array(
			'time'    => $this->iso_time( $m[1], $m[2] ),
			'level'   => self::LEVELS[ $m[3] ],
			'message' => $this->clean( $message ),
			'file'    => $this->clean( $this->relative_file( $file ) ),
			'line'    => $number,
		);
	}

	/**
	 * Cuts a message at the first stack trace: a " Stack trace:" label or a " #0 " frame marker. A flattened trace
	 * carries the arguments of each call, so nothing after the cut is returned. The location is read from the part
	 * before the cut, which is where the throwing code's file and line are.
	 *
	 * @param string $text Message after the PHP prefix, with its location.
	 * @return string The message up to the trace.
	 * @throws \RuntimeException If the regular expression fails.
	 */
	private static function before_trace( string $text ): string {
		$found = preg_match( '~\s(?:Stack trace:|#0 )~', $text, $m, PREG_OFFSET_CAPTURE );
		if ( false === $found ) {
			throw new \RuntimeException( 'Trace pattern failed.' );
		}

		return 1 === $found ? rtrim( substr( $text, 0, $m[0][1] ) ) : $text;
	}

	/**
	 * Redacts and shortens a message or file name: site paths out, then secrets out, then the length cap.
	 *
	 * @param string $text Text as logged.
	 * @return string Plain, redacted text of at most MAX_TEXT_BYTES bytes.
	 */
	private function clean( string $text ): string {
		foreach ( $this->content_roots as $root ) {
			$text = str_replace( $root . '/', 'wp-content/', $text );
			$text = str_replace( $root, 'wp-content', $text );
		}
		foreach ( $this->abspath_roots as $root ) {
			$text = str_replace( $root . '/', '', $text );
			$text = str_replace( $root, '', $text );
		}

		$text = self::reduce_paths( $text );

		$text = trim( PostReader::cut_utf8( Redactor::text( $text ), self::MAX_TEXT_BYTES ) );

		// The output must encode as JSON. Redactor::text() already returns valid UTF-8; this checks the string as built.
		return 1 === preg_match( '//u', $text ) ? $text : Redactor::MASK;
	}

	/**
	 * Replaces every absolute path left in a message with its base name: POSIX paths (a leading slash and at least one
	 * segment), Windows drive-letter paths and UNC paths. URLs are matched first and returned unchanged, so their
	 * slashes are never read as paths. A relative path such as wp-content/plugins/x/x.php does not start with a
	 * separator and is kept.
	 *
	 * @param string $text Message text, with the site directories already removed.
	 * @return string Text with no absolute path left in it.
	 * @throws \RuntimeException If the regular expression fails.
	 */
	private static function reduce_paths( string $text ): string {
		$result = preg_replace_callback( self::PATH, array( self::class, 'path_or_url' ), $text );
		if ( ! is_string( $result ) ) {
			throw new \RuntimeException( 'Path pattern failed.' );
		}

		return $result;
	}

	/**
	 * Callback for reduce_paths(): a URL is kept, and a path becomes its base name.
	 *
	 * @param array<int, string> $m Match groups: 1 is the URL alternative and is non-empty only for a URL.
	 * @return string Replacement.
	 */
	private static function path_or_url( array $m ): string {
		return ( $m[1] ?? '' ) !== '' ? $m[0] : self::base_name( $m[0] );
	}

	/**
	 * The last component of a path, for POSIX and Windows separators alike. A trailing separator is ignored.
	 *
	 * @param string $path Path.
	 * @return string Base name, or '' if none.
	 */
	private static function base_name( string $path ): string {
		$name = preg_replace( '~^.*[\\\\/]~', '', rtrim( $path, '/\\' ) );

		return is_string( $name ) ? $name : '';
	}

	/**
	 * A path that is already relative to the site. Anything that is not a clean relative path, meaning one with a ".."
	 * segment, a leading separator or drive letter, or a backslash, is reduced to its base name.
	 *
	 * @param string $path Path made relative to the site.
	 * @return string The path, or its base name.
	 */
	private static function clean_relative( string $path ): string {
		if ( 1 === preg_match( '~(?:^|[\\\\/])\.\.(?:[\\\\/]|$)|^(?:[\\\\/]|[A-Za-z]:|\\\\)|\\\\~', $path ) ) {
			return self::base_name( $path );
		}

		return $path;
	}

	/**
	 * Makes a file path relative to the site, as described in the class docblock.
	 *
	 * @param string $file File path as logged, or ''.
	 * @return string Relative path or base name.
	 */
	private function relative_file( string $file ): string {
		if ( '' === $file ) {
			return '';
		}

		foreach ( $this->content_roots as $root ) {
			if ( str_starts_with( $file, $root . '/' ) ) {
				return self::clean_relative( 'wp-content/' . substr( $file, strlen( $root ) + 1 ) );
			}
		}
		foreach ( $this->abspath_roots as $root ) {
			if ( str_starts_with( $file, $root . '/' ) ) {
				return self::clean_relative( substr( $file, strlen( $root ) + 1 ) );
			}
		}

		return self::base_name( $file );
	}

	/**
	 * Converts a logged timestamp to ISO 8601 in UTC.
	 *
	 * @param string $stamp Timestamp, DD-Mon-YYYY HH:MM:SS.
	 * @param string $zone  Logged zone name, or ''. Without one, PHP's default zone is used.
	 * @return string|null The time, or null if the zone is unknown or the time cannot be read.
	 */
	private function iso_time( string $stamp, string $zone ): ?string {
		try {
			$tz = '' === $zone ? new \DateTimeZone( date_default_timezone_get() ) : new \DateTimeZone( $zone );
		} catch ( \Exception ) {
			return null;
		}

		$date = \DateTimeImmutable::createFromFormat( '!d-M-Y H:i:s', $stamp, $tz );
		if ( false === $date ) {
			return null;
		}

		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * The spellings of a directory that a log may contain: as configured, and resolved, without a trailing slash.
	 *
	 * @param string $dir Directory.
	 * @return string[]
	 */
	private static function roots( string $dir ): array {
		$roots = array( rtrim( $dir, '/\\' ) );
		$real  = realpath( $dir );
		if ( false !== $real ) {
			$roots[] = rtrim( $real, '/\\' );
		}

		return array_values( array_unique( array_filter( $roots, static fn( string $root ): bool => '' !== $root ) ) );
	}
}
