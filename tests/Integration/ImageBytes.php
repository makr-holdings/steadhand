<?php
/**
 * Makes tiny real images with GD, for the media write tests.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

/**
 * Encodes small images with GD, so the tests send real bytes of each allowed type.
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
}
