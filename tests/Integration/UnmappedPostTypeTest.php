<?php
/**
 * Post types registered with map_meta_cap off are never allowed, so no Steadhand tool reads or writes their posts or
 * their attachments' parents (SPEC §14.1).
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\PostTypes;
use Steadhand\Taxonomies;

/**
 * Each negative type below is added through steadhand_allowed_post_types, and every tool must still refuse it. The
 * positive controls are the same kind of registration with map_meta_cap on (true, and the truthy 1), and they are
 * allowed and work. Core makes none of its per-post checks for a type with map_meta_cap off, and for some types it renames
 * the capability to one the agent holds (read), so the allowlist is the layer that refuses them. MetaCaps refuses them too,
 * as a second layer where core's capability name reaches it.
 */
final class UnmappedPostTypeTest extends PostWriteTestCase {

	/** Default capability_type, map_meta_cap false. */
	private const UNMAPPED = 'steadhand_unmapped';

	/** Custom capabilities and no map_meta_cap, so map_meta_cap is false. */
	private const CAPPED = 'steadhand_capped';

	/** Map_meta_cap false, with read_post renamed to core's read capability, which the agent holds. */
	private const READ_CAP = 'steadhand_read_cap';

	/** Map_meta_cap false, with read_post and edit_post renamed to read. */
	private const FULL_READ = 'steadhand_full_read';

	/** Capability_type page, map_meta_cap false. */
	private const PAGE_CAP = 'steadhand_page_cap';

	/** The positive control: map_meta_cap true. */
	private const MAPPED = 'steadhand_mapped';

	/** The positive control: map_meta_cap 1, which is truthy. */
	private const MAPPED_INT = 'steadhand_mapped_int';

	/** Types the rule must refuse. */
	private const NEGATIVE_TYPES = array( self::UNMAPPED, self::CAPPED, self::READ_CAP, self::FULL_READ, self::PAGE_CAP );

	/** Types the rule must allow. */
	private const POSITIVE_TYPES = array( self::MAPPED, self::MAPPED_INT );

	/** A taxonomy attached to every type above and to post, with an assign capability the agent holds. */
	private const TAX = 'steadhand_unmapped_tax';

	/** A public taxonomy attached only to the negative types, so the taxonomy allowlist must refuse it. */
	private const TAX_ONLY = 'steadhand_unmapped_only';

	private const UPDATE      = 'steadhand/update-post';
	private const EDIT_BLOCKS = 'steadhand/edit-blocks';
	private const CREATE      = 'steadhand-create-post';
	private const SET_TERMS   = 'steadhand-set-post-terms';
	private const GET_POST    = 'steadhand-get-post';
	private const GET_BLOCKS  = 'steadhand-get-blocks';
	private const GET_RENDER  = 'steadhand-get-rendered';
	private const TERMS_TOOL  = 'steadhand-get-post-terms';
	private const LIST_POSTS  = 'steadhand-list-posts';
	private const GET_MEDIA   = 'steadhand-get-media';
	private const LIST_MEDIA  = 'steadhand-list-media';
	private const NOT_FOUND   = 'Not found, or this agent may not access it.';

	/** Two paragraphs, so that edit-blocks has a block to replace. */
	private const CONTENT = "<!-- wp:paragraph -->\n<p>One</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Two</p>\n<!-- /wp:paragraph -->";

