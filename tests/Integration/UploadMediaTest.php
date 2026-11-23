<?php
/**
 * The steadhand/upload-media ability: the types, names, bytes, parents, permissions, refusals and the change log.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test fixtures: base64 is how the ability receives an image.

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\PostFields;
use Steadhand\Abilities\UploadMedia;

/**
 * Covers SPEC §5 (upload-media), §6.1, §7, §10 and §14.1. Every refusal test checks that no file and no attachment row
 * appeared in the uploads directory, and that the change-log row for the call does not hold the payload.
 */
final class UploadMediaTest extends PostWriteTestCase {

	use ImageBytes;

	private const ABILITY   = 'steadhand/upload-media';
	private const TOOL_NAME = 'steadhand-upload-media';
	private const NOT_FOUND = 'Not found, or this agent may not access it.';

	/**
	 * Attachment IDs this test created, deleted with their files in tearDown().
	 *
	 * @var int[]
	 */
	private array $created = array();

	/**
	 * Removes the filters and the attachments this file adds.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'steadhand_rate_limit_per_minute' );
		remove_all_filters( 'wp_handle_sideload_prefilter' );
		remove_all_filters( 'wp_generate_attachment_metadata' );
		remove_all_filters( 'steadhand_allowed_post_types' );

		foreach ( $this->created as $id ) {
			if ( null !== get_post( $id ) ) {
				wp_delete_attachment( $id, true );
			}
		}
		$this->created = array();

		parent::tearDown();
	}

	/**
	 * Calls the ability as the current user, and records a stored attachment for cleanup.
	 *
	 * @param array<string, mixed> $args Input.
	 * @return mixed
	 */
	private function upload( array $args ): mixed {
		$result = $this->execute_ability( self::ABILITY, $args );
		if ( is_array( $result ) && isset( $result['id'] ) ) {
			$this->created[] = (int) $result['id'];
		}

		return $result;
	}

	/**
	 * The input for an upload of these bytes, under this file name.
	 *
	 * @param string               $bytes    The file.
	 * @param string               $filename File name.
	 * @param array<string, mixed> $extra    More input fields.
	 * @return array<string, mixed>
	 */
	private function args( string $bytes, string $filename, array $extra = array() ): array {
		return array_merge(
			array(
				'filename'    => $filename,
				'data_base64' => base64_encode( $bytes ),
			),
			$extra
		);
	}

	/**
	 * Every file under the uploads directory, with its size, keyed by its path relative to the directory.
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
	 * A real JPEG attachment, for a parent that is an attachment. Recorded for cleanup.
	 */
	private function image_attachment(): int {
		$id              = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );
		$this->created[] = $id;

