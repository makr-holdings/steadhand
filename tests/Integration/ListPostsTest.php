<?php
/**
 * The steadhand/list-posts ability, end to end over MCP.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\ListPosts;
use Steadhand\Capabilities;
use Steadhand\Log\NullLogger;
use Steadhand\Pipeline\Pipeline;
use Steadhand\PostTypes;

/**
 * Covers SPEC §5 (list-posts), §10 and §14.1 (D6) for the listing tool.
 */
final class ListPostsTest extends ReadToolTestCase {

	private const TOOL_NAME = 'steadhand-list-posts';

	/**
	 * Removes the custom type and any filter this file adds.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'steadhand_allowed_post_types' );
		remove_all_filters( 'posts_results' );
		if ( post_type_exists( 'steadhand_book' ) ) {
			unregister_post_type( 'steadhand_book' );
		}
		parent::tearDown();
	}

	/**
	 * Post IDs in a list output, in order.
	 *
	 * @param array<string, mixed> $output Structured output.
	 * @return int[]
	 */
	private function ids( array $output ): array {
		return array_map( static fn( array $item ): int => $item['id'], $output['items'] );
	}

	public function test_default_listing_returns_other_authors_draft_pending_private_future_and_published_posts(): void {
		$this->act_as_agent();
		$published = $this->admin_post( array( 'post_status' => 'publish' ) );
		$draft     = $this->admin_post( array( 'post_status' => 'draft' ) );
		$pending   = $this->admin_post( array( 'post_status' => 'pending' ) );
		$private   = $this->admin_post( array( 'post_status' => 'private' ) );
		$future    = $this->admin_post(
			array(
				'post_status'   => 'future',
				'post_date'     => '2099-01-01 10:00:00',
				'post_date_gmt' => '2099-01-01 10:00:00',
			)
		);

		$output = $this->output( self::TOOL_NAME );
		$by_id  = array();
		foreach ( $output['items'] as $item ) {
			$by_id[ $item['id'] ] = $item['status'];
		}

		$this->assertEqualsCanonicalizing(
			array(
				$published => 'publish',
				$draft     => 'draft',
				$pending   => 'pending',
				$private   => 'private',
				$future    => 'future',
			),
			$by_id
		);
		$this->assertSame( 5, $output['total'] );
	}

