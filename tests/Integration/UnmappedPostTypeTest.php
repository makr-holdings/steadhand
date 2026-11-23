<?php
/**
 * Post types registered with map_meta_cap false, once allowed, cannot be read or edited through Steadhand (SPEC §14.1).
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;

/**
 * Covers the MetaCaps refusal for a type with map_meta_cap off. Core makes none of its per-post checks for such a type,
 * so MetaCaps refuses read_post, edit_post and publish_post for it. Every decision goes through a real
 * current_user_can() call, or a real ability call, and the negative tests check that the post row does not change.
 */
final class UnmappedPostTypeTest extends PostWriteTestCase {

	/** Default capability_type, map_meta_cap false. */
	private const UNMAPPED = 'steadhand_unmapped';

	/** A custom capabilities array with capability_type post and map_meta_cap left at its default, false. */
	private const CAPPED = 'steadhand_capped';

	/** The same registration with map_meta_cap true: the positive control. */
	private const MAPPED = 'steadhand_mapped';

	/**
	 * A type with map_meta_cap false whose read_post is core's read capability, which the agent holds. See the exception test.
	 */
	private const SECRET_RO = 'steadhand_secret_ro';

	/** The two registrations this suite negates. */
	private const UNMAPPED_TYPES = array( self::UNMAPPED, self::CAPPED );

	private const GET_TOOL    = 'steadhand-get-post';
	private const UPDATE      = 'steadhand/update-post';
	private const EDIT_BLOCKS = 'steadhand/edit-blocks';
	private const NOT_FOUND   = 'Not found, or this agent may not access it.';

	/** Two paragraphs, so that edit-blocks has a block to replace. */
	private const CONTENT = "<!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Two</p>\n<!-- /wp:paragraph -->";

	/**
	 * Registers the four types and allows them, so that only map_meta_cap decides what the agent may do.
	 */
	protected function setUp(): void {
		parent::setUp();
		register_post_type(
			self::UNMAPPED,
			array(
				'public'       => true,
				'map_meta_cap' => false,
			)
		);
		register_post_type(
			self::CAPPED,
			array(
				'public'       => true,
				'capabilities' => array( 'create_posts' => 'do_not_allow' ),
			)
		);
		register_post_type(
			self::MAPPED,
			array(
				'public'       => true,
				'map_meta_cap' => true,
			)
		);
		register_post_type(
			self::SECRET_RO,
			array(
				'public'          => true,
				'map_meta_cap'    => false,
				'capability_type' => 'secret_ro',
				'capabilities'    => array( 'read_post' => 'read' ),
			)
		);
		add_filter(
			'steadhand_allowed_post_types',
			static fn( array $types ): array => array_merge( $types, array( self::UNMAPPED, self::CAPPED, self::MAPPED, self::SECRET_RO ) )
		);
	}

	/**
	 * Removes the types this file registers.
	 */
	protected function tearDown(): void {
		foreach ( array( self::UNMAPPED, self::CAPPED, self::MAPPED, self::SECRET_RO ) as $type ) {
			if ( post_type_exists( $type ) ) {
				unregister_post_type( $type );
			}
		}
		parent::tearDown();
	}

	/**
	 * Makes an agent that holds every Steadhand capability at user level, so that only the post type can refuse it.
	 */
	private function full_agent(): int {
		return $this->make_agent( $this->full_agent_overrides() );
	}

	/**
	 * Creates a post of the type, owned by the fixture administrator, with two paragraphs. Returns its ID.
	 *
	 * @param string $type   Post type.
	 * @param string $status Post status.
	 */
	private function fixture( string $type, string $status ): int {
		return $this->admin_post(
			array(
				'post_type'    => $type,
				'post_status'  => $status,
				'post_content' => self::CONTENT,
			)
		);
	}

	/**
	 * Input that replaces the second paragraph of the fixture.
	 *
	 * @param int $post Post ID.
	 * @return array<string, mixed>
	 */
	private function replace_second_paragraph( int $post ): array {
		return array(
			'id'         => $post,
			'operations' => array(
				array(
					'op'     => 'replace',
					'path'   => '1',
					'name'   => 'core/paragraph',
					'markup' => "<!-- wp:paragraph -->\n<p>Changed</p>\n<!-- /wp:paragraph -->",
				),
			),
		);
	}

	/**
	 * Runs a check inside a real write call made by the current user, and returns what the check answered.
	 *
	 * @param callable $check Receives nothing, returns an array.
	 * @return array<string, mixed>
	 */
	private function inside_write( callable $check ): array {
		return $this->in_write( static fn(): array => $check() );
	}

