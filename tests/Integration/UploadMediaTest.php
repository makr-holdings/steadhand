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
use Steadhand\Guardrails\Rules\DailyUploadLimit;
use Steadhand\Log\Schema;
use Steadhand\Pipeline\MediaWriter;

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
	 * The memory_limit the process started with, restored in tearDown() after a test changes it.
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
	 * Removes the filters and the attachments this file adds.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'steadhand_rate_limit_per_minute' );
		remove_all_filters( 'wp_handle_sideload_prefilter' );
		remove_all_filters( 'wp_generate_attachment_metadata' );
		remove_all_filters( 'steadhand_allowed_post_types' );
		remove_all_filters( 'query' );
		remove_all_filters( 'wp_check_filetype_and_ext' );
		remove_all_filters( 'wp_unique_filename' );
		remove_all_filters( 'wp_handle_upload' );
		remove_all_filters( 'image_memory_limit' );
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

		$result = $this->upload( $this->args( $this->encode_image( 'jpeg' ), 'sea-wall.jpg' ) );

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
	 * The pixel rules, checked on the header alone through validate_input(): each side 1 to 8000, and at most 16 million
	 * pixels. Exactly at a limit passes.
	 */
	public function test_the_pixel_limits_hold_at_the_edges(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$validate = fn( int $width, int $height ): mixed => ( new UploadMedia() )->validate_input(
			array(
				'filename'    => 'header.png',
				'data_base64' => base64_encode( $this->png_header( $width, $height ) ),
			)
		);

		$this->assertTrue( $validate( 8000, 2000 ), 'An 8000 pixel side, at 16 million pixels, is at the limit.' );
		$this->assertTrue( $validate( 2000, 8000 ), 'An 8000 pixel side is at the limit.' );
		$this->assertTrue( $validate( 4000, 4000 ), 'Exactly 16 million pixels is at the limit.' );
		$this->assertWPError( $validate( 8001, 1 ), 'A side over 8000 is refused.' );
		$this->assertWPError( $validate( 1, 8001 ), 'A side over 8000 is refused.' );
		$this->assertWPError( $validate( 4001, 4000 ), 'One pixel over 16 million is refused.' );
		$this->assertWPError( $validate( 8000, 2001 ), 'More than 16 million pixels is refused, with both sides allowed.' );
	}

	/**
	 * A header just over 16 million pixels is refused as a whole call, with the header alone: nothing is decoded or stored.
	 */
	public function test_an_image_over_the_16_million_pixel_limit_is_refused_by_the_header_alone(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$result = $this->refused( $this->args( $this->png_header( 4001, 4000 ), 'over.png' ), 'steadhand_invalid_input' );

		$this->assertStringContainsString( '16 million', $result->get_error_message() );
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
			$this->refused( $this->args( $jpeg, $name ), 'steadhand_invalid_input' );
		}
	}

	/**
	 * Names outside the rule are refused with steadhand_invalid_input, and nothing is stored: bidi and zero-width characters,
	 * Windows device names, 81 characters before the extension, a multibyte base of 190 characters, spaces, and a leading dash
	 * or dot.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function names_outside_the_rule(): array {
		return array(
			'right-to-left override'   => array( "\u{202E}gnp.jpg" ),
			'zero-width space'         => array( "zero\u{200B}width.jpg" ),
			'CON device'               => array( 'CON.jpg' ),
			'lower-case nul device'    => array( 'nul.png' ),
			'COM1 device'              => array( 'com1.jpg' ),
			'LPT9 device'              => array( 'LPT9.png' ),
			'a webp name'              => array( 'photo.webp' ),
			'81 character base'        => array( str_repeat( 'a', 81 ) . '.jpg' ),
			'190 multibyte characters' => array( str_repeat( '漢', 190 ) . '.jpg' ),
			'a space'                  => array( 'my photo.jpg' ),
			'a leading dash'           => array( '-lead.jpg' ),
			'a leading dot'            => array( '.hidden.jpg' ),
			'a gif name'               => array( 'loop.gif' ),
		);
	}

	/**
	 * Each name outside the rule is refused, with the code, and no file or row is left.
	 *
	 * @dataProvider names_outside_the_rule
	 *
	 * @param string $name The file name.
	 */
	public function test_a_file_name_outside_the_rule_is_refused_and_nothing_is_stored( string $name ): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );

		$result = $this->refused( $this->args( $this->encode_image( 'jpeg' ), $name ), 'steadhand_invalid_input' );

		$this->assertStringContainsString( 'filename must be', $result->get_error_message(), 'The refusal must say the rule.' );
	}

	/**
	 * A name that is exactly 80 characters before the extension is accepted, and an upper-case extension is stored in lower case.
	 */
	public function test_the_edges_of_the_name_rule_are_accepted(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$jpeg = $this->encode_image( 'jpeg' );

		$this->assertTrue( ( new UploadMedia() )->validate_input( $this->args( $jpeg, str_repeat( 'a', 80 ) . '.jpg' ) ) );

		$result = $this->upload( $this->args( $jpeg, 'Holiday_2026-Photo.JPG' ) );
		$this->assertIsArray( $result );
		$this->assertStringEndsWith( 'Holiday_2026-Photo.jpg', get_attached_file( (int) $result['id'] ) );
	}

	/**
	 * A GIF is refused whether it is still or animated, and PHP appended to it is refused too: no file is made.
	 */
	public function test_a_gif_is_refused_still_animated_and_with_php_appended(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$gif      = $this->encode_image( 'gif' );
		$animated = $gif . "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";

		$this->refused( $this->args( $gif, 'still.gif' ), 'steadhand_invalid_input' );
		$this->refused( $this->args( $animated, 'animated.gif' ), 'steadhand_invalid_input' );
		$this->refused( $this->args( $gif . "<?php echo 'x'; ?>", 'appended.gif' ), 'steadhand_invalid_input' );
	}

	/**
	 * An animated PNG (a PNG with an acTL chunk) is refused, and a still PNG is accepted.
	 */
	public function test_an_animated_png_is_refused_and_a_still_png_is_accepted(): void {
		$agent = $this->act_as_agent( array( 'steadhand_media' => true ) );
		$png   = $this->encode_image( 'png' );
		$apng  = $this->png_with_chunk( $png, 'acTL', pack( 'NN', 2, 0 ) );

		$result = $this->refused( $this->args( $apng, 'animated.png' ), 'steadhand_invalid_input' );
		$this->assertSame( 'Animated images are not accepted.', $result->get_error_message() );

		$this->assertIsArray( $this->upload( $this->args( $png, 'still.png' ) ), 'A still PNG is accepted.' );
		$this->assertSame( 'ok', $this->latest_row( $agent )['result'] );
	}

	/**
	 * WebP is refused like any other type that is not JPEG or PNG: a still WebP, an animated one, one made of ANMF frames, and a
	 * cut-off one, each under a name the file name rule accepts, so the type check is the one that refuses. Under its own
	 * .webp name the name rule refuses it before the bytes are read. Each refusal is logged blocked, and stores nothing.
	 */
	public function test_webp_is_refused_in_every_form(): void {
		$agent = $this->act_as_agent( array( 'steadhand_media' => true ) );
		$still = $this->encode_image( 'webp' );

		$flagged = $this->webp_file( $this->webp_vp8x( 0x02 ), $this->riff_chunk( 'VP8L', str_repeat( "\0", 8 ) ) );
		$frames  = $this->webp_file( $this->webp_vp8x( 0x00 ), $this->riff_chunk( 'ANMF', str_repeat( "\0", 16 ) ) );

		$not_jpeg_or_png = 'The data is not a JPEG or PNG image. GIF, WebP, SVG and other files are refused.';
		$cases           = array(
			'a still WebP under a png name'     => array( $still, 'still.png' ),
			'an animated WebP under a png name' => array( $flagged, 'flagged.png' ),
			'an ANMF WebP under a jpg name'     => array( $frames, 'frames.jpg' ),
			'a cut-off WebP under a png name'   => array( substr( $still, 0, -4 ), 'cut.png' ),
		);

		foreach ( $cases as $case => list( $bytes, $name ) ) {
			$result = $this->refused( $this->args( $bytes, $name ), 'steadhand_invalid_input' );
			$this->assertSame( $not_jpeg_or_png, $result->get_error_message(), $case );
			$this->assertSame( 'blocked', $this->latest_row( $agent )['result'], $case );
		}

		$result = $this->refused( $this->args( $still, 'still.webp' ), 'steadhand_invalid_input' );
		$this->assertStringContainsString( 'filename must be', $result->get_error_message(), 'A .webp name is refused by the name rule.' );
	}

	/**
	 * A JPEG with more than 50 start-of-scan markers is refused. Exactly 50 passes the scan check. The header is enough for both,
	 * so the check is run on validate_input() and on the full call for the refusal.
	 */
	public function test_a_jpeg_with_more_than_fifty_scans_is_refused(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$jpeg     = $this->encode_image( 'jpeg' );
		$have     = substr_count( $jpeg, "\xFF\xDA" );
		$fifty    = $jpeg . str_repeat( "\xFF\xDA", 50 - $have );
		$fiftyone = $jpeg . str_repeat( "\xFF\xDA", 51 - $have );

		$this->assertSame( 50, substr_count( $fifty, "\xFF\xDA" ) );
		$this->assertTrue( ( new UploadMedia() )->validate_input( $this->args( $fifty, 'fifty.jpg' ) ), 'Exactly 50 scans is accepted.' );

		$result = $this->refused( $this->args( $fiftyone, 'fiftyone.jpg' ), 'steadhand_invalid_input' );
		$this->assertStringContainsString( '50 scans', $result->get_error_message() );
	}

	/**
	 * A PNG whose container cannot be walked to its end is refused, with nothing stored: no IEND, and a chunk whose length
	 * runs past the end of the file, including the 32-bit edge lengths.
	 */
	public function test_a_malformed_png_container_is_refused(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$png = $this->encode_image( 'png' );

		$this->refused( $this->args( substr( $png, 0, -12 ), 'cut.png' ), 'steadhand_invalid_input' );
		$this->refused( $this->args( substr( $png, 0, 33 ) . pack( 'N', 0x80000000 ) . "IENDx\0\0\0\0", 'huge.png' ), 'steadhand_invalid_input' );
		$this->refused( $this->args( substr( $png, 0, 33 ) . pack( 'N', 0xFFFFFFFF ) . "acTLx\0\0\0\0", 'huger.png' ), 'steadhand_invalid_input' );
	}

	/**
	 * A PHP tag in a comment is refused, in any case: in a PNG text chunk and in a JPEG comment segment.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function php_tags(): array {
		return array(
			'lower case' => array( '<?php' ),
			'upper case' => array( '<?PHP' ),
			'mixed case' => array( '<?PhP' ),
		);
	}

	/**
	 * A valid PNG or JPEG that holds PHP code in a comment is refused, with the PHP message.
	 *
	 * @dataProvider php_tags
	 *
	 * @param string $tag The opening tag, in the case under test.
	 */
	public function test_php_code_inside_a_valid_png_or_jpeg_is_refused( string $tag ): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$comment = $tag . ' echo "x"; ?>';

		$png  = $this->png_with_chunk( $this->encode_image( 'png' ), 'tEXt', "Comment\0" . $comment );
		$jpeg = $this->jpeg_with_comment( $this->encode_image( 'jpeg' ), $comment );

		foreach ( array(
			'comment.png' => $png,
			'comment.jpg' => $jpeg,
		) as $name => $bytes ) {
			$result = $this->refused( $this->args( $bytes, $name ), 'steadhand_invalid_input' );
			$this->assertSame( 'The file contains PHP code, so it was refused.', $result->get_error_message(), $name );
		}
	}

	/**
	 * A WordPress image_memory_limit filter cannot lower the limit below core's own: the limit the upload uses is at least
	 * WP_MAX_MEMORY_LIMIT, and a value of -1 from the filter lifts it. The filter may only raise the limit.
	 */
	public function test_the_image_memory_limit_filter_cannot_lower_the_limit(): void {
		ini_set( 'memory_limit', '256M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- The test sets the limit it checks against.
		add_filter( 'image_memory_limit', static fn(): string => '64M' );

		$this->assertGreaterThanOrEqual( wp_convert_hr_to_bytes( WP_MAX_MEMORY_LIMIT ), MediaWriter::memory_limit(), 'A lower filter value is not used.' );
	}

	/**
	 * The one-text warning every upload returns is passed through __() with the steadhand domain, so a translation replaces it.
	 */
	public function test_the_undo_warning_is_translatable(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$translate = static function ( string $translation, string $text, string $domain ): string {
			return 'steadhand' === $domain && str_starts_with( $text, 'Steadhand cannot undo an upload yet' ) ? 'TRANSLATED WARNING' : $translation;
		};
		add_filter( 'gettext', $translate, 10, 3 );

		try {
			$result = $this->upload( $this->args( $this->encode_image( 'png' ), 'warned.png' ) );
		} finally {
			remove_filter( 'gettext', $translate, 10 );
		}

		$this->assertIsArray( $result );
		$this->assertSame( array( 'TRANSLATED WARNING' ), $result['warnings'] );
	}

	/**
	 * A parent trashed after the permission check, while the guardrails run, is refused by the ability's own re-check with the
	 * not-found refusal, and nothing is stored.
	 */
	public function test_a_parent_trashed_after_the_check_is_refused_as_not_found(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$draft = $this->admin_post( array( 'post_status' => 'draft' ) );
		$done  = false;

		add_filter(
			'steadhand_rate_limit_per_minute',
			static function ( $limit ) use ( $draft, &$done ) {
				if ( ! $done ) {
					$done = true;
					wp_trash_post( $draft );
				}

				return $limit;
			}
		);

		$result = $this->refused( $this->args( $this->encode_image( 'png' ), 'late.png', array( 'parent' => $draft ) ), 'steadhand_not_found' );

		$this->assertSame( self::NOT_FOUND, $result->get_error_message() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertTrue( $done, 'The parent must be trashed during the guardrails.' );
		$this->assertSame( 'trash', get_post_status( $draft ) );
	}

	/**
	 * A file that is inserted into the media library after the parent check is refused when the parent has been trashed in
	 * the meantime: the check runs again just before the row is made, and nothing is left.
	 */
	public function test_a_parent_trashed_between_the_check_and_the_write_is_refused_and_nothing_is_stored(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$draft = $this->admin_post( array( 'post_status' => 'draft' ) );

		$trash = static function ( $upload, $context ) use ( $draft ) {
			if ( 'sideload' === $context ) {
				wp_trash_post( $draft );
			}

			return $upload;
		};
		add_filter( 'wp_handle_upload', $trash, 10, 2 );
		$before = $this->attachment_ids();

		try {
			$result = $this->refused( $this->args( $this->encode_image( 'png' ), 'raced.png', array( 'parent' => $draft ) ), 'steadhand_forbidden' );
		} finally {
			remove_filter( 'wp_handle_upload', $trash, 10 );
			$this->remove_attachments_since( $before );
		}

		$this->assertSame( self::NOT_FOUND, $result->get_error_message() );
		$this->assertSame( 'trash', get_post_status( $draft ) );
	}

	/**
	 * Core's own checks can be changed by filters. The type it returns, the extension it stores under, and the content type of
	 * what it stored are each checked again, so a change is refused, with no file and no row left.
	 *
	 * @return array<string, array{0: callable}>
	 */
	public static function core_changes(): array {
		return array(
			'a filetype check that reports gif' => array(
				static function (): void {
					add_filter(
						'wp_check_filetype_and_ext',
						static fn(): array => array(
							'ext'             => 'gif',
							'type'            => 'image/gif',
							'proper_filename' => false,
						)
					);
				},
			),
			'a unique name that ends in php'    => array(
				static function (): void {
					add_filter( 'wp_unique_filename', static fn(): string => 'stored-name.php' );
				},
			),
			'a stored type of text/html'        => array(
				static function (): void {
					add_filter(
						'wp_handle_upload',
						static function ( array $upload, string $context ): array {
							if ( 'sideload' === $context ) {
								$upload['type'] = 'text/html';
							}

							return $upload;
						},
						10,
						2
					);
				},
			),
		);
	}

	/**
	 * Each change that core's checks are made to is refused, and leaves no file and no row.
	 *
	 * @dataProvider core_changes
	 *
	 * @param callable $change Adds the filter that changes core's result.
	 */
	public function test_a_change_to_what_core_stored_is_refused_and_nothing_is_left( callable $change ): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$change();

		$result = $this->refused( $this->args( $this->encode_image( 'png' ), 'rechecked.png' ), 'steadhand_error' );

		$this->assertStringContainsString( 'something other than the image that was checked', $result->get_error_message() );
	}

	/**
	 * Twenty-four hours of uploads: 100 uploads in the last day are allowed, the 101st is refused as a guardrail block, and
	 * logged as blocked. Rows are written directly, two hours old, so the per-minute limit does not count them.
	 */
	public function test_the_101st_upload_in_a_day_is_refused_and_logged_as_blocked(): void {
		$agent = $this->act_as_agent( array( 'steadhand_media' => true ) );
		$this->daily_rows( $agent, DailyUploadLimit::LIMIT, 7200 );
		$files = $this->uploads_files();

		$result = $this->refused( $this->args( $this->encode_image( 'png' ), 'daily.png' ), 'steadhand_guardrail_blocked' );

		$this->assertSame( 422, $result->get_error_data()['status'] );
		$this->assertStringContainsString( '100 uploads in 24 hours', $result->get_error_message() );
		$this->assertSame( $files, $this->uploads_files() );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
	}

	/**
	 * Ninety-nine uploads in the day leave room for one more.
	 */
	public function test_ninety_nine_uploads_in_a_day_leave_room_for_one_more(): void {
		$agent = $this->act_as_agent( array( 'steadhand_media' => true ) );
		$this->daily_rows( $agent, DailyUploadLimit::LIMIT - 1, 7200 );

		$this->assertIsArray( $this->upload( $this->args( $this->encode_image( 'png' ), 'last.png' ) ) );
	}

	/**
	 * Uploads by another agent do not count toward this agent's day.
	 */
	public function test_another_agents_uploads_do_not_count_toward_the_daily_limit(): void {
		$other = $this->make_agent( array( 'steadhand_media' => true ) );
		$this->daily_rows( $other, DailyUploadLimit::LIMIT + 5, 7200 );
		$this->act_as_agent( array( 'steadhand_media' => true ) );

		$this->assertIsArray( $this->upload( $this->args( $this->encode_image( 'png' ), 'mine.png' ) ) );
	}

	/**
	 * Uploads older than 24 hours are not counted.
	 */
	public function test_uploads_older_than_a_day_are_not_counted(): void {
		$agent = $this->act_as_agent( array( 'steadhand_media' => true ) );
		$this->daily_rows( $agent, DailyUploadLimit::LIMIT, 90000 );

		$this->assertIsArray( $this->upload( $this->args( $this->encode_image( 'png' ), 'fresh.png' ) ) );
	}

	/**
	 * The daily cap is for uploads only: after 100 uploads in the day, a create-post by the same agent is not blocked by it.
	 */
	public function test_the_daily_cap_does_not_affect_other_write_abilities(): void {
		$agent = $this->act_as_agent( array( 'steadhand_media' => true ) );
		$this->daily_rows( $agent, DailyUploadLimit::LIMIT, 7200 );

		$result = $this->execute_ability( 'steadhand/create-post', array( 'title' => 'Written after the upload cap' ) );

		$this->assertIsArray( $result, 'A create-post is not refused by the upload cap.' );
		$this->assertSame( 'ok', $this->latest_row( $agent )['result'] );
		$this->assertSame( 'steadhand/create-post', $this->latest_row( $agent )['ability'] );
	}

	/**
	 * An image that needs more memory than the site allows is refused before the log row is reserved: the row is blocked, not
	 * error, so it does not count toward the day's 100. The test environment sets WP_MAX_MEMORY_LIMIT to -1, so core's limit is
	 * unlimited unless a filter raises it above the PHP limit. The filter sets 280 MB, above the 256 MB PHP limit. A 4000 by
	 * 4000 image needs about 122 MB of pixels, plus the 64 MB overhead and the memory in use, which the 80 MB ballast makes at
	 * least 80 MB more: about 266 MB or more, which is more than 280 MB once the process's own memory is counted, so it is
	 * refused. The ballast is released straight after the call.
	 */
	public function test_a_memory_refusal_is_blocked_and_does_not_use_up_the_daily_cap(): void {
		$agent = $this->act_as_agent( array( 'steadhand_media' => true ) );
		ini_set( 'memory_limit', '256M' ); // phpcs:ignore WordPress.PHP.IniSet.memory_limit_Disallowed -- The test sets the limit it checks against.
		add_filter( 'image_memory_limit', static fn(): string => '280M' );
		$this->daily_rows( $agent, DailyUploadLimit::LIMIT - 1, 7200 );

		$ballast = str_repeat( "\0", 80 * 1024 * 1024 );
		$result  = $this->refused( $this->args( $this->png_header( 4000, 4000 ), 'memory.png' ), 'steadhand_invalid_input' );
		unset( $ballast );

		$this->assertSame( 'The image needs more memory than this site allows, so nothing was stored.', $result->get_error_message() );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'], 'A memory refusal is blocked, not error.' );

		$this->assertIsArray( $this->upload( $this->args( $this->encode_image( 'png' ), 'last.png' ) ), 'The 100th upload still passes.' );
	}

	/**
	 * If the change log cannot be counted, the upload is refused: the limit fails closed.
	 */
	public function test_an_upload_is_refused_when_the_daily_count_cannot_be_read(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$table = Schema::table();
		add_filter(
			'query',
			static fn( string $query ): string => str_starts_with( $query, 'SELECT COUNT(*) FROM ' . $table )
				? str_replace( 'FROM ' . $table, 'FROM ' . $table . '_missing', $query )
				: $query
		);

		$this->refused( $this->args( $this->encode_image( 'png' ), 'nocount.png' ), 'steadhand_guardrail_blocked' );
	}

	/**
	 * Every attachment ID in the table, for a test that may store an attachment without getting its ID back.
	 *
	 * @return int[]
	 */
	private function attachment_ids(): array {
		return array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => 'attachment',
					'post_status'      => 'any',
					'numberposts'      => -1,
					'fields'           => 'ids',
					'suppress_filters' => true,
				)
			)
		);
	}

	/**
	 * Removes every attachment that appeared since $before, with its files. A refused call can store a row and still return an
	 * error, and then it has no ID to be tracked by.
	 *
	 * @param int[] $before Attachment IDs before the call.
	 */
	private function remove_attachments_since( array $before ): void {
		foreach ( array_diff( $this->attachment_ids(), $before ) as $id ) {
			wp_delete_attachment( $id, true );
		}
	}

	/**
	 * Adds successful upload rows for an agent, all at the same age.
	 *
	 * @param int $agent   Agent user ID.
	 * @param int $count   How many rows.
	 * @param int $seconds Age of each row, in seconds.
	 */
	private function daily_rows( int $agent, int $count, int $seconds ): void {
		for ( $i = 0; $i < $count; $i++ ) {
			$this->insert_row(
				array(
					'user_id'    => $agent,
					'ability'    => self::ABILITY,
					'result'     => 'ok',
					'created_at' => gmdate( 'Y-m-d H:i:s', time() - $seconds ),
				)
			);
		}
	}

	/**
	 * A refused call with a title, alt, caption, filename or parent that is not what the schema allows: the change log keeps the
	 * digest of the JSON of each one, never the value, and the stored input decodes to those digests.
	 */
	public function test_non_text_fields_and_a_non_integer_parent_are_logged_as_digests(): void {
		$agent = $this->act_as_agent( array( 'steadhand_media' => true ) );
		$title = array( 'part-one', 'part-two' );
		$alt   = array_fill( 0, 40, base64_encode( random_bytes( 30 ) ) );

		$this->execute_ability(
			self::ABILITY,
			array(
				'filename'    => 'fields.png',
				'data_base64' => base64_encode( $this->encode_image( 'png' ) ),
				'title'       => $title,
				'alt'         => $alt,
				'caption'     => 5,
				'parent'      => '7',
			)
		);

		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
		$logged = json_decode( $this->logged_input( $agent ), true );

		$this->assertSame( PostFields::digest( (string) wp_json_encode( $title ) ), $logged['title'] );
		$this->assertSame( PostFields::digest( (string) wp_json_encode( $alt ) ), $logged['alt'] );
		$this->assertSame( PostFields::digest( '5' ), $logged['caption'] );
		$this->assertSame( PostFields::digest( '"7"' ), $logged['parent'] );
		$this->assertStringNotContainsString( $alt[0], $this->logged_input( $agent ), 'No part of the alt array may be stored as text.' );
	}

	/**
	 * The log form of the payload is its digest, both from log_input() and in the stored row.
	 */
	public function test_log_input_returns_the_payload_digest(): void {
		$png = $this->encode_image( 'png', 3, 3 );

		$logged = ( new UploadMedia() )->log_input(
			array(
				'filename'    => 'probe.png',
				'data_base64' => base64_encode( $png ),
			)
		);

		$this->assertSame( PostFields::digest( $png ), $logged['data_base64'] );
		$this->assertSame( 'probe.png', $logged['filename'] );
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
	 * A side of 8001 pixels is refused, although the pixel count is small.
	 */
	public function test_a_side_over_eight_thousand_pixels_is_refused(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );

		$this->refused( $this->args( $this->encode_image( 'png', 1, 8001 ), 'tall.png' ), 'steadhand_invalid_input' );
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
