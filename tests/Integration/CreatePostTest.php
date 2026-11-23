<?php
/**
 * The steadhand/create-post ability, end to end over MCP and through the Abilities API: who may create, what they may set,
 * what the content rules refuse, and what the change log records.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Roles;
use WP\MCP\Abilities\McpAbilityExposure;

/**
 * Covers SPEC §4.2, §5 (create-post), §6.1, §7 and §14.1.
 */
final class CreatePostTest extends PostWriteTestCase {

	private const TOOL_NAME = 'steadhand-create-post';
	private const ABILITY   = 'steadhand/create-post';

	/**
	 * A block document that WordPress must store exactly as sent.
	 */
	private const BLOCKS = "<!-- wp:paragraph -->\n<p>Hello, world</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:heading {\"level\":2} -->\n<h2 class=\"wp-block-heading\">A heading</h2>\n<!-- /wp:heading -->";

	/**
	 * Properties an agent must not be able to set, each with a value of the right kind.
	 *
	 * @return array<string, array{0: string, 1: mixed}>
	 */
	private static function forbidden_properties(): array {
		return array(
			'post_author'    => array( 'post_author', 1 ),
			'author'         => array( 'author', 1 ),
			'post_password'  => array( 'post_password', 'secret' ),
			'password'       => array( 'password', 'secret' ),
			'meta'           => array( 'meta', array( 'key' => 'value' ) ),
			'meta_input'     => array( 'meta_input', array( 'key' => 'value' ) ),
			'tax_input'      => array( 'tax_input', array( 'category' => array( 1 ) ) ),
			'parent'         => array( 'parent', 0 ),
			'post_parent'    => array( 'post_parent', 0 ),
			'ID'             => array( 'ID', 1 ),
			'id'             => array( 'id', 1 ),
			'comment_status' => array( 'comment_status', 'open' ),
		);
	}

	public function test_is_registered_with_strict_schemas_annotations_and_is_not_public(): void {
		$ability = wp_get_ability( self::ABILITY );
		$this->assertNotNull( $ability );
		$this->assertFalse( $ability->get_input_schema()['additionalProperties'] );
		$this->assertFalse( $ability->get_output_schema()['additionalProperties'] );
		$this->assertSame( 'post', $ability->get_input_schema()['properties']['post_type']['default'] );
		$this->assertSame( 'draft', $ability->get_input_schema()['properties']['status']['default'] );

		$meta = $ability->get_meta();
		$this->assertFalse( $meta['annotations']['readonly'] );
		$this->assertFalse( $meta['annotations']['destructive'] );
		$this->assertFalse( $meta['annotations']['idempotent'] );

		$this->assertFalse( McpAbilityExposure::is_public( $ability ) );
		$this->assertFalse( McpAbilityExposure::is_meta_public( $meta ) );
		$this->assertFalse( (bool) ( $meta['show_in_rest'] ?? false ) );

		$this->act_as_agent();
		$names = wp_list_pluck( $this->body( $this->mcp( 'tools/list', array(), $this->mcp_session() ) )['result']['tools'], 'name' );
		$this->assertContains( self::TOOL_NAME, $names );
	}

	public function test_an_agent_with_write_drafts_creates_a_draft_that_it_authors(): void {
		$agent  = $this->act_as_agent();
		$before = $this->post_row_count();

		$out = $this->output_of(
			self::TOOL_NAME,
			array(
				'title'   => 'Hello from the agent',
				'content' => self::BLOCKS,
			)
		);

		$this->assertSame( 'draft', $out['status'] );
		$this->assertSame( 'post', $out['post_type'] );
		$this->assertSame( 'Hello from the agent', $out['title'] );
		$this->assertTrue( $out['changed'] );
		$this->assertSame( array(), $out['warnings'] );
		$this->assertSame( array(), $out['guardrail_notes'] );

		$post = get_post( $out['id'] );
		$this->assertSame( $agent, (int) $post->post_author, 'The agent is the author.' );
		$this->assertSame( 'draft', $post->post_status );
		$this->assertSame( $before + 1, $this->post_row_count() );
	}

	public function test_a_page_can_be_created(): void {
		$this->act_as_agent();

		$out = $this->output_of(
			self::TOOL_NAME,
			array(
				'post_type' => 'page',
				'title'     => 'About',
			)
		);

		$this->assertSame( 'page', $out['post_type'] );
		$this->assertSame( 'page', get_post( $out['id'] )->post_type );
	}

