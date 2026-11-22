<?php
/**
 * Unit tests for the PHP error log parser. Pure: no files and no WordPress state.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Steadhand\Diagnostics\PhpErrorParser;

/**
 * Covers which lines are kept, how they are cleaned, and how duplicates are counted.
 */
final class PhpErrorParserTest extends TestCase {

	private const CONTENT = '/var/www/html/wp-content';
	private const ABSPATH = '/var/www/html/';
	private const STAMP   = '[21-Nov-2026 10:00:00 UTC]';

	/**
	 * Parses lines with the test site's directories.
	 *
	 * @param string[] $lines Lines of the log.
	 * @param int      $max   Most items to return.
	 * @return array{items: array<int, array<string, mixed>>, skipped_lines: int}
	 */
	private function parse( array $lines, int $max = 20 ): array {
		$parser = new PhpErrorParser( self::CONTENT, self::ABSPATH );

		return $parser->parse( implode( "\n", $lines ), $max );
	}

	public function test_each_engine_level_is_kept_with_its_level_name(): void {
		$result = $this->parse(
			array(
				self::STAMP . ' PHP Fatal error:  a in /var/www/html/wp-content/a.php on line 1',
				self::STAMP . ' PHP Parse error:  b in /var/www/html/wp-content/b.php on line 2',
				self::STAMP . ' PHP Recoverable fatal error:  c in /var/www/html/wp-content/c.php on line 3',
				self::STAMP . ' PHP Catchable fatal error:  d in /var/www/html/wp-content/d.php on line 4',
				self::STAMP . ' PHP Warning:  e in /var/www/html/wp-content/e.php on line 5',
				self::STAMP . ' PHP Notice:  f in /var/www/html/wp-content/f.php on line 6',
				self::STAMP . ' PHP Deprecated:  g in /var/www/html/wp-content/g.php on line 7',
			)
		);

		$this->assertSame(
			array( 'fatal', 'parse', 'recoverable', 'recoverable', 'warning', 'notice', 'deprecated' ),
			array_column( $this->sorted( $result ), 'level' )
		);
		$this->assertSame( 0, $result['skipped_lines'] );
	}

	public function test_the_file_colon_line_form_is_kept(): void {
		$item = $this->parse(
			array( self::STAMP . ' PHP Fatal error:  Uncaught Exception: boom in /var/www/html/wp-content/x.php:7' )
		)['items'][0];

		$this->assertSame( 'fatal', $item['level'] );
		$this->assertSame( 'Uncaught Exception: boom', $item['message'] );
		$this->assertSame( 'wp-content/x.php', $item['file'] );
		$this->assertSame( 7, $item['line'] );
	}

