<?php
/**
 * Property-style checks: hostile site data must never produce output that fails the get-diagnostics output schema.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Diagnostics\Cron;
use Steadhand\Diagnostics\PhpErrorParser;
use Steadhand\Diagnostics\SiteHealth;

/**
 * Covers SPEC §5 (get-diagnostics output schema): a value from site data that exceeds its schema bound is clamped or
 * cut, never allowed to fail validation.
 */
final class DiagnosticsBoundsTest extends TestCase {

	private const ABILITY = 'steadhand/get-diagnostics';

	/**
	 * Validates one section against the output schema of the ability.
	 *
	 * @param string $section Section name.
	 * @param mixed  $value   Section value.
	 */
	private function assertSectionValid( string $section, mixed $value ): void {
		$schema = wp_get_ability( self::ABILITY )->get_output_schema()['properties'][ $section ];
		$valid  = rest_validate_value_from_schema( $value, $schema, $section );

		$this->assertNotInstanceOf( \WP_Error::class, $valid, "The $section section must match the output schema." );
		$this->assertNotFalse( wp_json_encode( $value ), "The $section section must encode as JSON." );
	}

	/**
	 * Hostile cron options: extreme timestamps, long and malformed hook names, and secret-looking names.
	 *
	 * @return array<int, array<int, array<int|string, mixed>>>
	 */
	public static function hostile_cron_options(): array {
		$event = array(
			'k' => array(
				'schedule' => false,
				'args'     => array( 'SECRET' ),
			),
		);
		$now   = time();

		return array(
			array( PHP_INT_MIN => array( 'a' => $event ) ),
			array( PHP_INT_MAX => array( 'a' => $event ) ),
			array( 1 => array( 'a' => $event ) ),
			array( 0 => array( 'a' => $event ) ),
			array( $now + 100 * 365 * 86400 => array( 'a' => $event ) ),
			array( $now - 100 * 365 * 86400 => array( str_repeat( 'h', 5000 ) => $event ) ),
			array( $now - 900 => array( str_repeat( "\u{e9}", 400 ) => $event ) ),
			array( $now - 900 => array( "\xC3\x28 bad" => $event ) ),
			array( $now - 900 => array( "\x1b[31mred\x1b[0m" => $event ) ),
			array( $now - 900 => array( 'password=' . str_repeat( 'x', 3000 ) => $event ) ),
			array( $now - 900 => array( "wp\0hook" => $event ) ),
			array(
				'version'  => 2,
				$now - 900 => array( 'a' => array( 'x' => 'not-an-event' ) ),
			),
		);
	}

	/**
	 * The cron section stays valid for every hostile stored option.
	 */
	public function test_the_cron_section_is_valid_for_hostile_stored_options(): void {
		foreach ( self::hostile_cron_options() as $option ) {
			$option['version'] = $option['version'] ?? 2;
			$section           = Cron::report( $option, time(), false );

			$this->assertSectionValid( 'cron', $section );
			$this->assertLessThanOrEqual( Cron::MAX_SECONDS, $section['overdue'][0]['overdue_seconds'] ?? 0 );
		}
	}

	/**
	 * Hostile log lines: long messages and files, invalid bytes, control characters, secrets and huge line numbers.
	 *
	 * @return string[]
	 */
	public static function hostile_log_lines(): array {
		$stamp = '[21-Nov-2026 10:00:00 UTC] PHP ';

		return array(
			$stamp . 'Warning:  ' . str_repeat( 'w', 9000 ) . ' in x.php on line 3',
			$stamp . 'Warning:  ' . str_repeat( "\u{e9}", 600 ) . ' in ' . str_repeat( 'f', 900 ) . '.php on line 999999999999',
			$stamp . 'Notice:  bad ' . "\xFF\xFE" . ' in x.php on line 1',
			$stamp . 'Deprecated:  ' . "\x1b[2J\x07ctl" . ' in x.php on line 0',
			$stamp . 'Fatal error:  password=' . str_repeat( 'A', 4000 ) . ' in /etc/passwd:12',
			$stamp . 'Parse error:  x in ../../../../etc/shadow on line 7',
			$stamp . 'Warning:  x in C:\\inetpub\\wwwroot\\site\\wp-content\\plugins\\x\\x.php on line 5',
			$stamp . 'Warning:  x in \\\\server\\share\\dir\\y.php on line 6',
		);
	}

	/**
	 * The php_errors section stays valid for every hostile log line.
	 */
	public function test_the_php_errors_section_is_valid_for_hostile_log_lines(): void {
		$parser = new PhpErrorParser( '/var/www/site/wp-content', '/var/www/site' );
		$result = $parser->parse( implode( "\n", self::hostile_log_lines() ), 50 );

		$this->assertSectionValid(
			'php_errors',
			array(
				'available'     => true,
				'reason'        => 'ok',
				'scanned_bytes' => 0,
				'skipped_lines' => $result['skipped_lines'],
				'items'         => $result['items'],
			)
		);
		$this->assertNotEmpty( $result['items'] );
	}

	/**
	 * Hostile Site Health transients: counts out of range, wrong types and invalid JSON.
	 *
	 * @return string[]
	 */
	public static function hostile_site_health(): array {
		return array(
			'{"good":999999999999,"recommended":0,"critical":0}',
			'{"good":-1,"recommended":0,"critical":0}',
			'{"good":"3","recommended":0,"critical":0}',
			'{"good":1,"recommended":2}',
			'not json',
			'[]',
		);
	}

	/**
	 * The site_health section stays valid for every hostile transient.
	 */
	public function test_the_site_health_section_is_valid_for_hostile_transients(): void {
		foreach ( self::hostile_site_health() as $stored ) {
			set_transient( SiteHealth::TRANSIENT, $stored, HOUR_IN_SECONDS );
			try {
				$this->assertSectionValid( 'site_health', SiteHealth::report() );
			} finally {
				delete_transient( SiteHealth::TRANSIENT );
			}
		}
	}
}
