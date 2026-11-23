<?php
/**
 * Steadhand\Abilities\ImageFormat: the PNG container reader and the JPEG scan count, on crafted bytes.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\ImageFormat;

/**
 * Covers the container rules of SPEC §5 (animation, malformed containers, scan count). The reader must answer for every
 * prefix of a file without reading past the string, so the truncation sweep runs every cut of a real file.
 */
final class ImageFormatTest extends TestCase {

	use ImageBytes;

	/**
	 * A still PNG made by GD.
	 */
	private function still_png(): string {
		return $this->encode_image( 'png' );
	}

	/**
	 * A PNG with an acTL chunk is animated; a still PNG is still; a chunk past the end, a missing IEND and a bad signature are
	 * malformed.
	 */
	public function test_png_containers_are_read_chunk_by_chunk(): void {
		$png  = $this->still_png();
		$apng = $this->png_with_chunk( $png, 'acTL', pack( 'NN', 2, 0 ) );

		$this->assertSame( ImageFormat::STILL, ImageFormat::png_kind( $png ) );
		$this->assertSame( ImageFormat::ANIMATED, ImageFormat::png_kind( $apng ) );
		$this->assertSame( ImageFormat::MALFORMED, ImageFormat::png_kind( substr( $png, 0, -12 ) ), 'No IEND is malformed.' );
		$this->assertSame( ImageFormat::MALFORMED, ImageFormat::png_kind( substr( $png, 0, 33 ) . pack( 'N', 999 ) . 'tEXt' ), 'A chunk past the end is malformed.' );
		$this->assertSame( ImageFormat::MALFORMED, ImageFormat::png_kind( 'GIF89a' . $png ), 'A bad signature is malformed.' );
	}

	/**
	 * A chunk length of 0x80000000 or 0xFFFFFFFF, which a 32-bit build would read as negative, is malformed. The length is refused
	 * before the chunk's type is looked at, so an IEND or an acTL chunk with such a length is not taken as the end or as animation.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function huge_chunk_lengths(): array {
		$lengths = array( 0x80000000, 0xFFFFFFFF );
		$cases   = array();

		foreach ( $lengths as $length ) {
			$hex = sprintf( '0x%08X', $length );

			$cases[ "tEXt length $hex" ] = array( 'tEXt', $length );
			$cases[ "IEND length $hex" ] = array( 'IEND', $length );
			$cases[ "acTL length $hex" ] = array( 'acTL', $length );
		}

		return $cases;
	}

	/**
	 * Each chunk whose length cannot fit in the file is malformed, whatever its type.
	 *
	 * @dataProvider huge_chunk_lengths
	 *
	 * @param string $type   The chunk type.
	 * @param int    $length The length the chunk header claims.
	 */
	public function test_a_chunk_length_that_cannot_fit_is_malformed( string $type, int $length ): void {
		$png = $this->still_png();
		// The chunk header, one data byte and a four-byte CRC: enough bytes that the length, not the end of the file, is what refuses it.
		$bad = substr( $png, 0, 33 ) . pack( 'N', $length ) . $type . 'x' . "\0\0\0\0";

		$this->assertSame( ImageFormat::MALFORMED, ImageFormat::png_kind( $bad ) );
	}

	/**
	 * Every cut of a real still PNG answers without a warning: only the whole file is still, and every shorter one is malformed.
	 */
	public function test_every_cut_of_a_real_png_is_answered_without_reading_past_the_end(): void {
		$png = $this->still_png();

		for ( $cut = 0, $end = strlen( $png ); $cut < $end; $cut++ ) {
			$this->assertSame( ImageFormat::MALFORMED, ImageFormat::png_kind( substr( $png, 0, $cut ) ), "PNG cut at $cut" );
		}

		$this->assertSame( ImageFormat::STILL, ImageFormat::png_kind( $png ) );
	}

	/**
	 * A JPEG with 50 start-of-scan markers passes; 51 is refused.
	 */
	public function test_a_jpeg_with_more_than_fifty_scan_markers_is_refused(): void {
		$jpeg = $this->encode_image( 'jpeg' );
		$have = substr_count( $jpeg, "\xFF\xDA" );

		$this->assertFalse( ImageFormat::jpeg_has_too_many_scans( $jpeg . str_repeat( "\xFF\xDA", 50 - $have ) ) );
		$this->assertTrue( ImageFormat::jpeg_has_too_many_scans( $jpeg . str_repeat( "\xFF\xDA", 51 - $have ) ) );
	}
}