	public function test_default_listing_never_returns_trashed_auto_draft_pages_attachments_or_blocks(): void {
		$this->act_as_agent();
		$visible = $this->admin_post();
		$this->admin_post( array( 'post_status' => 'trash' ) );
		$auto_draft = $this->admin_post( array( 'post_status' => 'auto-draft' ) );
		$this->assertSame( 'auto-draft', get_post_status( $auto_draft ), 'The fixture must really be an auto-draft.' );
		$this->admin_post( array( 'post_type' => 'page' ) );
		$this->admin_post( array( 'post_type' => 'wp_block' ) );
		wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => 'Image',
				'post_mime_type' => 'image/jpeg',
				'post_author'    => $this->admin_id(),
			)
		);

		$output = $this->output( self::TOOL_NAME );

		$this->assertSame( array( $visible ), $this->ids( $output ) );
		$this->assertSame( 1, $output['total'] );
	}

	public function test_status_filter_limits_the_results_to_the_requested_statuses(): void {
		$this->act_as_agent();
		$draft = $this->admin_post( array( 'post_status' => 'draft' ) );
		$this->admin_post( array( 'post_status' => 'publish' ) );
		$this->admin_post( array( 'post_status' => 'private' ) );

		$output = $this->output( self::TOOL_NAME, array( 'status' => array( 'draft' ) ) );

		$this->assertSame( array( $draft ), $this->ids( $output ) );
	}

	public function test_statuses_outside_the_list_are_never_returned_even_when_they_are_searchable(): void {
		// WordPress's 'any' would include this status, because it is searchable. An explicit list must not.
		register_post_status(
			'steadhand_hidden',
			array(
				'label'               => 'Hidden',
				'public'              => false,
				'internal'            => false,
				'exclude_from_search' => false,
			)
		);
		$this->act_as_agent();
		$hidden = $this->admin_post( array( 'post_status' => 'steadhand_hidden' ) );
		$draft  = $this->admin_post( array( 'post_status' => 'draft' ) );

		try {
			$this->assertSame( array( $draft ), $this->ids( $this->output( self::TOOL_NAME ) ) );
			$this->assertNotContains( $hidden, $this->ids( $this->output( self::TOOL_NAME, array( 'search' => 'Fixture' ) ) ) );
		} finally {
			global $wp_post_statuses;
			unset( $wp_post_statuses['steadhand_hidden'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Core has no unregister function for statuses.
		}
	}

	public function test_search_matches_the_title_within_the_allowed_statuses(): void {
		$this->act_as_agent();
		$match = $this->admin_post(
			array(
				'post_title'  => 'Lighthouse keeping',
				'post_status' => 'draft',
			)
		);
		$this->admin_post(
			array(
				'post_title'  => 'Harbour notes',
				'post_status' => 'draft',
			)
		);

		$output = $this->output( self::TOOL_NAME, array( 'search' => 'Lighthouse' ) );

		$this->assertSame( array( $match ), $this->ids( $output ) );
	}

	public function test_date_range_filters_on_the_post_date_and_includes_both_ends(): void {
		$this->act_as_agent();
		$january  = $this->admin_post(
			array(
				'post_date_gmt' => '2026-01-10 12:00:00',
				'post_date'     => '2026-01-10 12:00:00',
			)
		);
		$february = $this->admin_post(
			array(
				'post_date_gmt' => '2026-02-10 12:00:00',
				'post_date'     => '2026-02-10 12:00:00',
			)
		);
		$edge     = $this->admin_post(
			array(
				'post_date_gmt' => '2026-02-20 00:00:00',
				'post_date'     => '2026-02-20 00:00:00',
			)
		);
		$march    = $this->admin_post(
			array(
				'post_date_gmt' => '2026-03-10 12:00:00',
				'post_date'     => '2026-03-10 12:00:00',
			)
		);

		$output = $this->output(
			self::TOOL_NAME,
			array(
				'after'   => '2026-02-01T00:00:00Z',
				'before'  => '2026-02-20T00:00:00Z',
				'orderby' => 'date',
				'order'   => 'asc',
			)
		);

		$this->assertSame( array( $february, $edge ), $this->ids( $output ) );
		$this->assertNotContains( $january, $this->ids( $output ) );
		$this->assertNotContains( $march, $this->ids( $output ) );
	}

	public function test_a_draft_with_a_zero_gmt_date_is_found_by_its_post_date(): void {
		$this->act_as_agent();
		$draft = $this->admin_post(
			array(
				'post_status'   => 'draft',
				'post_date'     => '2026-02-10 12:00:00',
				'post_date_gmt' => '0000-00-00 00:00:00',
			)
		);
		$this->assertSame( '0000-00-00 00:00:00', get_post( $draft )->post_date_gmt, 'Control: WordPress keeps the zero GMT date for this draft.' );

		$output = $this->output(
			self::TOOL_NAME,
			array(
				'after'  => '2026-02-01T00:00:00Z',
				'before' => '2026-02-20T00:00:00Z',
			)
		);

		$this->assertSame( array( $draft ), $this->ids( $output ) );
		$this->assertNull( $output['items'][0]['date_gmt'] );
	}

	public function test_date_bounds_are_read_as_instants_and_compared_in_the_site_time_zone(): void {
		update_option( 'timezone_string', 'Europe/Paris' );
		$this->act_as_agent();
		// 13:00 in Paris in February (UTC+1) is 12:00 UTC.
		$post = $this->admin_post(
			array(
				'post_date'     => '2026-02-10 13:00:00',
				'post_date_gmt' => '2026-02-10 12:00:00',
			)
		);

		$at_start = $this->output( self::TOOL_NAME, array( 'after' => '2026-02-10T12:00:00Z' ) );
		$after    = $this->output( self::TOOL_NAME, array( 'after' => '2026-02-10T12:00:01Z' ) );

		$this->assertSame( array( $post ), $this->ids( $at_start ), 'The lower bound is inclusive, and it is an instant in UTC.' );
		$this->assertSame( array(), $this->ids( $after ) );
	}

	public function test_date_range_is_read_as_utc_even_with_an_offset_in_the_request(): void {
		$this->act_as_agent();
		$post = $this->admin_post(
			array(
				'post_date_gmt' => '2026-02-10 23:30:00',
				'post_date'     => '2026-02-11 00:30:00',
			)
		);

		// 2026-02-11T00:00+01:00 is 2026-02-10T23:00Z, which is before the post.
		$output = $this->output( self::TOOL_NAME, array( 'after' => '2026-02-11T00:00:00+01:00' ) );

		$this->assertSame( array( $post ), $this->ids( $output ) );
	}

	public function test_ordering_by_title_ascending_and_the_default_date_descending(): void {
		$this->act_as_agent();
		$b = $this->admin_post(
			array(
				'post_title'    => 'Bravo',
				'post_date_gmt' => '2026-01-01 00:00:00',
				'post_date'     => '2026-01-01 00:00:00',
			)
		);
		$a = $this->admin_post(
			array(
				'post_title'    => 'Alpha',
				'post_date_gmt' => '2026-01-03 00:00:00',
				'post_date'     => '2026-01-03 00:00:00',
			)
		);
		$c = $this->admin_post(
			array(
				'post_title'    => 'Charlie',
				'post_date_gmt' => '2026-01-02 00:00:00',
				'post_date'     => '2026-01-02 00:00:00',
			)
		);

		$this->assertSame( array( $a, $c, $b ), $this->ids( $this->output( self::TOOL_NAME ) ) );
		$this->assertSame(
			array( $a, $b, $c ),
			$this->ids(
				$this->output(
					self::TOOL_NAME,
					array(
						'orderby' => 'title',
						'order'   => 'asc',
					)
				)
			)
		);
	}

	public function test_pagination_reports_totals_and_an_empty_page_beyond_the_end_is_not_an_error(): void {
		$this->act_as_agent();
		for ( $i = 0; $i < 5; $i++ ) {
			$this->admin_post( array( 'post_title' => "Post $i" ) );
		}

		$first = $this->output(
			self::TOOL_NAME,
			array(
				'per_page' => 2,
				'page'     => 1,
			)
		);
		$last  = $this->output(
			self::TOOL_NAME,
			array(
				'per_page' => 2,
				'page'     => 3,
			)
		);
		$past  = $this->output(
			self::TOOL_NAME,
			array(
				'per_page' => 2,
				'page'     => 4,
			)
		);

		$this->assertCount( 2, $first['items'] );
		$this->assertSame( 5, $first['total'] );
		$this->assertSame( 3, $first['total_pages'] );
		$this->assertSame( 1, $first['page'] );
		$this->assertSame( 2, $first['per_page'] );

		$this->assertCount( 1, $last['items'] );
		$this->assertSame( 5, $last['total'] );

		$this->assertSame( array(), $past['items'] );
		$this->assertSame( 5, $past['total'] );
		$this->assertSame( 3, $past['total_pages'] );
		$this->assertSame( 4, $past['page'] );
	}

	public function test_an_empty_result_has_zero_pages(): void {
		$this->act_as_agent();

		$output = $this->output( self::TOOL_NAME, array( 'search' => 'nothing-matches-this' ) );

		$this->assertSame( array(), $output['items'] );
		$this->assertSame( 0, $output['total'] );
		$this->assertSame( 0, $output['total_pages'] );
	}

	public function test_per_page_of_51_and_page_0_are_rejected(): void {
		$this->act_as_agent();

		$this->schema_refusal_text( $this->tool_result( self::TOOL_NAME, array( 'per_page' => 51 ) ) );
		$this->schema_refusal_text( $this->tool_result( self::TOOL_NAME, array( 'page' => 0 ) ) );
		$this->schema_refusal_text( $this->tool_result( self::TOOL_NAME, array( 'page' => 10001 ) ) );
	}

	public function test_attachment_wp_block_and_unregistered_post_types_are_refused_and_name_the_allowed_types(): void {
		$this->act_as_agent();

		foreach ( array( 'attachment', 'wp_block', 'revision', 'no_such_type' ) as $type ) {
			$text = $this->refusal_text( $this->tool_result( self::TOOL_NAME, array( 'post_type' => $type ) ) );
			$this->assertStringContainsString( 'post, page', $text, $type );
		}
	}

	public function test_a_custom_type_is_refused_until_the_filter_allows_it_and_then_listed(): void {
		register_post_type( 'steadhand_book', array( 'public' => true ) );
		$this->act_as_agent();
		$book = $this->admin_post( array( 'post_type' => 'steadhand_book' ) );

		$this->refusal_text( $this->tool_result( self::TOOL_NAME, array( 'post_type' => 'steadhand_book' ) ) );

		add_filter(
			'steadhand_allowed_post_types',
			static fn( array $types ): array => array_merge( $types, array( 'steadhand_book' ) )
		);

		$output = $this->output( self::TOOL_NAME, array( 'post_type' => 'steadhand_book' ) );
		$this->assertSame( array( $book ), $this->ids( $output ) );
	}

	public function test_statuses_outside_the_enum_are_rejected_by_the_schema(): void {
		$this->act_as_agent();

		foreach ( array( 'trash', 'any', 'auto-draft', 'inherit' ) as $status ) {
			$this->schema_refusal_text( $this->tool_result( self::TOOL_NAME, array( 'status' => array( $status ) ) ) );
		}
		$this->schema_refusal_text( $this->tool_result( self::TOOL_NAME, array( 'status' => array( 'draft', 'draft' ) ) ) );
		$this->schema_refusal_text( $this->tool_result( self::TOOL_NAME, array( 'status' => array() ) ) );
	}

	public function test_an_unknown_property_is_rejected(): void {
		$this->act_as_agent();

		$this->schema_refusal_text( $this->tool_result( self::TOOL_NAME, array( 'author' => 1 ) ) );
	}

	public function test_output_never_carries_passwords_emails_or_logins(): void {
		$login_probe = 'loginprobe' . wp_rand();
		$email       = 'emailprobe' . wp_rand() . '@example.test';
		$author      = self::factory()->user->create(
			array(
				'role'         => 'administrator',
				'user_login'   => $login_probe,
				'user_email'   => $email,
				'display_name' => 'Display Name',
			)
		);
		$password    = 'PasswordProbe' . wp_rand();
		$this->admin_post(
			array(
				'post_author'   => $author,
				'post_password' => $password,
			)
		);
		$this->act_as_agent();

		$result = $this->tool_result( self::TOOL_NAME );
		$text   = (string) wp_json_encode( $result );

		$this->assertFalse( $result['isError'] );
		$this->assertStringNotContainsString( $password, $text );
		$this->assertStringNotContainsString( $email, $text );
		$this->assertStringNotContainsString( $login_probe, $text );
		foreach ( $this->keys_at_any_depth( $result ) as $key ) {
			$this->assertNotContains( $key, array( 'post_password', 'password', 'user_pass', 'email', 'user_email', 'login', 'user_login' ) );
		}
		$this->assertSame( 'Display Name', $result['structuredContent']['items'][0]['author']['name'] );
		$this->assertTrue( $result['structuredContent']['items'][0]['password_protected'] );
	}

	public function test_an_agent_without_steadhand_read_is_refused(): void {
		$this->act_as_agent( array( Capabilities::READ => false ) );

		$text = $this->refusal_text( $this->tool_result( self::TOOL_NAME ) );

		$this->assertStringContainsString( 'steadhand_read', $text );
	}

	public function test_an_administrator_is_refused_at_the_transport_gate(): void {
		wp_set_current_user( $this->admin_id() );

		$response = $this->mcp(
			'initialize',
			array(
				'protocolVersion' => '2025-11-25',
				'capabilities'    => new \stdClass(),
				'clientInfo'      => array(
					'name'    => 'steadhand-tests',
					'version' => '1',
				),
			)
		);

		$this->assertSame( 403, $response->get_status() );
	}

	/**
	 * Defence in depth: a row that fails read_post is dropped even when the query returned it. A plugin filter injects
	 * a trashed post, an attachment and a block into the results to simulate that.
	 */
	public function test_rows_that_fail_the_per_row_read_check_are_dropped(): void {
		$this->act_as_agent();
		$visible = $this->admin_post( array( 'post_title' => 'Visible' ) );
		$trashed = $this->admin_post( array( 'post_status' => 'trash' ) );
		$image   = wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => 'Image',
				'post_mime_type' => 'image/jpeg',
				'post_author'    => $this->admin_id(),
			)
		);
		$block   = $this->admin_post( array( 'post_type' => 'wp_block' ) );

		add_filter(
			'posts_results',
			static function ( array $posts ) use ( $trashed, $image, $block ): array {
				foreach ( array( $trashed, $image, $block ) as $id ) {
					$posts[] = get_post( $id );
				}
				return $posts;
			}
		);

		$output = $this->output( self::TOOL_NAME );

		$this->assertSame( array( $visible ), $this->ids( $output ) );
	}

	public function test_the_object_check_refuses_a_disallowed_type_on_its_own(): void {
		$ability = new ListPosts();
		wp_set_current_user( $this->make_agent() );

		$this->assertFalse( $ability->authorize_object( array( 'post_type' => 'attachment' ) ) );
		$this->assertFalse( $ability->authorize_object( array( 'post_type' => 'wp_block' ) ) );
		$this->assertTrue( $ability->authorize_object( array( 'post_type' => 'post' ) ) );
		$this->assertContains( 'post', PostTypes::allowed() );
	}

	public function test_the_pipeline_is_not_running_after_a_call(): void {
		$this->act_as_agent();

		$this->output( self::TOOL_NAME );
		$this->tool_result( self::TOOL_NAME, array( 'post_type' => 'attachment' ) );

		$this->assertFalse( Pipeline::is_running() );
	}
	/**
	 * Step order (SPEC §5, §14.1): an agent without steadhand_read learns nothing from the ability's own input rule,
	 * so the capability refusal comes first, and the allowed-types list is not shown.
	 */
	public function test_a_caller_without_steadhand_read_gets_the_capability_refusal_before_the_type_rule(): void {
		$this->act_as_agent( array( \Steadhand\Capabilities::READ => false ) );

		$text = $this->refusal_text( $this->tool_result( self::TOOL_NAME, array( 'post_type' => 'attachment' ) ) );

		$this->assertStringContainsString( 'steadhand_read', $text );
		$this->assertStringNotContainsString( 'Steadhand allows', $text );
	}

	/**
	 * The date rule rejects values that only roll over: 2026-02-30 is not a date, so it is refused rather than read
	 * as 2 March. A real leap day and offsets still work.
	 */
	public function test_after_and_before_must_be_real_calendar_date_times(): void {
		$this->act_as_agent();

		$this->assertStringContainsString( 'ISO 8601', $this->refusal_text( $this->tool_result( self::TOOL_NAME, array( 'after' => '2026-02-30T00:00:00Z' ) ) ) );
		$this->assertStringContainsString( 'invalid input', $this->schema_refusal_text( $this->tool_result( self::TOOL_NAME, array( 'after' => '2026-02-01T25:00:00Z' ) ) ) );
		$this->assertStringContainsString( 'invalid input', $this->schema_refusal_text( $this->tool_result( self::TOOL_NAME, array( 'after' => '2026-13-01T00:00:00Z' ) ) ) );
		$this->assertStringContainsString( 'before must be', $this->refusal_text( $this->tool_result( self::TOOL_NAME, array( 'before' => '2026-02-31T00:00:00+01:00' ) ) ) );

		$this->output( self::TOOL_NAME, array( 'after' => '2028-02-29T00:00:00Z' ) );
		$this->output( self::TOOL_NAME, array( 'after' => '2026-02-01T00:00:00+05:30' ) );
	}
}
