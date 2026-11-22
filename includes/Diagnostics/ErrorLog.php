<?php
/**
 * Finds the PHP error log that WordPress itself is set up to write, and reads its last part.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the debug log that WP_DEBUG_LOG points at. Nothing else is ever read.
 *
 * Location rules:
 * - WP_DEBUG_LOG off (false, or an empty string) gives logging_off. The server-wide error_log directive is never read,
 *   because a server-wide PHP log can hold other sites' data.
 * - WP_DEBUG_LOG true (or the strings "true" and "1", as core reads them) means wp-content/debug.log.
 * - Any other string is the path the site owner chose.
 * A path that is a symbolic link gives outside_allowed_location. Otherwise the path is resolved with realpath(). A
 * directory, or a missing file, gives no_log_file. The resolved path must lie inside the resolved WP_CONTENT_DIR or the
 * resolved ABSPATH. The one exception is a string path that is already canonical: the site owner typed it, and it is
 * the exact path. Anything else gives outside_allowed_location. A file that cannot be read gives unreadable.
 *
 * Reading: only the last TAIL_BYTES bytes, with fopen, fseek and fread in binary mode. The partial line at the start of
 * the chunk is dropped. The file is never loaded whole.
 */
final class ErrorLog {

	/**
	 * Most bytes read from the end of the log.
	 */
	public const TAIL_BYTES = 262144;

	/**
	 * Constructor.
	 *
	 * @param mixed  $setting     The configured WP_DEBUG_LOG value, as normalise() reads it.
	 * @param string $content_dir WP_CONTENT_DIR.
	 * @param string $abspath     ABSPATH.
	 */
	public function __construct(
		private mixed $setting,
		private string $content_dir,
		private string $abspath
	) {}

	/**
	 * Builds the reader from the live constants.
	 *
	 * @return self
	 */
	public static function from_constants(): self {
		$setting = defined( 'WP_DEBUG_LOG' ) ? constant( 'WP_DEBUG_LOG' ) : false;

		return new self( $setting, WP_CONTENT_DIR, ABSPATH );
	}

	/**
	 * Reads WP_DEBUG_LOG the way core does (wp_debug_mode() in wp-includes/load.php). Any value that core turns into the
	 * string 'true' or '1' (the boolean true, the integer 1, the strings 'true', 'TRUE' and '1') means the default log,
	 * and is returned as true. Any other string is a path, returned unchanged. Anything else means logging is off.
	 *
	 * @param mixed $setting The configured value.
	 * @return bool|string True for the default log, false for off, or the path string.
	 */
	public static function normalise( mixed $setting ): bool|string {
		if ( is_string( $setting ) && ! in_array( strtolower( $setting ), array( 'true', '1' ), true ) ) {
			return $setting;
		}

		if ( is_scalar( $setting ) ) {
			return in_array( strtolower( (string) $setting ), array( 'true', '1' ), true );
		}

		return false;
	}

	/**
	 * Reads the log and returns the php_errors section.
	 *
	 * @param int $max_errors Most distinct items to return.
	 * @return array{available: bool, reason: string, scanned_bytes: int, skipped_lines: int, items: array<int, array<string, mixed>>}
	 */
	public function read( int $max_errors ): array {
		$found = $this->locate();
		if ( 'ok' !== $found['reason'] || null === $found['path'] ) {
			return $this->section( $found['reason'], 0, 0, array() );
		}

		$tail = $this->tail( $found['path'] );
		if ( null === $tail ) {
			return $this->section( 'unreadable', 0, 0, array() );
		}

		$parsed = ( new PhpErrorParser( $this->content_dir, $this->abspath ) )->parse( $tail['text'], $max_errors );

		return $this->section( 'ok', $tail['scanned'], $parsed['skipped_lines'], $parsed['items'] );
	}

	/**
	 * Builds the section.
	 *
	 * @param string                           $reason        Reason code.
	 * @param int                              $scanned       Bytes read.
	 * @param int                              $skipped       Lines skipped.
	 * @param array<int, array<string, mixed>> $items         Items.
	 * @return array{available: bool, reason: string, scanned_bytes: int, skipped_lines: int, items: array<int, array<string, mixed>>}
	 */
	private function section( string $reason, int $scanned, int $skipped, array $items ): array {
		return array(
			'available'     => 'ok' === $reason,
			'reason'        => $reason,
			'scanned_bytes' => $scanned,
			'skipped_lines' => $skipped,
			'items'         => $items,
		);
	}

