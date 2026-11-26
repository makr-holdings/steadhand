<?php
/**
 * Checks that the files a test uploads are deleted when that test ends.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

/**
 * The first test uploads an image and hands its path to the second, which runs after the first test's tearDown.
 * Without the cleanup in TestCase::tearDown() the file, and every size WordPress made from it, would still be there.
 */
final class UploadsRemovedTest extends TestCase {

	/**
	 * Uploads the fixture image and returns the path of the stored file.
	 */
	public function test_an_upload_is_on_disk_during_its_own_test(): string {
		$id = self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->assertIsInt( $id, 'The image fixture must upload.' );

		$path = (string) get_attached_file( $id );
		$this->assertFileExists( $path );

		return $path;
	}

	/**
	 * The stored file and its generated sizes are gone once the uploading test has ended.
	 *
	 * @depends test_an_upload_is_on_disk_during_its_own_test
	 *
	 * @param string $path Path of the file the first test uploaded.
	 */
	public function test_the_upload_and_its_sizes_are_gone_after_that_test( string $path ): void {
		$this->assertFileDoesNotExist( $path, 'TestCase::tearDown() must delete the files a test uploaded.' );

		$stem = substr( $path, 0, -strlen( '.jpg' ) );
		$this->assertSame( array(), (array) glob( $stem . '-*.jpg' ), 'The generated sizes of the upload must be deleted too.' );
	}
}
