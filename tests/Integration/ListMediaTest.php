<?php
/**
 * The steadhand/list-media ability, end to end over MCP.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Pipeline\Pipeline;

/**
 * Covers SPEC §5 (list-media), §14.1 and the media rules of the M1 brief.
 */
final class ListMediaTest extends MediaToolTestCase {

	private const TOOL_NAME = 'steadhand-list-media';

	private const ITEM_KEYS = array( 'id', 'title', 'slug', 'mime_type', 'media_type', 'url', 'alt', 'caption', 'date_gmt', 'modified_gmt', 'author', 'parent', 'width', 'height', 'filesize', 'duration' );

	public function test_default_order_is_newest_first_and_each_item_has_exactly_the_documented_fields(): void {
		$this->act_as_agent();
		$old = $this->dated( 'image/jpeg', '2026-01-01 10:00:00' );
		$new = $this->dated( 'application/pdf', '2026-03-01 10:00:00' );
		$mid = $this->dated( 'text/plain', '2026-02-01 10:00:00' );

		$output = $this->output( self::TOOL_NAME );

		$this->assertSame( array( $new, $mid, $old ), wp_list_pluck( $output['items'], 'id' ) );
		$this->assertSame( self::ITEM_KEYS, array_keys( $output['items'][0] ) );
		$this->assert_matches_output_schema( $output, 'steadhand/list-media' );
		$this->assertSame( 3, $output['total'] );
		$this->assertSame( 1, $output['total_pages'] );
		$this->assertSame( 1, $output['page'] );
		$this->assertSame( 20, $output['per_page'] );
		$this->assertSame( '2026-03-01T10:00:00Z', $output['items'][0]['date_gmt'] );
		$this->assertFalse( Pipeline::is_running(), 'The pipeline must not be running after the call.' );
	}

	public function test_the_media_type_filter_uses_the_mime_top_level_type(): void {
		$this->act_as_agent();
		$image = $this->image();
		$pdf   = $this->plain( 'application/pdf' );
		$this->plain( 'audio/mpeg' );

		$this->assertSame( array( $image ), wp_list_pluck( $this->output( self::TOOL_NAME, array( 'media_type' => 'image' ) )['items'], 'id' ) );
		$this->assertSame( array( $pdf ), wp_list_pluck( $this->output( self::TOOL_NAME, array( 'media_type' => 'application' ) )['items'], 'id' ) );
		$this->assertSame( 'image', $this->output( self::TOOL_NAME, array( 'media_type' => 'image' ) )['items'][0]['media_type'] );
	}

	public function test_search_matches_the_title(): void {
		$this->act_as_agent();
		$spring = $this->plain( 'image/png', 0, array( 'post_title' => 'Spring orchard' ) );
		$this->plain( 'image/png', 0, array( 'post_title' => 'Winter report' ) );

		$this->assertSame( array( $spring ), wp_list_pluck( $this->output( self::TOOL_NAME, array( 'search' => 'spring' ) )['items'], 'id' ) );
	}

	public function test_orderby_title_ascending_sorts_by_title(): void {
		$this->act_as_agent();
		$b = $this->plain( 'image/png', 0, array( 'post_title' => 'Bravo' ) );
		$a = $this->plain( 'image/png', 0, array( 'post_title' => 'Alpha' ) );

		$this->assertSame(
			array( $a, $b ),
			wp_list_pluck(
				$this->output(
					self::TOOL_NAME,
					array(
						'orderby' => 'title',
						'order'   => 'asc',
					)
				)['items'],
				'id'
			)
		);
	}

	public function test_parent_zero_lists_only_unattached_media(): void {
		$this->act_as_agent();
		$parent     = $this->admin_post( array( 'post_status' => 'draft' ) );
		$unattached = $this->plain( 'application/pdf' );
		$this->image( $parent );

		$this->assertSame( array( $unattached ), wp_list_pluck( $this->output( self::TOOL_NAME, array( 'parent' => 0 ) )['items'], 'id' ) );
	}

	public function test_parent_of_a_readable_draft_lists_its_attachments(): void {
		$this->act_as_agent();
		$parent = $this->admin_post( array( 'post_status' => 'draft' ) );
		$child  = $this->plain( 'application/pdf', $parent );
		$this->plain( 'application/pdf' );

		$output = $this->output( self::TOOL_NAME, array( 'parent' => $parent ) );

		$this->assertSame( array( $child ), wp_list_pluck( $output['items'], 'id' ) );
		$this->assertSame( $parent, $output['items'][0]['parent'] );
	}

	public function test_a_parent_that_is_trashed_missing_or_an_attachment_is_refused_with_one_text(): void {
		$this->act_as_agent();
		$trashed    = $this->admin_post( array( 'post_status' => 'trash' ) );
		$attachment = $this->plain( 'application/pdf' );

		$texts = array(
			'trashed'    => $this->refusal_text( $this->tool_result( self::TOOL_NAME, array( 'parent' => $trashed ) ) ),
			'attachment' => $this->refusal_text( $this->tool_result( self::TOOL_NAME, array( 'parent' => $attachment ) ) ),
			'missing'    => $this->refusal_text( $this->tool_result( self::TOOL_NAME, array( 'parent' => 999999999 ) ) ),
		);

		$this->assertSame( array( self::NOT_FOUND ), array_values( array_unique( $texts ) ), wp_json_encode( $texts ) );
	}

