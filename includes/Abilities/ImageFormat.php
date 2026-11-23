<?php
/**
 * Reads the parts of an uploaded image that its header and its decoder do not check: whether a PNG is animated, whether its
 * container is whole, and how many scans a JPEG has.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Abilities;

defined( 'ABSPATH' ) || exit;

/**
 * The readers stay inside the string they are given. Every length is checked against the bytes left before it is used, the
 * walk stops after a fixed number of chunks, and a container that cannot be walked to its end is MALFORMED, never guessed at.
 * Trailing bytes after the end of the container are not read: they are stored as sent, like any other extra bytes (SPEC §11).
 */
final class ImageFormat {

	/** The container is a still image. */
	public const STILL = 'still';

	/** The container is animated: a PNG with an acTL chunk. */
	public const ANIMATED = 'animated';

	/** The container cannot be walked to its end. */
	public const MALFORMED = 'malformed';

	/** The most start-of-scan markers a JPEG may have. A progressive JPEG has one per scan. */
	public const MAX_JPEG_SCANS = 50;

	/** The most chunks one container is walked through. Far more than any still image has; a bound, not a format rule. */
	private const MAX_CHUNKS = 4096;

	/** The eight bytes every PNG starts with. */
	private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

	/** Bytes a PNG chunk takes apart from its data: the length, the type and the CRC. */
	private const PNG_CHUNK_OVERHEAD = 12;

	/**
	 * Reads a PNG container, chunk by chunk, up to IEND. A chunk's length is read as unsigned. A length that is negative (on a
	 * 32-bit build) or runs past the end of the file makes the container malformed, before the chunk's type is looked at, so
	 * the walk can never move backwards or past the end. An acTL chunk makes the file animated. A file that ends before IEND is
	 * malformed.
	 *
	 * @param string $bytes The file.
	 * @return string One of STILL, ANIMATED or MALFORMED.
	 */
	public static function png_kind( string $bytes ): string {
		$length = strlen( $bytes );
		if ( $length < 8 || self::PNG_SIGNATURE !== substr( $bytes, 0, 8 ) ) {
			return self::MALFORMED;
		}

		$offset = 8;

		for ( $count = 0; $count < self::MAX_CHUNKS; ++$count ) {
			if ( $offset + self::PNG_CHUNK_OVERHEAD > $length ) {
				return self::MALFORMED;
			}

			$size = self::be32( $bytes, $offset );
			$type = substr( $bytes, $offset + 4, 4 );

			// The data and the CRC after it must lie inside the file. A negative length is refused here too.
			if ( $size < 0 || $size > $length - $offset - self::PNG_CHUNK_OVERHEAD ) {
				return self::MALFORMED;
			}

			if ( 'acTL' === $type ) {
				return self::ANIMATED;
			}

			if ( 'IEND' === $type ) {
				return self::STILL;
			}

			$offset += self::PNG_CHUNK_OVERHEAD + $size;
		}

		return self::MALFORMED;
	}

	/**
	 * Whether a JPEG has more start-of-scan markers (FF DA) than MAX_JPEG_SCANS. Entropy-coded data never holds FF DA, because
	 * an FF inside it is always followed by a zero byte or a restart marker, so each scan adds one.
	 *
	 * @param string $bytes The file.
	 * @return bool
	 */
	public static function jpeg_has_too_many_scans( string $bytes ): bool {
		return substr_count( $bytes, "\xFF\xDA" ) > self::MAX_JPEG_SCANS;
	}

	/**
	 * The unsigned big-endian 32-bit value at an offset. The caller has checked that four bytes are there. On a 32-bit build a
	 * value above 2^31 is negative, and the caller refuses it.
	 *
	 * @param string $bytes  The file.
	 * @param int    $offset Offset of the first byte.
	 * @return int
	 */
	private static function be32( string $bytes, int $offset ): int {
		$values = unpack( 'N', substr( $bytes, $offset, 4 ) );

		return is_array( $values ) ? (int) $values[1] : -1;
	}
}