	/**
	 * Registers the seven types and the two taxonomies, and adds all seven types to the filter, so that only the rule
	 * decides which of them the tools may use.
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
			self::READ_CAP,
			array(
				'public'       => true,
				'map_meta_cap' => false,
				'capabilities' => array( 'read_post' => 'read' ),
			)
		);
		register_post_type(
			self::FULL_READ,
			array(
				'public'       => true,
				'map_meta_cap' => false,
				'capabilities' => array(
					'read_post' => 'read',
					'edit_post' => 'read',
				),
			)
		);
		register_post_type(
			self::PAGE_CAP,
			array(
				'public'          => true,
				'capability_type' => 'page',
				'map_meta_cap'    => false,
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
			self::MAPPED_INT,
			array(
				'public'       => true,
				'map_meta_cap' => 1,
			)
		);
		register_taxonomy(
			self::TAX,
			array_merge( array( 'post' ), self::NEGATIVE_TYPES, self::POSITIVE_TYPES ),
			array(
				'public'       => true,
				'capabilities' => array( 'assign_terms' => Capabilities::READ ),
			)
		);
		register_taxonomy( self::TAX_ONLY, self::NEGATIVE_TYPES, array( 'public' => true ) );
		add_filter(
			'steadhand_allowed_post_types',
			static fn( array $types ): array => array_merge( $types, self::NEGATIVE_TYPES, self::POSITIVE_TYPES )
		);
	}

	/**
	 * Removes the types and taxonomies this file registers.
	 */
	protected function tearDown(): void {
		foreach ( array( self::TAX, self::TAX_ONLY ) as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				unregister_taxonomy( $taxonomy );
			}
		}
		foreach ( array_merge( self::NEGATIVE_TYPES, self::POSITIVE_TYPES ) as $type ) {
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

	/**
	 * The refusal text a tool gives for a post type that is not registered at all. Every type the allowlist refuses must
	 * give this same text, so an agent cannot tell a refused type from a missing one.
	 *
	 * @param string $tool Tool name.
	 * @param array  $args Arguments, with post_type set to the unregistered name.
	 */
	private function unregistered_type_text( string $tool, array $args ): string {
		// Short enough for the post_type schema (20 characters), so the allowlist decides, as it does for a refused type.
		return $this->refusal_text( $this->tool_result( $tool, array_merge( $args, array( 'post_type' => 'steadhand_nope' ) ) ) );
	}

	public function test_the_allowlist_never_contains_a_type_registered_with_map_meta_cap_off(): void {
		foreach ( self::NEGATIVE_TYPES as $type ) {
			$this->assertFalse( PostTypes::uses_meta_caps( $type ), "$type is registered with map_meta_cap off." );
			$this->assertFalse( PostTypes::is_allowed( $type ), "$type must not be allowed, even though the filter adds it." );
			$this->assertNotContains( $type, PostTypes::allowed() );
		}

		foreach ( self::POSITIVE_TYPES as $type ) {
			$this->assertTrue( PostTypes::uses_meta_caps( $type ), "$type is registered with map_meta_cap on." );
			$this->assertTrue( PostTypes::is_allowed( $type ), "$type must be allowed." );
		}

		$this->assertSame( array( 'post', 'page' ), array_values( array_intersect( PostTypes::allowed(), array( 'post', 'page' ) ) ) );
		$this->assertEqualsCanonicalizing( array( 'post', 'page' ), array_values( array_diff( PostTypes::allowed(), self::POSITIVE_TYPES ) ) );
		$this->assertFalse( PostTypes::uses_meta_caps( 'steadhand_never_registered' ), 'An unregistered name has no meta mapping.' );
	}

	public function test_the_taxonomy_allowlist_follows_the_post_type_allowlist(): void {
		$this->assertTrue( Taxonomies::is_allowed( self::TAX ), 'Control: a taxonomy attached to post is allowed.' );
		$this->assertFalse( Taxonomies::is_allowed( self::TAX_ONLY ), 'A taxonomy attached only to refused types is not allowed.' );
	}

	public function test_create_post_is_refused_for_a_draft_and_for_a_publish_of_every_negative_type_and_creates_no_row(): void {
		$this->act_as_agent( $this->full_agent_overrides() );

		foreach ( self::NEGATIVE_TYPES as $type ) {
			foreach ( array( 'draft', 'publish' ) as $status ) {
				$before = $this->post_row_count();

				$this->refusal_text(
					$this->tool_result(
						self::CREATE,
						array(
							'post_type' => $type,
							'title'     => 'Refused',
							'status'    => $status,
						)
					)
				);

				$this->assertSame( $before, $this->post_row_count(), "create-post of a $status $type must write no row." );
			}
		}
	}

	public function test_update_post_is_refused_on_a_draft_and_on_a_published_post_and_the_row_is_unchanged(): void {
		$this->act_as_agent( $this->full_agent_overrides() );

		foreach ( self::NEGATIVE_TYPES as $type ) {
			foreach ( array( 'draft', 'publish' ) as $status ) {
				$post   = $this->fixture( $type, $status );
				$before = $this->row( $post );

				$result = $this->execute_ability(
					self::UPDATE,
					array(
						'id'    => $post,
						'title' => 'Changed',
					)
				);

				$this->assertWPError( $result, "update-post on a $status $type post must be refused." );
				$this->assertSame( $before, $this->row( $post ), "A refused update leaves the $status $type post unchanged." );
			}
		}
	}

	public function test_update_post_cannot_publish_a_draft_of_a_negative_type(): void {
		$this->act_as_agent( $this->full_agent_overrides() );

		foreach ( self::NEGATIVE_TYPES as $type ) {
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
			$this->assertSame( $before, $this->row( $post ), "The $type draft stays unchanged." );
			$this->assertSame( 'draft', get_post( $post )->post_status );
		}
	}

	public function test_edit_blocks_is_refused_on_a_draft_and_on_a_published_post_and_the_row_is_unchanged(): void {
		$this->act_as_agent( $this->full_agent_overrides() );

		foreach ( self::NEGATIVE_TYPES as $type ) {
			foreach ( array( 'draft', 'publish' ) as $status ) {
				$post   = $this->fixture( $type, $status );
				$before = $this->row( $post );

				$result = $this->execute_ability( self::EDIT_BLOCKS, $this->replace_second_paragraph( $post ) );

				$this->assertWPError( $result, "edit-blocks on a $status $type post must be refused." );
				$this->assertSame( $before, $this->row( $post ), "A refused edit-blocks leaves the $status $type post unchanged." );
			}
		}
	}

	public function test_get_post_is_refused_for_published_private_pending_and_another_authors_draft_and_for_trash(): void {
		$this->act_as_agent( $this->full_agent_overrides() );

		foreach ( self::NEGATIVE_TYPES as $type ) {
			foreach ( array( 'publish', 'private', 'pending', 'draft', 'trash' ) as $status ) {
				$post = $this->fixture( $type, $status );

				$this->assertSame(
					self::NOT_FOUND,
					$this->refusal_text( $this->tool_result( self::GET_POST, array( 'id' => $post ) ) ),
					"get-post on a $status $type post must be refused."
				);
			}
		}
	}

	public function test_get_blocks_get_rendered_and_get_post_terms_are_refused_for_published_private_and_another_authors_draft(): void {
		$this->act_as_agent( $this->full_agent_overrides() );

		foreach ( self::NEGATIVE_TYPES as $type ) {
			foreach ( array( 'publish', 'private', 'draft' ) as $status ) {
				$post = $this->fixture( $type, $status );

				foreach ( array( self::GET_BLOCKS, self::GET_RENDER, self::TERMS_TOOL ) as $tool ) {
					$this->assertSame(
						self::NOT_FOUND,
						$this->refusal_text( $this->tool_result( $tool, array( 'id' => $post ) ) ),
						"$tool on a $status $type post must be refused."
					);
				}
			}
		}
	}

	public function test_set_post_terms_is_refused_on_every_negative_type_and_the_terms_are_unchanged(): void {
		$this->act_as_agent( $this->full_agent_overrides() );
		$term = (int) self::factory()->term->create( array( 'taxonomy' => self::TAX ) );

		// Control: the same taxonomy and term are set on a post, so the refusals below come from the post type.
		$post = $this->fixture( 'post', 'draft' );
		$this->output_of(
			self::SET_TERMS,
			array(
				'id'       => $post,
				'taxonomy' => self::TAX,
				'terms'    => array( $term ),
			)
		);
		$this->assertSame( array( $term ), wp_get_object_terms( $post, self::TAX, array( 'fields' => 'ids' ) ), 'Control: set-post-terms works on a post.' );

		foreach ( self::NEGATIVE_TYPES as $type ) {
			foreach ( array( 'draft', 'publish' ) as $status ) {
				$post = $this->fixture( $type, $status );

				$this->assertSame(
					self::NOT_FOUND,
					$this->refusal_text(
						$this->tool_result(
							self::SET_TERMS,
							array(
								'id'       => $post,
								'taxonomy' => self::TAX,
								'terms'    => array( $term ),
							)
						)
					),
					"set-post-terms on a $status $type post must be refused."
				);
				$this->assertSame( array(), wp_get_object_terms( $post, self::TAX, array( 'fields' => 'ids' ) ), "The $type post gets no term." );
			}
		}
	}

	public function test_list_posts_refuses_every_negative_type_with_the_text_of_an_unregistered_type_even_with_a_search(): void {
		$this->act_as_agent( $this->full_agent_overrides() );
		foreach ( self::NEGATIVE_TYPES as $type ) {
			$this->fixture( $type, 'publish' );
			$this->fixture( $type, 'private' );
		}

		foreach ( self::NEGATIVE_TYPES as $type ) {
			foreach ( array( array(), array( 'search' => 'Fixture' ) ) as $extra ) {
				$args = array_merge( array( 'post_type' => $type ), $extra );

				$this->assertSame(
					$this->unregistered_type_text( self::LIST_POSTS, $extra ),
					$this->refusal_text( $this->tool_result( self::LIST_POSTS, $args ) ),
					"list-posts for $type must refuse exactly as it refuses an unregistered type."
				);
			}
		}
	}

	public function test_get_media_refuses_an_attachment_whose_parent_is_a_published_or_private_negative_type_post(): void {
		$this->act_as_agent( $this->full_agent_overrides() );

		foreach ( self::NEGATIVE_TYPES as $type ) {
			foreach ( array( 'publish', 'private' ) as $status ) {
				$parent     = $this->fixture( $type, $status );
				$attachment = $this->plain( $parent );

				$this->assertSame(
					self::NOT_FOUND,
					$this->refusal_text( $this->tool_result( self::GET_MEDIA, array( 'id' => $attachment ) ) ),
					"get-media of a file under a $status $type post must be refused."
				);
			}
		}
	}

	public function test_list_media_omits_and_does_not_count_files_under_negative_type_parents(): void {
		$this->act_as_agent( $this->full_agent_overrides() );
		$unattached = $this->plain( 0 );
		$mapped     = $this->plain( $this->fixture( self::MAPPED, 'publish' ) );
		foreach ( self::NEGATIVE_TYPES as $type ) {
			$this->plain( $this->fixture( $type, 'publish' ) );
			$this->plain( $this->fixture( $type, 'private' ) );
		}

		$output = $this->output_of( self::LIST_MEDIA, array( 'per_page' => 50 ) );

		$this->assertEqualsCanonicalizing( array( $unattached, $mapped ), wp_list_pluck( $output['items'], 'id' ) );
		$this->assertSame( 2, $output['total'], 'total must not count the files under refused parents.' );
		$this->assertSame( 1, $output['total_pages'] );
	}

	/**
	 * Inside a write call, the agent's core answers on the types whose capability names core keeps are all false. MetaCaps
	 * refuses them. READ_CAP and FULL_READ are left out on purpose: core renames their read_post and edit_post to read
	 * before the map_meta_cap filter runs, so MetaCaps never sees the call and core answers true for the agent. Steadhand's
	 * tools refuse those types through the allowlist, which the tests above check. That core answer is a known residual,
	 * reported to the maintainer. It is not pinned here, so a change in core or in Steadhand does not have to match it.
	 */
	public function test_inside_a_write_call_the_agent_is_refused_edit_publish_and_delete_where_core_keeps_the_names(): void {
		$this->act_as_agent( $this->full_agent_overrides() );

		foreach ( array( self::UNMAPPED, self::CAPPED, self::PAGE_CAP ) as $type ) {
			$draft     = $this->fixture( $type, 'draft' );
			$published = $this->fixture( $type, 'publish' );

			$answers = $this->inside_write(
				static fn(): array => array(
					'edit_draft'     => current_user_can( 'edit_post', $draft ),
					'publish_draft'  => current_user_can( 'publish_post', $draft ),
					'edit_published' => current_user_can( 'edit_post', $published ),
					'publish_pub'    => current_user_can( 'publish_post', $published ),
					'delete_draft'   => current_user_can( 'delete_post', $draft ),
				)
			);

			foreach ( $answers as $question => $answer ) {
				$this->assertFalse( $answer, "$question on a $type post, inside a write call, must be false for the agent." );
			}
		}
	}

	/**
	 * Inside a write call, read_post is refused on a type whose read_post core keeps. As above, READ_CAP and FULL_READ are
	 * left out, because core renames their read_post to read before the filter runs (see the test above).
	 */
	public function test_inside_a_write_call_the_agent_is_refused_read_post_where_core_keeps_the_name(): void {
		$this->act_as_agent( $this->full_agent_overrides() );

		foreach ( array( self::UNMAPPED, self::CAPPED, self::PAGE_CAP ) as $type ) {
			$published = $this->fixture( $type, 'publish' );

			$this->assertFalse(
				$this->inside_write( static fn(): array => array( 'read' => current_user_can( 'read_post', $published ) ) )['read'],
				"read_post on a published $type post, inside a write call, must be false for the agent."
			);
		}
	}

	/**
	 * The positive controls: both map_meta_cap registrations are allowed and work through the tools. The second one, with
	 * map_meta_cap 1, shows that the rule is truthy and not a strict true check.
	 */
	public function test_positive_controls_both_mapped_registrations_are_created_edited_and_read_through_the_tools(): void {
		$this->act_as_agent( $this->full_agent_overrides() );

		foreach ( self::POSITIVE_TYPES as $type ) {
			$created = $this->output_of(
				self::CREATE,
				array(
					'post_type' => $type,
					'title'     => 'Created',
					'status'    => 'draft',
				)
			);
			$this->assertSame( $type, get_post( $created['id'] )->post_type, "create-post of a $type draft must run." );

			$draft = $this->fixture( $type, 'draft' );
			$this->execute_ability(
				self::UPDATE,
				array(
					'id'    => $draft,
					'title' => 'Changed',
				)
			);
			$this->assertSame( 'Changed', get_post( $draft )->post_title, "update-post on a $type draft must run." );

			$published = $this->fixture( $type, 'publish' );
			$this->assertSame( 'publish', $this->output_of( self::GET_POST, array( 'id' => $published ) )['status'], "get-post on a $type post must run." );
		}
	}

	public function test_positive_controls_inside_a_write_call_the_agent_is_granted_on_both_mapped_registrations(): void {
		$this->act_as_agent( $this->full_agent_overrides() );

		foreach ( self::POSITIVE_TYPES as $type ) {
			$draft     = $this->fixture( $type, 'draft' );
			$published = $this->fixture( $type, 'publish' );

			$answers = $this->inside_write(
				static fn(): array => array(
					'edit_draft'     => current_user_can( 'edit_post', $draft ),
					'read_published' => current_user_can( 'read_post', $published ),
				)
			);

			$this->assertTrue( $answers['edit_draft'], "edit_post on a draft of $type is granted inside a write call." );
			$this->assertTrue( $answers['read_published'], "read_post on a published $type post is granted." );
		}
	}

	public function test_positive_controls_a_file_under_a_mapped_parent_is_read_through_get_media_and_list_media(): void {
		$this->act_as_agent( $this->full_agent_overrides() );

		foreach ( self::POSITIVE_TYPES as $type ) {
			$parent = $this->fixture( $type, 'publish' );
			$file   = $this->plain( $parent );

			$this->assertSame( $file, $this->output_of( self::GET_MEDIA, array( 'id' => $file ) )['id'], "get-media of a file under a $type parent must run." );
			$this->assertSame( array( $file ), wp_list_pluck( $this->output_of( self::LIST_MEDIA, array( 'parent' => $parent ) )['items'], 'id' ) );
		}
	}

	/**
	 * Plain attachment row under the given parent, owned by the fixture administrator.
	 *
	 * @param int $parent_id Parent post ID, or 0.
	 */
	private function plain( int $parent_id ): int {
		$id = self::factory()->attachment->create_object(
			'steadhand-file.pdf',
			$parent_id,
			array(
				'post_author'    => $this->admin_id(),
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'Plain file',
				'post_status'    => 'inherit',
			)
		);
		$this->assertGreaterThan( 0, $id, 'The attachment fixture must be created.' );

		return (int) $id;
	}

	/**
	 * The administrator is not a dedicated agent, so the mapping must return core's answer unchanged, inside a write call as
	 * well as outside one. The expected answers are taken outside a Steadhand call, where MetaCaps does nothing. Core may
	 * refuse the administrator some of these, so the test compares with core, not with a fixed true or false.
	 */
	public function test_an_administrator_keeps_core_answers_on_such_a_type_inside_a_write_call(): void {
		$agent = $this->full_agent();
		wp_set_current_user( $agent );
		$admin = $this->admin_id();

		foreach ( self::NEGATIVE_TYPES as $type ) {
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
