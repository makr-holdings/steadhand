<?php
/**
 * Unit tests for the PHP error log reader: where it looks, what it refuses, and how much it reads.
 *
 * Every test builds its own files under get_temp_dir() and removes exactly those files afterwards. No recursive
 * deletion is used.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Steadhand\Diagnostics\ErrorLog;

/**
 * Covers SPEC §5 get-diagnostics for the php_errors section: logging off, missing and unreadable files, directories,
 * symlinks and ../ escapes, the tail-only read, and parsing through the reader.
 */
final class ErrorLogTest extends TestCase {

	private const STAMP = '[21-Nov-2026 10:00:00 UTC]';

	/**
	 * Root of this test's files. Created in set_up and removed in tear_down.
	 *
	 * @var string
	 */
	private string $sandbox = '';

	/**
	 * Files this test created, removed in tear_down.
	 *
	 * @var string[]
	 */
	private array $files = array();

	/**
	 * Directories this test created, removed in tear_down (deepest first).
	 *
	 * @var string[]
	 */
	private array $dirs = array();

	/**
	 * Builds the sandbox: an "allowed" site root with a wp-content folder, and an "elsewhere" folder outside it.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->sandbox = rtrim( get_temp_dir(), '/\\' ) . '/steadhand-log-test-' . uniqid( '', true );
		$this->make_dir( $this->sandbox );
		$this->make_dir( $this->sandbox . '/site' );
		$this->make_dir( $this->sandbox . '/site/wp-content' );
		$this->make_dir( $this->sandbox . '/elsewhere' );
	}

	/**
	 * Removes exactly the files and directories this test created.
	 */
	protected function tearDown(): void {
		foreach ( array_reverse( $this->files ) as $file ) {
			if ( is_link( $file ) || is_file( $file ) ) {
				unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Removes a test file this test created.
			}
		}
		foreach ( array_reverse( $this->dirs ) as $dir ) {
			if ( is_dir( $dir ) ) {
				rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Removes an empty test directory this test created.
			}
		}
		$this->files = array();
		$this->dirs  = array();

		parent::tearDown();
	}