	public function test_a_disallowed_or_always_excluded_type_is_refused_and_nothing_is_created(): void {
		$this->act_as_agent();
		$before = $this->post_row_count();

		foreach ( array( 'attachment', 'wp_template', 'wp_template_part', 'revision', 'nav_menu_item', 'wp_block', 'custom_css', 'user_request' ) as $type ) {
			$this->refusal_text(
				$this->tool_result(
					self::TOOL_NAME,
					array(
						'post_type' => $type,
						'title'     => 'x',
					)
				)
			);
		}

		$this->assertSame( $before, $this->post_row_count() );
	}

	public function test_an_agent_without_write_drafts_is_refused_even_with_read_or_publish(): void {
		$before = $this->post_row_count();

		foreach ( array(
			array(
				Capabilities::WRITE_DRAFTS => false,
				Capabilities::READ         => true,
			),
			array(
				Capabilities::WRITE_DRAFTS => false,
				Capabilities::PUBLISH      => true,
			),
		) as $overrides ) {
			$this->act_as_agent( $overrides );

			$text = $this->refusal_text( $this->tool_result( self::TOOL_NAME, array( 'title' => 'x' ) ) );

			$this->assertStringContainsString( 'steadhand_write_drafts', $text );
		}

		$this->assertSame( $before, $this->post_row_count() );
	}

	public function test_an_administrator_an_anonymous_caller_and_a_mixed_role_user_are_refused(): void {
		$before = $this->post_row_count();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 'steadhand_forbidden', $this->refusal_code( $this->execute_ability( self::ABILITY, array( 'title' => 'x' ) ) ) );

		wp_set_current_user( 0 );
		$this->assertSame( 'steadhand_forbidden', $this->refusal_code( $this->execute_ability( self::ABILITY, array( 'title' => 'x' ) ) ) );

		$mixed = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_userdata( $mixed )->add_role( Roles::ROLE );
		wp_set_current_user( $mixed );
		$this->assertSame( 'steadhand_forbidden', $this->refusal_code( $this->execute_ability( self::ABILITY, array( 'title' => 'x' ) ) ) );

