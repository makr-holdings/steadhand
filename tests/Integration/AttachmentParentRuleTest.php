<?php
/**
 * The attachment rule's own-status and parent conditions, end to end over MCP (SPEC §14.1).
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

/**
 * An attachment is readable only when its own status is inherit and its parent is none, a post that no longer exists,
 * or a post of an allowed type that is not in the trash. get-media and list-media must agree on that.
 */
final class AttachmentParentRuleTest extends MediaToolTestCase {

	private const GET_TOOL  = 'steadhand-get-media';
	private const LIST_TOOL = 'steadhand-list-media';

	/**
	 * Removes the filter each test may add.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'steadhand_allowed_post_types' );
		parent::tearDown();
	}

	/**
	 * Attachments the rule excludes, keyed by the reason. Each one is a file a call must refuse.
	 *
	 * @return array<string, int>
	 */
	private function refused_attachments(): array {
		$secret_private = $this->admin_post(
			array(
				'post_type'   => self::SECRET_TYPE,
				'post_status' => 'private',
			)
		);
		$block          = $this->admin_post( array( 'post_type' => 'wp_block' ) );
		$navigation     = $this->admin_post(
			array(
				'post_type'   => 'wp_navigation',
				'post_status' => 'draft',
			)
		);
		$trashed        = $this->admin_post( array( 'post_status' => 'publish' ) );
		wp_trash_post( $trashed );

		return array(
			'private parent of a registered type that is not allowed' => $this->plain( 'application/pdf', $secret_private ),
			'wp_block parent'            => $this->plain( 'application/pdf', $block ),
			'draft wp_navigation parent' => $this->plain( 'application/pdf', $navigation ),
			'trashed parent'             => $this->plain( 'application/pdf', $trashed ),
			'own status private'         => $this->plain( 'application/pdf', 0, array( 'post_status' => 'private' ) ),
		);
	}

	/**
	 * Attachments the rule allows, keyed by the reason.
	 *
	 * @return array<string, int>
	 */
	private function readable_attachments(): array {
		$other_author = self::factory()->user->create( array( 'role' => 'author' ) );
		$draft        = $this->admin_post(
			array(
				'post_status' => 'draft',
				'post_author' => $other_author,
			)
		);
		$private_page = $this->admin_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'private',
			)
		);
		$orphan       = $this->plain( 'application/pdf' );
		$this->set_parent_directly( $orphan, 999999999 );

		return array(
			'unattached'              => $this->plain( 'application/pdf' ),
			'draft by another author' => $this->plain( 'application/pdf', $draft ),
			'private page'            => $this->plain( 'application/pdf', $private_page ),
			'parent no longer exists' => $orphan,
		);
	}

	/**
	 * Sets an attachment's parent in the database, for a parent that no longer exists.
	 *
	 * @param int $id     Attachment ID.
	 * @param int $parent_id Parent ID to store.
	 */
	private function set_parent_directly( int $id, int $parent_id ): void {
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_parent' => $parent_id ), array( 'ID' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Stores a parent ID that no post has, which the API cannot do.
		clean_post_cache( $id );
	}

	public function test_get_media_refuses_every_attachment_the_rule_excludes_with_the_standard_text(): void {
		$this->act_as_agent();

		foreach ( $this->refused_attachments() as $case => $id ) {
			$this->assertSame( self::NOT_FOUND, $this->refusal_text( $this->tool_result( self::GET_TOOL, array( 'id' => $id ) ) ), $case );
		}
	}

	public function test_get_media_reads_every_attachment_the_rule_allows(): void {
		$this->act_as_agent();

		foreach ( $this->readable_attachments() as $case => $id ) {
			$this->assertSame( $id, $this->output( self::GET_TOOL, array( 'id' => $id ) )['id'], $case );
		}
	}

	public function test_list_media_lists_and_counts_only_the_attachments_the_rule_allows(): void {
		$this->act_as_agent();
		$readable = $this->readable_attachments();
		$this->refused_attachments();

		$output = $this->output( self::LIST_TOOL, array( 'per_page' => 50 ) );

		$this->assertEqualsCanonicalizing( array_values( $readable ), wp_list_pluck( $output['items'], 'id' ) );
		$this->assertSame( count( $readable ), $output['total'], 'total must not count the excluded rows.' );
		$this->assertSame( 1, $output['total_pages'] );
		$this->assert_matches_output_schema( $output, 'steadhand/list-media' );
	}

	public function test_list_media_pagination_counts_only_the_allowed_rows_even_past_the_end(): void {
		$this->act_as_agent();
		$readable = count( $this->readable_attachments() );
		$this->refused_attachments();

		$first = $this->output( self::LIST_TOOL, array( 'per_page' => 1 ) );
		$last  = $this->output(
			self::LIST_TOOL,
			array(
				'per_page' => 1,
				'page'     => $readable,
			)
		);
		$past  = $this->output(
			self::LIST_TOOL,
			array(
				'per_page' => 1,
				'page'     => $readable + 1,
			)
		);

		$this->assertSame( $readable, $first['total'] );
		$this->assertSame( $readable, $first['total_pages'] );
		$this->assertCount( 1, $last['items'] );
		$this->assertSame( array(), $past['items'] );
		$this->assertSame( $readable, $past['total'], 'A page past the end must still report the allowed total.' );
		$this->assertSame( $readable, $past['total_pages'] );
	}

	public function test_an_attachment_under_a_type_the_filter_allows_becomes_readable(): void {
		$parent     = $this->admin_post(
			array(
				'post_type'   => self::SECRET_TYPE,
				'post_status' => 'private',
			)
		);
		$attachment = $this->plain( 'application/pdf', $parent );
		add_filter(
			'steadhand_allowed_post_types',
			static fn( array $types ): array => array_merge( $types, array( self::SECRET_TYPE ) )
		);
		$this->act_as_agent();

		$this->assertSame( $attachment, $this->output( self::GET_TOOL, array( 'id' => $attachment ) )['id'] );
		$this->assertSame( array( $attachment ), wp_list_pluck( $this->output( self::LIST_TOOL, array( 'parent' => $parent ) )['items'], 'id' ) );
	}
}
