<?php
/**
 * The steadhand/update-post ability: which post statuses and requested statuses each grant allows, what a refusal leaves
 * behind, what a change saves, and what the guardrails and the change log do.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Pipeline\Pipeline;
use Steadhand\Roles;
use WP\MCP\Abilities\McpAbilityExposure;

/**
 * Covers SPEC §4.2, §5 (update-post), §6.1, §7, §10 and §14.1. The matrix is the core of it: for every status a post can
 * have, every grant an agent can hold, and every change it can ask for, the call is allowed or refused as §14.1 says, and
 * a refusal leaves the post exactly as it was.
 */
final class UpdatePostTest extends PostWriteTestCase {

	private const TOOL_NAME = 'steadhand-update-post';
	private const ABILITY   = 'steadhand/update-post';

	/**
	 * Block content used as the starting point of most fixtures.
	 */
	private const ORIGINAL = "<!-- wp:paragraph -->\n<p>Original</p>\n<!-- /wp:paragraph -->";

	/**
	 * The grants an agent is tested with. "write" and "publish" say what the grant set means, and "overrides" are the
	 * user-level capability settings that make it so (the agent role grants steadhand_write_drafts by default).
	 */
	private const CAPABILITY_SETS = array(
		'write drafts only' => array(
			'write'     => true,
			'publish'   => false,
			'overrides' => array(),
		),
		'publish only'      => array(
			'write'     => false,
			'publish'   => true,
			'overrides' => array(
				Capabilities::WRITE_DRAFTS => false,
				Capabilities::PUBLISH      => true,
			),
		),
		'both'              => array(
			'write'     => true,
			'publish'   => true,
			'overrides' => array( Capabilities::PUBLISH => true ),
		),
		'neither'           => array(
			'write'     => false,
			'publish'   => false,
			'overrides' => array( Capabilities::WRITE_DRAFTS => false ),
		),
		'user-level denial' => array(
			'write'     => false,
			'publish'   => false,
			'overrides' => array(
				Capabilities::WRITE_DRAFTS => false,
				Capabilities::PUBLISH      => false,
			),
		),
	);

	/**
	 * The statuses a fixture post can start in: the six the plugin handles, and trash.
	 */
	private const STATUSES = array( 'draft', 'pending', 'auto-draft', 'publish', 'future', 'private', 'trash' );

	/**
	 * The changes the matrix makes. "target" is the status the change sets, or null for a title-only change.
	 */
	private const CHANGES = array(
		'title only'                 => null,
		'status to draft'            => 'draft',
		'status to pending'          => 'pending',
		'status to publish'          => 'publish',
		'status to private'          => 'private',
		'status to future with date' => 'future',
	);

	/**
	 * Post fields a refused call must leave byte-identical.
	 */
	private const COMPARED_FIELDS = array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_author', 'post_name', 'post_modified', 'post_date_gmt' );

	/**
	 * The statuses the matrix's write grant covers, as the draft-like group.
	 */
	private const DRAFT_LIKE = array( 'draft', 'pending', 'auto-draft' );

	/**
	 * Every status that counts as live when it is requested or held.
	 */
	private const LIVE = array( 'publish', 'future', 'private' );

	/**
	 * Data for the matrix: every status, every grant set and every change.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function matrix(): array {
		$cases = array();
		foreach ( self::STATUSES as $status ) {
			foreach ( array_keys( self::CAPABILITY_SETS ) as $set ) {
				foreach ( array_keys( self::CHANGES ) as $change ) {
					$cases[ "$status | $set | $change" ] = array( $status, $set, $change );
				}
			}
		}

		return $cases;
	}

	/**
	 * What §14.1 allows for a call, given the post's status, the grants, and the status the call asks for.
	 *
	 * @param string      $status Current status of the post.
	 * @param bool        $write  The agent holds steadhand_write_drafts.
	 * @param bool        $pub    The agent holds steadhand_publish.
	 * @param string|null $target Status the call sets, or null.
	 */
	private function allowed_by_the_rules( string $status, bool $write, bool $pub, ?string $target ): bool {
		if ( ! $write && ! $pub ) {
			return false;
		}

		if ( 'trash' === $status ) {
			return false;
		}

		if ( in_array( $status, self::DRAFT_LIKE, true ) ? ! $write : ! $pub ) {
			return false;
		}

		return null === $target || ! in_array( $target, self::LIVE, true ) || $pub;
	}

