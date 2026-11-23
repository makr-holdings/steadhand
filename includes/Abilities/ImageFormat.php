<?php
/**
 * Reads the parts of an uploaded image that its header and its decoder do not check: whether a WebP or PNG is animated,
 * whether its container is whole, and how many scans a JPEG has.
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

	/** The container is animated: a WebP with the animation flag or an ANIM/ANMF chunk, or a PNG with an acTL chunk. */
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

	/** The animation flag in the first byte of a WebP VP8X chunk's flags (libwebp's ANIMATION_FLAG). */
	private const WEBP_ANIMATION_BIT = 0x02;

	/**
	 * Reads a WebP container. The RIFF size must fit the string; the chunks, each padded to an even length, must end exactly
	 * at the end of the RIFF payload. A VP8X chunk with the animation flag, or any ANIM or ANMF chunk, makes it animated.
	 *
	 * @param string $bytes The file.
	 * @return string One of STILL, ANIMATED or MALFORMED.
	 */
	public static function webp_kind( string $bytes ): string {
		$length = strlen( $bytes );
		if ( $length < 12 || 'RIFF' !== substr( $bytes, 0, 4 ) || 'WEBP' !== substr( $bytes, 8, 4 ) ) {
			return self::MALFORMED;
		}

		// The RIFF size counts the bytes after its own eight. A file shorter than that is cut off.
		$riff_end = 8 + self::le32( $bytes, 4 );
		if ( $riff_end < 12 || $riff_end > $length ) {
			return self::MALFORMED;
		}

		$animated = false;
		$offset   = 12;

		for ( $count = 0; $offset < $riff_end; ++$count ) {
			if ( $count >= self::MAX_CHUNKS || $offset + 8 > $riff_end ) {
				return self::MALFORMED;
			}

			$type = substr( $bytes, $offset, 4 );
			$size = self::le32( $bytes, $offset + 4 );
			$data = $offset + 8;

			// The chunk's data must lie inside the RIFF payload.
			if ( $size > $riff_end - $data ) {
				return self::MALFORMED;
			}

			if ( 'VP8X' === $type ) {
				if ( $size < 10 ) {
					return self::MALFORMED;
				}
				if ( 0 !== ( ord( $bytes[ $data ] ) & self::WEBP_ANIMATION_BIT ) ) {
					$animated = true;
				}
			} elseif ( 'ANIM' === $type || 'ANMF' === $type ) {
				$animated = true;
			}

			// Odd-sized data is followed by one pad byte, which the RIFF size includes.
			$offset = $data + $size + ( $size % 2 );
		}

		if ( $offset !== $riff_end ) {
			return self::MALFORMED;
		}

		return $animated ? self::ANIMATED : self::STILL;
	}

	/**
	 * Reads a PNG container, chunk by chunk, up to IEND. A chunk's data must lie inside the file. An acTL chunk makes the
	 * file animated. A file that ends before IEND is malformed.
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

			// The data and the CRC after it must lie inside the file.
			if ( $size > $length - $offset - self::PNG_CHUNK_OVERHEAD ) {
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
	 * The unsigned little-endian 32-bit value at an offset. The caller has checked that four bytes are there.
	 *
	 * @param string $bytes  The file.
	 * @param int    $offset Offset of the first byte.
	 * @return int
	 */
	private static function le32( string $bytes, int $offset ): int {
		$values = unpack( 'V', substr( $bytes, $offset, 4 ) );

		return is_array( $values ) ? (int) $values[1] : 0;
	}

	/**
	 * The unsigned big-endian 32-bit value at an offset. The caller has checked that four bytes are there.
	 *
	 * @param string $bytes  The file.
	 * @param int    $offset Offset of the first byte.
	 * @return int
	 */
	private static function be32( string $bytes, int $offset ): int {
		$values = unpack( 'N', substr( $bytes, $offset, 4 ) );

		return is_array( $values ) ? (int) $values[1] : 0;
	}
}