	private function make_dir( string $path ): void {
		mkdir( $path, 0777, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Creates a test folder inside the sandbox.
		$this->dirs[] = $path;
	}

	/**
	 * Writes a file this test owns, and records it for removal.
	 *
	 * @param string $path     Path.
	 * @param string $contents Contents.
	 * @return string The path.
	 */
	private function write( string $path, string $contents ): string {
		file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writes a test file inside the sandbox.
		$this->files[] = $path;

		return $path;
	}

	private function site_root(): string {
		return $this->sandbox . '/site';
	}

	private function content_dir(): string {
		return $this->sandbox . '/site/wp-content';
	}

	/**
	 * A reader whose allowed folders are the sandbox site's folders.
	 *
	 * @param bool|string $setting WP_DEBUG_LOG value.
	 */
	private function reader( mixed $setting ): ErrorLog {
		return new ErrorLog( $setting, $this->content_dir(), $this->site_root() );
	}

	public function test_logging_off_when_debug_log_is_false(): void {
		$this->write( $this->content_dir() . '/debug.log', self::STAMP . ' PHP Notice:  a in x on line 1' . "\n" );

		$section = $this->reader( false )->read( 20 );

		$this->assertSame( 'logging_off', $section['reason'] );
		$this->assertFalse( $section['available'] );
		$this->assertSame( 0, $section['scanned_bytes'] );
		$this->assertSame( array(), $section['items'] );
	}

	public function test_an_empty_string_setting_means_logging_is_off(): void {
		$this->assertSame( 'logging_off', $this->reader( '' )->read( 20 )['reason'] );
	}

	public function test_no_log_file_when_the_default_log_does_not_exist(): void {
		$section = $this->reader( true )->read( 20 );

		$this->assertSame( 'no_log_file', $section['reason'] );
		$this->assertFalse( $section['available'] );
	}

	public function test_no_log_file_when_the_configured_path_does_not_exist(): void {
		$section = $this->reader( $this->sandbox . '/site/wp-content/missing.log' )->read( 20 );

		$this->assertSame( 'no_log_file', $section['reason'] );
	}

	public function test_a_directory_instead_of_a_file_is_not_a_log_file(): void {
		$dir = $this->sandbox . '/site/wp-content/debug.log';
		$this->make_dir( $dir );

		$this->assertSame( 'no_log_file', $this->reader( true )->read( 20 )['reason'] );
	}

	public function test_a_symlink_to_a_file_outside_the_allowed_folders_is_refused(): void {
		$outside = $this->write( $this->sandbox . '/elsewhere/other.log', self::STAMP . " PHP Notice:  secret in x on line 1\n" );
		$link    = $this->content_dir() . '/debug.log';
		symlink( $outside, $link ); // phpcs:ignore WordPress.WP.AlternativeFunctions.symlink_symlink -- Creates the test link inside the sandbox.
		$this->files[] = $link;

		$section = $this->reader( true )->read( 20 );

		$this->assertSame( 'outside_allowed_location', $section['reason'] );
		$this->assertSame( array(), $section['items'] );
		$this->assertSame( 0, $section['scanned_bytes'] );
	}

	public function test_a_symlink_to_a_file_inside_the_allowed_folders_is_refused_too(): void {
		$inside = $this->write( $this->content_dir() . '/real.log', self::STAMP . " PHP Notice:  inside in x on line 1\n" );
		$link   = $this->content_dir() . '/debug.log';
		symlink( $inside, $link ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_symlink -- Creates the test link inside the sandbox.
		$this->files[] = $link;

		$section = $this->reader( true )->read( 20 );

		$this->assertSame( 'outside_allowed_location', $section['reason'] );
		$this->assertSame( array(), $section['items'] );
	}

	public function test_logging_off_ignores_the_php_error_log_directive(): void {
		$server_wide = $this->write( $this->sandbox . '/elsewhere/server.log', self::STAMP . " PHP Warning:  server wide in x on line 1\n" );
		$previous    = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $server_wide ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Points the error_log directive at a test file to prove it is never read.

		try {
			$section = $this->reader( false )->read( 20 );
		} finally {
			ini_set( 'error_log', $previous ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- Restores the value read above.
		}

		$this->assertSame( 'logging_off', $section['reason'] );
		$this->assertSame( array(), $section['items'] );
	}

	public function test_a_dot_dot_path_that_escapes_the_allowed_folders_is_refused(): void {
		$this->write( $this->sandbox . '/elsewhere/other.log', self::STAMP . " PHP Notice:  x in y on line 1\n" );
		$escaping = $this->content_dir() . '/../../elsewhere/other.log';

		$section = $this->reader( $escaping )->read( 20 );

		$this->assertSame( 'outside_allowed_location', $section['reason'] );
		$this->assertSame( array(), $section['items'] );
	}

	public function test_a_configured_path_outside_the_folders_is_read_when_it_is_written_in_canonical_form(): void {
		$path = $this->write( $this->sandbox . '/elsewhere/owner.log', self::STAMP . " PHP Notice:  owner chose this in x on line 1\n" );

		$section = $this->reader( (string) realpath( $path ) )->read( 20 );

		$this->assertSame( 'ok', $section['reason'] );
		$this->assertSame( 'owner chose this', $section['items'][0]['message'] );
	}

	public function test_an_unreadable_file_is_reported(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Running as root: a file with mode 000 is still readable, so the unreadable case cannot be tested.' );
		}
		$path = $this->write( $this->content_dir() . '/debug.log', self::STAMP . " PHP Notice:  a in x on line 1\n" );
		chmod( $path, 0000 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Makes the test file unreadable for one assertion; restored right after.

		try {
			$section = $this->reader( true )->read( 20 );
		} finally {
			chmod( $path, 0600 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Restores access so the file can be removed.
		}

		$this->assertSame( 'unreadable', $section['reason'] );
		$this->assertFalse( $section['available'] );
	}

	public function test_a_log_with_all_six_levels_and_both_location_forms_is_parsed(): void {
		$path = $this->write(
			$this->content_dir() . '/debug.log',
			implode(
				"\n",
				array(
					self::STAMP . ' PHP Fatal error:  Uncaught Exception: boom in ' . $this->site_root() . '/wp-content/x.php:7',
					'Stack trace:',
					'#0 {main}',
					'  thrown in ' . $this->site_root() . '/wp-content/x.php on line 7',
					self::STAMP . ' PHP Parse error:  syntax in ' . $this->site_root() . '/wp-content/p.php on line 2',
					self::STAMP . ' PHP Recoverable fatal error:  r in ' . $this->site_root() . '/wp-content/r.php on line 3',
					self::STAMP . ' PHP Warning:  w in ' . $this->site_root() . '/wp-content/w.php on line 4',
					self::STAMP . ' PHP Notice:  n in ' . $this->site_root() . '/wp-content/n.php on line 5',
					self::STAMP . ' PHP Deprecated:  d in ' . $this->site_root() . '/wp-content/d.php on line 6',
					'',
				)
			)
		);

		$section = $this->reader( true )->read( 20 );

		$this->assertSame( 'ok', $section['reason'] );
		$this->assertTrue( $section['available'] );
		$this->assertSame( filesize( $path ), $section['scanned_bytes'] );
		$this->assertSame( 3, $section['skipped_lines'] );
		$levels = array_column( $section['items'], 'level' );
		sort( $levels );
		$this->assertSame( array( 'deprecated', 'fatal', 'notice', 'parse', 'recoverable', 'warning' ), $levels );
		$this->assertStringNotContainsString( $this->sandbox, (string) wp_json_encode( $section ) );
	}

	public function test_free_form_lines_and_secrets_in_them_never_leave_the_reader(): void {
		$this->write(
			$this->content_dir() . '/debug.log',
			implode(
				"\n",
				array(
					'[21-Nov-2026 10:00:00 UTC] My plugin: user password=hunter2 token=abc123',
					self::STAMP . ' PHP Warning:  curl failed for https://user:s3cret@api.example.com/?api_key=ZZZZ in ' . $this->site_root() . '/wp-content/plugins/x/x.php on line 5',
					'',
				)
			)
		);

		$section = $this->reader( true )->read( 20 );
		$encoded = (string) wp_json_encode( $section );

		$this->assertSame( 1, $section['skipped_lines'] );
		$this->assertStringNotContainsString( 'hunter2', $encoded );
		$this->assertStringNotContainsString( 'abc123', $encoded );
		$this->assertStringNotContainsString( 's3cret', $encoded );
		$this->assertStringNotContainsString( 'ZZZZ', $encoded );
		$this->assertStringNotContainsString( $this->sandbox, $encoded );
		$this->assertSame( 'wp-content/plugins/x/x.php', $section['items'][0]['file'] );
	}

	public function test_duplicates_are_counted_and_the_newest_come_first(): void {
		$this->write(
			$this->content_dir() . '/debug.log',
			implode(
				"\n",
				array(
					self::STAMP . ' PHP Notice:  first in ' . $this->site_root() . '/wp-content/a.php on line 1',
					'[21-Nov-2026 10:01:00 UTC] PHP Notice:  first in ' . $this->site_root() . '/wp-content/a.php on line 1',
					'[21-Nov-2026 10:02:00 UTC] PHP Warning:  second in ' . $this->site_root() . '/wp-content/b.php on line 2',
				)
			)
		);

		$items = $this->reader( true )->read( 20 )['items'];

		$this->assertSame( 'second', $items[0]['message'] );
		$this->assertSame( 'first', $items[1]['message'] );
		$this->assertSame( 2, $items[1]['count'] );
		$this->assertSame( '2026-11-21T10:01:00Z', $items[1]['time'] );
	}

	public function test_max_errors_limits_the_items(): void {
		$lines = array();
		for ( $i = 1; $i <= 6; $i++ ) {
			$lines[] = self::STAMP . " PHP Notice:  n$i in " . $this->site_root() . "/wp-content/a.php on line $i";
		}
		$this->write( $this->content_dir() . '/debug.log', implode( "\n", $lines ) );

		$items = $this->reader( true )->read( 2 )['items'];

		$this->assertSame( array( 'n6', 'n5' ), array_column( $items, 'message' ) );
	}

	public function test_only_the_tail_of_a_two_megabyte_log_is_read(): void {
		$early  = self::STAMP . ' PHP Warning:  EARLY_MARKER in ' . $this->site_root() . "/wp-content/a.php on line 1\n";
		$late   = self::STAMP . ' PHP Warning:  LATE_MARKER in ' . $this->site_root() . "/wp-content/a.php on line 2\n";
		$filler = str_repeat( '[21-Nov-2026 10:00:00 UTC] PHP Notice:  padding padding padding padding padding padding in ' . $this->site_root() . "/wp-content/p.php on line 3\n", 20000 );
		$path   = $this->write( $this->content_dir() . '/debug.log', $early . $filler . $late );
		$this->assertGreaterThan( 2000000, filesize( $path ) );

		$start   = microtime( true );
		$section = $this->reader( true )->read( 50 );
		$elapsed = microtime( true ) - $start;

		$this->assertSame( 'ok', $section['reason'] );
		$this->assertLessThanOrEqual( ErrorLog::TAIL_BYTES, $section['scanned_bytes'] );
		$this->assertLessThan( 1.0, $elapsed );
		$messages = array_column( $section['items'], 'message' );
		$this->assertContains( 'LATE_MARKER', $messages );
		$this->assertNotContains( 'EARLY_MARKER', $messages );
	}

	public function test_a_log_with_invalid_utf8_nul_bytes_and_a_long_line_does_not_break_the_call(): void {
		$this->write(
			$this->content_dir() . '/debug.log',
			self::STAMP . " PHP Warning:  bad \xFF bytes in " . $this->site_root() . "/wp-content/a.php on line 1\n"
			. self::STAMP . " PHP Warning:  nul\0byte in " . $this->site_root() . "/wp-content/a.php on line 2\n"
			. self::STAMP . ' PHP Warning:  ' . str_repeat( 'x', 20000 ) . ' in ' . $this->site_root() . "/wp-content/a.php on line 3\n"
		);

		$section = $this->reader( true )->read( 20 );

		$this->assertSame( 'ok', $section['reason'] );
		$this->assertNotFalse( wp_json_encode( $section ) );
		$this->assertCount( 1, $section['items'] );
		$this->assertSame( 2, $section['skipped_lines'] );
	}

	public function test_an_empty_log_file_is_well_formed(): void {
		$this->write( $this->content_dir() . '/debug.log', '' );

		$section = $this->reader( true )->read( 20 );

		$this->assertSame(
			array(
				'available'     => true,
				'reason'        => 'ok',
				'scanned_bytes' => 0,
				'skipped_lines' => 0,
				'items'         => array(),
			),
			$section
		);
	}

	public function test_the_factory_reads_the_configured_constants_and_returns_a_well_formed_section(): void {
		$section = ErrorLog::from_constants()->read( 20 );

		$this->assertContains( $section['reason'], array( 'ok', 'logging_off', 'no_log_file', 'unreadable', 'outside_allowed_location' ) );
		$this->assertIsBool( $section['available'] );
		$this->assertIsArray( $section['items'] );
	}

	public function test_a_stream_wrapper_value_is_refused_before_any_filesystem_call_or_request(): void {
		ProbeStream::$calls = 0;
		$requests           = 0;
		$recorder           = static function ( $preempt ) use ( &$requests ) {
			++$requests;

			return $preempt;
		};

		add_filter( 'pre_http_request', $recorder, PHP_INT_MAX, 3 );
		stream_wrapper_register( 'steadhand-probe', ProbeStream::class );

		try {
			foreach ( array( 'steadhand-probe://x/debug.log', 'ftp://127.0.0.1:9/debug.log', 'http://127.0.0.1:9/debug.log', 'data://text/plain,hi', 'php://memory', 'phar:///tmp/x.phar/y', 'glob:///tmp/*.log' ) as $setting ) {
				$this->assertSame( 'outside_allowed_location', $this->reader( $setting )->read( 5 )['reason'], $setting );
			}
		} finally {
			stream_wrapper_unregister( 'steadhand-probe' );
			remove_filter( 'pre_http_request', $recorder, PHP_INT_MAX );
		}

		$this->assertSame( 0, ProbeStream::$calls, 'No filesystem call may reach a stream wrapper.' );
		$this->assertSame( 0, $requests, 'No HTTP request may be attempted.' );
	}

	public function test_a_relative_path_is_refused_because_core_has_no_relative_form(): void {
		$this->assertSame( 'outside_allowed_location', $this->reader( 'debug.log' )->read( 5 )['reason'] );
	}

	public function test_a_path_with_a_nul_byte_is_refused(): void {
		$this->assertSame( 'outside_allowed_location', $this->reader( "/tmp/x\0.log" )->read( 5 )['reason'] );
	}

	public function test_proc_sys_and_dev_paths_are_refused_even_when_they_are_the_configured_path(): void {
		foreach ( array( '/proc/1/environ', '/proc/self/status', '/dev/zero', '/sys/kernel/notes' ) as $path ) {
			$this->assertSame( 'outside_allowed_location', $this->reader( $path )->read( 5 )['reason'], $path );
		}
	}

	public function test_core_treats_one_and_true_as_the_default_log_and_nothing_else_as_a_path(): void {
		$this->assertTrue( ErrorLog::normalise( 1 ) );
		$this->assertTrue( ErrorLog::normalise( '1' ) );
		$this->assertTrue( ErrorLog::normalise( true ) );
		$this->assertTrue( ErrorLog::normalise( 'TRUE' ) );
		$this->assertFalse( ErrorLog::normalise( 2 ) );
		$this->assertFalse( ErrorLog::normalise( false ) );
		$this->assertSame( '', ErrorLog::normalise( '' ) );
		$this->assertSame( '/a/b.log', ErrorLog::normalise( '/a/b.log' ) );
	}

	public function test_integer_one_reads_the_default_log_as_core_does(): void {
		$this->write( $this->content_dir() . '/debug.log', self::STAMP . " PHP Warning:  integer one in x.php on line 2\n" );

		$section = $this->reader( 1 )->read( 5 );

		$this->assertSame( 'ok', $section['reason'] );
		$this->assertSame( 'integer one', $section['items'][0]['message'] );
	}
}