	public function test_update_post_is_refused_on_a_draft_and_the_row_is_unchanged(): void {
		$this->act_as_agent( $this->full_agent_overrides() );

		foreach ( self::UNMAPPED_TYPES as $type ) {
			$post   = $this->fixture( $type, 'draft' );
			$before = $this->row( $post );

			$result = $this->execute_ability(
				self::UPDATE,
				array(
					'id'    => $post,
					'title' => 'Changed',
				)
			);

			$this->assertWPError( $result, "update-post on a draft of $type must be refused." );
			$this->assertSame( $before, $this->row( $post ), "A refused update leaves the $type draft unchanged." );
		}
	}

	public function test_update_post_is_refused_on_a_published_post_and_the_row_is_unchanged(): void {
		$this->act_as_agent( $this->full_agent_overrides() );

		foreach ( self::UNMAPPED_TYPES as $type ) {
			$post   = $this->fixture( $type, 'publish' );
			$before = $this->row( $post );

			$result = $this->execute_ability(
				self::UPDATE,
				array(
					'id'    => $post,
					'title' => 'Changed',
				)
			);

			$this->assertWPError( $result, "update-post on a published $type post must be refused." );
			$this->assertSame( $before, $this->row( $post ), "A refused update leaves the published $type post unchanged." );
		}
	}

	public function test_update_post_cannot_publish_a_draft_of_such_a_type(): void {
		$this->act_as_agent( $this->full_agent_overrides() );

		foreach ( self::UNMAPPED_TYPES as $type ) {
			$post   = $this->fixture( $type, 'draft' );
			$before = $this->row( $post );

			$result = $this->execute_ability(
				self::UPDATE,
				array(
					'id'     => $post,
					'status' => 'publish',
				)
			);

			$this->assertWPError( $result, "Publishing a $type draft through update-post must be refused." );
			$this->assertSame( $before, $this->row( $post ), "The $type draft stays a draft." );
			$this->assertSame( 'draft', get_post( $post )->post_status );
		}
	}

	public function test_edit_blocks_is_refused_on_a_draft_and_on_a_published_post_and_the_row_is_unchanged(): void {
		$this->act_as_agent( $this->full_agent_overrides() );

		foreach ( self::UNMAPPED_TYPES as $type ) {
			foreach ( array( 'draft', 'publish' ) as $status ) {
				$post   = $this->fixture( $type, $status );
				$before = $this->row( $post );

				$result = $this->execute_ability( self::EDIT_BLOCKS, $this->replace_second_paragraph( $post ) );

				$this->assertWPError( $result, "edit-blocks on a $status $type post must be refused." );
				$this->assertSame( $before, $this->row( $post ), "A refused edit-blocks leaves the $status $type post unchanged." );
			}
		}
	}

	public function test_get_post_is_refused_for_a_published_post_a_private_post_and_another_authors_draft(): void {
		$this->act_as_agent( $this->full_agent_overrides() );

		foreach ( self::UNMAPPED_TYPES as $type ) {
			foreach ( array( 'publish', 'private', 'draft' ) as $status ) {
				$post = $this->fixture( $type, $status );

				$this->assertSame(
					self::NOT_FOUND,
					$this->refusal_text( $this->tool_result( self::GET_TOOL, array( 'id' => $post ) ) ),
					"get-post on a $status $type post by another author must be refused."
				);
			}
		}
	}

	public function test_inside_a_write_call_the_agent_holds_no_post_capability_on_such_a_type(): void {
		$this->act_as_agent( $this->full_agent_overrides() );

		foreach ( self::UNMAPPED_TYPES as $type ) {
			$draft     = $this->fixture( $type, 'draft' );
			$published = $this->fixture( $type, 'publish' );

			$answers = $this->inside_write(
				static fn(): array => array(
					'edit_draft'     => current_user_can( 'edit_post', $draft ),
					'publish_draft'  => current_user_can( 'publish_post', $draft ),
					'read_draft'     => current_user_can( 'read_post', $draft ),
					'edit_published' => current_user_can( 'edit_post', $published ),
					'publish_pub'    => current_user_can( 'publish_post', $published ),
					'read_published' => current_user_can( 'read_post', $published ),
					'delete_draft'   => current_user_can( 'delete_post', $draft ),
				)
			);

			foreach ( $answers as $question => $answer ) {
				$this->assertFalse( $answer, "$question on a $type post, inside a write call, must be false for the agent." );
			}
		}
	}

	/**
	 * The same registration with map_meta_cap true is mapped. This shows that the refusals above are the new rule and not
	 * some other failure of the fixtures.
	 */
	public function test_positive_control_a_mapped_type_is_edited_through_update_post(): void {
		$this->act_as_agent( $this->full_agent_overrides() );
		$draft = $this->fixture( self::MAPPED, 'draft' );

		$result = $this->execute_ability(
			self::UPDATE,
			array(
				'id'    => $draft,
				'title' => 'Changed',
			)
		);

		$this->assertIsArray( $result, 'update-post on a draft of a map_meta_cap true type must run.' );
		$this->assertSame( 'Changed', get_post( $draft )->post_title );
	}