	/**
	 * Works out which file to read, and whether it may be read.
	 *
	 * @return array{reason: string, path: string|null}
	 */
	private function locate(): array {
		$setting = self::normalise( $this->setting );

		if ( false === $setting || '' === $setting ) {
			return array(
				'reason' => 'logging_off',
				'path'   => null,
			);
		}

		// Refused before any filesystem call. A stream wrapper such as ftp:// or a custom scheme would otherwise be
		// asked to stat the path, which can connect out or run code, and core only ever writes to plain paths.
		if ( is_string( $setting ) && self::is_not_a_plain_path( $setting ) ) {
			return array(
				'reason' => 'outside_allowed_location',
				'path'   => null,
			);
		}

		$candidate = true === $setting ? rtrim( $this->content_dir, '/\\' ) . '/debug.log' : $setting;

		if ( is_link( $candidate ) ) {
			return array(
				'reason' => 'outside_allowed_location',
				'path'   => null,
			);
		}

		if ( ! file_exists( $candidate ) ) {
			return array(
				'reason' => 'no_log_file',
				'path'   => null,
			);
		}

		$real = realpath( $candidate );
		if ( false === $real || ! is_file( $real ) ) {
			return array(
				'reason' => 'no_log_file',
				'path'   => null,
			);
		}

		if ( self::is_device_path( $real ) ) {
			return array(
				'reason' => 'outside_allowed_location',
				'path'   => null,
			);
		}

		if ( ! $this->allowed( $real, $candidate, $setting ) ) {
			return array(
				'reason' => 'outside_allowed_location',
				'path'   => null,
			);
		}

		if ( ! is_readable( $real ) ) {
			return array(
				'reason' => 'unreadable',
				'path'   => null,
			);
		}

		return array(
			'reason' => 'ok',
			'path'   => $real,
		);
	}

	/**
	 * Whether a configured string cannot be a plain absolute path to a file: it has a NUL byte, a URL or stream-wrapper
	 * scheme (data:, php:, phar:, glob: and so on), or it is not absolute. Checked on the text alone.
	 *
	 * @param string $path Configured path.
	 * @return bool
	 */
	private static function is_not_a_plain_path( string $path ): bool {
		if ( str_contains( $path, "\0" ) || str_contains( $path, '://' ) ) {
			return true;
		}

		if ( 1 === preg_match( '~^[A-Za-z][A-Za-z0-9+.\-]*:~', $path ) && 1 !== preg_match( '~^[A-Za-z]:[\\\\/]~', $path ) ) {
			return true;
		}

		if ( 1 === preg_match( '~^/(?:proc|sys|dev)(?:/|$)~', $path ) ) {
			return true;
		}

		return 1 !== preg_match( '~^(?:/|[A-Za-z]:[\\\\/]|\\\\\\\\)~', $path );
	}

	/**
	 * Whether a resolved path is under /proc, /sys or /dev. Those are kernel and device files, not logs, and some
	 * block or never end when read.
	 *
	 * @param string $real Resolved path.
	 * @return bool
	 */
	private static function is_device_path( string $real ): bool {
		return 1 === preg_match( '~^/(?:proc|sys|dev)(?:/|$)~', $real );
	}

	/**
	 * Whether a resolved path may be read.
	 *
	 * @param string      $real      The resolved path.
	 * @param string      $candidate The path as configured, before resolution.
	 * @param bool|string $setting   The normalised setting.
	 * @return bool
	 */
	private function allowed( string $real, string $candidate, bool|string $setting ): bool {
		foreach ( array( $this->content_dir, $this->abspath ) as $base ) {
			$resolved = realpath( $base );
			if ( false !== $resolved && str_starts_with( $real, rtrim( $resolved, '/\\' ) . '/' ) ) {
				return true;
			}
		}

		return is_string( $setting ) && $real === $candidate;
	}

	/**
	 * Reads the last TAIL_BYTES bytes of a file. The partial line at the start is dropped.
	 *
	 * @param string $path Resolved path of a regular, readable file.
	 * @return array{text: string, scanned: int}|null Null if the file cannot be opened or read.
	 */
	private function tail( string $path ): ?array {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Reads only the tail of a log that can be gigabytes; WP_Filesystem cannot seek. Read-only, binary.
		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			return null;
		}

		try {
			$stat   = fstat( $handle );
			$size   = is_array( $stat ) ? (int) $stat['size'] : 0;
			$offset = max( 0, $size - self::TAIL_BYTES );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek -- Seeks to the tail of a large log; WP_Filesystem cannot seek. Read-only.
			if ( 0 !== fseek( $handle, $offset ) ) {
				return null;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Reads at most TAIL_BYTES from the tail of a large log; WP_Filesystem cannot seek. Read-only.
			$data = fread( $handle, self::TAIL_BYTES );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the read-only handle opened above.
			fclose( $handle );
		}

		if ( ! is_string( $data ) ) {
			return null;
		}

		$scanned = strlen( $data );
		if ( $offset > 0 ) {
			$newline = strpos( $data, "\n" );
			$data    = false === $newline ? '' : substr( $data, $newline + 1 );
		}

		return array(
			'text'    => $data,
			'scanned' => $scanned,
		);
	}
}