		$this->assertSame( $before, $this->post_row_count() );
	}

	public function test_statuses_other_than_draft_need_publish_and_nothing_is_created_without_it(): void {
		$this->act_as_agent();
		$before = $this->post_row_count();

		foreach ( array( 'publish', 'pending', 'private' ) as $status ) {
			$this->assertStringContainsString(
				'steadhand_publish',
				$this->refusal_text(
					$this->tool_result(
						self::TOOL_NAME,
						array(
							'title'  => 'x',
							'status' => $status,
						)
					)
				),
				"status $status"
			);
		}

		$this->assertStringContainsString(
			'steadhand_publish',
			$this->refusal_text(
				$this->tool_result(
					self::TOOL_NAME,
					array(
						'title'  => 'x',
						'status' => 'future',
						'date'   => $this->gmt_in( DAY_IN_SECONDS ),
					)
				)
			),
			'status future'
		);

		$this->assertSame( $before, $this->post_row_count(), 'No post is created by a refused status.' );
	}

	public function test_with_publish_each_status_is_stored_as_asked(): void {
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );

		foreach ( array( 'publish', 'pending', 'private' ) as $status ) {
			$out = $this->output_of(
				self::TOOL_NAME,
				array(
					'title'  => "Status $status",
					'status' => $status,
				)
			);

			$this->assertSame( $status, $out['status'] );
			$this->assertSame( $status, get_post( $out['id'] )->post_status );
		}

		$when = $this->gmt_in( DAY_IN_SECONDS );
		$out  = $this->output_of(
			self::TOOL_NAME,
			array(
				'title'  => 'Later',
				'status' => 'future',
				'date'   => $when,
			)
		);

		$this->assertSame( 'future', $out['status'] );
		$this->assertSame( gmdate( 'Y-m-d H:i:s', strtotime( $when ) ), get_post( $out['id'] )->post_date_gmt );
	}

	public function test_a_future_status_without_a_date_is_refused(): void {
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );

		$text = $this->refusal_text(
			$this->tool_result(
				self::TOOL_NAME,
				array(
					'title'  => 'x',
					'status' => 'future',
				)
			)
		);

		$this->assertStringContainsString( 'needs a date', $text );
	}

	public function test_a_date_with_a_status_other_than_future_is_refused(): void {
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );
		$before = $this->post_row_count();

		$this->refusal_text(
			$this->tool_result(
				self::TOOL_NAME,
				array(
					'title' => 'x',
					'date'  => $this->gmt_in( DAY_IN_SECONDS ),
				)
			)
		);
		$this->refusal_text(
			$this->tool_result(
				self::TOOL_NAME,
				array(
					'title'  => 'x',
					'status' => 'draft',
					'date'   => $this->gmt_in( DAY_IN_SECONDS ),
				)
			)
		);

		$this->assertSame( $before, $this->post_row_count() );
	}

	public function test_a_scheduled_date_must_be_at_least_a_minute_ahead_and_at_most_ten_years(): void {
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );
		$before = $this->post_row_count();

		foreach ( array(
			'in the past'    => $this->gmt_in( -HOUR_IN_SECONDS ),
			'30 s ahead'     => $this->gmt_in( 30 ),
			'11 years ahead' => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '+11 years' ) ),
		) as $case => $date ) {
			$this->assertStringContainsString(
				'date',
				$this->refusal_text(
					$this->tool_result(
						self::TOOL_NAME,
						array(
							'title'  => 'x',
							'status' => 'future',
							'date'   => $date,
						)
					)
				),
				$case
			);
		}

		$this->assertSame( $before, $this->post_row_count() );

		$out = $this->output_of(
			self::TOOL_NAME,
			array(
				'title'  => 'Two minutes',
				'status' => 'future',
				'date'   => $this->gmt_in( 120 ),
			)
		);
		$this->assertSame( 'future', $out['status'], 'Two minutes ahead is allowed.' );
	}

	public function test_properties_that_are_not_in_the_schema_are_refused_one_at_a_time_and_nothing_is_created(): void {
		$this->act_as_agent();
		$before = $this->post_row_count();

		foreach ( self::forbidden_properties() as $case => $pair ) {
			list( $key, $value ) = $pair;

			$this->schema_refusal_text(
				$this->tool_result(
					self::TOOL_NAME,
					array(
						'title' => 'x',
						$key    => $value,
					)
				)
			);

			$this->assertSame( $before, $this->post_row_count(), "Sending $case must not create a post." );
		}
	}

	public function test_an_empty_title_and_content_together_are_refused(): void {
		$this->act_as_agent();

		$text = $this->refusal_text(
			$this->tool_result(
				self::TOOL_NAME,
				array(
					'title'   => '   ',
					'content' => '',
				)
			)
		);

		$this->assertStringContainsString( 'title', $text );
	}

	public function test_text_that_is_not_valid_utf8_is_refused(): void {
		$this->act_as_agent();
		$before = $this->post_row_count();

		$this->assertSame( 'steadhand_invalid_input', $this->refusal_code( $this->execute_ability( self::ABILITY, array( 'title' => "bad \xff" ) ) ) );
		$this->assertSame(
			'steadhand_invalid_input',
			$this->refusal_code(
				$this->execute_ability(
					self::ABILITY,
					array(
						'title'   => 'x',
						'excerpt' => "bad \xc3",
					)
				)
			)
		);

		$this->assertSame( $before, $this->post_row_count() );
	}

	public function test_content_over_the_byte_limit_is_refused_by_the_guardrail_and_nothing_is_created(): void {
		$agent  = $this->act_as_agent();
		$before = $this->post_row_count();

		// 131073 characters of two bytes each: 262146 bytes, which is over the byte limit but within the character schema.
		$code = $this->refusal_code( $this->execute_ability( self::ABILITY, array( 'content' => str_repeat( "\xc3\xa9", 131073 ) ) ) );

		$this->assertSame( 'steadhand_guardrail_blocked', $code );
		$this->assertSame( $before, $this->post_row_count() );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
	}

	public function test_ascii_content_over_the_character_limit_is_refused_by_the_schema_before_any_rule_runs(): void {
		$this->act_as_agent();
		$before = $this->post_row_count();

		$this->assertWPError( $this->execute_ability( self::ABILITY, array( 'content' => str_repeat( 'a', 262145 ) ) ) );

		$this->assertSame( $before, $this->post_row_count() );
	}

	public function test_block_markup_whose_comments_do_not_balance_is_refused_and_nothing_is_created(): void {
		$this->act_as_agent();
		$before = $this->post_row_count();

		$code = $this->refusal_code(
			$this->execute_ability(
				self::ABILITY,
				array(
					'title'   => 'x',
					'content' => '<!-- wp:group --><p>x</p>',
				)
			)
		);

		$this->assertSame( 'steadhand_guardrail_blocked', $code );
		$this->assertSame( $before, $this->post_row_count() );
	}

	public function test_a_valid_block_document_is_stored_byte_identical(): void {
		$this->act_as_agent();

		$out = $this->execute_ability(
			self::ABILITY,
			array(
				'title'   => 'Blocks',
				'content' => self::BLOCKS,
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( self::BLOCKS, get_post( $out['id'] )->post_content );
		$this->assertFalse( $out['content_modified_on_save'] );
	}

	public function test_a_script_tag_is_stripped_and_reported_and_clean_content_is_not(): void {
		$this->act_as_agent();

		$dirty = $this->output_of(
			self::TOOL_NAME,
			array(
				'title'   => 'Dirty',
				'content' => '<p>Hello</p><script>alert(1)</script>',
			)
		);
		$this->assertStringNotContainsString( '<script', get_post( $dirty['id'] )->post_content );
		$this->assertTrue( $dirty['content_modified_on_save'] );

		$clean = $this->output_of(
			self::TOOL_NAME,
			array(
				'title'   => 'Clean',
				'content' => '<p>Hello <strong>there</strong></p>',
			)
		);
		$this->assertFalse( $clean['content_modified_on_save'] );
	}

	public function test_a_slug_is_honoured(): void {
		$this->act_as_agent();

		$out = $this->output_of(
			self::TOOL_NAME,
			array(
				'title' => 'Slug test',
				'slug'  => 'steadhand-honoured-slug',
			)
		);

		$this->assertSame( 'steadhand-honoured-slug', $out['slug'] );
		$this->assertSame( 'steadhand-honoured-slug', get_post( $out['id'] )->post_name );
		$this->assertSame( array(), $out['warnings'] );
	}

	public function test_a_slug_that_is_not_lowercase_words_is_refused_by_the_schema(): void {
		$this->act_as_agent();

		$this->schema_refusal_text(
			$this->tool_result(
				self::TOOL_NAME,
				array(
					'title' => 'x',
					'slug'  => 'Not A Slug',
				)
			)
		);
		$this->schema_refusal_text(
			$this->tool_result(
				self::TOOL_NAME,
				array(
					'title' => 'x',
					'slug'  => "trailing-newline\n",
				)
			)
		);
	}

	public function test_a_published_slug_that_is_taken_gets_a_warning_and_a_unique_stored_slug(): void {
		$this->admin_post(
			array(
				'post_name'   => 'steadhand-dup-slug',
				'post_status' => 'publish',
			)
		);
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );

		$out = $this->output_of(
			self::TOOL_NAME,
			array(
				'title'  => 'Duplicate',
				'status' => 'publish',
				'slug'   => 'steadhand-dup-slug',
			)
		);

		$this->assertSame( 'steadhand-dup-slug-2', $out['slug'] );
		$this->assertSame( 'steadhand-dup-slug-2', get_post( $out['id'] )->post_name );
		$this->assertCount( 1, $out['warnings'] );
	}

	public function test_core_keeps_the_slug_of_a_draft_as_sent_even_when_it_is_taken(): void {
		$this->admin_post(
			array(
				'post_name'   => 'steadhand-draft-dup',
				'post_status' => 'publish',
			)
		);
		$this->act_as_agent();

		$out = $this->output_of(
			self::TOOL_NAME,
			array(
				'title' => 'Draft duplicate',
				'slug'  => 'steadhand-draft-dup',
			)
		);

		$this->assertSame( 'steadhand-draft-dup', $out['slug'], 'WordPress does not make a draft slug unique until it is published.' );
		$this->assertSame( array(), $out['warnings'] );
	}

	public function test_a_slug_sent_with_pending_status_is_not_kept_and_is_reported(): void {
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );

		$out = $this->output_of(
			self::TOOL_NAME,
			array(
				'title'  => 'Pending',
				'status' => 'pending',
				'slug'   => 'steadhand-pending-slug',
			)
		);

		$this->assertSame( '', get_post( $out['id'] )->post_name, 'WordPress stores no slug for a pending post.' );
		$this->assertCount( 1, $out['warnings'] );
		$this->assertStringContainsString( 'steadhand-pending-slug', $out['warnings'][0] );
	}

	public function test_a_bare_ampersand_is_stored_as_an_entity_and_reported_as_changed_on_save(): void {
		$this->act_as_agent();

		$out = $this->output_of(
			self::TOOL_NAME,
			array(
				'title'   => 'Tom & Jerry',
				'content' => '<p>a & b</p>',
			)
		);

		$this->assertSame( 'Tom &amp; Jerry', get_post( $out['id'] )->post_title );
		$this->assertSame( '<p>a &amp; b</p>', get_post( $out['id'] )->post_content );
		$this->assertTrue( $out['content_modified_on_save'] );
		$this->assertSame( 'Tom & Jerry', $out['title'], 'The output title is plain text.' );
	}

	public function test_a_successful_create_writes_one_ok_row_for_the_post(): void {
		$agent  = $this->act_as_agent();
		$before = $this->count_rows( $agent );

		$out = $this->output_of( self::TOOL_NAME, array( 'title' => 'Logged' ) );

		$this->assertSame( $before + 1, $this->count_rows( $agent ) );
		$row = $this->latest_row( $agent );
		$this->assertSame( 'ok', $row['result'] );
		$this->assertSame( self::ABILITY, $row['ability'] );
		$this->assertSame( 'post', $row['object_type'] );
		$this->assertSame( $out['id'], (int) $row['object_id'] );
		// A created post has no revision to save, and the pipeline records only a non-zero snapshot as undoable. See the report.
		$this->assertSame( 0, (int) $row['undoable'] );
	}

	public function test_a_refused_create_writes_one_blocked_row(): void {
		$agent  = $this->act_as_agent();
		$before = $this->count_rows( $agent );

		$this->refusal_code(
			$this->execute_ability(
				self::ABILITY,
				array(
					'title'  => 'x',
					'status' => 'publish',
				)
			)
		);

		$this->assertSame( $before + 1, $this->count_rows( $agent ) );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
	}

	public function test_a_guardrail_block_writes_one_blocked_row_that_names_the_rule(): void {
		$agent  = $this->act_as_agent();
		$before = $this->count_rows( $agent );

		$this->refusal_code(
			$this->execute_ability(
				self::ABILITY,
				array(
					'title'   => 'x',
					'content' => '<!-- wp:group --><p>x</p>',
				)
			)
		);

		$this->assertSame( $before + 1, $this->count_rows( $agent ) );
		$row = $this->latest_row( $agent );
		$this->assertSame( 'blocked', $row['result'] );
		$this->assertStringContainsString( 'steadhand/block-markup', $row['guardrail_notes'] );
	}

	public function test_get_change_log_shows_the_create_to_its_agent_and_not_to_another(): void {
		$mine  = $this->act_as_agent();
		$out   = $this->output_of( self::TOOL_NAME, array( 'title' => 'Mine' ) );
		$other = $this->other_agent();

		wp_set_current_user( $mine );
		$items = $this->output_of( 'steadhand-get-change-log' )['items'];
		$this->assertContains( $out['id'], array_column( $items, 'object_id' ) );
		$this->assertContains( self::ABILITY, array_column( $items, 'ability' ) );

		wp_set_current_user( $other );
		$others = $this->output_of( 'steadhand-get-change-log' )['items'];
		$this->assertNotContains( self::ABILITY, array_column( $others, 'ability' ) );
	}

	public function test_a_failed_log_write_returns_steadhand_log_failed_and_creates_nothing(): void {
		$this->act_as_agent();
		$before = $this->post_row_count();
		$this->make_inserts_fail();

		$this->assertSame( 'steadhand_log_failed', $this->refusal_code( $this->execute_ability( self::ABILITY, array( 'title' => 'x' ) ) ) );

		$this->assertSame( $before, $this->post_row_count(), 'A create that cannot be recorded must not run.' );
	}

	public function test_the_thirty_first_create_in_a_minute_is_refused_and_nothing_is_created(): void {
		$agent = $this->act_as_agent();
		for ( $i = 0; $i < 29; $i++ ) {
			$this->insert_row(
				array(
					'user_id' => $agent,
					'ability' => self::ABILITY,
					'result'  => 'ok',
				)
			);
		}

		$this->assertIsArray( $this->execute_ability( self::ABILITY, array( 'title' => 'Thirtieth' ) ), 'The thirtieth write in the window is allowed.' );

		$before = $this->post_row_count();
		$code   = $this->refusal_code( $this->execute_ability( self::ABILITY, array( 'title' => 'Thirty-first' ) ) );

		$this->assertSame( 'steadhand_guardrail_blocked', $code );
		$this->assertSame( $before, $this->post_row_count() );
	}

	public function test_the_output_always_carries_an_empty_guardrail_notes_list_when_nothing_was_said(): void {
		$this->act_as_agent();

		$out = $this->output_of( self::TOOL_NAME, array( 'title' => 'Notes' ) );

		$this->assertIsArray( $out['guardrail_notes'] );
		$this->assertSame( array(), $out['guardrail_notes'] );
	}
}
