<?php
/**
 * The edit-lock guardrail: a post another user has open in the block editor.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Guardrails\Context;
use Steadhand\Guardrails\Result;
use Steadhand\Guardrails\Rules\EditLock;

/**
 * Covers the lock rule (SPEC §6, core's _edit_lock meta and its wp_check_post_lock_window filter).
 */
final class EditLockRuleTest extends TestCase {

	/**
	 * Runs the rule for a write to the post by the user.
	 *
	 * @param int    $post_id Post ID.
	 * @param int    $user_id Agent user ID.
	 * @param string $type    Object type.
	 */
	private function evaluate( int $post_id, int $user_id, string $type = 'post' ): Result {
		return ( new EditLock() )->evaluate( new Context( 'steadhand/update-post', 'steadhand_write_drafts', $user_id, array(), $type, $post_id ) );
	}

	/**
	 * Creates a post and gives it an edit lock by the given user, the given number of seconds ago.
	 *
	 * @param int $holder  User holding the lock.
	 * @param int $seconds Age of the lock.
	 */
	private function locked_post( int $holder, int $seconds = 0 ): int {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_edit_lock', ( time() - $seconds ) . ':' . $holder );

		return $post_id;
	}

	public function test_a_fresh_lock_held_by_another_user_blocks(): void {
		$agent = $this->make_agent();
		$human = self::factory()->user->create(
			array(
				'user_login'   => 'zelda-editor',
				'display_name' => 'Zelda Editor',
			)
		);
		$post  = $this->locked_post( $human );

		$result = $this->evaluate( $post, $agent );

		$this->assertSame( Result::MODE_BLOCK, $result->mode() );
		$this->assertSame( 'Someone is editing this post in WordPress right now. Try again after they finish.', $result->message() );
	}

	public function test_the_refusal_does_not_name_the_other_user(): void {
		$agent = $this->make_agent();
		$human = self::factory()->user->create(
			array(
				'user_login'   => 'zelda-editor',
				'display_name' => 'Zelda Editor',
			)
		);
		$post  = $this->locked_post( $human );

		$message = $this->evaluate( $post, $agent )->message();

		$this->assertStringNotContainsString( 'zelda', strtolower( $message ) );
		$this->assertStringNotContainsString( (string) $human, $message );
	}

