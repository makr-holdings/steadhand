<?php
/**
 * The steadhand/get-post ability, end to end over MCP.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Pipeline\Pipeline;

/**
 * Covers SPEC §5 (get-post), §10 and §14.1 (D6) for the single-post tool.
 */
final class GetPostTest extends ReadToolTestCase {

	private const TOOL_NAME = 'steadhand-get-post';

	/**
	 * Content with block markup, a shortcode, an entity and multi-byte text, to prove the raw bytes come back.
	 */
	private const RAW = "<!-- wp:paragraph -->\n<p>Hello &amp; welcome, \"friend\" \xc3\xa9t\xc3\xa9</p>\n<!-- /wp:paragraph -->\n\n[gallery ids=\"1,2\"]\n";

	public function test_returns_the_raw_content_exactly_as_stored(): void {
		$this->act_as_agent();
		$id = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => self::RAW,
				'post_excerpt' => 'Short & sweet',
			)
		);

		$output = $this->output( self::TOOL_NAME, array( 'id' => $id ) );

		$this->assertSame( self::RAW, get_post( $id )->post_content, 'Control: the fixture is stored as written.' );
		$this->assertSame( self::RAW, $output['content'] );
		$this->assertSame( get_post( $id )->post_excerpt, $output['excerpt_raw'], 'The excerpt is returned as stored.' );
		$this->assertSame( 'draft', $output['status'] );
		$this->assertSame( 'Site Owner', $output['author']['name'] );
		$this->assertSame( $this->admin_id(), $output['author']['id'] );
	}

	public function test_returns_exactly_the_documented_fields_and_nothing_else(): void {
		$this->act_as_agent();
		$id = $this->admin_post();

		$output = $this->output( self::TOOL_NAME, array( 'id' => $id ) );

		$expected = array(
			'id',
			'type',
			'status',
			'title',
			'slug',
			'date_gmt',
			'modified_gmt',
			'author',
			'excerpt',
			'password_protected',
			'parent',
			'link',
			'content',
			'excerpt_raw',
			'comment_status',
			'ping_status',
			'menu_order',
			'template',
			'featured_media',
			'sticky',
			'truncated',
			'content_bytes',
		);
		$this->assertSame( $expected, array_keys( $output ) );
	}

	public function test_another_authors_draft_and_private_post_are_readable(): void {
		$this->act_as_agent();
		$draft   = $this->admin_post( array( 'post_status' => 'draft' ) );
		$private = $this->admin_post( array( 'post_status' => 'private' ) );

		$this->assertSame( 'draft', $this->output( self::TOOL_NAME, array( 'id' => $draft ) )['status'] );
		$this->assertSame( 'private', $this->output( self::TOOL_NAME, array( 'id' => $private ) )['status'] );
	}

	public function test_trashed_missing_disallowed_and_revision_ids_get_the_same_refusal(): void {
		$this->act_as_agent();

		$texts = $this->single_post_refusal_texts( self::TOOL_NAME );

		$this->assertCount( 9, $texts );
		$this->assertCount( 1, array_unique( array_values( $texts ) ), 'Every refusal must read the same: ' . wp_json_encode( $texts ) );
		$this->assertStringContainsString( 'Not found, or this agent may not access it.', $texts['missing'] );
	}

	/**
	 * A visitor's privacy request is stored as user_request, a built-in type. Even when a filter adds it, the
	 * call is refused, and the title (an email address) never appears.
	 */
	public function test_a_privacy_request_is_refused_even_when_a_filter_adds_its_type(): void {
		add_filter(
			'steadhand_allowed_post_types',
			static fn( array $types ): array => array_merge( $types, array( 'user_request' ) )
		);
		$this->act_as_agent();
		$id = wp_insert_post(
			array(
				'post_type'    => 'user_request',
				'post_status'  => 'request-pending',
				'post_title'   => 'visitor@example.test',
				'post_name'    => 'privacy-probe',
				'post_content' => 'Privacy request body',
				'post_author'  => $this->admin_id(),
			)
		);

		try {
			$this->assertGreaterThan( 0, $id, 'The fixture must exist.' );
			$result = $this->tool_result( self::TOOL_NAME, array( 'id' => $id ) );

			$this->refusal_text( $result );
			$this->assertStringNotContainsString( 'visitor@example.test', (string) wp_json_encode( $result ) );
		} finally {
			remove_all_filters( 'steadhand_allowed_post_types' );
		}
	}

	public function test_the_author_name_is_plain_text(): void {
		$this->act_as_agent();
		$author = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'display_name' => 'Tom &amp; Co',
			)
		);
		$id     = $this->admin_post( array( 'post_author' => $author ) );

		$output = $this->output( self::TOOL_NAME, array( 'id' => $id ) );

		$this->assertSame( 'Tom & Co', $output['author']['name'] );
	}

	public function test_ids_that_are_zero_negative_or_not_integers_are_refused(): void {
		$this->act_as_agent();

		foreach ( array( 0, -1, 1.5, 'abc', 2147483648 ) as $id ) {
			$this->schema_refusal_text( $this->tool_result( self::TOOL_NAME, array( 'id' => $id ) ) );
		}
	}

	public function test_a_password_protected_post_returns_its_content_and_never_the_password(): void {
		$password = 'PasswordProbe' . wp_rand();
		$this->act_as_agent();
		$id = $this->admin_post(
			array(
				'post_content'  => 'Secret body text',
				'post_password' => $password,
			)
		);

		$result = $this->tool_result( self::TOOL_NAME, array( 'id' => $id ) );
		$output = $result['structuredContent'];

		$this->assertFalse( $result['isError'] );
		$this->assertSame( 'Secret body text', $output['content'] );
		$this->assertTrue( $output['password_protected'] );
		$this->assertStringNotContainsString( $password, (string) wp_json_encode( $result ) );
		$this->assertNotContains( 'post_password', $this->keys_at_any_depth( $result ) );
	}

	public function test_a_post_on_the_limit_is_not_truncated(): void {
		$this->act_as_agent();
		$content = str_repeat( 'a', 262144 );
		$id      = $this->admin_post( array( 'post_content' => $content ) );

		$output = $this->output( self::TOOL_NAME, array( 'id' => $id ) );

		$this->assertFalse( $output['truncated'] );
		$this->assertSame( 262144, $output['content_bytes'] );
		$this->assertSame( $content, $output['content'] );
	}

	public function test_a_post_over_the_limit_is_cut_on_a_utf8_boundary_and_reports_its_full_size(): void {
		$this->act_as_agent();
		// Each euro sign is three bytes. 262144 is not a multiple of three, so a naive cut would split one.
		$content = str_repeat( "\xe2\x82\xac", 100000 );
		$id      = $this->admin_post( array( 'post_content' => $content ) );

		$output = $this->output( self::TOOL_NAME, array( 'id' => $id ) );

		$this->assertTrue( $output['truncated'] );
		$this->assertSame( 300000, $output['content_bytes'] );
		$this->assertSame( 262143, strlen( $output['content'] ), 'The cut stops before the first incomplete character.' );
		$this->assertSame( 1, preg_match( '//u', $output['content'] ), 'The truncated content must be valid UTF-8.' );
		$this->assertSame( str_repeat( "\xe2\x82\xac", 87381 ), $output['content'] );
	}

	public function test_a_refused_call_returns_no_content(): void {
		$this->act_as_agent( array( Capabilities::READ => false ) );
		$id = $this->admin_post( array( 'post_content' => 'Hidden body' ) );

		$text = $this->refusal_text( $this->tool_result( self::TOOL_NAME, array( 'id' => $id ) ) );

		$this->assertStringNotContainsString( 'Hidden body', $text );
		$this->assertStringContainsString( 'steadhand_read', $text );
	}

	public function test_the_pipeline_is_not_running_after_a_call(): void {
		$this->act_as_agent();
		$id = $this->admin_post();

		$this->output( self::TOOL_NAME, array( 'id' => $id ) );
		$this->tool_result( self::TOOL_NAME, array( 'id' => 999999999 ) );

		$this->assertFalse( Pipeline::is_running() );
	}
}