	public function test_stack_trace_lines_are_skipped_and_counted(): void {
		$result = $this->parse(
			array(
				self::STAMP . ' PHP Fatal error:  Uncaught Exception: boom in /var/www/html/wp-content/x.php:7',
				'Stack trace:',
				'#0 {main}',
				'  thrown in /var/www/html/wp-content/x.php on line 7',
			)
		);

		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 3, $result['skipped_lines'] );
	}

	public function test_free_form_log_output_is_skipped_and_never_returned(): void {
		$result = $this->parse(
			array(
				'[21-Nov-2026 10:00:00 UTC] My plugin: user password=hunter2 token=abc123',
				"array (\n  'sql' => 'SELECT user_pass FROM wp_users'\n)",
				'[21-Nov-2026 10:00:01 UTC] SQL: INSERT INTO wp_options VALUES ("secret")',
			)
		);

		$this->assertSame( array(), $result['items'] );
		$this->assertSame( 5, $result['skipped_lines'] );
		$encoded = (string) wp_json_encode( $result );
		$this->assertStringNotContainsString( 'hunter2', $encoded );
		$this->assertStringNotContainsString( 'abc123', $encoded );
		$this->assertStringNotContainsString( 'wp_users', $encoded );
	}

	public function test_secrets_in_a_message_are_redacted_and_the_absolute_path_is_relative(): void {
		$item = $this->parse(
			array( self::STAMP . ' PHP Warning:  curl failed for https://user:s3cret@api.example.com/?api_key=ZZZZ in /var/www/html/wp-content/plugins/x/x.php on line 5' )
		)['items'][0];

		$this->assertSame( 'curl failed for https://[redacted]@api.example.com/?api_key=[redacted]', $item['message'] );
		$this->assertSame( 'wp-content/plugins/x/x.php', $item['file'] );
		$this->assertSame( 5, $item['line'] );

		$encoded = (string) wp_json_encode( $item );
		$this->assertStringNotContainsString( 's3cret', $encoded );
		$this->assertStringNotContainsString( 'ZZZZ', $encoded );
		$this->assertStringNotContainsString( '/var/www/html', $encoded );
	}

	public function test_the_absolute_site_path_is_stripped_from_inside_the_message(): void {
		$item = $this->parse(
			array( self::STAMP . ' PHP Warning:  Failed to open /var/www/html/wp-config.php and /var/www/html/wp-content/a.php in /var/www/html/wp-content/a.php on line 2' )
		)['items'][0];

		$this->assertSame( 'Failed to open wp-config.php and wp-content/a.php', $item['message'] );
		$this->assertStringNotContainsString( '/var/www/html', $item['message'] );
	}

	public function test_a_file_outside_the_site_becomes_its_base_name(): void {
		$item = $this->parse( array( self::STAMP . ' PHP Notice:  x in /opt/other/site/file.php on line 2' ) )['items'][0];

		$this->assertSame( 'file.php', $item['file'] );
	}

	public function test_a_file_under_the_site_root_is_relative_to_it(): void {
		$item = $this->parse( array( self::STAMP . ' PHP Notice:  x in /var/www/html/wp-admin/menu.php on line 2' ) )['items'][0];

		$this->assertSame( 'wp-admin/menu.php', $item['file'] );
	}

	public function test_a_line_without_a_location_has_no_file_or_line(): void {
		$item = $this->parse( array( self::STAMP . ' PHP Notice:  plain message' ) )['items'][0];

		$this->assertSame( 'plain message', $item['message'] );
		$this->assertSame( '', $item['file'] );
		$this->assertNull( $item['line'] );
	}

	public function test_identical_entries_are_counted_once_with_the_latest_time(): void {
		$result = $this->parse(
			array(
				self::STAMP . ' PHP Warning:  same in /var/www/html/wp-content/a.php on line 9',
				'[21-Nov-2026 10:05:00 UTC] PHP Warning:  same in /var/www/html/wp-content/a.php on line 9',
				'[21-Nov-2026 10:01:00 UTC] PHP Notice:  other in /var/www/html/wp-content/b.php on line 1',
			)
		);

		$this->assertCount( 2, $result['items'] );
		$this->assertSame( 'other', $result['items'][0]['message'], 'The newest entry comes first.' );
		$this->assertSame( 'same', $result['items'][1]['message'] );
		$this->assertSame( 2, $result['items'][1]['count'] );
		$this->assertSame( '2026-11-21T10:05:00Z', $result['items'][1]['time'] );
	}

	public function test_only_the_most_recent_max_errors_items_are_returned(): void {
		$lines = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$lines[] = self::STAMP . " PHP Notice:  n$i in /var/www/html/wp-content/a.php on line $i";
		}

		$result = $this->parse( $lines, 2 );

		$this->assertSame( array( 'n5', 'n4' ), array_column( $result['items'], 'message' ) );
	}

	public function test_the_time_is_iso_8601_utc_and_converted_from_the_logged_zone(): void {
		$utc   = $this->parse( array( '[21-Nov-2026 10:00:00 UTC] PHP Notice:  a in /var/www/html/wp-content/a.php on line 1' ) )['items'][0];
		$paris = $this->parse( array( '[21-Nov-2026 10:00:00 Europe/Paris] PHP Notice:  a in /var/www/html/wp-content/a.php on line 1' ) )['items'][0];

		$this->assertSame( '2026-11-21T10:00:00Z', $utc['time'] );
		$this->assertSame( '2026-11-21T09:00:00Z', $paris['time'] );
	}

	public function test_an_unknown_time_zone_gives_a_null_time(): void {
		$item = $this->parse( array( '[21-Nov-2026 10:00:00 Not/AZone] PHP Notice:  a in /var/www/html/wp-content/a.php on line 1' ) )['items'][0];

		$this->assertNull( $item['time'] );
	}

	public function test_a_long_message_is_cut_to_500_bytes_on_a_character_boundary(): void {
		$item = $this->parse( array( self::STAMP . ' PHP Notice:  ' . str_repeat( 'é', 400 ) . ' in /var/www/html/wp-content/a.php on line 1' ) )['items'][0];

		$this->assertLessThanOrEqual( 500, strlen( $item['message'] ) );
		$this->assertSame( 1, preg_match( '//u', $item['message'] ) );
	}

	public function test_invalid_utf8_nul_bytes_and_overlong_lines_do_not_break_the_output(): void {
		$bad_utf8 = self::STAMP . " PHP Warning:  bad \xFF\xC3 bytes in /var/www/html/wp-content/a.php on line 1";
		$nul      = self::STAMP . " PHP Warning:  nul\0byte in /var/www/html/wp-content/a.php on line 2";
		$long     = self::STAMP . ' PHP Warning:  ' . str_repeat( 'x', 20000 ) . ' in /var/www/html/wp-content/a.php on line 3';

		$result = $this->parse( array( $bad_utf8, $nul, $long ) );

		$this->assertNotFalse( wp_json_encode( $result ), 'The result must encode as JSON.' );
		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 2, $result['skipped_lines'] );
	}

	public function test_carriage_returns_at_the_end_of_lines_are_ignored(): void {
		$parser = new PhpErrorParser( self::CONTENT, self::ABSPATH );
		$result = $parser->parse( self::STAMP . " PHP Notice:  a in /var/www/html/wp-content/a.php on line 1\r\n", 20 );

		$this->assertSame( 'a', $result['items'][0]['message'] );
		$this->assertSame( 0, $result['skipped_lines'] );
	}

	public function test_empty_input_gives_no_items_and_no_skipped_lines(): void {
		$parser = new PhpErrorParser( self::CONTENT, self::ABSPATH );

		$this->assertSame(
			array(
				'items'         => array(),
				'skipped_lines' => 0,
			),
			$parser->parse( '', 20 )
		);
	}

	/**
	 * Items in a fixed order: by message, so that assertions do not depend on the parser's order.
	 *
	 * @param array{items: array<int, array<string, mixed>>} $result Parser result.
	 * @return array<int, array<string, mixed>>
	 */
	private function sorted( array $result ): array {
		$items = $result['items'];
		usort( $items, static fn( array $a, array $b ): int => strcmp( (string) $a['message'], (string) $b['message'] ) );

		return $items;
	}

	public function test_a_long_bare_secret_in_a_warning_is_masked_and_the_result_encodes_as_json(): void {
		$line = '[21-Nov-2026 10:00:00 UTC] PHP Warning:  password=' . str_repeat( 'a', 1023 ) . "\u{e9} tail in x.php on line 3";

		$result = $this->parse( array( $line ) );

		$this->assertSame( 'password=[redacted] tail', $result['items'][0]['message'] );
		$this->assertStringNotContainsString( 'aaaa', (string) wp_json_encode( $result ) );
		$this->assertNotFalse( wp_json_encode( $result ) );
	}

	/**
	 * Parses one warning with the given message and location, and returns its item.
	 *
	 * @param string $message Message as logged, before " in <file>".
	 * @param string $where   The location text after " in ".
	 * @return array<string, mixed>
	 */
	private function item_for( string $message, string $where ): array {
		return $this->parse( array( self::STAMP . ' PHP Warning:  ' . $message . ' in ' . $where . ' on line 4' ) )['items'][0];
	}

	public function test_an_absolute_path_outside_the_site_in_a_message_becomes_its_base_name(): void {
		$item = $this->item_for( 'include(/home/otheruser/public_html/wp-config.php): Failed to open stream', 'x.php' );

		$this->assertSame( 'include(wp-config.php): Failed to open stream', $item['message'] );
		$this->assertStringNotContainsString( '/home/otheruser', (string) wp_json_encode( $item ) );
	}

	public function test_open_basedir_messages_lose_every_outside_path_but_keep_the_rest(): void {
		$item = $this->item_for(
			'file_get_contents(): open_basedir restriction in effect. File(/etc/shadow) is not within the allowed path(s): (/var/www/vhosts/client-a.example/:/tmp/)',
			'x.php'
		);

		$this->assertSame(
			'file_get_contents(): open_basedir restriction in effect. File(shadow) is not within the allowed path(s): (client-a.example:tmp)',
			$item['message']
		);
	}

	public function test_a_path_in_error_log_is_reduced_to_its_base_name(): void {
		$item = $this->item_for( 'error_log(/tmp/probe.log)', 'x.php' );

		$this->assertSame( 'error_log(probe.log)', $item['message'] );
	}

	public function test_a_windows_path_in_a_message_is_reduced_to_its_base_name(): void {
		$item = $this->item_for( 'Failed to load C:\\inetpub\\wwwroot\\site\\wp-content\\plugins\\x\\x.php: missing', 'x.php' );

		$this->assertSame( 'Failed to load x.php: missing', $item['message'] );
	}

	public function test_a_windows_path_in_the_file_field_is_reduced_to_its_base_name(): void {
		$item = $this->parse( array( self::STAMP . ' PHP Warning:  boom in C:\\inetpub\\wwwroot\\site\\wp-content\\plugins\\x\\x.php on line 5' ) )['items'][0];

		$this->assertSame( 'x.php', $item['file'] );
		$this->assertSame( 5, $item['line'] );
	}

	public function test_a_unc_path_in_a_message_is_reduced_to_its_base_name(): void {
		$item = $this->item_for( 'Cannot read \\\\fileserver\\share\\dir\\report.txt', 'x.php' );

		$this->assertSame( 'Cannot read report.txt', $item['message'] );
	}

	public function test_a_dot_dot_file_path_never_leaves_a_traversal_in_the_file_field(): void {
		$item = $this->parse( array( self::STAMP . ' PHP Warning:  boom in /var/www/html/wp-content/../../../etc/passwd on line 2' ) )['items'][0];

		$this->assertSame( 'passwd', $item['file'] );
		$this->assertStringNotContainsString( '..', $item['file'] );
	}

	public function test_a_leading_slash_never_reaches_the_file_field(): void {
		$item = $this->parse( array( self::STAMP . ' PHP Warning:  boom in /etc/passwd on line 2' ) )['items'][0];

		$this->assertSame( 'passwd', $item['file'] );
	}

	public function test_a_url_in_a_message_is_not_mangled(): void {
		$item = $this->item_for( 'Failed to fetch https://example.com/a/b/c.php: timeout', 'x.php' );

		$this->assertSame( 'Failed to fetch https://example.com/a/b/c.php: timeout', $item['message'] );
	}

	public function test_a_relative_path_in_a_message_is_kept(): void {
		$item = $this->item_for( 'Error in wp-content/plugins/x/x.php: boom', 'x.php' );

		$this->assertSame( 'Error in wp-content/plugins/x/x.php: boom', $item['message'] );
	}

	public function test_a_flattened_stack_trace_does_not_return_its_arguments(): void {
		$line = self::STAMP . " PHP Fatal error:  Uncaught Exception: boom in /var/www/html/wp-content/x.php:3 Stack trace: #0 /var/www/html/wp-content/x.php(12): login('admin', 'Hunter2xyz') #1 {main} thrown in /var/www/html/wp-content/x.php on line 20";

		$result = $this->parse( array( $line ) );
		$item   = $result['items'][0];

		$this->assertSame( 'Uncaught Exception: boom', $item['message'] );
		$this->assertSame( 'wp-content/x.php', $item['file'] );
		$this->assertSame( 3, $item['line'] );
		$encoded = (string) wp_json_encode( $result );
		$this->assertStringNotContainsString( 'Hunter2xyz', $encoded );
		$this->assertStringNotContainsString( 'login', $encoded );
	}

	public function test_a_flattened_frame_marker_without_a_stack_trace_label_is_also_cut(): void {
		$line = self::STAMP . " PHP Fatal error:  Uncaught Exception: boom in /var/www/html/wp-content/x.php:3 #0 x.php(12): login('admin', 'Hunter2xyz')";

		$item = $this->parse( array( $line ) )['items'][0];

		$this->assertSame( 'Uncaught Exception: boom', $item['message'] );
		$this->assertSame( 3, $item['line'] );
		$this->assertStringNotContainsString( 'Hunter2xyz', (string) wp_json_encode( $item ) );
	}

	public function test_a_multi_line_fatal_with_a_stack_trace_still_parses_as_before(): void {
		$lines = array(
			self::STAMP . ' PHP Fatal error:  Uncaught Exception: boom in /var/www/html/wp-content/x.php:3',
			'Stack trace:',
			'#0 /var/www/html/wp-content/y.php(12): login(\'admin\', \'Hunter2xyz\')',
			'#1 {main}',
			'  thrown in /var/www/html/wp-content/x.php on line 3',
		);

		$result = $this->parse( $lines );

		$this->assertCount( 1, $result['items'] );
		$this->assertSame( 'Uncaught Exception: boom', $result['items'][0]['message'] );
		$this->assertSame( 'wp-content/x.php', $result['items'][0]['file'] );
		$this->assertSame( 3, $result['items'][0]['line'] );
		$this->assertSame( 4, $result['skipped_lines'] );
	}
}
