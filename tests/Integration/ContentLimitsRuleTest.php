<?php
/**
 * The content-limits guardrail: size, encoding, block count and nesting of stored content.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\PostReader;
use Steadhand\Guardrails\Context;
use Steadhand\Guardrails\Result;
use Steadhand\Guardrails\Rules\ContentLimits;

/**
 * Covers SPEC §6.1 (post content size and nesting limits). Each limit is tested exactly at its value and one over it.
 */
final class ContentLimitsRuleTest extends TestCase {

	/**
	 * Runs the rule over the given content fields.
	 *
	 * @param array<string, mixed> $content Field name => value.
	 */
	private function evaluate( array $content ): Result {
		return ( new ContentLimits() )->evaluate(
			new Context( 'steadhand/update-post', 'steadhand_write_drafts', 1, array(), 'post', 0, $content )
		);
	}

	/**
	 * A chain of nested group blocks, $depth levels deep.
	 *
	 * @param int $depth Number of levels.
	 */
	private function nested( int $depth ): string {
		return str_repeat( '<!-- wp:group -->', $depth ) . str_repeat( '<!-- /wp:group -->', $depth );
	}

	public function test_content_exactly_at_the_byte_limit_passes(): void {
		$content = str_repeat( 'a', PostReader::MAX_CONTENT_BYTES );

		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( array( 'post_content' => $content ) )->mode() );
	}

	public function test_content_one_byte_over_the_limit_is_blocked_before_it_is_parsed(): void {
		$parsed = 0;
		add_filter(
			'block_parser_class',
			static function ( string $block_class ) use ( &$parsed ): string {
				++$parsed;
				return $block_class;
			}
		);
		$content = str_repeat( 'a', PostReader::MAX_CONTENT_BYTES + 1 );

		$result = $this->evaluate( array( 'post_content' => $content ) );

		$this->assertSame( Result::MODE_BLOCK, $result->mode() );
		$this->assertStringContainsString( 'over the limit of 262144 bytes', $result->message() );
		$this->assertSame( 0, $parsed, 'Oversized content must not be parsed.' );
	}

	public function test_invalid_utf8_is_blocked(): void {
		$result = $this->evaluate( array( 'post_content' => "ok \xff\xfe bad" ) );

		$this->assertSame( Result::MODE_BLOCK, $result->mode() );
		$this->assertStringContainsString( 'not valid UTF-8', $result->message() );
	}

	public function test_exactly_2000_blocks_passes_and_2001_is_blocked(): void {
		$void = '<!-- wp:spacer /-->';

		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( array( 'post_content' => str_repeat( $void, PostReader::MAX_BLOCKS ) ) )->mode() );

		$result = $this->evaluate( array( 'post_content' => str_repeat( $void, PostReader::MAX_BLOCKS + 1 ) ) );
		$this->assertSame( Result::MODE_BLOCK, $result->mode() );
		$this->assertStringContainsString( 'more than 2000 blocks', $result->message() );
	}

	public function test_nested_blocks_count_towards_the_block_limit(): void {
		// 1000 groups, each with one inner void block, makes 2000 blocks; one more group makes 2002.
		$inner = str_repeat( '<!-- wp:group --><!-- wp:spacer /--><!-- /wp:group -->', 1000 );
		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( array( 'post_content' => $inner ) )->mode() );

		$this->assertSame( Result::MODE_BLOCK, $this->evaluate( array( 'post_content' => $inner . '<!-- wp:spacer /--><!-- wp:spacer /-->' ) )->mode() );
	}

	public function test_exactly_20_levels_of_nesting_passes_and_21_is_blocked(): void {
		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( array( 'post_content' => $this->nested( PostReader::MAX_DEPTH ) ) )->mode() );

		$result = $this->evaluate( array( 'post_content' => $this->nested( PostReader::MAX_DEPTH + 1 ) ) );
		$this->assertSame( Result::MODE_BLOCK, $result->mode() );
		$this->assertStringContainsString( 'more than 20 levels deep', $result->message() );
	}

	public function test_a_non_text_field_is_blocked(): void {
		$this->assertSame( Result::MODE_BLOCK, $this->evaluate( array( 'post_content' => array( '<p>x</p>' ) ) )->mode() );
	}

	public function test_every_field_is_checked_not_only_the_first(): void {
		$content = array(
			'post_title'   => 'Fine',
			'post_content' => str_repeat( '<!-- wp:spacer /-->', PostReader::MAX_BLOCKS + 1 ),
		);

		$this->assertSame( Result::MODE_BLOCK, $this->evaluate( $content )->mode() );
	}

	public function test_classic_html_within_the_limits_passes(): void {
		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( array( 'post_content' => '<p>Hello</p>' ) )->mode() );
		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( array() )->mode() );
	}
}