	public function test_the_agents_own_lock_passes(): void {
		$agent = $this->make_agent();
		$post  = $this->locked_post( $agent );

		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( $post, $agent )->mode() );
	}

	public function test_a_stale_lock_passes(): void {
		$agent = $this->make_agent();
		$human = self::factory()->user->create();
		$post  = $this->locked_post( $human, 1000 );

		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( $post, $agent )->mode() );
	}

	public function test_a_lock_inside_the_default_window_blocks_and_one_just_outside_passes(): void {
		$agent   = $this->make_agent();
		$human   = self::factory()->user->create();
		$inside  = $this->locked_post( $human, 100 );
		$outside = $this->locked_post( $human, 200 );

		$this->assertSame( Result::MODE_BLOCK, $this->evaluate( $inside, $agent )->mode() );
		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( $outside, $agent )->mode() );
	}

	public function test_core_filter_shortens_the_window(): void {
		$agent = $this->make_agent();
		$human = self::factory()->user->create();
		$post  = $this->locked_post( $human, 60 );
		add_filter( 'wp_check_post_lock_window', static fn(): int => 30 );

		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( $post, $agent )->mode() );
	}

	public function test_a_malformed_window_filter_value_gives_the_default_window(): void {
		$agent = $this->make_agent();
		$human = self::factory()->user->create();
		$post  = $this->locked_post( $human, 60 );
		add_filter( 'wp_check_post_lock_window', static fn(): string => 'abc' );

		$this->assertSame( Result::MODE_BLOCK, $this->evaluate( $post, $agent )->mode() );
	}

	public function test_a_malformed_lock_passes(): void {
		$agent = $this->make_agent();
		$post  = self::factory()->post->create();

		foreach ( array( 'garbage', '123', '123:', ':456', '123:abc', '12:34:56' ) as $lock ) {
			update_post_meta( $post, '_edit_lock', $lock );
			$this->assertSame( Result::MODE_ALLOW, $this->evaluate( $post, $agent )->mode(), "The lock '$lock' must not block." );
		}
	}

	public function test_a_lock_held_by_user_zero_passes(): void {
		$agent = $this->make_agent();
		$post  = $this->locked_post( 0 );

		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( $post, $agent )->mode() );
	}

	public function test_a_call_on_something_other_than_a_post_passes(): void {
		$agent = $this->make_agent();
		$human = self::factory()->user->create();
		$post  = $this->locked_post( $human );

		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( $post, $agent, 'attachment' )->mode() );
		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( 0, $agent )->mode() );
	}

	public function test_a_post_with_no_lock_passes(): void {
		$agent = $this->make_agent();
		$post  = self::factory()->post->create();

		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( $post, $agent )->mode() );
	}


	/**
	 * Gives a post the legacy lock format, a timestamp with no user, and an optional _edit_last holder.
	 *
	 * @param int      $seconds Age of the lock.
	 * @param int|null $last    The _edit_last user, or null for none.
	 */
	private function legacy_locked_post( int $seconds, ?int $last ): int {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, '_edit_lock', (string) ( time() - $seconds ) );
		if ( null !== $last ) {
			update_post_meta( $post_id, '_edit_last', (string) $last );
		}

		return $post_id;
	}

	public function test_a_fresh_legacy_lock_with_no_last_editor_blocks_because_the_holder_is_unknown(): void {
		$post = $this->legacy_locked_post( 0, null );

		$this->assertSame( Result::MODE_BLOCK, $this->evaluate( $post, self::factory()->user->create() )->mode() );
	}

	public function test_a_fresh_legacy_lock_whose_last_editor_is_zero_blocks(): void {
		$post = $this->legacy_locked_post( 0, 0 );

		$this->assertSame( Result::MODE_BLOCK, $this->evaluate( $post, self::factory()->user->create() )->mode() );
	}

	public function test_a_fresh_legacy_lock_held_by_another_user_blocks(): void {
		$other = self::factory()->user->create();
		$agent = self::factory()->user->create();
		$post  = $this->legacy_locked_post( 0, $other );

		$this->assertSame( Result::MODE_BLOCK, $this->evaluate( $post, $agent )->mode() );
	}

	public function test_a_fresh_legacy_lock_the_agent_itself_holds_passes(): void {
		$agent = self::factory()->user->create();
		$post  = $this->legacy_locked_post( 0, $agent );

		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( $post, $agent )->mode() );
	}

	public function test_a_stale_legacy_lock_passes_whoever_it_names(): void {
		$other = self::factory()->user->create();
		$agent = self::factory()->user->create();
		$post  = $this->legacy_locked_post( 3600, $other );

		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( $post, $agent )->mode() );
	}


	public function test_is_locked_sees_every_live_lock_while_the_rule_keeps_its_own_exemptions(): void {
		$agent = $this->make_agent();

		// The agent's own lock and a lock held by user 0 are live for is_locked() (the administrator's undo refuses them), and the
		// rule still lets the agent write through them.
		$own = $this->locked_post( $agent );
		$this->assertTrue( EditLock::is_locked( $own ) );
		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( $own, $agent )->mode() );

		$zero = $this->locked_post( 0 );
		$this->assertTrue( EditLock::is_locked( $zero ) );
		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( $zero, $agent )->mode() );

		// A stale lock is not live for either.
		$stale = $this->locked_post( $agent, 1000 );
		$this->assertFalse( EditLock::is_locked( $stale ) );
		$this->assertFalse( EditLock::is_locked( self::factory()->post->create() ) );
	}

	public function test_a_lock_with_a_leading_space_is_read_as_core_reads_it_and_blocks_another_users_lock(): void {
		$agent = $this->make_agent();
		$human = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post  = self::factory()->post->create();
		update_post_meta( $post, '_edit_lock', ' ' . time() . ':' . $human );

		$this->assertSame( $human, (int) wp_check_post_lock( $post ), 'Core reads the leading-space lock as held by the human.' );
		$this->assertSame( Result::MODE_BLOCK, $this->evaluate( $post, $agent )->mode() );
	}

	public function test_a_lock_with_a_trailing_newline_is_read_as_core_reads_it_and_blocks_another_users_lock(): void {
		$agent = $this->make_agent();
		$human = self::factory()->user->create( array( 'role' => 'editor' ) );
		$post  = self::factory()->post->create();
		update_post_meta( $post, '_edit_lock', time() . ':' . $human . "\n" );

		$this->assertSame( $human, (int) wp_check_post_lock( $post ), 'Core reads the trailing-newline lock as held by the human.' );
		$this->assertSame( Result::MODE_BLOCK, $this->evaluate( $post, $agent )->mode() );
	}
}
