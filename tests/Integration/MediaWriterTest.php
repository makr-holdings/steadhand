<?php
/**
 * Steadhand\Pipeline\MediaWriter, called directly inside a write: the write guard, the argument checks, the memory check, the
 * cleanup after a failed store, the re-checks, and the featured-image lost-update check.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Pipeline\MediaWriter;

/**
 * Covers the writer's own rules (SPEC §14.1, §7). The ability-level behaviour is in UploadMediaTest and SetFeaturedImageTest.
 */
final class MediaWriterTest extends PostWriteTestCase {

	use ImageBytes;

	/**
	 * Attachment IDs this test created, deleted with their files in tearDown().
	 *
	 * @var int[]
	 */
	private array $created = array();

	/**
	 * The memory_limit the process started with, restored in tearDown().
	 *
	 * @var string
	 */
	private static string $memory_limit_before = '';

	/**
	 * Records the memory limit before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		self::$memory_limit_before = (string) ini_get( 'memory_limit' );
	}

	/**
	 * Removes the filters, the memory limit and the attachments this file adds.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'wp_handle_sideload_prefilter' );
		remove_all_filters( 'wp_generate_attachment_metadata' );
		remove_all_filters( 'image_memory_limit' );
		remove_all_filters( 'wp_check_filetype_and_ext' );
		remove_all_filters( 'wp_unique_filename' );
		remove_all_filters( 'wp_handle_upload' );
		ini_set( 'memory_limit', self::$memory_limit_before ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- Restores the limit the test changed.

		foreach ( $this->created as $id ) {
			if ( null !== get_post( $id ) ) {
				wp_delete_attachment( $id, true );
			}
		}
		$this->created = array();

		parent::tearDown();
	}

	/**
	 * The files in the uploads directory, keyed by their path relative to it.
	 *
	 * @return array<string, int>
	 */
	private function uploads_files(): array {
		$base  = wp_upload_dir()['basedir'];
		$files = array();

		if ( ! is_dir( $base ) ) {
			return $files;
		}

		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() ) {
				$files[ substr( $file->getPathname(), strlen( $base ) ) ] = $file->getSize();
			}
		}
		ksort( $files );

		return $files;
	}

	/**
	 * The number of attachment rows in the table.
	 */
	private function attachment_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A fixed count of a core table.
	}

	/**
	 * Runs a writer call inside a write, and returns what it returned, or the class of the exception it threw.
	 *
	 * @param callable $call The writer call.
	 * @return mixed The return value, or the exception class name prefixed with "throws:".
	 */
	private function writer( callable $call ): mixed {
		return $this->in_write(
			static function () use ( $call ): mixed {
				try {
					return $call();
				} catch ( \Throwable $thrown ) {
					return 'throws:' . $thrown::class;
				}
			}
		);
	}

	/**
	 * Both writer methods refuse a call made outside a write, and create nothing.
	 */
	public function test_both_methods_refuse_to_run_outside_a_write(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$post  = $this->admin_post();
		$files = $this->uploads_files();
		$rows  = $this->attachment_count();

		try {
			MediaWriter::create_image( $this->encode_image( 'png' ), 'outside.png', array(), 0, static fn(): bool => true );
			$this->fail( 'create_image must refuse to run outside a write.' );
		} catch ( \LogicException $thrown ) {
			$this->assertStringContainsString( 'write ability', $thrown->getMessage() );
		}

		try {
			MediaWriter::set_featured_image( $post, 0, 0, static fn(): bool => true );
			$this->fail( 'set_featured_image must refuse to run outside a write.' );
		} catch ( \LogicException $thrown ) {
			$this->assertStringContainsString( 'write ability', $thrown->getMessage() );
		}

		$this->assertSame( $files, $this->uploads_files() );
		$this->assertSame( $rows, $this->attachment_count() );
	}

	/**
	 * Fields outside the allowlist, and values that are not strings, are refused before anything is stored.
	 */
	public function test_fields_must_be_allowlisted_strings(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$png   = $this->encode_image( 'png' );
		$files = $this->uploads_files();
		$rows  = $this->attachment_count();

		$cases = array(
			'an unknown key'               => array( 'post_status' => 'publish' ),
			'a numeric key'                => array( 0 => 'x' ),
			'an author field'              => array( 'post_author' => 1 ),
			'a title that is not a string' => array( 'title' => 5 ),
			'an alt that is an array'      => array( 'alt' => array( 'x' ) ),
		);

		foreach ( $cases as $case => $fields ) {
			$this->assertSame( 'throws:InvalidArgumentException', $this->writer( static fn() => MediaWriter::create_image( $png, 'fields.png', $fields, 0, static fn(): bool => true ) ), $case );
		}

		$this->assertSame( $files, $this->uploads_files() );
		$this->assertSame( $rows, $this->attachment_count() );
	}

	/**
	 * Empty bytes, a negative parent and an empty name are refused as programming errors.
	 */
	public function test_bad_arguments_are_refused(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$png = $this->encode_image( 'png' );

		$this->assertSame( 'throws:InvalidArgumentException', $this->writer( static fn() => MediaWriter::create_image( '', 'empty.png', array(), 0, static fn(): bool => true ) ) );
		$this->assertSame( 'throws:InvalidArgumentException', $this->writer( static fn() => MediaWriter::create_image( $png, 'parent.png', array(), -1, static fn(): bool => true ) ) );
		$this->assertSame( 'throws:InvalidArgumentException', $this->writer( static fn() => MediaWriter::create_image( $png, '/', array(), 0, static fn(): bool => true ) ) );
		$this->assertSame( 'throws:InvalidArgumentException', $this->writer( static fn() => MediaWriter::set_featured_image( 0, 0, 0, static fn(): bool => true ) ) );
		$this->assertSame( 'throws:InvalidArgumentException', $this->writer( static fn() => MediaWriter::set_featured_image( 1, -1, 0, static fn(): bool => true ) ) );
	}

	/**
	 * A stored image gets the agent as author, status inherit, the parent, and the title, caption and alt.
	 */
	public function test_create_image_stores_the_attachment_with_its_fields(): void {
		$agent  = $this->act_as_agent( array( 'steadhand_media' => true ) );
		$parent = $this->admin_post();
		$png    = $this->encode_image( 'png', 3, 2 );

		$id = $this->writer(
			static fn() => MediaWriter::create_image(
				$png,
				'direct.png',
				array(
					'title'   => 'Direct',
					'alt'     => 'An alt text',
					'caption' => 'A caption',
				),
				$parent,
				static fn(): bool => true
			)
		);

		$this->assertIsInt( $id );
		$this->created[] = $id;
		$post            = get_post( $id );
		$this->assertSame( 'attachment', $post->post_type );
		$this->assertSame( 'inherit', $post->post_status );
		$this->assertSame( $agent, (int) $post->post_author );
		$this->assertSame( $parent, (int) $post->post_parent );
		$this->assertSame( 'Direct', $post->post_title );
		$this->assertSame( 'A caption', $post->post_excerpt );
		$this->assertSame( 'An alt text', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'image/png', get_post_mime_type( $id ) );
		$this->assertFileExists( get_attached_file( $id ) );
		$this->assertIsArray( wp_get_attachment_metadata( $id ) );
	}

	/**
	 * When core cannot store the file, nothing is left: no file and no row.
	 */
	public function test_a_refusal_by_core_stores_nothing(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$png = $this->encode_image( 'png' );
		add_filter(
			'wp_handle_sideload_prefilter',
			static function ( array $file ): array {
				$file['error'] = 'Refused by the test.';
				return $file;
			}
		);
		$files = $this->uploads_files();
		$rows  = $this->attachment_count();

		$result = $this->writer( static fn() => MediaWriter::create_image( $png, 'core.png', array(), 0, static fn(): bool => true ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_invalid_input', $result->get_error_code() );
		$this->assertSame( $files, $this->uploads_files() );
		$this->assertSame( $rows, $this->attachment_count() );
	}

	/**
	 * When the metadata cannot be made after the row exists, the row and the file are both removed.
	 */
	public function test_a_failure_after_the_row_exists_removes_the_row_and_the_file(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$png = $this->encode_image( 'png' );
		add_filter( 'wp_generate_attachment_metadata', static fn(): array => array() );
		$files = $this->uploads_files();
		$rows  = $this->attachment_count();

		$result = $this->writer( static fn() => MediaWriter::create_image( $png, 'metadata.png', array(), 0, static fn(): bool => true ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_error', $result->get_error_code() );
		$this->assertSame( $rows, $this->attachment_count(), 'The row made for the failed store must be removed.' );
		$this->assertSame( $files, $this->uploads_files(), 'The file made for the failed store must be removed.' );
	}

	/**
	 * Bytes with a sound header but a damaged body are stored as sent: the writer makes no second decode, and core's metadata
	 * reports the size from the header, so nothing reports the damage. The container and header checks are the ability's, and
	 * they refuse a body that is not a valid container (see UploadMediaTest). This pins the writer's behaviour.
	 */
	public function test_a_damaged_body_with_a_sound_header_is_stored_as_sent(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$png  = $this->encode_image( 'png' );
		$torn = substr( $png, 0, 33 ) . str_repeat( "\x5a", 64 );

		$id = $this->writer( static fn() => MediaWriter::create_image( $torn, 'damaged.png', array(), 0, static fn(): bool => true ) );

		$this->assertIsInt( $id );
		$this->created[] = $id;
		$this->assertSame( $torn, file_get_contents( get_attached_file( $id ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the probe file this test stored.
	}

	/**
	 * A header that claims more memory than the site allows is refused before the file is written: the image needs 3.2 GB, and
	 * neither the default limit nor a filter raising it to 1 GB can hold it. The filter is set to show it is not enough.
	 */
	public function test_an_image_that_needs_more_memory_than_the_site_allows_is_refused_before_anything_is_written(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		ini_set( 'memory_limit', '256M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- The test sets the limit it checks against.
		add_filter( 'image_memory_limit', static fn(): string => '1G' );
		$files = $this->uploads_files();
		$rows  = $this->attachment_count();

		$huge   = $this->png_header( 20000, 20000 );
		$result = $this->writer( static fn() => MediaWriter::create_image( $huge, 'memory.png', array(), 0, static fn(): bool => true ) );
		if ( is_int( $result ) ) {
			$this->created[] = $result; // Only if the check fails: removed in tearDown().
		}

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_invalid_input', $result->get_error_code() );
		$this->assertSame( 'The image needs more memory than this site allows, so nothing was stored.', $result->get_error_message() );
		$this->assertSame( $files, $this->uploads_files() );
		$this->assertSame( $rows, $this->attachment_count() );
	}

	/**
	 * The memory limit is core's: with memory_limit at -1 there is none, and a filter can raise the limit to what it says.
	 */
	public function test_the_memory_limit_follows_core_and_the_filter_may_only_raise_it(): void {
		ini_set( 'memory_limit', '-1' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- The test sets the limit it checks against.
		$this->assertSame( -1, MediaWriter::memory_limit(), 'With no limit in PHP there is none to check against.' );

		ini_set( 'memory_limit', '256M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- The test sets the limit it checks against.
		add_filter( 'image_memory_limit', static fn(): string => '1G' );
		$this->assertSame( 1073741824, MediaWriter::memory_limit(), 'A higher filter value is the limit.' );
	}

	/**
	 * The arithmetic of the check: pixels at 8 bytes each, the memory in use, and 64 MB. Tested at limits that are far from the
	 * memory in use, so the answer does not depend on how much the test process holds.
	 */
	public function test_fits_memory_counts_pixels_at_eight_bytes_plus_64_mb(): void {
		$this->assertTrue( MediaWriter::fits_memory( 16000000, 1, -1 ), 'A limit of -1 is unlimited.' );
		$this->assertFalse( MediaWriter::fits_memory( 1, 1, 0 ), 'Nothing fits in a limit of 0.' );
		$this->assertFalse( MediaWriter::fits_memory( 4000, 4000, 128 * 1024 * 1024 ), '128 MB of pixels does not fit in 128 MB.' );
		$this->assertTrue( MediaWriter::fits_memory( 1, 1, PHP_INT_MAX ), 'A tiny image fits a huge limit.' );
	}

	/**
	 * The permission check, run again just before the row is made: when it refuses, nothing is stored, and the file is removed.
	 */
	public function test_a_closure_that_refuses_the_row_stores_nothing(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$png   = $this->encode_image( 'png' );
		$files = $this->uploads_files();
		$rows  = $this->attachment_count();

		$result = $this->writer( static fn() => MediaWriter::create_image( $png, 'refused.png', array(), 0, static fn(): bool => false ) );
		if ( is_int( $result ) ) {
			$this->created[] = $result; // Only if the refusal fails: removed in tearDown().
		}

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
		$this->assertSame( $files, $this->uploads_files() );
		$this->assertSame( $rows, $this->attachment_count() );
	}

	/**
	 * The permission check, run again on the post as it is now: when it refuses, nothing is written.
	 */
	public function test_a_closure_that_refuses_writes_nothing(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$post  = $this->admin_post();
		$image = $this->set_thumbnail_target();
		update_post_meta( $post, '_thumbnail_id', 0 );

		$seen   = null;
		$result = $this->writer(
			static function () use ( $post, $image, &$seen ): mixed {
				return MediaWriter::set_featured_image(
					$post,
					$image,
					0,
					static function ( \WP_Post $fresh ) use ( &$seen ): bool {
						$seen = $fresh;
						return false;
					}
				);
			}
		);

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
		$this->assertInstanceOf( \WP_Post::class, $seen, 'The closure must receive the post as it is now.' );
		$this->assertSame( $post, $seen->ID );
		$this->assertSame( 0, MediaWriter::stored_thumbnail( $post ) );
	}

	/**
	 * A thumbnail changed since the caller read it is not overwritten: the write is refused and the other value stays.
	 */
	public function test_a_stale_expected_thumbnail_is_refused_and_the_other_value_stays(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$post   = $this->admin_post();
		$stored = $this->set_thumbnail_target();
		$wanted = $this->set_thumbnail_target();
		update_post_meta( $post, '_thumbnail_id', $stored );

		$result = $this->writer(
			static fn() => MediaWriter::set_featured_image( $post, $wanted, 0, static fn(): bool => true )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_refused_content_changed', $result->get_error_code() );
		$this->assertSame( $stored, MediaWriter::stored_thumbnail( $post ) );
	}

	/**
	 * A set, and then a removal, both read back what is stored.
	 */
	public function test_set_and_remove_read_back_what_is_stored(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$post  = $this->admin_post();
		$image = $this->set_thumbnail_target();

		$set = $this->writer(
			static fn() => MediaWriter::set_featured_image( $post, $image, 0, static fn(): bool => true )
		);
		$this->assertSame( array( 'featured_media' => $image ), $set );
		$this->assertSame( $image, MediaWriter::stored_thumbnail( $post ) );

		$removed = $this->writer(
			static fn() => MediaWriter::set_featured_image( $post, 0, $image, static fn(): bool => true )
		);
		$this->assertSame( array( 'featured_media' => 0 ), $removed );
		$this->assertSame( 0, MediaWriter::stored_thumbnail( $post ) );
	}

	/**
	 * A trashed or missing post is refused with the one text, before the permission closure is called.
	 */
	public function test_a_trashed_or_missing_post_is_refused_without_calling_the_closure(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$trashed = $this->admin_post();
		wp_trash_post( $trashed );
		$called = false;

		foreach ( array( $trashed, 987654321 ) as $post ) {
			$result = $this->writer(
				static function () use ( $post, &$called ): mixed {
					return MediaWriter::set_featured_image(
						$post,
						0,
						0,
						static function () use ( &$called ): bool {
							$called = true;
							return true;
						}
					);
				}
			);

			$this->assertWPError( $result );
			$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
		}

		$this->assertFalse( $called );
	}

	/**
	 * The crash cleanup routine removes the temporary file, the stored file, a generated size and the attachment row. A path
	 * outside its directory is not removed.
	 */
	public function test_the_cleanup_removes_every_file_and_the_row_it_is_given(): void {
		$id    = $this->set_thumbnail_target();
		$file  = get_attached_file( $id );
		$size  = trailingslashit( dirname( $file ) ) . 'steadhand-cleanup-probe-1x1.jpg';
		$tmp   = trailingslashit( get_temp_dir() ) . 'steadhand-cleanup-probe-' . wp_generate_password( 8, false ) . '.png';
		$other = trailingslashit( get_temp_dir() ) . 'steadhand-cleanup-outside-' . wp_generate_password( 8, false ) . '.jpg';

		copy( $file, $size );
		file_put_contents( $tmp, 'temporary' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A probe file in the temporary directory.
		file_put_contents( $other, 'outside' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- A probe file outside the uploads directory.

		MediaWriter::remove_unfinished( $tmp, $file, $id, array( $size ) );

		$this->assertFileDoesNotExist( $tmp, 'The temporary file must be removed.' );
		$this->assertFileDoesNotExist( $file, 'The stored file must be removed.' );
		$this->assertFileDoesNotExist( $size, 'A generated size must be removed.' );
		$this->assertNull( get_post( $id ), 'The attachment row must be removed.' );
		$this->assertFileExists( $other, 'A path outside its directory is never removed.' );

		wp_delete_file( $other );
		$this->created = array();
	}

	/**
	 * The shutdown function does nothing when no write is running, so a finished write is not cleaned up twice.
	 */
	public function test_the_shutdown_cleanup_does_nothing_when_no_write_is_running(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$id   = $this->set_thumbnail_target();
		$file = get_attached_file( $id );

		MediaWriter::finish_after_fatal();

		$this->assertFileExists( $file );
		$this->assertNotNull( get_post( $id ) );
	}

	/**
	 * Makes a real image attachment for the thumbnail tests. Recorded for cleanup.
	 */
	private function set_thumbnail_target(): int {
		$id              = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->created[] = $id;

		return $id;
	}
}