	/**
	 * Creates a post by the fixture administrator in the given status, with known content, and returns its ID.
	 *
	 * @param string $status Post status.
	 */
	private function fixture( string $status ): int {
		$fields = array(
			'post_status'  => $status,
			'post_title'   => 'Original title',
			'post_content' => self::ORIGINAL,
			'post_excerpt' => 'Original excerpt',
		);
		if ( 'future' === $status ) {
			$fields['post_date'] = '2099-01-01 00:00:00';
		}

		return $this->admin_post( $fields );
	}

	/**
	 * The input a matrix change sends, for a post.
	 *
	 * @param string $change Change name, a key of CHANGES.
	 * @param int    $post   Post ID.
	 * @return array<string, mixed>
	 */
	private function change_args( string $change, int $post ): array {
		$args = array( 'id' => $post );
		if ( 'title only' === $change ) {
			$args['title'] = 'Matrix title';

			return $args;
		}

		$args['status'] = self::CHANGES[ $change ];
		if ( 'future' === self::CHANGES[ $change ] ) {
			$args['date'] = $this->gmt_in( 2 * DAY_IN_SECONDS );
		}

		return $args;
	}

	/**
	 * The one refusal text every object-level miss gets, for an update that names a title.
	 */
	private const NOT_FOUND = 'Not found, or this agent may not access it.';

	public function test_is_registered_with_strict_schemas_annotations_and_is_not_public(): void {
		$ability = wp_get_ability( self::ABILITY );
		$this->assertNotNull( $ability );
		$this->assertFalse( $ability->get_input_schema()['additionalProperties'] );
		$this->assertFalse( $ability->get_output_schema()['additionalProperties'] );
		$this->assertSame( array( 'id' ), $ability->get_input_schema()['required'] );
		$this->assertArrayNotHasKey( 'default', $ability->get_input_schema()['properties']['status'], 'A missing status means leave it as it is.' );
		$this->assertArrayNotHasKey( 'default', $ability->get_input_schema()['properties']['date'] );

		$meta = $ability->get_meta();
		$this->assertFalse( $meta['annotations']['readonly'] );
		$this->assertTrue( $meta['annotations']['destructive'] );
		$this->assertTrue( $meta['annotations']['idempotent'] );

		$this->assertFalse( McpAbilityExposure::is_public( $ability ) );
		$this->assertFalse( McpAbilityExposure::is_meta_public( $meta ) );
		$this->assertFalse( (bool) ( $meta['show_in_rest'] ?? false ) );

		$this->act_as_agent();
		$names = wp_list_pluck( $this->body( $this->mcp( 'tools/list', array(), $this->mcp_session() ) )['result']['tools'], 'name' );
		$this->assertContains( self::TOOL_NAME, $names );
	}