	public function test_a_parent_the_agent_cannot_read_is_refused(): void {
		$this->act_as_agent();
		$regular = $this->admin_post( array( 'post_type' => 'wp_block' ) );

		$this->assertTrue( $this->tool_result( self::TOOL_NAME, array( 'parent' => $regular ) )['isError'] );
	}

	public function test_the_date_range_is_applied_and_an_invalid_calendar_date_is_refused(): void {
		$this->act_as_agent();
		$this->dated( 'image/png', '2026-01-10 12:00:00' );
		$middle = $this->dated( 'image/png', '2026-02-10 12:00:00' );
		$this->dated( 'image/png', '2026-03-10 12:00:00' );

		$this->assertSame(
			array( $middle ),
			wp_list_pluck(
				$this->output(
					self::TOOL_NAME,
					array(
						'after'  => '2026-02-01T00:00:00Z',
						'before' => '2026-02-28T00:00:00Z',
					)
				)['items'],
				'id'
			)
		);

		$this->assertTrue( $this->tool_result( self::TOOL_NAME, array( 'after' => '2026-02-30T00:00:00Z' ) )['isError'], 'February 30 is not a date.' );
		$this->assertTrue( $this->tool_result( self::TOOL_NAME, array( 'before' => '2026-13-01T00:00:00Z' ) )['isError'], 'Month 13 is not a date.' );
	}

	public function test_pagination_counts_pages_and_a_page_past_the_end_is_empty_not_an_error(): void {
		$this->act_as_agent();
		for ( $i = 0; $i < 5; $i++ ) {
			$this->plain( 'image/png' );
		}

		$third = $this->output(
			self::TOOL_NAME,
			array(
				'per_page' => 2,
				'page'     => 3,
			)
		);
		$this->assertCount( 1, $third['items'] );
		$this->assertSame( 5, $third['total'] );
		$this->assertSame( 3, $third['total_pages'] );

		$past = $this->output(
			self::TOOL_NAME,
			array(
				'per_page' => 2,
				'page'     => 4,
			)
		);
		$this->assertSame( array(), $past['items'] );
		$this->assertSame( 5, $past['total'] );
		$this->assertSame( 3, $past['total_pages'] );
		$this->assertSame( 4, $past['page'] );
	}

	public function test_trashed_media_is_never_listed(): void {
		$this->act_as_agent();
		$kept    = $this->plain( 'image/png' );
		$trashed = $this->plain( 'image/png' );
		wp_trash_post( $trashed );

		$this->assertSame( array( $kept ), wp_list_pluck( $this->output( self::TOOL_NAME )['items'], 'id' ) );
	}

	public function test_media_in_any_status_other_than_inherit_is_never_listed(): void {
		$this->act_as_agent();
		$kept    = $this->plain( 'image/png' );
		$private = $this->plain( 'image/png', 0, array( 'post_status' => 'private' ) );

		$this->assertSame( array( $kept ), wp_list_pluck( $this->output( self::TOOL_NAME )['items'], 'id' ), "The private row $private must not be listed." );
	}

	public function test_schema_refusals_for_bounds_unknown_properties_and_unknown_media_types(): void {
		$this->act_as_agent();

		$this->assertTrue( $this->tool_result( self::TOOL_NAME, array( 'per_page' => 51 ) )['isError'], 'per_page is at most 50.' );
		$this->assertTrue( $this->tool_result( self::TOOL_NAME, array( 'page' => 10001 ) )['isError'], 'page is at most 10000.' );
		$this->assertTrue( $this->tool_result( self::TOOL_NAME, array( 'media_type' => 'font' ) )['isError'], 'font is not a media type.' );
		$this->assertTrue( $this->tool_result( self::TOOL_NAME, array( 'post_status' => 'any' ) )['isError'], 'Unknown properties are refused.' );
		$this->assertTrue( $this->tool_result( self::TOOL_NAME, array( 'search' => str_repeat( 'a', 201 ) ) )['isError'] );
	}

	public function test_an_agent_without_steadhand_read_is_refused(): void {
		$this->act_as_agent( array( \Steadhand\Capabilities::READ => false ) );

		$this->assertTrue( $this->tool_result( self::TOOL_NAME )['isError'] );
	}

	public function test_the_output_never_holds_server_paths_metadata_exif_gps_or_user_details(): void {
		$this->act_as_agent();
		$image = $this->image( 0, array( 'post_excerpt' => 'Canola field' ) );
		update_post_meta( $image, '_wp_attachment_image_alt', 'A field of canola' );
		$this->with_sensitive_metadata( $image );

		$output = $this->output( self::TOOL_NAME, array( 'media_type' => 'image' ) );

		$this->assertSame( array( $image ), wp_list_pluck( $output['items'], 'id' ) );
		$this->assert_no_forbidden_data( $output, 'list-media' );
	}

	public function test_a_trashed_attachment_is_not_counted_in_total_or_total_pages(): void {
		$this->act_as_agent();
		$this->plain( 'image/png' );
		$trashed = $this->plain( 'image/png' );
		wp_trash_post( $trashed );

		$output = $this->output( self::TOOL_NAME, array( 'media_type' => 'image' ) );

		$this->assertSame( 1, $output['total'], 'total must not count the trashed attachment.' );
		$this->assertSame( 1, $output['total_pages'] );
	}
}
