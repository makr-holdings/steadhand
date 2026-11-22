<?php
/**
 * The M2 post mappings (SPEC §14.1): edit_post, publish_post and delete_post for dedicated agents, inside Steadhand calls.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Closure;
use Steadhand\Capabilities;
use Steadhand\Roles;

/**
 * Every decision goes through a real current_user_can() call. The mapping is reached only through a real pipeline run
 * (the probe evaluates the check inside execute()). read_post is covered by MetaCapsTest.
 */
final class MetaCapsWriteTest extends TestCase {

	private const CUSTOM = 'steadhand_book';

	/** A status that is neither public nor private, so core asks edit_post when it checks read_post. */
	private const STATUS = 'steadhand_review';

	/** Every status a post can have that the mapping distinguishes. */
	private const STATUSES = array( 'draft', 'pending', 'auto-draft', 'publish', 'future', 'private', 'trash' );

	/**
	 * Removes the custom type and any filter this file adds.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'map_meta_cap' );
		if ( post_type_exists( self::CUSTOM ) ) {
			unregister_post_type( self::CUSTOM );
		}
		// WordPress has no unregister_post_status(); the registry is a global array.
		unset( $GLOBALS['wp_post_statuses'][ self::STATUS ] );
		parent::tearDown();
	}

	/**
	 * Runs a real pipeline call as the current user, and returns what the check inside it answered.
	 *
	 * @param string $cap     Meta capability to check.
	 * @param int    $post_id Post ID.
	 */
	private function pipeline_can( string $cap, int $post_id ): bool {
		$result = $this->run_check(
			static fn( array $input ): bool => current_user_can( $cap, $input['post_id'] ),
			array( 'post_id' => $post_id )
		);

		return $result;
	}

	/**
	 * Runs a check inside a real write-call probe and returns its answer. The probe is not read-only, so edit_post and
	 * publish_post are mapped inside it (the write-call rule, MetaCaps). A read tool is covered in WriteCallScopeTest.
	 *
	 * @param Closure              $check Receives the sanitized input, returns a bool.
	 * @param array<string, mixed> $input Input.
	 */
	private function run_check( Closure $check, array $input ): bool {
		$result = ( new \Steadhand\Pipeline\Pipeline( new SpyLogger() ) )->run(
			new ProbeAbility(
				Capabilities::READ,
				static fn( array $sanitised ): array => array( 'can' => (bool) $check( $sanitised ) ),
				array(
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				)
			),
			$input
		);
		$this->assertIsArray( $result, 'The probe call must be allowed to run.' );

		return true === $result['can'];
	}

	/**
	 * Creates a post by another author, with the given status and type. Returns its ID.
	 *
	 * @param string $status Post status.
	 * @param string $type   Post type.
	 */
	private function other_authors_post( string $status, string $type = 'post' ): int {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$args   = array(
			'post_type'   => $type,
			'post_status' => $status,
			'post_author' => $author,
		);
		if ( 'future' === $status ) {
			$args['post_date'] = '2099-01-01 00:00:00';
		}

		return self::factory()->post->create( $args );
	}

	/**
	 * Creates a post by the agent itself, with the given status. Returns its ID.
	 *
	 * @param int    $agent  Agent user ID.
	 * @param string $status Post status.
	 */
	private function own_post( int $agent, string $status ): int {
		$args = array(
			'post_status' => $status,
			'post_author' => $agent,
		);
		if ( 'future' === $status ) {
			$args['post_date'] = '2099-01-01 00:00:00';
		}

		return self::factory()->post->create( $args );
	}

	public function test_inside_a_call_edit_and_publish_follow_the_status_table_for_every_grant_combination(): void {
		$agents = array(
			'write drafts only' => $this->make_agent(),
			'publish only'      => $this->make_agent(
				array(
					Capabilities::WRITE_DRAFTS => false,
					Capabilities::PUBLISH      => true,
				)
			),
			'both'              => $this->make_agent( array( Capabilities::PUBLISH => true ) ),
			'neither'           => $this->make_agent( array( Capabilities::WRITE_DRAFTS => false ) ),
		);

		foreach ( $agents as $label => $agent ) {
			wp_set_current_user( $agent );
			$can_write = Capabilities::user_has( get_userdata( $agent ), Capabilities::WRITE_DRAFTS );
			$can_pub   = Capabilities::user_has( get_userdata( $agent ), Capabilities::PUBLISH );

			foreach ( self::STATUSES as $status ) {
				$post_id = $this->other_authors_post( $status );

				$edit_expected = match ( true ) {
					'trash' === $status => false,
					in_array( $status, array( 'draft', 'pending', 'auto-draft' ), true ) => $can_write,
					default => $can_pub,
				};

				$this->assertSame( $edit_expected, $this->pipeline_can( 'edit_post', $post_id ), "edit_post on a $status post, $label." );
				$this->assertSame( 'trash' !== $status && $can_pub, $this->pipeline_can( 'publish_post', $post_id ), "publish_post on a $status post, $label." );
				$this->assertFalse( $this->pipeline_can( 'delete_post', $post_id ), "delete_post on a $status post, $label." );
			}
		}
	}

	public function test_outside_a_steadhand_call_none_of_the_post_capabilities_are_granted(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );

		foreach ( self::STATUSES as $status ) {
			$post_id = $this->other_authors_post( $status );

			$this->assertFalse( current_user_can( 'edit_post', $post_id ), "edit_post on a $status post outside a call." );
			$this->assertFalse( current_user_can( 'publish_post', $post_id ), "publish_post on a $status post outside a call." );
			$this->assertFalse( current_user_can( 'delete_post', $post_id ), "delete_post on a $status post outside a call." );
		}
	}

	public function test_a_post_of_a_type_that_is_not_allowed_is_refused_for_every_post_capability(): void {
		register_post_type( self::CUSTOM, array( 'public' => false ) );
		wp_set_current_user( $this->make_agent() );
		$post_id = $this->other_authors_post( 'draft', self::CUSTOM );

		foreach ( array( 'read_post', 'edit_post', 'publish_post', 'delete_post' ) as $cap ) {
			$this->assertFalse( $this->pipeline_can( $cap, $post_id ), "$cap on a disallowed type must be refused." );
		}
	}

	/**
	 * Core's read_post check asks edit_post for a post that is neither public nor private and is not the agent's own. A
	 * refusal of edit_post must not stop the read: reads are any non-trash status (SPEC §5).
	 */
	public function test_a_status_the_mapping_does_not_list_stays_readable_and_is_not_editable(): void {
		register_post_status(
			self::STATUS,
			array(
				'public'   => false,
				'private'  => false,
				'internal' => false,
			)
		);
		wp_set_current_user( $this->make_agent() );
		$post_id = $this->other_authors_post( self::STATUS );

		$this->assertTrue( $this->pipeline_can( 'read_post', $post_id ), 'A post in an unlisted status is still readable.' );
		$this->assertFalse( $this->pipeline_can( 'edit_post', $post_id ), 'It is not editable, since no status rule covers it.' );
	}

	public function test_an_attachment_is_refused_for_edit_publish_and_delete(): void {
		wp_set_current_user( $this->make_agent() );
		$parent     = $this->other_authors_post( 'publish' );
		$attachment = self::factory()->attachment->create_object(
			'file.pdf',
			$parent,
			array(
				'post_status'    => 'inherit',
				'post_mime_type' => 'application/pdf',
				'post_author'    => self::factory()->user->create( array( 'role' => 'author' ) ),
			)
		);

		foreach ( array( 'edit_post', 'publish_post', 'delete_post' ) as $cap ) {
			$this->assertFalse( $this->pipeline_can( $cap, $attachment ), "$cap on an attachment must be refused." );
		}
	}

	public function test_a_missing_post_is_refused_for_every_post_capability(): void {
		wp_set_current_user( $this->make_agent() );

		foreach ( array( 'read_post', 'edit_post', 'publish_post', 'delete_post' ) as $cap ) {
			$this->assertFalse( $this->pipeline_can( $cap, 999999999 ), "$cap on a missing post must be refused." );
		}
	}

	public function test_delete_post_is_denied_for_every_status_and_for_the_agents_own_posts(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );

		foreach ( self::STATUSES as $status ) {
			$this->assertFalse( $this->pipeline_can( 'delete_post', $this->own_post( $agent, $status ) ), "delete_post on its own $status post." );
			$this->assertFalse( $this->pipeline_can( 'delete_post', $this->other_authors_post( $status ) ), "delete_post on another's $status post." );
		}
	}

	public function test_a_user_level_denial_overrides_the_role_grant(): void {
		$agent = $this->make_agent( array( Capabilities::WRITE_DRAFTS => false ) );
		wp_set_current_user( $agent );

		$this->assertFalse( $this->pipeline_can( 'edit_post', $this->other_authors_post( 'draft' ) ) );
	}

	public function test_an_administrator_and_a_mixed_role_user_get_core_answers_inside_a_call(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$mixed = self::factory()->user->create( array( 'role' => 'editor' ) );
		get_userdata( $mixed )->add_role( Roles::ROLE );
		$agent = $this->make_agent();
		wp_set_current_user( $agent );
		$draft = $this->other_authors_post( 'draft' );

		$answers = $this->run_check(
			static function ( array $input ) use ( $admin, $mixed, $agent, $draft ): bool {
				unset( $input );
				wp_set_current_user( $admin );
				$admin_edit = current_user_can( 'edit_post', $draft );
				wp_set_current_user( $mixed );
				$mixed_edit = current_user_can( 'edit_post', $draft );
				wp_set_current_user( $agent );

				return $admin_edit && $mixed_edit;
			},
			array()
		);

		$this->assertTrue( $answers, 'The administrator and the mixed-role editor keep core\'s answers, which allow the edit.' );
	}

	public function test_an_earlier_do_not_allow_is_never_turned_into_a_grant(): void {
		wp_set_current_user( $this->make_agent() );
		$draft     = $this->other_authors_post( 'draft' );
		$published = $this->other_authors_post( 'publish' );
		$deny      = static function ( array $caps, string $cap ): array {
			return in_array( $cap, array( 'edit_post', 'publish_post' ), true ) ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny, 5, 2 );

		try {
			$this->assertFalse( $this->pipeline_can( 'edit_post', $draft ), 'A denial from an earlier filter stands for a draft.' );
			$this->assertFalse( $this->pipeline_can( 'publish_post', $published ), 'A denial from an earlier filter stands for publish.' );
		} finally {
			remove_filter( 'map_meta_cap', $deny, 5 );
		}
	}

	public function test_the_agent_holds_no_wordpress_editing_primitives(): void {
		$agent = get_userdata( $this->make_agent() );

		foreach ( array( 'edit_posts', 'edit_others_posts', 'publish_posts', 'delete_posts', 'delete_others_posts' ) as $primitive ) {
			$this->assertFalse( $agent->has_cap( $primitive ), "The agent role must not hold $primitive." );
		}
	}
}