		return $id;
	}

	/**
	 * The number of attachment rows in the table.
	 */
	private function attachment_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment'" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A fixed count of a core table.
	}

	/**
	 * Calls the ability, asserts the refusal code, and asserts that no file and no attachment row was made.
	 *
	 * @param array<string, mixed> $args Input.
	 * @param string               $code Expected error code.
	 * @return \WP_Error
	 */
	private function refused( array $args, string $code ): \WP_Error {
		$files       = $this->uploads_files();
		$attachments = $this->attachment_count();

		$result = $this->upload( $args );

		$this->assertWPError( $result, 'The call must be refused.' );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $files, $this->uploads_files(), 'A refused call must leave no file in the uploads directory.' );
		$this->assertSame( $attachments, $this->attachment_count(), 'A refused call must leave no attachment row.' );

		return $result;
	}

	/**
	 * The change-log input of the agent's latest row, as stored.
	 *
	 * @param int $agent Agent user ID.
	 */
	private function logged_input( int $agent ): string {
		return (string) $this->latest_row( $agent )['input'];
	}

	/**
	 * Asserts that stored text holds no part of the payload, in plain or JSON-escaped form.
	 *
	 * @param string $base64 The payload as sent.
	 * @param string $stored The stored text.
	 */
	private function assert_no_payload( string $base64, string $stored ): void {
		$needle = substr( $base64, 0, 40 );

		$this->assertStringNotContainsString( $needle, $stored, 'The log must not hold the payload.' );
		$this->assertStringNotContainsString( str_replace( '/', '\/', $needle ), $stored, 'The log must not hold the payload, escaped.' );
	}

	/**
	 * Every allowed type is stored: the file exists, the row has the agent as author, status inherit and the MIME type.
	 */
	public function test_each_allowed_type_is_stored_as_an_inherit_attachment_by_the_agent(): void {
		$agent = $this->act_as_agent( array( 'steadhand_media' => true ) );

		$cases = array(
			array( 'png', 'shot.png', 'image/png' ),
			array( 'jpeg', 'photo.jpg', 'image/jpeg' ),
			array( 'jpeg', 'photo.jpeg', 'image/jpeg' ),
			array( 'gif', 'loop.gif', 'image/gif' ),
			array( 'webp', 'card.webp', 'image/webp' ),
		);

		foreach ( $cases as list( $type, $name, $mime ) ) {
			$result = $this->upload( $this->args( $this->encode_image( $type ), $name ) );

			$this->assertIsArray( $result, "$name must be stored." );
			$id = (int) $result['id'];
			$this->assertSame( $mime, get_post_mime_type( $id ), $name );
			$this->assertFileExists( get_attached_file( $id ), $name );

			$post = get_post( $id );
			$this->assertSame( $agent, (int) $post->post_author, "$name must be authored by the agent." );
			$this->assertSame( 'inherit', $post->post_status, $name );
			$this->assertSame( 0, (int) $post->post_parent, $name );
		}
	}

	/**
	 * The title, alt text and caption are stored, and the output is what get-media returns for the same attachment.
	 */
	public function test_title_alt_and_caption_are_stored_and_the_output_matches_get_media(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );

		$result = $this->upload(
			$this->args(
				$this->encode_image( 'png', 6, 5 ),
				'harbour.png',
				array(
					'title'   => 'The <b>harbour</b> at dawn',
					'alt'     => 'Boats in still water',
					'caption' => 'Taken before sunrise',
				)
			)
		);

		$this->assertIsArray( $result );
		$id = (int) $result['id'];
		$this->assertSame( 'The harbour at dawn', get_post( $id )->post_title, 'Text is plain: the tags are removed.' );
		$this->assertSame( 'Taken before sunrise', get_post( $id )->post_excerpt );
		$this->assertSame( 'Boats in still water', get_post_meta( $id, '_wp_attachment_image_alt', true ) );

		$media = $this->execute_ability( 'steadhand/get-media', array( 'id' => $id ) );
		$this->assertIsArray( $media );
		$this->assertEquals( $media, array_diff_key( $result, array_flip( array( 'warnings', 'guardrail_notes' ) ) ) );
		$this->assertSame( array( 'Steadhand cannot undo an upload yet, so remove the file in the media library if it was a mistake.' ), $result['warnings'] );
		$this->assertSame( array(), $result['guardrail_notes'] );

		$valid = rest_validate_value_from_schema( $result, wp_get_ability( self::ABILITY )->get_output_schema(), 'output' );
		$this->assertNotInstanceOf( \WP_Error::class, $valid, 'The output must match the output schema.' );
	}

	/**
	 * The title defaults to the file name without its extension.
	 */
	public function test_the_title_defaults_to_the_file_name_without_its_extension(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );

		$result = $this->upload( $this->args( $this->encode_image( 'gif' ), 'sea wall.gif' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'sea-wall', get_post( (int) $result['id'] )->post_title );
	}

	/**
	 * The call is made over MCP, as an agent would make it, and returns structured output.
	 */
	public function test_the_tool_works_over_mcp(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );

		$output          = $this->output_of( self::TOOL_NAME, $this->args( $this->encode_image( 'jpeg' ), 'over-mcp.jpg' ) );
		$this->created[] = (int) $output['id'];

		$this->assertSame( 'image/jpeg', $output['mime_type'] );
		$this->assertFileExists( get_attached_file( (int) $output['id'] ) );
	}

	/**
	 * The agent needs no administrator capability: the default agent role has no upload_files, and still uploads.
	 */
	public function test_an_agent_without_upload_files_can_upload(): void {
		$agent = $this->act_as_agent( array( 'steadhand_media' => true ) );

		$this->assertFalse( user_can( $agent, 'upload_files' ), 'The agent role must not hold upload_files.' );
		$this->assertIsArray( $this->upload( $this->args( $this->encode_image( 'png' ), 'plain.png' ) ) );
	}

	/**
	 * Without steadhand_media the permission check refuses the call, before the payload is read.
	 */
	public function test_refused_without_steadhand_media(): void {
		$this->act_as_agent( array( 'steadhand_media' => false ) );

		$result = $this->refused( $this->args( $this->encode_image( 'png' ), 'nope.png' ), 'steadhand_forbidden' );

		$this->assertStringContainsString( 'steadhand_media', $result->get_error_message() );
	}

	/**
	 * Without steadhand_read the call is refused, because the output reads the new attachment back.
	 */
	public function test_refused_without_steadhand_read(): void {
		$this->act_as_agent(
			array(
				'steadhand_media' => true,
				'steadhand_read'  => false,
			)
		);

		$result = $this->refused( $this->args( $this->encode_image( 'png' ), 'nope.png' ), 'steadhand_forbidden' );

		$this->assertStringContainsString( 'steadhand_read', $result->get_error_message() );
	}

	/**
	 * An administrator who is not an agent cannot use the tool.
	 */
	public function test_an_administrator_without_steadhand_capabilities_is_refused(): void {
		wp_set_current_user( $this->admin_id() );

		$this->refused( $this->args( $this->encode_image( 'png' ), 'admin.png' ), 'steadhand_forbidden' );
	}

	/**
	 * Payloads that are not standard base64 are refused: invalid characters, bad length, whitespace, line breaks, a data URI.
	 */
	public function test_a_payload_that_is_not_strict_base64_is_refused(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$encoded = base64_encode( $this->encode_image( 'png' ) );

		$payloads = array(
			'invalid characters' => '!!' . $encoded,
			'bad length'         => substr( $encoded, 0, -1 ),
			'a space'            => substr( $encoded, 0, 8 ) . ' ' . substr( $encoded, 8 ),
			'a line break'       => chunk_split( $encoded, 60, "\n" ),
			'a carriage return'  => chunk_split( $encoded, 60, "\r\n" ),
			'a data URI prefix'  => 'data:image/png;base64,' . $encoded,
			'missing padding'    => rtrim( $encoded, '=' ),
			'an empty payload'   => '',
		);

		foreach ( $payloads as $case => $payload ) {
			$this->refused(
				array(
					'filename'    => 'strict.png',
					'data_base64' => $payload,
				),
				'steadhand_invalid_input'
			);
			$this->assertSame( 'blocked', $this->latest_row( $this->current_agent() )['result'], $case );
		}
	}

	/**
	 * The current user's ID, for the log checks.
	 */
	private function current_agent(): int {
		return get_current_user_id();
	}

	/**
	 * A decoded payload one byte over 5 MB is refused.
	 */
	public function test_a_payload_over_five_megabytes_is_refused(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );

		// A real PNG with trailing bytes, so the size rule is the only one that refuses it.
		$png    = $this->encode_image( 'png' );
		$padded = $png . str_repeat( "\0", UploadMedia::MAX_BYTES + 1 - strlen( $png ) );
		$this->assertSame( UploadMedia::MAX_BYTES + 1, strlen( $padded ) );

		$result = $this->refused( $this->args( $padded, 'big.png' ), 'steadhand_invalid_input' );
		$this->assertStringContainsString( '5 MB', $result->get_error_message() );
	}

	/**
	 * A payload of exactly 5 MB decoded passes the size rule. The validation is run directly, so the test does not decode it.
	 */
	public function test_a_payload_of_exactly_five_megabytes_passes_the_size_rule(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$png    = $this->encode_image( 'png' );
		$padded = $png . str_repeat( "\0", UploadMedia::MAX_BYTES - strlen( $png ) );

		$this->assertTrue( ( new UploadMedia() )->validate_input( $this->args( $padded, 'edge.png' ) ) );
	}

	/**
	 * The pixel rules, checked on the header alone through validate_input(): each side 1 to 12000, and at most 40 million
	 * pixels. Exactly at a limit passes.
	 */
	public function test_the_pixel_limits_hold_at_the_edges(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$validate = static fn( int $width, int $height ): mixed => ( new UploadMedia() )->validate_input(
			array(
				'filename'    => 'header.png',
				'data_base64' => base64_encode( self::png_header( $width, $height ) ),
			)
		);

		$this->assertTrue( $validate( 12000, 1 ), 'A 12000 pixel side is at the limit.' );
		$this->assertTrue( $validate( 1, 12000 ), 'A 12000 pixel side is at the limit.' );
		$this->assertTrue( $validate( 10000, 4000 ), 'Exactly 40 million pixels is at the limit.' );
		$this->assertWPError( $validate( 12001, 1 ), 'A side over 12000 is refused.' );
		$this->assertWPError( $validate( 1, 12001 ), 'A side over 12000 is refused.' );
		$this->assertWPError( $validate( 10001, 4000 ), 'More than 40 million pixels is refused.' );
		$this->assertWPError( $validate( 12000, 3334 ), 'More than 40 million pixels is refused, with both sides allowed.' );
	}

	/**
	 * The IHDR of a PNG that claims these dimensions, followed by nothing decodable.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 */
	private static function png_header( int $width, int $height ): string {
		$png = "\x89PNG\r\n\x1a\n" . pack( 'N', 13 ) . 'IHDR' . pack( 'N', $width ) . pack( 'N', $height ) . "\x08\x02\x00\x00\x00" . pack( 'N', 0 );

		return $png;
	}

	/**
	 * A BMP named as a PNG is refused, whatever its header says.
	 */
	public function test_a_bmp_named_as_a_png_is_refused(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$image = imagecreatetruecolor( 4, 4 );

		ob_start();
		imagebmp( $image );
		$bmp = (string) ob_get_clean();

		$this->refused( $this->args( $bmp, 'bitmap.png' ), 'steadhand_invalid_input' );
	}

	/**
	 * Non-image bytes are refused under any image name: PHP code, SVG, an HTML document and a text file.
	 */
	public function test_bytes_that_are_not_images_are_refused_under_image_names(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>';

		$this->refused( $this->args( '<?php echo "x"; ?>', 'script.jpg' ), 'steadhand_invalid_input' );
		$this->refused( $this->args( $svg, 'vector.svg' ), 'steadhand_invalid_input' );
		$this->refused( $this->args( $svg, 'vector.png' ), 'steadhand_invalid_input' );
		$this->refused( $this->args( '<!DOCTYPE html><html><body>hello</body></html>', 'page.jpg' ), 'steadhand_invalid_input' );
		$this->refused( $this->args( 'just some text', 'notes.jpg' ), 'steadhand_invalid_input' );
	}

	/**
	 * A real PNG named .jpg is refused: the name's extension must match the bytes' type.
	 */
	public function test_a_real_png_named_jpg_is_refused_as_a_mismatch(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );

		$this->refused( $this->args( $this->encode_image( 'png' ), 'mismatch.jpg' ), 'steadhand_invalid_input' );
		$this->refused( $this->args( $this->encode_image( 'jpeg' ), 'mismatch.png' ), 'steadhand_invalid_input' );
	}

	/**
	 * Names with a second extension, a path, a traversal, a control character or a null byte are refused, even around a real image.
	 */
	public function test_a_name_with_two_extensions_a_path_or_a_null_byte_is_refused(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$jpeg = $this->encode_image( 'jpeg' );

		$names = array(
			'shell.php.jpg',
			'image.jpg.php',
			'../escape.jpg',
			'nested/one.jpg',
			'nested\\one.jpg',
			"null\0byte.jpg",
			'..jpg',
			'.jpg',
			'no-extension',
			"tab\tname.jpg",
		);

		foreach ( $names as $name ) {
			$result = $this->upload( $this->args( $jpeg, $name ) );
			$this->assertWPError( $result, 'Refused: ' . wp_json_encode( $name ) );
		}
	}

	/**
	 * A GIF with PHP appended is stored only as a GIF: its detected type is GIF, its name ends in .gif, and no PHP file appears.
	 */
	public function test_a_gif_with_php_appended_is_stored_as_a_gif_and_no_php_file_appears(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$before = $this->uploads_files();

		$result = $this->upload( $this->args( $this->encode_image( 'gif' ) . "<?php echo 'x'; ?>", 'appended.gif' ) );

		$this->assertIsArray( $result, 'A GIF is an allowed image even with trailing bytes.' );
		$path = get_attached_file( (int) $result['id'] );
		$this->assertStringEndsWith( '.gif', $path );
		$this->assertSame( 'image/gif', wp_get_image_mime( $path ) );

		$added = array_diff_key( $this->uploads_files(), $before );
		$this->assertNotEmpty( $added );
		foreach ( array_keys( $added ) as $relative ) {
			$this->assertStringNotContainsString( '.php', $relative, 'No PHP file may appear in the uploads directory.' );
		}
	}

	/**
	 * A PNG header that claims 20000 by 20000 pixels is refused before anything is decoded.
	 */
	public function test_a_png_header_claiming_too_many_pixels_is_refused(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );

		$png = $this->encode_image( 'png' );
		$png = substr_replace( $png, pack( 'N', 20000 ), 16, 4 );
		$png = substr_replace( $png, pack( 'N', 20000 ), 20, 4 );

		$this->refused( $this->args( $png, 'huge.png' ), 'steadhand_invalid_input' );
	}

	/**
	 * A side of 12001 pixels is refused, although the pixel count is small.
	 */
	public function test_a_side_over_twelve_thousand_pixels_is_refused(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );

		$this->refused( $this->args( $this->encode_image( 'png', 1, 12001 ), 'tall.png' ), 'steadhand_invalid_input' );
	}

	/**
	 * An image whose body does not decode is refused, and nothing is stored.
	 */
	public function test_an_image_that_does_not_decode_is_refused(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );

		$png  = $this->encode_image( 'png' );
		$torn = substr( $png, 0, 33 ) . str_repeat( "\x5a", 64 );

		$this->refused( $this->args( $torn, 'torn.png' ), 'steadhand_invalid_input' );
	}

	/**
	 * A parent the caller may not edit is refused with the one text, and nothing is stored. The cases: missing, trashed, a type
	 * that is not allowed, an attachment, and a published post when the agent may not publish.
	 */
	public function test_a_parent_the_caller_may_not_edit_is_refused_with_the_one_text(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$bytes = $this->encode_image( 'png' );

		$trashed = $this->admin_post( array( 'post_status' => 'draft' ) );
		wp_trash_post( $trashed );
		$block = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_status' => 'publish',
			)
		);
		$image = $this->image_attachment();
		$live  = $this->admin_post();

		$parents = array(
			'a missing post'    => 987654321,
			'a trashed post'    => $trashed,
			'a disallowed type' => $block,
			'an attachment'     => $image,
			'a published post'  => $live,
		);

		foreach ( $parents as $case => $parent ) {
			$result = $this->refused( $this->args( $bytes, 'parent.png', array( 'parent' => $parent ) ), 'steadhand_forbidden' );
			$this->assertSame( self::NOT_FOUND, $result->get_error_message(), $case );
		}
	}

	/**
	 * A draft parent is allowed for an agent that may edit drafts, and the attachment is attached to it.
	 */
	public function test_a_draft_parent_is_allowed_and_the_attachment_is_attached_to_it(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$draft = $this->admin_post( array( 'post_status' => 'draft' ) );

		$result = $this->upload( $this->args( $this->encode_image( 'png' ), 'attached.png', array( 'parent' => $draft ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( $draft, (int) $result['parent'] );
		$this->assertSame( $draft, (int) get_post( (int) $result['id'] )->post_parent );
	}

	/**
	 * A published parent is allowed for an agent that may publish.
	 */
	public function test_a_published_parent_is_allowed_for_an_agent_that_may_publish(): void {
		$this->act_as_agent(
			array(
				'steadhand_media'   => true,
				'steadhand_publish' => true,
			)
		);
		$live = $this->admin_post();

		$result = $this->upload( $this->args( $this->encode_image( 'png' ), 'live.png', array( 'parent' => $live ) ) );

		$this->assertIsArray( $result );
		$this->assertSame( $live, (int) $result['parent'] );
	}

	/**
	 * The agent is refused at the rate limit, and the second call stores nothing.
	 */
	public function test_the_rate_limit_applies(): void {
		add_filter( 'steadhand_rate_limit_per_minute', static fn(): int => 1 );
		$this->act_as_agent( array( 'steadhand_media' => true ) );

		$this->assertIsArray( $this->upload( $this->args( $this->encode_image( 'png' ), 'first.png' ) ) );
		$this->refused( $this->args( $this->encode_image( 'png', 5, 5 ), 'second.png' ), 'steadhand_guardrail_blocked' );
	}

	/**
	 * A success writes one ok row for the attachment. The row holds the digest of the decoded bytes, and never the payload.
	 */
	public function test_a_success_logs_the_digest_of_the_decoded_bytes_and_never_the_payload(): void {
		$agent = $this->act_as_agent( array( 'steadhand_media' => true ) );
		$png   = $this->encode_image( 'png', 7, 3 );
		$b64   = base64_encode( $png );

		$result = $this->upload( $this->args( $png, 'logged.png', array( 'alt' => 'Logged' ) ) );

		$this->assertIsArray( $result );
		$row = $this->latest_row( $agent );
		$this->assertSame( 'ok', $row['result'] );
		$this->assertSame( 'attachment', $row['object_type'] );
		$this->assertSame( (int) $result['id'], (int) $row['object_id'] );
		$this->assertSame( 0, (int) $row['undoable'], 'An upload is not undoable yet.' );

		$stored = $this->logged_input( $agent );
		$this->assert_no_payload( $b64, $stored );
		$logged = json_decode( $stored, true );
		$this->assertSame( strlen( $png ), $logged['data_base64']['bytes'] );
		$this->assertSame( PostFields::group_hex( hash( 'sha256', $png ) ), $logged['data_base64']['sha256'] );
		$this->assertSame( 'logged.png', $logged['filename'] );
		$this->assertSame( 'Logged', $logged['alt'] );
	}

	/**
	 * Refusals of every kind log the input without the payload: a refused permission, a schema refusal with an extra field
	 * that carries the payload, a payload that does not decode, and the rate limit.
	 */
	public function test_every_refusal_path_logs_the_input_without_the_payload(): void {
		add_filter( 'steadhand_rate_limit_per_minute', static fn(): int => 1 );
		$agent = $this->act_as_agent( array( 'steadhand_media' => false ) );
		$png   = $this->encode_image( 'png', 9, 2 );
		$b64   = base64_encode( $png );

		// The permission refusal.
		$this->execute_ability( self::ABILITY, $this->args( $png, 'denied.png' ) );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
		$this->assert_no_payload( $b64, $this->logged_input( $agent ) );

		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$agent = get_current_user_id();

		// The schema refusal: an extra field holding the payload, under a name that is not an input.
		$this->execute_ability(
			self::ABILITY,
			array(
				'filename' => 'extra.png',
				'data'     => $b64,
			)
		);
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
		$this->assert_no_payload( $b64, $this->logged_input( $agent ) );

		// A payload that does not decode is logged as the digest of the text as sent.
		$spoiled = substr( $b64, 0, 16 ) . ' ' . substr( $b64, 16 );
		$this->execute_ability(
			self::ABILITY,
			array(
				'filename'    => 'spoiled.png',
				'data_base64' => $spoiled,
			)
		);
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
		$this->assert_no_payload( $b64, $this->logged_input( $agent ) );

		// The rate limit: one allowed upload, then a blocked one that carries the payload.
		$this->assertIsArray( $this->upload( $this->args( $png, 'allowed.png' ) ) );
		$this->execute_ability( self::ABILITY, $this->args( $png, 'limited.png' ) );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
		$this->assert_no_payload( $b64, $this->logged_input( $agent ) );
		$logged = json_decode( $this->logged_input( $agent ), true );
		$this->assertSame( strlen( $png ), $logged['data_base64']['bytes'] );
	}

	/**
	 * A payload that is not text (a schema refusal can carry one) is logged as its type, with no size of a payload.
	 */
	public function test_a_payload_that_is_not_text_is_logged_as_its_type(): void {
		$agent = $this->act_as_agent( array( 'steadhand_media' => true ) );

		$this->execute_ability(
			self::ABILITY,
			array(
				'filename'    => 'number.png',
				'data_base64' => 12345,
			)
		);

		$logged = json_decode( $this->logged_input( $agent ), true );
		$this->assertSame( 'integer', $logged['data_base64']['type'] );
	}

	/**
	 * A refused call with no steadhand_media leaves no row of the payload and no file, and the log row is blocked.
	 */
	public function test_the_refused_permission_writes_a_blocked_row_and_no_file(): void {
		$agent = $this->act_as_agent( array( 'steadhand_media' => false ) );
		$png   = $this->encode_image( 'png' );

		$this->refused( $this->args( $png, 'denied.png' ), 'steadhand_forbidden' );

		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
	}

	/**
	 * A failure while the attachment's metadata is generated removes the file and the row, and returns an error.
	 */
	public function test_a_failure_after_the_row_exists_removes_the_row_and_the_file(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		add_filter( 'wp_generate_attachment_metadata', static fn(): array => array() );

		$this->refused( $this->args( $this->encode_image( 'png' ), 'metadata.png' ), 'steadhand_error' );
	}

	/**
	 * A failure before core stores the file leaves nothing behind.
	 */
	public function test_a_failure_before_core_stores_the_file_leaves_nothing(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		add_filter(
			'wp_handle_sideload_prefilter',
			static function ( array $file ): array {
				$file['error'] = 'Refused by the test.';
				return $file;
			}
		);

		$this->refused( $this->args( $this->encode_image( 'png' ), 'prefilter.png' ), 'steadhand_invalid_input' );
	}

	/**
	 * A successful upload leaves no temporary file in the system temp directory.
	 */
	public function test_the_temporary_file_is_removed_after_a_success(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$temp   = get_temp_dir();
		$before = scandir( $temp );

		$this->assertIsArray( $this->upload( $this->args( $this->encode_image( 'png' ), 'tidy.png' ) ) );

		$this->assertSame( $before, scandir( $temp ), 'No temporary image may be left behind.' );
	}
}
