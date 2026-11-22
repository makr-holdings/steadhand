<?php
/**
 * S8: the change log keeps a digest of post content, never the text, and a throwing log_input still writes a row.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Pipeline\Pipeline;
use Steadhand\Log\DbLogger;

/**
 * Integration tests for the change-log digest of post content (S8).
 */
final class LogDigestTest extends PostWriteTestCase {

	/**
	 * The digest as the log stores it: the SHA-256 in groups of eight hex digits, separated by spaces.
	 *
	 * @param string $text Text.
	 */
	private static function digest( string $text ): string {
		return implode( ' ', str_split( hash( 'sha256', $text ), 8 ) );
	}

	public function test_a_large_create_stores_the_title_status_slug_and_a_content_digest_and_never_the_text(): void {
		$agent   = $this->act_as_agent();
		$marker  = 'MARKER-' . strtolower( wp_generate_password( 12, false ) );
		$content = '<p>' . $marker . '</p>' . str_repeat( '<p>' . str_repeat( 'lorem ipsum ', 20 ) . '</p>', 780 );
		$this->assertGreaterThan( 190000, strlen( $content ) );

		$result = $this->execute_ability(
			'steadhand/create-post',
			array(
				'title'   => 'Big post',
				'content' => $content,
				'slug'    => 'big-post-digest',
			)
		);

		$this->assertIsArray( $result );
		$row   = $this->latest_row( $agent );
		$input = json_decode( (string) $row['input'], true );
		$this->assertSame( 'Big post', $input['title'] );
		$this->assertSame( 'draft', $input['status'] );
		$this->assertSame( 'big-post-digest', $input['slug'] );
		$this->assertSame( strlen( $content ), $input['content']['bytes'] );
		$this->assertSame( self::digest( $content ), $input['content']['sha256'] );
		$this->assertStringNotContainsString( $marker, (string) $row['input'], 'The stored input must not contain the content text.' );
		$this->assertStringNotContainsString( 'lorem ipsum', (string) $row['input'] );
	}

	public function test_a_long_excerpt_is_a_digest_in_the_log_and_a_short_one_is_kept(): void {
		$agent = $this->act_as_agent();
		$long  = str_repeat( 'e', 600 );

		$this->execute_ability(
			'steadhand/create-post',
			array(
				'title'   => 'Excerpts',
				'excerpt' => $long,
			)
		);
		$input = json_decode( (string) $this->latest_row( $agent )['input'], true );
		$this->assertSame(
			array(
				'bytes'  => 600,
				'sha256' => self::digest( $long ),
			),
			$input['excerpt']
		);

		$this->execute_ability(
			'steadhand/create-post',
			array(
				'title'   => 'Short',
				'excerpt' => 'short one',
			)
		);
		$input = json_decode( (string) $this->latest_row( $agent )['input'], true );
		$this->assertSame( 'short one', $input['excerpt'] );
	}

	public function test_a_log_input_that_throws_still_produces_a_row(): void {
		$agent = $this->act_as_agent();

		$result = ( new Pipeline( new DbLogger() ) )->run( new ThrowingLogInputAbility(), array( 'note' => 'x' ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
		$row = $this->latest_row( $agent );
		$this->assertSame( 'steadhand/zz-throwing-log', $row['ability'] );
		$this->assertSame( 'ok', $row['result'] );
		$this->assertSame( '{}', $row['input'], 'A throwing log_input stores the empty input.' );
	}
}