	/**
	 * Every status, grant set and change: the call is allowed or refused as §14.1 says, and a refusal changes nothing.
	 *
	 * @dataProvider matrix
	 *
	 * @param string $status Status the fixture post starts in.
	 * @param string $set    Grant set, a key of CAPABILITY_SETS.
	 * @param string $change Change, a key of CHANGES.
	 */
	public function test_the_status_rules_decide_each_update_for_every_grant_and_a_refusal_changes_nothing( string $status, string $set, string $change ): void {
		$definition = self::CAPABILITY_SETS[ $set ];
		$post       = $this->fixture( $status );
		$this->act_as_agent( $definition['overrides'] );
		$target  = self::CHANGES[ $change ];
		$allowed = $this->allowed_by_the_rules( $status, $definition['write'], $definition['publish'], $target );
		$before  = $this->row( $post );

		$result = $this->execute_ability( self::ABILITY, $this->change_args( $change, $post ) );

		$label = "$status / $set / $change";
		if ( $allowed ) {
			$this->assertIsArray( $result, "Allowed by the rules, so it must run: $label." );
			if ( null === $target ) {
				$this->assertSame( 'Matrix title', get_post( $post )->post_title, "The title changes: $label." );
			} else {
				// WordPress keeps a post whose date is in the future as scheduled, so publish on it stores future.
				$expected = 'future' === $status && 'publish' === $target ? 'future' : $target;
				$this->assertSame( $expected, get_post( $post )->post_status, "The status changes: $label." );
			}
			$this->assertSame( $before['post_author'], get_post( $post )->post_author, "The author never changes: $label." );

			return;
		}

		$this->assertWPError( $result, "Refused by the rules, so it must not run: $label." );
		$this->assertSame( $before, $this->row( $post ), "A refusal must leave the post byte-identical: $label." );
	}

