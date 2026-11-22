<?php
/**
 * The block-markup guardrail: the delimiter structure WordPress reads, checked without JavaScript.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Guardrails\Context;
use Steadhand\Guardrails\Result;
use Steadhand\Guardrails\Rules\BlockMarkup;

/**
 * Covers SPEC §6.1 (block markup must re-validate, structurally). The rule is checked against core's own parser where a
 * case needs it.
 */
final class BlockMarkupRuleTest extends TestCase {

	/**
	 * Runs the rule over one content field.
	 *
	 * @param string $markup Content.
	 */
	private function evaluate( string $markup ): Result {
		return ( new BlockMarkup() )->evaluate(
			new Context( 'steadhand/update-post', 'steadhand_write_drafts', 1, array(), 'post', 0, array( 'post_content' => $markup ) )
		);
	}

	public function test_valid_nested_markup_passes(): void {
		$markup = "<!-- wp:group -->\n<div class=\"wp-block-group\"><!-- wp:paragraph -->\n<p>x</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->";

		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( $markup )->mode() );
	}

	public function test_classic_html_without_any_delimiter_passes(): void {
		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( '<p>Hello <strong>there</strong></p>' )->mode() );
		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( '' )->mode() );
	}

	public function test_void_blocks_pass_with_and_without_attributes(): void {
		$markup = '<!-- wp:spacer {"height":"10px"} /--><!-- wp:separator /-->';

		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( $markup )->mode() );
	}

	public function test_a_namespaced_block_passes(): void {
		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( '<!-- wp:my-plugin/card {"tone":"calm"} --><div></div><!-- /wp:my-plugin/card -->' )->mode() );
	}

	public function test_an_unclosed_block_is_named(): void {
		$result = $this->evaluate( '<!-- wp:group --><div>x</div>' );

		$this->assertSame( Result::MODE_BLOCK, $result->mode() );
		$this->assertStringContainsString( 'group', $result->message() );
		$this->assertStringContainsString( 'never closed', $result->message() );
		$this->assertStringContainsString( '<!-- /wp:group -->', $result->message(), 'The message says what to add.' );
	}

	public function test_closers_in_the_wrong_order_are_refused(): void {
		$result = $this->evaluate( '<!-- wp:group --><!-- wp:columns --><!-- /wp:group --><!-- /wp:columns -->' );

		$this->assertSame( Result::MODE_BLOCK, $result->mode() );
		$this->assertStringContainsString( 'out of order', $result->message() );
		$this->assertStringContainsString( 'columns', $result->message() );
	}

	public function test_a_stray_closer_is_refused(): void {
		$result = $this->evaluate( '<p>x</p><!-- /wp:paragraph -->' );

		$this->assertSame( Result::MODE_BLOCK, $result->mode() );
		$this->assertStringContainsString( 'no opening comment', $result->message() );
	}

	public function test_a_closer_for_a_block_that_is_not_open_is_refused(): void {
		$result = $this->evaluate( '<!-- wp:group --><p>x</p><!-- /wp:columns --><!-- /wp:group -->' );

		$this->assertSame( Result::MODE_BLOCK, $result->mode() );
		$this->assertStringContainsString( 'not open', $result->message() );
	}

	/**
	 * Attributes that are not one JSON object are refused, and the message says so.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function invalid_attributes(): array {
		return array(
			'missing value'  => array( '<!-- wp:paragraph {"align":} --><p>x</p><!-- /wp:paragraph -->' ),
			'bare word'      => array( '<!-- wp:paragraph {bad json} --><p>x</p><!-- /wp:paragraph -->' ),
			'single quotes'  => array( "<!-- wp:paragraph {'align':'left'} --><p>x</p><!-- /wp:paragraph -->" ),
			'trailing comma' => array( '<!-- wp:paragraph {"align":"left",} --><p>x</p><!-- /wp:paragraph -->' ),
			'void block'     => array( '<!-- wp:spacer {"height":} /-->' ),
		);
	}

	/**
	 * Runs the rule over markup whose attributes are not valid JSON.
	 *
	 * @dataProvider invalid_attributes
	 *
	 * @param string $markup Content with invalid attributes.
	 */
	public function test_invalid_json_attributes_are_refused_with_a_useful_message( string $markup ): void {
		$result = $this->evaluate( $markup );

		$this->assertSame( Result::MODE_BLOCK, $result->mode() );
		$this->assertStringContainsString( 'not valid JSON', $result->message() );
	}

	public function test_a_delimiter_with_a_bad_name_is_refused_as_malformed(): void {
		$result = $this->evaluate( '<!-- wp:Paragraph --><p>x</p><!-- /wp:Paragraph -->' );

		$this->assertSame( Result::MODE_BLOCK, $result->mode() );
		$this->assertStringContainsString( 'malformed', $result->message() );
		$this->assertStringContainsString( 'plain text', $result->message() );
	}

	/**
	 * Comments that look like delimiters but that WordPress does not read as blocks are refused, as the loose count finds
	 * them.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function malformed_spellings(): array {
		return array(
			'no space after comment' => array( '<!--wp:paragraph --><p>x</p><!-- /wp:paragraph -->' ),
			'no space after wp:'     => array( '<!-- wp: paragraph --><p>x</p><!-- /wp:paragraph -->' ),
			'closer with space'      => array( '<!-- wp:paragraph --><p>x</p><!--/wp:paragraph -->' ),
		);
	}

	/**
	 * Runs the rule over markup with a malformed delimiter.
	 *
	 * @dataProvider malformed_spellings
	 *
	 * @param string $markup Content with a malformed delimiter.
	 */
	public function test_a_malformed_delimiter_is_refused( string $markup ): void {
		$this->assertSame( Result::MODE_BLOCK, $this->evaluate( $markup )->mode() );
	}

	public function test_a_comment_that_contains_a_delimiter_is_read_as_core_reads_it(): void {
		$markup = '<!-- a note <!-- wp:paragraph --> -->';

		// Core reads the inner comment as an opener that is never closed, so the rule must refuse it as well.
		$named = array_values(
			array_filter(
				parse_blocks( $markup ),
				static fn( array $block ): bool => null !== $block['blockName']
			)
		);
		$this->assertSame( 'core/paragraph', $named[0]['blockName'] ?? null, 'Control: core sees a paragraph block.' );

		$result = $this->evaluate( $markup );
		$this->assertSame( Result::MODE_BLOCK, $result->mode() );
		$this->assertStringContainsString( 'never closed', $result->message() );
	}

	public function test_a_valid_block_inside_a_comment_with_its_closer_is_balanced_as_core_balances_it(): void {
		$markup = '<!-- note --><!-- wp:paragraph --><p>x</p><!-- /wp:paragraph --><!-- end -->';

		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( $markup )->mode() );
	}

	public function test_two_hundred_kilobytes_of_unclosed_openers_are_refused_quickly(): void {
		$markup = str_repeat( '<!-- wp:a {', 20000 );
		$this->assertGreaterThan( 200000, strlen( $markup ) );

		$start  = microtime( true );
		$result = $this->evaluate( $markup );
		$took   = microtime( true ) - $start;

		$this->assertSame( Result::MODE_BLOCK, $result->mode() );
		$this->assertLessThan( 1.0, $took, 'The check must return well under a second on hostile input.' );
	}

	public function test_a_refusal_names_the_field(): void {
		$result = $this->evaluate( '<!-- wp:group -->' );

		$this->assertStringStartsWith( 'The post_content field', $result->message() );
	}


	public function test_an_upper_case_wp_comment_is_not_a_delimiter_and_passes(): void {
		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( "<p>x</p>\n<!-- WP:Engine cache note -->" )->mode() );
	}

	public function test_a_comment_with_no_space_after_the_opening_is_not_a_delimiter_and_passes(): void {
		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( '<p>x</p><!--wp:x-->' )->mode() );
	}

	public function test_a_space_after_the_opening_still_counts_as_a_delimiter(): void {
		$this->assertSame( Result::MODE_BLOCK, $this->evaluate( '<!-- wp:paragraph --><p>x</p>' )->mode() );
	}
}
