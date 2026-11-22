<?php
/**
 * The read half of the D6 mapping: agents read allowed posts in any status except trash, inside Steadhand calls only.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Closure;
use Steadhand\Capabilities;
use Steadhand\MetaCaps;
use Steadhand\Pipeline\Pipeline;
use Steadhand\Plugin;
use Steadhand\Requirements;

/**
 * Covers SPEC §4.2 and §14.1. Every decision goes through real current_user_can() calls. The mapping is reached
 * only through a real pipeline run (the ProbeAbility runs the check inside execute()), never by setting the flag.
 */
final class MetaCapsTest extends TestCase {

	private const CUSTOM = 'steadhand_book';

	/**
	 * Removes the custom type and any filter this file adds.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'steadhand_allowed_post_types' );
		if ( post_type_exists( self::CUSTOM ) ) {
			unregister_post_type( self::CUSTOM );
		}
		parent::tearDown();
	}

	/**
	 * Creates a post of the given type and status, by another author, and returns its ID.
	 *
	 * @param string $post_type Post type.
	 * @param string $status    Post status.
	 * @param array  $extra     Extra post fields.
	 */
	private function other_authors_post( string $post_type, string $status, array $extra = array() ): int {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );

		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'   => $post_type,
					'post_status' => $status,
					'post_author' => $author,
				),
				$extra
			)
		);
	}

	/**
	 * Runs a real pipeline call as the current user; the probe evaluates $check inside execute().
	 *
	 * @param Closure              $check   Receives the sanitised input, returns the output array.
	 * @param array<string, mixed> $input   Input.
	 * @param string               $cap     Steadhand capability the probe requires.
	 * @return array<string, mixed>
	 */
	private function run_probe( Closure $check, array $input, string $cap = Capabilities::READ ): array {
		$result = ( new Pipeline( new SpyLogger() ) )->run( new ProbeAbility( $cap, $check ), $input );
		$this->assertIsArray( $result, 'The probe call must be allowed to run.' );

		return $result;
	}

	/**
	 * Whether the current user can read the post, evaluated inside a real pipeline call.
	 *
	 * @param int $post_id Post ID.
	 */
	private function pipeline_can_read( int $post_id ): bool {
		$result = $this->run_probe(
			static fn( array $input ): array => array( 'can' => current_user_can( 'read_post', $input['post_id'] ) ),
			array( 'post_id' => $post_id )
		);

		return true === $result['can'];
	}

	/**
	 * Whether the current user can perform $cap on the post, evaluated inside a real pipeline call.
	 *
	 * @param string $cap     Capability to check.
	 * @param int    $post_id Post ID.
	 */
	private function pipeline_can( string $cap, int $post_id ): bool {
		$result = $this->run_probe(
			static fn( array $input ): array => array( 'can' => current_user_can( $cap, $input['post_id'] ) ),
			array( 'post_id' => $post_id )
		);

		return true === $result['can'];
	}

	/**
	 * Runs $check as a non-agent user from inside a real agent call. Reproduces a plugin that switches the user
	 * while a Steadhand call is running. Restores the agent afterwards.
	 *
	 * @param int     $user_id User to switch to.
	 * @param Closure $check   Check to run as that user.
	 * @return mixed The check's result.
	 */
	private function as_user_inside_a_call( int $user_id, Closure $check ): mixed {
		$agent_id = get_current_user_id();
		$result   = $this->run_probe(
			static function () use ( $user_id, $check, $agent_id ): array {
				wp_set_current_user( $user_id );
				try {
					return array( 'value' => $check() );
				} finally {
					wp_set_current_user( $agent_id );
				}
			},
			array()
		);

		return $result['value'];
	}


	public function test_inside_a_call_an_agent_reads_other_authors_posts_in_every_non_trash_status(): void {
		wp_set_current_user( $this->make_agent() );

		foreach ( array( 'draft', 'pending', 'private', 'publish', 'auto-draft' ) as $status ) {
			foreach ( array( 'post', 'page' ) as $type ) {
				$post_id = $this->other_authors_post( $type, $status );
				$this->assertTrue( $this->pipeline_can_read( $post_id ), "An agent must read a $status $type by another author." );
			}
		}
	}

	public function test_inside_a_call_an_agent_reads_a_future_post_by_another_author(): void {
		wp_set_current_user( $this->make_agent() );
		$post_id = $this->other_authors_post( 'post', 'future', array( 'post_date' => '2099-01-01 00:00:00' ) );

		$this->assertTrue( $this->pipeline_can_read( $post_id ) );
	}

	public function test_inside_a_call_an_agent_reads_a_password_protected_post(): void {
		wp_set_current_user( $this->make_agent() );
		$post_id = $this->other_authors_post( 'post', 'publish', array( 'post_password' => 'secret' ) );

		$this->assertTrue( $this->pipeline_can_read( $post_id ) );
	}

	public function test_inside_a_call_an_agent_cannot_read_a_trashed_post_or_page(): void {
		wp_set_current_user( $this->make_agent() );

		$this->assertFalse( $this->pipeline_can_read( $this->other_authors_post( 'post', 'trash' ) ) );
		$this->assertFalse( $this->pipeline_can_read( $this->other_authors_post( 'page', 'trash' ) ) );
	}

	public function test_inside_a_call_an_agent_cannot_read_excluded_types_or_a_missing_post(): void {
		wp_set_current_user( $this->make_agent() );
		$block     = $this->other_authors_post( 'wp_block', 'publish' );
		$menu_item = $this->other_authors_post( 'nav_menu_item', 'publish' );
		$parent    = $this->other_authors_post( 'post', 'publish' );
		$revision  = $this->other_authors_post( 'revision', 'inherit', array( 'post_parent' => $parent ) );

		$this->assertFalse( $this->pipeline_can_read( $block ), 'wp_block' );
		$this->assertFalse( $this->pipeline_can_read( $menu_item ), 'nav_menu_item' );
		$this->assertFalse( $this->pipeline_can_read( $revision ), 'revision' );
		$this->assertFalse( $this->pipeline_can_read( 999999 ), 'a post that does not exist' );
	}

	public function test_inside_a_call_an_agent_cannot_read_a_registered_custom_type_that_is_not_allowed(): void {
		register_post_type( self::CUSTOM, array( 'public' => true ) );
		wp_set_current_user( $this->make_agent() );

		$this->assertFalse( $this->pipeline_can_read( $this->other_authors_post( self::CUSTOM, 'publish' ) ) );
	}

	public function test_inside_a_call_an_agent_reads_a_custom_type_once_the_filter_allows_it(): void {
		register_post_type( self::CUSTOM, array( 'public' => true ) );
		add_filter(
			'steadhand_allowed_post_types',
			static fn( array $types ): array => array_merge( $types, array( self::CUSTOM ) )
		);
		wp_set_current_user( $this->make_agent() );

		$this->assertTrue( $this->pipeline_can_read( $this->other_authors_post( self::CUSTOM, 'draft' ) ) );
	}

	public function test_inside_a_call_a_refused_user_level_steadhand_read_is_refused_for_the_agent(): void {
		$post_id = $this->other_authors_post( 'post', 'draft' );

		wp_set_current_user( $this->make_agent() );
		$this->assertTrue( $this->pipeline_can_read( $post_id ), 'Control: with steadhand_read granted, the agent reads the draft.' );

		// A probe that needs only steadhand_connect reaches authorize_object() and execute() without steadhand_read.
		wp_set_current_user( $this->make_agent( array( Capabilities::READ => false ) ) );
		$result = $this->run_probe(
			static fn( array $input ): array => array( 'can' => current_user_can( 'read_post', $input['post_id'] ) ),
			array( 'post_id' => $post_id ),
			Capabilities::CONNECT
		);

		$this->assertFalse( $result['can'] );
	}

	/**
	 * A membership or other plugin that denies read_post before Steadhand's filter runs (priority 5, before MetaCaps
	 * at 10) must keep its denial. Steadhand must never turn another component's denial into a grant.
	 */
	public function test_an_earlier_do_not_allow_is_kept_inside_a_call(): void {
		$draft = $this->other_authors_post( 'post', 'draft' );
		wp_set_current_user( $this->make_agent() );
		$this->assertTrue( $this->pipeline_can_read( $draft ), 'Control: without the denial, the agent reads the draft.' );

		$deny = static function ( array $caps, string $cap ): array {
			return 'read_post' === $cap ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny, 5, 2 );
		try {
			$this->assertFalse( $this->pipeline_can_read( $draft ) );
		} finally {
			remove_filter( 'map_meta_cap', $deny, 5 );
		}
	}

	/**
	 * Milestone M2 maps edit_post for drafts to steadhand_write_drafts, which the default agent holds (SPEC §14.1). The
	 * assertions that remain here are the ones that did not change: publish_post and delete_post are never granted to
	 * the default agent, and edit_post is not granted on a published post, which needs steadhand_publish. The M2
	 * mappings have their own tests in MetaCapsWriteTest.
	 */
	public function test_inside_a_call_publish_delete_and_edit_of_a_published_post_are_not_granted_to_the_default_agent(): void {
		wp_set_current_user( $this->make_agent() );
		$own_draft = $this->make_agent_owned_draft();
		$other     = $this->other_authors_post( 'post', 'draft' );
		$published = $this->other_authors_post( 'post', 'publish' );

		foreach ( array( $own_draft, $other, $published ) as $post_id ) {
			$this->assertFalse( $this->pipeline_can( 'publish_post', $post_id ), "publish_post must not be granted to an agent (post $post_id)." );
			$this->assertFalse( $this->pipeline_can( 'delete_post', $post_id ), "delete_post must not be granted to an agent (post $post_id)." );
		}

		$this->assertFalse( $this->pipeline_can( 'edit_post', $published ), 'edit_post on a published post needs steadhand_publish, which the default agent lacks.' );
	}

	public function test_inside_a_call_an_administrator_gets_core_answers(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$draft    = $this->other_authors_post( 'post', 'draft' );
		wp_set_current_user( $this->make_agent() );

		$inside  = $this->as_user_inside_a_call(
			$admin_id,
			static fn(): bool => current_user_can( 'read_post', $draft )
		);
		$outside = user_can( $admin_id, 'read_post', $draft );

		$this->assertTrue( $outside, 'Control: core lets an administrator read a draft.' );
		$this->assertSame( $outside, $inside );
	}

	public function test_inside_a_call_a_subscriber_gets_core_answers(): void {
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$draft         = $this->other_authors_post( 'post', 'draft' );
		$private       = $this->other_authors_post( 'post', 'private' );
		wp_set_current_user( $this->make_agent() );

		$inside_draft   = $this->as_user_inside_a_call( $subscriber_id, static fn(): bool => current_user_can( 'read_post', $draft ) );
		$inside_private = $this->as_user_inside_a_call( $subscriber_id, static fn(): bool => current_user_can( 'read_post', $private ) );

		$this->assertSame( user_can( $subscriber_id, 'read_post', $draft ), $inside_draft );
		$this->assertSame( user_can( $subscriber_id, 'read_post', $private ), $inside_private );
		$this->assertFalse( $inside_draft );
		$this->assertFalse( $inside_private );
	}

	public function test_inside_a_call_a_mixed_role_user_holding_the_agent_role_is_not_mapped(): void {
		$mixed_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		get_userdata( $mixed_id )->add_role( \Steadhand\Roles::ROLE );
		$draft = $this->other_authors_post( 'post', 'draft' );
		wp_set_current_user( $this->make_agent() );

		$inside = $this->as_user_inside_a_call( $mixed_id, static fn(): bool => current_user_can( 'read_post', $draft ) );

		$this->assertFalse( $inside, 'A user who is not a dedicated agent must not get the mapping.' );
		$this->assertSame( user_can( $mixed_id, 'read_post', $draft ), $inside );
	}


	public function test_outside_a_call_an_agent_gets_core_answers_and_cannot_read_other_authors_drafts_or_private_posts(): void {
		$agent_id = $this->make_agent();
		wp_set_current_user( $agent_id );
		$draft   = $this->other_authors_post( 'post', 'draft' );
		$pending = $this->other_authors_post( 'post', 'pending' );
		$private = $this->other_authors_post( 'post', 'private' );

		$this->assertFalse( current_user_can( 'read_post', $draft ), 'A draft by another author.' );
		$this->assertFalse( current_user_can( 'read_post', $pending ), 'A pending post by another author.' );
		$this->assertFalse( current_user_can( 'read_post', $private ), 'A private post by another author.' );
		$this->assertSame( user_can( $agent_id, 'read_post', $draft ), current_user_can( 'read_post', $draft ) );
	}

	public function test_outside_a_call_an_agent_gets_no_read_on_trash_or_on_a_custom_type_it_may_not_read(): void {
		register_post_type( self::CUSTOM, array( 'public' => true ) );
		wp_set_current_user( $this->make_agent() );

		$this->assertFalse( current_user_can( 'read_post', $this->other_authors_post( 'post', 'trash' ) ) );
		$this->assertFalse( current_user_can( 'read_post', $this->other_authors_post( self::CUSTOM, 'draft' ) ) );
	}

	public function test_outside_a_call_edit_publish_and_delete_are_never_granted_to_an_agent(): void {
		$agent_id = $this->make_agent();
		wp_set_current_user( $agent_id );
		$own_draft = $this->make_agent_owned_draft();
		$other     = $this->other_authors_post( 'post', 'draft' );
		$published = $this->other_authors_post( 'post', 'publish' );

		foreach ( array( 'edit_post', 'publish_post', 'delete_post' ) as $cap ) {
			foreach ( array( $own_draft, $other, $published ) as $post_id ) {
				$this->assertFalse( current_user_can( $cap, $post_id ), "$cap must not be granted outside a call (post $post_id)." );
			}
		}
	}

	public function test_outside_a_call_an_administrator_and_a_subscriber_get_core_answers(): void {
		$admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$draft         = $this->other_authors_post( 'post', 'draft' );

		$this->assertTrue( user_can( $admin_id, 'read_post', $draft ) );
		$this->assertFalse( user_can( $subscriber_id, 'read_post', $draft ) );
	}


	/**
	 * Creates an attachment by another author, attached to $parent (0 for none), and returns its ID.
	 *
	 * @param int    $parent_id Parent post ID, or 0.
	 * @param string $status Attachment status: inherit, or trash.
	 */
	private function other_authors_attachment( int $parent_id = 0, string $status = 'inherit' ): int {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$id     = wp_insert_attachment(
			array(
				'post_type'      => 'attachment',
				'post_status'    => $status,
				'post_title'     => 'File',
				'post_mime_type' => 'application/pdf',
				'post_author'    => $author,
			),
			false,
			$parent_id
		);
		$this->assertGreaterThan( 0, $id, 'The attachment fixture must be created.' );

		return (int) $id;
	}

	/**
	 * The attachment rule (SPEC §14.1, layer two): inside a call an agent reads a non-trashed attachment whatever its
	 * parent's status or author. Core answers outside a call, and for every other user.
	 */
	public function test_inside_a_call_an_agent_reads_an_attachment_of_another_authors_draft(): void {
		$attachment = $this->other_authors_attachment( $this->other_authors_post( 'post', 'draft' ) );
		wp_set_current_user( $this->make_agent() );

		$this->assertTrue( $this->pipeline_can_read( $attachment ) );
	}

	public function test_inside_a_call_an_agent_reads_an_unattached_attachment_and_one_on_a_private_page(): void {
		$unattached = $this->other_authors_attachment();
		$private    = $this->other_authors_attachment( $this->other_authors_post( 'page', 'private' ) );
		wp_set_current_user( $this->make_agent() );

		$this->assertTrue( $this->pipeline_can_read( $unattached ), 'unattached' );
		$this->assertTrue( $this->pipeline_can_read( $private ), 'attached to a private page' );
	}

	public function test_inside_a_call_an_agent_cannot_read_a_trashed_attachment(): void {
		$trashed = $this->other_authors_attachment();
		wp_trash_post( $trashed );
		wp_set_current_user( $this->make_agent() );

		$this->assertFalse( $this->pipeline_can_read( $trashed ) );
	}

	public function test_outside_a_call_the_same_agent_gets_core_answers_for_attachments(): void {
		$draft_parent = $this->other_authors_attachment( $this->other_authors_post( 'post', 'draft' ) );
		$unattached   = $this->other_authors_attachment();
		wp_set_current_user( $this->make_agent() );

		$this->assertFalse( current_user_can( 'read_post', $draft_parent ), 'Core refuses a draft parent.' );
		$this->assertTrue( current_user_can( 'read_post', $unattached ), 'Core treats an unattached file as published.' );
	}

	public function test_inside_a_call_edit_publish_and_delete_are_never_granted_on_an_attachment(): void {
		wp_set_current_user( $this->make_agent() );
		$own   = $this->make_agent_owned_attachment();
		$other = $this->other_authors_attachment( $this->other_authors_post( 'post', 'draft' ) );

		foreach ( array( 'edit_post', 'publish_post', 'delete_post' ) as $cap ) {
			foreach ( array( $own, $other ) as $attachment ) {
				$this->assertFalse( $this->pipeline_can( $cap, $attachment ), "$cap must not be granted on attachment $attachment." );
			}
		}
	}

	public function test_outside_a_call_edit_publish_and_delete_are_never_granted_on_an_attachment(): void {
		wp_set_current_user( $this->make_agent() );
		$own   = $this->make_agent_owned_attachment();
		$other = $this->other_authors_attachment();

		foreach ( array( 'edit_post', 'publish_post', 'delete_post' ) as $cap ) {
			foreach ( array( $own, $other ) as $attachment ) {
				$this->assertFalse( current_user_can( $cap, $attachment ), "$cap must not be granted outside a call (attachment $attachment)." );
			}
		}
	}

	public function test_inside_a_call_an_agent_without_steadhand_read_is_refused_an_attachment(): void {
		$attachment = $this->other_authors_attachment( $this->other_authors_post( 'post', 'draft' ) );
		wp_set_current_user( $this->make_agent( array( Capabilities::READ => false ) ) );

		$result = $this->run_probe(
			static fn( array $input ): array => array( 'can' => current_user_can( 'read_post', $input['post_id'] ) ),
			array( 'post_id' => $attachment ),
			Capabilities::CONNECT
		);

		$this->assertFalse( $result['can'] );
	}

	public function test_inside_a_call_an_administrator_and_a_subscriber_get_core_answers_for_attachments(): void {
		$admin_id      = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$subscriber_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$draft_parent  = $this->other_authors_attachment( $this->other_authors_post( 'post', 'draft' ) );
		$unattached    = $this->other_authors_attachment();
		wp_set_current_user( $this->make_agent() );

		foreach ( array( $draft_parent, $unattached ) as $attachment ) {
			$admin_inside      = $this->as_user_inside_a_call( $admin_id, static fn(): bool => current_user_can( 'read_post', $attachment ) );
			$subscriber_inside = $this->as_user_inside_a_call( $subscriber_id, static fn(): bool => current_user_can( 'read_post', $attachment ) );

			$this->assertSame( user_can( $admin_id, 'read_post', $attachment ), $admin_inside, "admin, attachment $attachment" );
			$this->assertSame( user_can( $subscriber_id, 'read_post', $attachment ), $subscriber_inside, "subscriber, attachment $attachment" );
		}
		$this->assertFalse( $this->as_user_inside_a_call( $subscriber_id, static fn(): bool => current_user_can( 'read_post', $draft_parent ) ) );
	}

	public function test_inside_a_call_an_agent_is_refused_an_attachment_whose_parent_the_rule_excludes(): void {
		register_post_type( self::CUSTOM, array( 'public' => false ) );
		wp_set_current_user( $this->make_agent() );
		$trashed = $this->other_authors_post( 'post', 'publish' );
		wp_trash_post( $trashed );

		$cases = array(
			'private parent of a registered type that is not allowed' => $this->other_authors_post( self::CUSTOM, 'private' ),
			'wp_block parent'            => $this->other_authors_post( 'wp_block', 'publish' ),
			'draft wp_navigation parent' => $this->other_authors_post( 'wp_navigation', 'draft' ),
			'trashed parent'             => $trashed,
		);
		foreach ( $cases as $case => $parent ) {
			$this->assertFalse( $this->pipeline_can_read( $this->other_authors_attachment( $parent ) ), $case );
		}
	}

	public function test_inside_a_call_an_attachment_with_its_own_status_private_is_refused(): void {
		wp_set_current_user( $this->make_agent() );
		$attachment = $this->other_authors_attachment();
		wp_update_post(
			array(
				'ID'          => $attachment,
				'post_status' => 'private',
			)
		);

		$this->assertFalse( $this->pipeline_can_read( $attachment ) );
	}

	public function test_inside_a_call_an_attachment_whose_parent_is_missing_or_of_an_allowed_type_is_readable(): void {
		register_post_type( self::CUSTOM, array( 'public' => false ) );
		add_filter(
			'steadhand_allowed_post_types',
			static fn( array $types ): array => array_merge( $types, array( self::CUSTOM ) )
		);
		$missing        = $this->other_authors_attachment();
		$allowed_parent = $this->other_authors_attachment( $this->other_authors_post( self::CUSTOM, 'private' ) );
		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_parent' => 999999999 ), array( 'ID' => $missing ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Stores a parent ID that no post has, which the API cannot do.
		clean_post_cache( $missing );
		wp_set_current_user( $this->make_agent() );

		$this->assertTrue( $this->pipeline_can_read( $missing ), 'a parent that no longer exists' );
		$this->assertTrue( $this->pipeline_can_read( $allowed_parent ), 'a parent of an allowed type' );
	}

	/**
	 * Creates an attachment authored by the current agent user.
	 */
	private function make_agent_owned_attachment(): int {
		return (int) wp_insert_attachment(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => 'Own file',
				'post_mime_type' => 'application/pdf',
				'post_author'    => get_current_user_id(),
			),
			false
		);
	}

	/**
	 * Guards Plugin::boot(): the MetaCaps filter is registered when the requirements are met. The bootstrap runs
	 * boot() on plugins_loaded, so the filter is already present when the tests start.
	 */
	public function test_metacaps_filter_is_registered_when_requirements_are_met(): void {
		$this->assertSame( 1, $this->callbacks_of( 'map_meta_cap' ) );
	}

	/**
	 * Guards Plugin::boot(): with requirements missing, no MetaCaps filter is added, while the lockdown still is.
	 */
	public function test_metacaps_filter_is_not_registered_when_requirements_are_not_met(): void {
		$before = $this->callbacks_of( 'map_meta_cap' );

		$booted = ( new Plugin() )->boot( new Requirements( false, false ) );

		$this->assertFalse( $booted );
		$this->assertSame( $before, $this->callbacks_of( 'map_meta_cap' ) );
	}

	/**
	 * Counts map_meta_cap callbacks that belong to MetaCaps.
	 *
	 * @param string $hook Hook name.
	 */
	private function callbacks_of( string $hook ): int {
		$filters = $GLOBALS['wp_filter'][ $hook ] ?? null;
		if ( ! $filters instanceof \WP_Hook ) {
			return 0;
		}

		$count = 0;
		foreach ( $filters->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'];
				if ( is_array( $function ) && $function[0] instanceof MetaCaps ) {
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Creates a draft authored by the current agent user.
	 */
	private function make_agent_owned_draft(): int {
		return self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_author' => get_current_user_id(),
			)
		);
	}
}