	public function test_positive_control_a_mapped_type_is_granted_inside_a_write_call_and_readable(): void {
		$this->act_as_agent( $this->full_agent_overrides() );
		$draft     = $this->fixture( self::MAPPED, 'draft' );
		$published = $this->fixture( self::MAPPED, 'publish' );

		$answers = $this->inside_write(
			static fn(): array => array(
				'edit_draft'     => current_user_can( 'edit_post', $draft ),
				'read_published' => current_user_can( 'read_post', $published ),
			)
		);

		$this->assertTrue( $answers['edit_draft'], 'edit_post on a draft of a map_meta_cap true type is granted.' );
		$this->assertTrue( $answers['read_published'], 'read_post on a published post of a map_meta_cap true type is granted.' );
		$this->assertSame( 'publish', $this->output_of( self::GET_TOOL, array( 'id' => $published ) )['status'] );
	}

	/**
	 * The administrator is not a dedicated agent, so the mapping must return core's answer unchanged, inside a write call as
	 * well as outside one. The expected answers are taken outside a Steadhand call, where MetaCaps does nothing. Core may
	 * refuse the administrator some of these (edit_post on an unmapped type is not a primitive the admin role holds), so
	 * the test compares with core, not with a fixed true or false.
	 */
	public function test_an_administrator_keeps_core_answers_on_such_a_type_inside_a_write_call(): void {
		$agent = $this->full_agent();
		wp_set_current_user( $agent );
		$admin = $this->admin_id();

		foreach ( self::UNMAPPED_TYPES as $type ) {
			$draft     = $this->fixture( $type, 'draft' );
			$published = $this->fixture( $type, 'publish' );

			$ask = static function () use ( $draft, $published ): array {
				return array(
					'edit_draft'     => current_user_can( 'edit_post', $draft ),
					'publish_draft'  => current_user_can( 'publish_post', $draft ),
					'read_draft'     => current_user_can( 'read_post', $draft ),
					'edit_published' => current_user_can( 'edit_post', $published ),
					'read_published' => current_user_can( 'read_post', $published ),
				);
			};

			wp_set_current_user( $admin );
			$core = $ask();
			wp_set_current_user( $agent );

			$inside = $this->inside_write(
				static function () use ( $ask, $admin, $agent ): array {
					wp_set_current_user( $admin );
					try {
						return $ask();
					} finally {
						wp_set_current_user( $agent );
					}
				}
			);

			$this->assertSame( $core, $inside, "An administrator's answers on a $type post inside a write call are core's answers." );
			$this->assertContains( true, $core, "The comparison is not vacuous: core allows the administrator something on a $type post." );
		}
	}

	/**
	 * The one case where the type's own capabilities decide: a type whose read_post is core's read capability. Core asks
	 * for read, not for read_post, so MetaCaps does not run, and the agent's steadhand_read is enough. Core's read does not
	 * depend on the status, so every status except trash is readable. The object check (PostReader::readable_post()) still
	 * refuses the trash.
	 */
	public function test_exception_a_type_whose_own_read_capability_is_read_is_readable_in_every_status_but_trash(): void {
		$this->act_as_agent( $this->full_agent_overrides() );

		foreach ( array( 'publish', 'draft', 'pending', 'private' ) as $status ) {
			$post = $this->fixture( self::SECRET_RO, $status );
			$this->assertSame( $status, $this->output_of( self::GET_TOOL, array( 'id' => $post ) )['status'], "A $status post of this type is readable." );
		}

		$draft  = $this->fixture( self::SECRET_RO, 'draft' );
		$before = $this->row( $draft );
		$this->assertWPError(
			$this->execute_ability(
				self::UPDATE,
				array(
					'id'    => $draft,
					'title' => 'Changed',
				)
			),
			'Editing a draft of this type is still refused: core answers with its own renamed capability, which the agent lacks.'
		);
		$this->assertSame( $before, $this->row( $draft ) );

		$trashed = $this->fixture( self::SECRET_RO, 'trash' );
		$this->assertSame(
			self::NOT_FOUND,
			$this->refusal_text( $this->tool_result( self::GET_TOOL, array( 'id' => $trashed ) ) ),
			'A trashed post is still refused.'
		);
	}

	/**
	 * The user-level grants of full_agent(), as the overrides act_as_agent() takes them.
	 *
	 * @return array<string, bool>
	 */
	private function full_agent_overrides(): array {
		return array(
			Capabilities::CONNECT      => true,
			Capabilities::READ         => true,
			Capabilities::WRITE_DRAFTS => true,
			Capabilities::PUBLISH      => true,
			Capabilities::MEDIA        => true,
			Capabilities::MENUS        => true,
			Capabilities::TRASH        => true,
			Capabilities::SETTINGS     => true,
		);
	}
}
