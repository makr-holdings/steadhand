<?php
/**
 * Regression guard: after the attachment read rule, the post tools still refuse attachments (SPEC §14.1). The rule
 * lives in MetaCaps and in MediaReader, and PostReader::readable_post() keeps its own type check.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

/**
 * Every post tool gets an attachment that the agent may read through the media tools, and must refuse it with the
 * standard text.
 */
final class AttachmentRefusalTest extends MediaToolTestCase {

	public function test_the_single_post_tools_refuse_a_readable_attachment_with_the_standard_text(): void {
		$image = $this->image();
		$this->act_as_agent();

		foreach ( array( 'steadhand-get-post', 'steadhand-get-blocks', 'steadhand-get-rendered', 'steadhand-get-post-terms' ) as $tool ) {
			$this->assertSame( self::NOT_FOUND, $this->refusal_text( $this->tool_result( $tool, array( 'id' => $image ) ) ), $tool );
		}

		// Control: the media tool reads the same attachment in the same kind of call.
		$this->assertSame( $image, $this->output( 'steadhand-get-media', array( 'id' => $image ) )['id'] );
	}

	public function test_an_attachment_on_a_draft_is_refused_by_the_post_tools_too(): void {
		$image = $this->image( $this->admin_post( array( 'post_status' => 'draft' ) ) );
		$this->act_as_agent();

		$this->assertSame( self::NOT_FOUND, $this->refusal_text( $this->tool_result( 'steadhand-get-post', array( 'id' => $image ) ) ) );
		$this->assertSame( self::NOT_FOUND, $this->refusal_text( $this->tool_result( 'steadhand-get-rendered', array( 'id' => $image ) ) ) );
	}

	public function test_list_posts_still_refuses_the_attachment_post_type(): void {
		$this->act_as_agent();

		$this->assertTrue( $this->tool_result( 'steadhand-list-posts', array( 'post_type' => 'attachment' ) )['isError'] );
	}

	public function test_a_trashed_attachment_is_refused_by_the_post_and_media_tools_with_the_same_text(): void {
		$image = $this->image();
		wp_trash_post( $image );
		$this->act_as_agent();

		$this->assertSame( self::NOT_FOUND, $this->refusal_text( $this->tool_result( 'steadhand-get-post', array( 'id' => $image ) ) ) );
		$this->assertSame( self::NOT_FOUND, $this->refusal_text( $this->tool_result( 'steadhand-get-media', array( 'id' => $image ) ) ) );
	}
}
