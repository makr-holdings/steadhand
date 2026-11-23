<?php
/**
 * Steadhand\Pipeline\MediaWriter, called directly inside a write: the write guard, the argument checks, the cleanup after a
 * failed store, and the featured-image lost-update check.
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
	 * Removes the filters and attachments this file adds.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'wp_handle_sideload_prefilter' );
		remove_all_filters( 'wp_generate_attachment_metadata' );

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
			MediaWriter::create_image( $this->encode_image( 'png' ), 'outside.png', array(), 0 );
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
			$this->assertSame( 'throws:InvalidArgumentException', $this->writer( static fn() => MediaWriter::create_image( $png, 'fields.png', $fields, 0 ) ), $case );
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

		$this->assertSame( 'throws:InvalidArgumentException', $this->writer( static fn() => MediaWriter::create_image( '', 'empty.png', array(), 0 ) ) );
		$this->assertSame( 'throws:InvalidArgumentException', $this->writer( static fn() => MediaWriter::create_image( $png, 'parent.png', array(), -1 ) ) );
		$this->assertSame( 'throws:InvalidArgumentException', $this->writer( static fn() => MediaWriter::create_image( $png, '/', array(), 0 ) ) );
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
				$parent
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

		$result = $this->writer( static fn() => MediaWriter::create_image( $png, 'core.png', array(), 0 ) );

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

		$result = $this->writer( static fn() => MediaWriter::create_image( $png, 'metadata.png', array(), 0 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_error', $result->get_error_code() );
		$this->assertSame( $rows, $this->attachment_count(), 'The row made for the failed store must be removed.' );
		$this->assertSame( $files, $this->uploads_files(), 'The file made for the failed store must be removed.' );
	}

	/**
	 * Bytes that do not decode as an image are refused before core stores anything.
	 */
	public function test_bytes_that_do_not_decode_are_refused_before_storing(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$png   = $this->encode_image( 'png' );
		$torn  = substr( $png, 0, 33 ) . str_repeat( "\x5a", 64 );
		$files = $this->uploads_files();
		$rows  = $this->attachment_count();

		$result = $this->writer( static fn() => MediaWriter::create_image( $torn, 'torn.png', array(), 0 ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_invalid_input', $result->get_error_code() );
		$this->assertSame( $files, $this->uploads_files() );
		$this->assertSame( $rows, $this->attachment_count() );
	}

	/**
	 * The permission check, run again just before the write: when it refuses, nothing is written.
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
	 * Makes a real image attachment for the thumbnail tests. Recorded for cleanup.
	 */
	private function set_thumbnail_target(): int {
		$id              = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->created[] = $id;

		return $id;
	}
}
