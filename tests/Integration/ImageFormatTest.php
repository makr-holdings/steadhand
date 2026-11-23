<?php
/**
 * Steadhand\Abilities\ImageFormat: the WebP and PNG container readers and the JPEG scan count, on crafted bytes.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\ImageFormat;

/**
 * Covers the container rules of SPEC §5 (animation, malformed containers, scan count). The readers must answer for every
 * prefix of a file without reading past the string, so the truncation sweep runs every cut of a real file.
 */
final class ImageFormatTest extends TestCase {

	use ImageBytes;

	/**
	 * A still WebP made by GD, and a still PNG made by GD.
	 *
	 * @return string[] Keyed by 'webp' and 'png'.
	 */
	private function stills(): array {
		return array(
			'webp' => $this->encode_image( 'webp' ),
			'png'  => $this->encode_image( 'png' ),
		);
	}

	/**
	 * A still WebP is still, and the flags byte decides animation: the animation bit makes it animated, and another flag does not.
	 */
	public function test_a_webp_is_animated_only_by_the_animation_flag(): void {
		$still = $this->webp_file( $this->webp_vp8x( 0x00 ), $this->riff_chunk( 'VP8L', str_repeat( "\0", 8 ) ) );
		$exif  = $this->webp_file( $this->webp_vp8x( 0x10 ), $this->riff_chunk( 'VP8L', str_repeat( "\0", 8 ) ) );
		$anim  = $this->webp_file( $this->webp_vp8x( 0x02 ), $this->riff_chunk( 'VP8L', str_repeat( "\0", 8 ) ) );

		$this->assertSame( ImageFormat::STILL, ImageFormat::webp_kind( $still ) );
		$this->assertSame( ImageFormat::STILL, ImageFormat::webp_kind( $exif ), 'An EXIF flag is not an animation.' );
		$this->assertSame( ImageFormat::ANIMATED, ImageFormat::webp_kind( $anim ) );
		$this->assertSame( ImageFormat::STILL, ImageFormat::webp_kind( $this->stills()['webp'] ), 'A real still WebP is still.' );
	}

	/**
	 * An ANIM or ANMF chunk makes a WebP animated, whatever its flags say.
	 */
	public function test_an_anim_or_anmf_chunk_makes_a_webp_animated(): void {
		$anim = $this->webp_file( $this->riff_chunk( 'ANIM', "\0\0\0\0\0\0" ) );
		$anmf = $this->webp_file( $this->riff_chunk( 'ANMF', str_repeat( "\0", 16 ) ) );

		$this->assertSame( ImageFormat::ANIMATED, ImageFormat::webp_kind( $anim ) );
		$this->assertSame( ImageFormat::ANIMATED, ImageFormat::webp_kind( $anmf ) );
	}

	/**
	 * Containers that cannot be walked to their end are malformed: a bad signature, a RIFF size past the end, a chunk past the
	 * RIFF payload, a pad byte missing, a VP8X chunk too short, and more chunks than the walk allows.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function malformed_webps(): array {
		$body = self::riff_static( 'VP8L', str_repeat( "\0", 8 ) );

		return array(
			'no RIFF'              => array( 'JUNK' . substr( $body, 4 ) ),
			'a RIFF size past end' => array( 'RIFF' . pack( 'V', 400 ) . 'WEBP' . $body ),
			'a chunk past the end' => array( 'RIFF' . pack( 'V', 12 ) . 'WEBPVP8L' . pack( 'V', 99 ) . 'ab' ),
			'a missing pad byte'   => array( 'RIFF' . pack( 'V', 4 + 8 + 3 ) . 'WEBPEXIF' . pack( 'V', 3 ) . 'abc' ),
			'a short VP8X'         => array( 'RIFF' . pack( 'V', 4 + 8 + 4 ) . 'WEBPVP8X' . pack( 'V', 4 ) . "\x02\0\0\0" ),
			'too many chunks'      => array( 'RIFF' . pack( 'V', 4 + 4097 * 8 ) . 'WEBP' . str_repeat( 'JUNK' . pack( 'V', 0 ), 4097 ) ),
		);
	}

	/**
	 * One RIFF chunk for the data providers, which are static.
	 *
	 * @param string $type Chunk type.
	 * @param string $data Chunk data.
	 */
	private static function riff_static( string $type, string $data ): string {
		return $type . pack( 'V', strlen( $data ) ) . $data . ( 1 === strlen( $data ) % 2 ? "\0" : '' );
	}

	/**
	 * Each malformed container is refused as malformed.
	 *
	 * @dataProvider malformed_webps
	 *
	 * @param string $bytes The container.
	 */
	public function test_a_malformed_webp_is_malformed( string $bytes ): void {
		$this->assertSame( ImageFormat::MALFORMED, ImageFormat::webp_kind( $bytes ) );
	}

	/**
	 * A PNG with an acTL chunk is animated; a still PNG is still; a chunk past the end, a missing IEND and a bad signature are
	 * malformed.
	 */
	public function test_png_containers_are_read_chunk_by_chunk(): void {
		$png  = $this->stills()['png'];
		$apng = $this->png_with_chunk( $png, 'acTL', pack( 'NN', 2, 0 ) );

		$this->assertSame( ImageFormat::STILL, ImageFormat::png_kind( $png ) );
		$this->assertSame( ImageFormat::ANIMATED, ImageFormat::png_kind( $apng ) );
		$this->assertSame( ImageFormat::MALFORMED, ImageFormat::png_kind( substr( $png, 0, -12 ) ), 'No IEND is malformed.' );
		$this->assertSame( ImageFormat::MALFORMED, ImageFormat::png_kind( substr( $png, 0, 33 ) . pack( 'N', 999 ) . 'tEXt' ), 'A chunk past the end is malformed.' );
		$this->assertSame( ImageFormat::MALFORMED, ImageFormat::png_kind( 'GIF89a' . $png ), 'A bad signature is malformed.' );
	}

	/**
	 * Every cut of a real still WebP and a real PNG answers without a warning: only the whole file is still, and every shorter
	 * one is malformed.
	 */
	public function test_every_cut_of_a_real_file_is_answered_without_reading_past_the_end(): void {
		$stills = $this->stills();

		for ( $cut = 0, $end = strlen( $stills['webp'] ); $cut < $end; $cut++ ) {
			$this->assertSame( ImageFormat::MALFORMED, ImageFormat::webp_kind( substr( $stills['webp'], 0, $cut ) ), "WebP cut at $cut" );
		}
		for ( $cut = 0, $end = strlen( $stills['png'] ); $cut < $end; $cut++ ) {
			$this->assertSame( ImageFormat::MALFORMED, ImageFormat::png_kind( substr( $stills['png'], 0, $cut ) ), "PNG cut at $cut" );
		}

		$this->assertSame( ImageFormat::STILL, ImageFormat::webp_kind( $stills['webp'] ) );
		$this->assertSame( ImageFormat::STILL, ImageFormat::png_kind( $stills['png'] ) );
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
