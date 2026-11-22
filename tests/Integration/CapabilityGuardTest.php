<?php
/**
 * MF-1: a user_has_cap filter cannot grant a Steadhand capability, and does not change core's answers for an administrator.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;

/**
 * The guard runs at PHP_INT_MAX on user_has_cap, so every test filter below runs before it. Each test grants through a
 * filter, then asks through the real pipeline.
 */
final class CapabilityGuardTest extends PostWriteTestCase {

	use InsideCall;

	/**
	 * Removes the filters the tests add.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'user_has_cap' );
		parent::tearDown();
	}

	/**
	 * A user_has_cap filter that grants every name it is asked about, and the names it is not asked about.
	 */
	private function grant_everything(): void {
		add_filter(
			'user_has_cap',
			static fn( array $all, array $caps ): array => array_fill_keys( array_merge( array_keys( $all ), $caps ), true ),
			10,
			2
		);
	}

	public function test_a_filter_that_grants_publish_does_not_let_a_drafts_only_agent_edit_a_live_post(): void {
		$post = $this->admin_post( array( 'post_content' => 'live original' ) );
		$this->act_as_agent();
		add_filter(
			'user_has_cap',
			static function ( array $all ): array {
				$all[ Capabilities::PUBLISH ] = true;
				return $all;
			}
		);
		$before = $this->row( $post );

		$result = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $post,
				'content' => 'hijacked',
			)
		);

		$this->assertSame( 'steadhand_forbidden', $this->refusal_code( $result ) );
		$this->assertSame( $before, $this->row( $post ), 'The post row must be byte-identical.' );
	}

	public function test_a_filter_that_grants_publish_does_not_let_a_drafts_only_agent_unpublish_a_live_post(): void {
		$post = $this->admin_post( array( 'post_content' => 'live' ) );
		$this->act_as_agent();
		add_filter(
			'user_has_cap',
			static function ( array $all ): array {
				$all[ Capabilities::PUBLISH ] = true;
				return $all;
			}
		);
		$before = $this->row( $post );

		$result = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'     => $post,
				'status' => 'draft',
			)
		);

		$this->assertSame( 'steadhand_forbidden', $this->refusal_code( $result ) );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_a_grant_everything_filter_does_not_let_a_drafts_only_agent_edit_or_unpublish_a_live_post(): void {
		$post = $this->admin_post( array( 'post_content' => 'live original' ) );
		$this->act_as_agent();
		$this->grant_everything();
		$before = $this->row( $post );

		$edit      = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $post,
				'content' => 'hijacked',
			)
		);
		$unpublish = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'     => $post,
				'status' => 'draft',
			)
		);

		$this->assertSame( 'steadhand_forbidden', $this->refusal_code( $edit ) );
		$this->assertSame( 'steadhand_forbidden', $this->refusal_code( $unpublish ) );
		$this->assertSame( $before, $this->row( $post ), 'The post row must be byte-identical.' );
	}

	public function test_a_grant_everything_filter_does_not_let_a_drafts_only_agent_publish_through_create_post(): void {
		$this->act_as_agent();
		$this->grant_everything();
		$count = $this->post_row_count();

		$result = $this->execute_ability(
			'steadhand/create-post',
			array(
				'title'  => 'x',
				'status' => 'publish',
			)
		);

		$this->assertSame( 'steadhand_forbidden', $this->refusal_code( $result ) );
		$this->assertSame( $count, $this->post_row_count(), 'No post may be created.' );
	}

	public function test_a_grant_everything_filter_does_not_make_edit_post_true_for_an_attachment_inside_a_call(): void {
		$attachment = self::factory()->attachment->create(
			array(
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/png',
			)
		);
		$this->act_as_agent();
		$this->grant_everything();

		$answer = $this->inside_write_call(
			static fn(): bool => current_user_can( 'edit_post', $attachment )
		);

		$this->assertFalse( $answer, 'The no-grant name must stay false, whatever a filter says.' );
	}

	public function test_a_filter_that_grants_the_no_grant_name_does_not_let_edit_post_pass_inside_a_call(): void {
		$attachment = self::factory()->attachment->create(
			array(
				'post_status'    => 'inherit',
				'post_mime_type' => 'image/png',
			)
		);
		$this->act_as_agent();
		add_filter(
			'user_has_cap',
			static function ( array $all ): array {
				$all[ Capabilities::NO_GRANT ] = true;
				return $all;
			}
		);

		$answer = $this->inside_write_call(
			static fn(): bool => current_user_can( 'edit_post', $attachment )
		);

		$this->assertFalse( $answer );
		$this->assertFalse( current_user_can( Capabilities::NO_GRANT ), 'The no-grant name is never held.' );
	}

	public function test_a_user_level_denial_still_wins_over_a_grant_everything_filter(): void {
		$post  = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'draft',
			)
		);
		$agent = $this->act_as_agent( array( Capabilities::PUBLISH => false ) );
		$this->grant_everything();

		$this->assertFalse( Capabilities::user_has( get_userdata( $agent ), Capabilities::PUBLISH ) );
		$this->assertFalse( current_user_can( Capabilities::PUBLISH ) );

		$result = $this->execute_ability(
			'steadhand/create-post',
			array(
				'title'  => 'x',
				'status' => 'publish',
			)
		);
		$this->assertSame( 'steadhand_forbidden', $this->refusal_code( $result ) );

		$before = $this->row( $post );
		$this->assertSame(
			'steadhand_forbidden',
			$this->refusal_code(
				$this->execute_ability(
					'steadhand/update-post',
					array(
						'id'     => $post,
						'status' => 'publish',
					)
				)
			)
		);
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_the_object_check_reads_the_stored_capability_even_when_the_guard_is_absent(): void {
		$post = $this->admin_post( array( 'post_content' => 'live original' ) );
		$this->act_as_agent();
		// Removes the guard for this test only: the test hooks are restored after each test. Core's answer for edit_post now
		// comes from the filter, so only UpdatePost's own check on the stored allcaps can refuse the edit.
		remove_all_filters( 'user_has_cap' );
		add_filter(
			'user_has_cap',
			static function ( array $all ): array {
				$all[ Capabilities::PUBLISH ] = true;
				return $all;
			}
		);
		$before = $this->row( $post );

		$result = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $post,
				'content' => 'hijacked',
			)
		);

		$this->assertSame( 'steadhand_forbidden', $this->refusal_code( $result ) );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_a_grant_everything_filter_adds_no_steadhand_grant_the_agent_does_not_hold(): void {
		$this->act_as_agent();
		$this->grant_everything();

		$user = wp_get_current_user();
		$this->assertFalse( $user->has_cap( Capabilities::PUBLISH ) );
		$this->assertFalse( $user->has_cap( Capabilities::MEDIA ) );
		$this->assertTrue( $user->has_cap( Capabilities::READ ), 'A grant the agent does hold is still there.' );
	}

	public function test_the_guard_leaves_core_capabilities_of_an_administrator_alone_but_a_filter_may_still_remove_one(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );

		$this->assertTrue( current_user_can( 'manage_options' ), 'Core answers for an administrator are unchanged.' );
		$this->assertTrue( current_user_can( 'edit_others_posts' ) );
		$this->assertFalse( current_user_can( Capabilities::PUBLISH ), 'An administrator holds no Steadhand grant.' );

		add_filter(
			'user_has_cap',
			static function ( array $all ): array {
				$all['manage_options']        = false;
				$all[ Capabilities::PUBLISH ] = true;
				return $all;
			}
		);

		$this->assertFalse( current_user_can( 'manage_options' ), 'A filter may still change a core capability: the guard does not restore it.' );
		$this->assertFalse( current_user_can( Capabilities::PUBLISH ), 'A filter may not grant a Steadhand capability.' );
	}
}