	public function test_a_draft_written_by_another_user_is_edited_and_its_author_stays(): void {
		$draft = $this->admin_post(
			array(
				'post_status' => 'draft',
				'post_title'  => 'Admin draft',
			)
		);
		$agent = $this->act_as_agent();

		$out = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'    => $draft,
				'title' => 'Edited by the agent',
			)
		);

		$this->assertTrue( $out['changed'] );
		$post = get_post( $draft );
		$this->assertSame( 'Edited by the agent', $post->post_title );
		$this->assertSame( $this->admin_id(), (int) $post->post_author, 'The author is the administrator, not the agent.' );
		$this->assertNotSame( $agent, (int) $post->post_author );
	}

	public function test_properties_that_are_not_in_the_schema_are_refused_one_at_a_time_and_the_post_is_unchanged(): void {
		$post = $this->fixture( 'draft' );
		$this->act_as_agent();
		$before = $this->row( $post );

		foreach ( array(
			'post_author'    => 1,
			'author'         => 1,
			'post_password'  => 'secret',
			'password'       => 'secret',
			'meta'           => array( 'key' => 'value' ),
			'meta_input'     => array( 'key' => 'value' ),
			'tax_input'      => array( 'category' => array( 1 ) ),
			'parent'         => 0,
			'post_parent'    => 0,
			'ID'             => $post,
			'post_type'      => 'page',
			'type'           => 'page',
			'comment_status' => 'open',
		) as $key => $value ) {
			$this->refusal_text(
				$this->tool_result(
					self::TOOL_NAME,
					array(
						'id'    => $post,
						'title' => 'x',
						$key    => $value,
					)
				)
			);

			$this->assertSame( $before, $this->row( $post ), "Sending $key must change nothing." );
		}
	}

	public function test_a_missing_trashed_attachment_disallowed_or_revision_id_gets_the_same_refusal(): void {
		$this->act_as_agent();
		$parent = $this->admin_post();

		$attachment = wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => 'Image',
				'post_mime_type' => 'image/jpeg',
				'post_author'    => $this->admin_id(),
			)
		);
		$revision   = $this->admin_post(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $parent,
			)
		);
		$block      = $this->admin_post( array( 'post_type' => 'wp_block' ) );
		$css        = wp_insert_post(
			array(
				'post_type'    => 'custom_css',
				'post_status'  => 'publish',
				'post_title'   => 'Custom CSS',
				'post_name'    => 'steadhand-update-test-css',
				'post_content' => 'body { color: red; }',
				'post_author'  => $this->admin_id(),
			)
		);

		$cases = array(
			'missing'    => 999999999,
			'trashed'    => $this->admin_post( array( 'post_status' => 'trash' ) ),
			'attachment' => $attachment,
			'revision'   => $revision,
			'wp_block'   => $block,
			'custom_css' => $css,
		);

		$texts = array();
		foreach ( $cases as $case => $id ) {
			$texts[ $case ] = $this->refusal_text(
				$this->tool_result(
					self::TOOL_NAME,
					array(
						'id'    => $id,
						'title' => 'x',
					)
				)
			);
		}

		$this->assertCount( 1, array_unique( array_values( $texts ) ), 'Every refusal must read the same: ' . wp_json_encode( $texts ) );
		$this->assertStringContainsString( self::NOT_FOUND, $texts['missing'] );
	}

	public function test_only_the_submitted_fields_change(): void {
		$post = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_title'   => 'Before',
				'post_content' => self::ORIGINAL,
				'post_excerpt' => 'Keep this excerpt',
				'post_name'    => 'steadhand-keep-slug',
			)
		);
		$this->act_as_agent();
		$before = $this->row( $post );

		$this->output_of(
			self::TOOL_NAME,
			array(
				'id'    => $post,
				'title' => 'After',
			)
		);

		$after = $this->row( $post );
		$this->assertSame( 'After', $after['post_title'] );
		foreach ( array( 'post_content', 'post_excerpt', 'post_name', 'post_status', 'post_author', 'post_date_gmt' ) as $field ) {
			$this->assertSame( $before[ $field ], $after[ $field ], "$field must not change." );
		}
	}

	public function test_a_no_op_update_reports_no_change_saves_no_revision_and_logs_one_row(): void {
		$post      = $this->admin_post(
			array(
				'post_status' => 'draft',
				'post_title'  => 'Same',
			)
		);
		$agent     = $this->act_as_agent();
		$revisions = $this->revision_ids( $post );
		$rows      = $this->count_rows( $agent );

		$out = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'    => $post,
				'title' => 'Same',
			)
		);

		$this->assertFalse( $out['changed'] );
		$this->assertSame( array(), $out['warnings'] );
		$this->assertSame( $revisions, $this->revision_ids( $post ), 'No revision is saved for a call that changes nothing.' );
		$this->assertSame( $rows + 1, $this->count_rows( $agent ), 'The call is still logged once.' );
		$this->assertSame( 'ok', $this->latest_row( $agent )['result'] );
	}

	public function test_the_snapshot_is_a_revision_of_the_content_before_the_update_and_the_row_points_at_it(): void {
		$post  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'Before the update',
			)
		);
		$agent = $this->act_as_agent();

		$out = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'      => $post,
				'content' => 'After the update',
			)
		);

		$this->assertTrue( $out['changed'] );
		$this->assertSame( array(), $out['warnings'] );
		$row = $this->latest_row( $agent );
		$this->assertSame( 'ok', $row['result'] );
		$this->assertSame( 1, (int) $row['undoable'] );

		$revision = (int) $row['snapshot_id'];
		$this->assertGreaterThan( 0, $revision );
		$this->assertSame( 'Before the update', get_post( $revision )->post_content );
		$this->assertSame( $post, (int) get_post( $revision )->post_parent );
		$this->assertSame( 'After the update', get_post( $post )->post_content );
	}

	public function test_with_revisions_turned_off_the_update_succeeds_and_is_reported_as_not_undoable(): void {
		add_filter( 'wp_revisions_to_keep', static fn(): int => 0 );
		$post  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'Before',
			)
		);
		$agent = $this->act_as_agent();

		$out = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'      => $post,
				'content' => 'After',
			)
		);

		$this->assertSame( 'After', get_post( $post )->post_content );
		$this->assertTrue( $out['changed'] );
		$this->assertCount( 1, $out['warnings'] );
		$this->assertStringContainsString( 'cannot undo', $out['warnings'][0] );
		$row = $this->latest_row( $agent );
		$this->assertSame( 'ok', $row['result'] );
		$this->assertSame( 0, (int) $row['undoable'] );
	}

	public function test_a_fresh_edit_lock_held_by_another_user_blocks_the_update_and_a_stale_one_does_not(): void {
		$post  = $this->admin_post(
			array(
				'post_status' => 'draft',
				'post_title'  => 'Locked',
			)
		);
		$human = self::factory()->user->create( array( 'role' => 'editor' ) );
		$agent = $this->act_as_agent();
		update_post_meta( $post, '_edit_lock', time() . ':' . $human );
		$before = $this->row( $post );

		$this->assertSame(
			'steadhand_guardrail_blocked',
			$this->refusal_code(
				$this->execute_ability(
					self::ABILITY,
					array(
						'id'    => $post,
						'title' => 'x',
					)
				)
			)
		);
		$this->assertSame( $before, $this->row( $post ) );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );

		update_post_meta( $post, '_edit_lock', ( time() - 1000 ) . ':' . $human );

		$out = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'    => $post,
				'title' => 'After the stale lock',
			)
		);
		$this->assertTrue( $out['changed'] );
		$this->assertSame( 'After the stale lock', get_post( $post )->post_title );
	}

	public function test_content_over_the_byte_limit_and_unbalanced_markup_leave_the_post_unchanged(): void {
		$post = $this->fixture( 'draft' );
		$this->act_as_agent();
		$before = $this->row( $post );

		$this->assertSame(
			'steadhand_guardrail_blocked',
			$this->refusal_code(
				$this->execute_ability(
					self::ABILITY,
					array(
						'id'      => $post,
						'content' => str_repeat( "\xc3\xa9", 131073 ),
					)
				)
			)
		);
		$this->assertSame(
			'steadhand_guardrail_blocked',
			$this->refusal_code(
				$this->execute_ability(
					self::ABILITY,
					array(
						'id'      => $post,
						'content' => '<!-- wp:group --><p>x</p>',
					)
				)
			)
		);

		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_a_script_tag_is_stripped_and_reported_by_content_modified_on_save(): void {
		$post = $this->fixture( 'draft' );
		$this->act_as_agent();

		$out = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'      => $post,
				'content' => '<p>Hello</p><script>alert(1)</script>',
			)
		);

		$this->assertStringNotContainsString( '<script', get_post( $post )->post_content );
		$this->assertTrue( $out['content_modified_on_save'] );
	}

	public function test_a_failed_log_write_returns_steadhand_log_failed_and_leaves_the_post_unchanged(): void {
		$post = $this->fixture( 'draft' );
		$this->act_as_agent();
		$before = $this->row( $post );
		$this->make_inserts_fail();

		$this->assertSame(
			'steadhand_log_failed',
			$this->refusal_code(
				$this->execute_ability(
					self::ABILITY,
					array(
						'id'    => $post,
						'title' => 'x',
					)
				)
			)
		);

		$this->assertSame( $before, $this->row( $post ), 'An update that cannot be recorded must not run.' );
	}

	public function test_an_application_password_cannot_update_the_post_through_core_rest(): void {
		$post             = $this->fixture( 'draft' );
		$agent            = $this->make_agent();
		$user             = get_userdata( $agent );
		list( $password ) = $this->app_password_for( $agent, 'Regression' );
		$before           = $this->row( $post );

		$resolved = $this->authenticate_with_app_password( $user, $password );
		$this->assertSame( 0, (int) $resolved, 'The lockdown refuses the agent identity outside the Steadhand endpoint.' );
		wp_set_current_user( (int) $resolved );

		$response = $this->rest( 'PUT', '/wp/v2/posts/' . $post, array( 'title' => 'Via REST' ) );

		$this->assertContains( $response->get_status(), array( 401, 403 ) );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_the_post_capability_mapping_is_off_again_once_the_call_returns(): void {
		$post = $this->fixture( 'draft' );
		$this->act_as_agent();

		$this->output_of(
			self::TOOL_NAME,
			array(
				'id'    => $post,
				'title' => 'Mapped only during the call',
			)
		);

		$this->assertFalse( current_user_can( 'edit_post', $post ), 'Outside a Steadhand call the agent holds no edit capability.' );
		$this->assertFalse( Pipeline::is_running() );
	}

	public function test_schedule_rules_for_an_update(): void {
		$post = $this->fixture( 'draft' );
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );
		$before = $this->row( $post );

		foreach ( array(
			'future without a date' => array( 'status' => 'future' ),
			'date without a status' => array( 'date' => $this->gmt_in( DAY_IN_SECONDS ) ),
			'date with draft'       => array(
				'status' => 'draft',
				'date'   => $this->gmt_in( DAY_IN_SECONDS ),
			),
			'30 seconds ahead'      => array(
				'status' => 'future',
				'date'   => $this->gmt_in( 30 ),
			),
			'in the past'           => array(
				'status' => 'future',
				'date'   => $this->gmt_in( -HOUR_IN_SECONDS ),
			),
			'11 years ahead'        => array(
				'status' => 'future',
				'date'   => gmdate( 'Y-m-d\TH:i:s\Z', strtotime( '+11 years' ) ),
			),
		) as $case => $fields ) {
			$this->refusal_text( $this->tool_result( self::TOOL_NAME, array( 'id' => $post ) + $fields ) );
			$this->assertSame( $before, $this->row( $post ), "$case must change nothing." );
		}
	}

	public function test_a_taken_slug_on_publish_gets_a_warning_and_the_stored_slug_is_unique(): void {
		$this->admin_post(
			array(
				'post_name'   => 'steadhand-update-taken',
				'post_status' => 'publish',
			)
		);
		$post = $this->admin_post(
			array(
				'post_name'   => 'steadhand-update-mine',
				'post_status' => 'publish',
			)
		);
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );

		$out = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'   => $post,
				'slug' => 'steadhand-update-taken',
			)
		);

		$this->assertSame( 'steadhand-update-taken-2', get_post( $post )->post_name );
		$this->assertSame( 'steadhand-update-taken-2', $out['slug'] );
		$this->assertCount( 1, $out['warnings'] );
	}

	public function test_a_call_with_no_field_to_change_is_refused(): void {
		$post = $this->fixture( 'draft' );
		$this->act_as_agent();

		$this->refusal_text( $this->tool_result( self::TOOL_NAME, array( 'id' => $post ) ) );
	}

	public function test_a_title_and_content_that_are_both_empty_are_refused(): void {
		$post = $this->fixture( 'draft' );
		$this->act_as_agent();
		$before = $this->row( $post );

		$this->refusal_text(
			$this->tool_result(
				self::TOOL_NAME,
				array(
					'id'      => $post,
					'title'   => ' ',
					'content' => '',
				)
			)
		);

		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_text_that_is_not_valid_utf8_is_refused(): void {
		$post = $this->fixture( 'draft' );
		$this->act_as_agent();
		$before = $this->row( $post );

		$this->assertSame(
			'steadhand_invalid_input',
			$this->refusal_code(
				$this->execute_ability(
					self::ABILITY,
					array(
						'id'    => $post,
						'title' => "bad \xff",
					)
				)
			)
		);

		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_a_successful_update_writes_one_ok_row_that_names_the_post(): void {
		$post  = $this->fixture( 'draft' );
		$agent = $this->act_as_agent();
		$rows  = $this->count_rows( $agent );

		$this->output_of(
			self::TOOL_NAME,
			array(
				'id'    => $post,
				'title' => 'Logged',
			)
		);

		$this->assertSame( $rows + 1, $this->count_rows( $agent ) );
		$row = $this->latest_row( $agent );
		$this->assertSame( 'ok', $row['result'] );
		$this->assertSame( self::ABILITY, $row['ability'] );
		$this->assertSame( 'post', $row['object_type'] );
		$this->assertSame( $post, (int) $row['object_id'] );
	}

	public function test_a_refused_update_writes_one_blocked_row_for_the_post(): void {
		$post  = $this->fixture( 'draft' );
		$agent = $this->act_as_agent( array( Capabilities::WRITE_DRAFTS => false ) );
		$rows  = $this->count_rows( $agent );

		$this->refusal_code(
			$this->execute_ability(
				self::ABILITY,
				array(
					'id'    => $post,
					'title' => 'x',
				)
			)
		);

		$this->assertSame( $rows + 1, $this->count_rows( $agent ) );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
	}
}
