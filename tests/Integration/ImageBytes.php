<?php
/**
 * Makes tiny real images with GD, and the container pieces the media tests need, for the media write tests.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Test fixtures build bytes in memory; nothing is written to disk here.

namespace Steadhand\Tests\Integration;

/**
 * Encodes small images with GD, so the tests send real bytes of each allowed type, and builds the containers the tests
 * need on top of them: PNG chunks, WebP RIFF chunks and JPEG segments. Nothing here touches the disk.
 */
trait ImageBytes {

	/**
	 * A solid image of the given type and size, encoded by GD. Skips the test when GD cannot write WebP.
	 *
	 * @param string $type   One of png, jpeg, gif, webp.
	 * @param int    $width  Width in pixels.
	 * @param int    $height Height in pixels.
	 */
	protected function encode_image( string $type, int $width = 4, int $height = 4 ): string {
		if ( 'webp' === $type && ! function_exists( 'imagewebp' ) ) {
			$this->markTestSkipped( 'GD has no WebP support.' );
		}

		$image = imagecreatetruecolor( $width, $height );
		$fill  = imagecolorallocate( $image, 200, 40, 90 );
		imagefilledrectangle( $image, 0, 0, $width - 1, $height - 1, $fill );

		ob_start();
		switch ( $type ) {
			case 'png':
				imagepng( $image );
				break;
			case 'jpeg':
				imagejpeg( $image );
				break;
			case 'gif':
				imagegif( $image );
				break;
			default:
				imagewebp( $image );
				break;
		}

		return (string) ob_get_clean();
	}

	/**
	 * A PNG that claims these dimensions: its signature, its IHDR and an empty IEND, so the container is whole, but there is no
	 * pixel data. Enough for the header, pixel and container checks, which run before any decode.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 */
	protected function png_header( int $width, int $height ): string {
		$ihdr = pack( 'N', $width ) . pack( 'N', $height ) . "\x08\x02\x00\x00\x00";

		return "\x89PNG\r\n\x1a\n" . $this->png_chunk( 'IHDR', $ihdr ) . $this->png_chunk( 'IEND', '' );
	}

	/**
	 * One PNG chunk: length, type, data and the CRC the PNG format uses.
	 *
	 * @param string $type PNG chunk type, four letters.
	 * @param string $data Chunk data.
	 */
	protected function png_chunk( string $type, string $data ): string {
		return pack( 'N', strlen( $data ) ) . $type . $data . pack( 'N', crc32( $type . $data ) );
	}

	/**
	 * A GD PNG with one chunk inserted straight after its IHDR, which is always 33 bytes long (signature, then IHDR).
	 *
	 * @param string $png  A PNG from encode_image().
	 * @param string $type Chunk type.
	 * @param string $data Chunk data.
	 */
	protected function png_with_chunk( string $png, string $type, string $data ): string {
		return substr( $png, 0, 33 ) . $this->png_chunk( $type, $data ) . substr( $png, 33 );
	}

	/**
	 * One RIFF chunk for a WebP file: type, little-endian size, data, and the pad byte an odd size needs.
	 *
	 * @param string $type Four-character chunk type.
	 * @param string $data Chunk data.
	 */
	protected function riff_chunk( string $type, string $data ): string {
		return $type . pack( 'V', strlen( $data ) ) . $data . ( 1 === strlen( $data ) % 2 ? "\0" : '' );
	}

	/**
	 * A WebP file made of these chunks, with a RIFF header whose size is right.
	 *
	 * @param string ...$chunks Chunks from riff_chunk(), or raw bytes.
	 */
	protected function webp_file( string ...$chunks ): string {
		$body = implode( '', $chunks );

		return 'RIFF' . pack( 'V', 4 + strlen( $body ) ) . 'WEBP' . $body;
	}

	/**
	 * A VP8X chunk for a 4 by 4 image with these flags: 0x02 is the animation flag.
	 *
	 * @param int $flags Flags byte.
	 */
	protected function webp_vp8x( int $flags ): string {
		return $this->riff_chunk( 'VP8X', chr( $flags ) . "\0\0\0\x03\0\0\x03\0\0" );
	}

	/**
	 * A JPEG with a comment segment inserted after its start-of-image marker.
	 *
	 * @param string $jpeg    A JPEG from encode_image().
	 * @param string $comment Comment text.
	 */
	protected function jpeg_with_comment( string $jpeg, string $comment ): string {
		return substr( $jpeg, 0, 2 ) . "\xFF\xFE" . pack( 'n', strlen( $comment ) + 2 ) . $comment . substr( $jpeg, 2 );
	}
}
