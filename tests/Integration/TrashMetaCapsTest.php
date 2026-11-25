<?php
/**
 * The delete_post mapping of MetaCaps (SPEC §14.1): granted only inside steadhand/trash-post and steadhand/restore-post, and, for a
 * trashed post, only delete_post inside steadhand/restore-post. Every other answer is the one it was before this mapping.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;
use Steadhand\Pipeline\Pipeline;

/**
 * Each check asks the question from inside a call with the name of a real ability, through NamedProbe, so the answer is the one
 * a real call gets. The probes are write calls unless a test says otherwise.
 */
final class TrashMetaCapsTest extends TrashTestCase {

	/**
	 * The answers of the four post capabilities for one post, asked from inside a call of the given ability.
	 *
	 * @param string $ability Ability name.
	 * @param int    $post_id Post ID.
	 * @param bool   $write   Whether the call writes.
	 * @return array{read: bool, edit: bool, publish: bool, delete: bool}
	 */
	private function answers_inside( string $ability, int $post_id, bool $write = true ): array {
		return $this->inside(
			$ability,
			static fn(): array => array(
				'read'    => current_user_can( 'read_post', $post_id ),
				'edit'    => current_user_can( 'edit_post', $post_id ),
				'publish' => current_user_can( 'publish_post', $post_id ),
				'delete'  => current_user_can( 'delete_post', $post_id ),
			),
			$write
		);
	}

	/**
	 * Delete_post is refused inside every ability other than the two trash and restore, and inside a read call, for every status.
	 */
	public function test_delete_post_is_refused_inside_every_other_ability_and_inside_a_read(): void {
		$post = $this->draft_post();
		$this->act_as_agent(
			array(
				Capabilities::TRASH   => true,
				Capabilities::PUBLISH => true,
			)
		);

		foreach ( array( 'steadhand/update-post', 'steadhand/create-post', 'steadhand/set-featured-image', 'steadhand/undo-change' ) as $ability ) {
			$this->assertFalse( $this->may_delete_inside( $ability, $post ), "delete_post inside $ability." );
		}

		$this->assertFalse( $this->answers_inside( 'steadhand/get-post', $post, false )['delete'], 'delete_post inside a read call.' );
	}

	/**
	 * Outside a Steadhand call, delete_post keeps core's answer, which the agent never holds: refused.
	 */
	public function test_delete_post_is_refused_outside_a_call_for_a_draft_and_a_trashed_post(): void {
		$draft   = $this->draft_post();
		$trashed = $this->draft_post();
		$this->act_as_agent(
			array(
				Capabilities::TRASH   => true,
				Capabilities::PUBLISH => true,
			)
		);
		$this->trash( $trashed );

		$this->assertSame( '', Pipeline::current_ability_name() );
		$this->assertFalse( current_user_can( 'delete_post', $draft ) );
		$this->assertFalse( current_user_can( 'delete_post', $trashed ) );
	}

	/**
	 * Inside steadhand/trash-post, delete_post needs steadhand_trash and the edit capability of the post's status, both together.
	 */
	public function test_inside_trash_post_delete_post_needs_trash_and_the_capability_for_the_status(): void {
		$draft   = $this->draft_post();
		$pending = $this->admin_post( array( 'post_status' => 'pending' ) );
		$live    = $this->live_post();
		$private = $this->admin_post( array( 'post_status' => 'private' ) );

		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->assertTrue( $this->may_delete_inside( self::TRASH, $draft ), 'A draft with trash and write_drafts.' );
		$this->assertTrue( $this->may_delete_inside( self::TRASH, $pending ), 'A pending post with trash and write_drafts.' );
		$this->assertFalse( $this->may_delete_inside( self::TRASH, $live ), 'A published post without publish.' );
		$this->assertFalse( $this->may_delete_inside( self::TRASH, $private ), 'A private post without publish.' );

		$this->act_as_agent(
			array(
				Capabilities::TRASH   => true,
				Capabilities::PUBLISH => true,
			)
		);
		$this->assertTrue( $this->may_delete_inside( self::TRASH, $live ), 'A published post with trash and publish.' );
		$this->assertTrue( $this->may_delete_inside( self::TRASH, $private ), 'A private post with trash and publish.' );
	}

	/**
	 * Without steadhand_trash, delete_post is refused inside steadhand/trash-post, even for a draft the agent may edit.
	 */
	public function test_inside_trash_post_delete_post_is_refused_without_steadhand_trash(): void {
		$draft = $this->draft_post();
		$this->act_as_agent( array( Capabilities::WRITE_DRAFTS => true ) );

		$this->assertFalse( $this->may_delete_inside( self::TRASH, $draft ) );
	}

	/**
	 * Inside steadhand/trash-post, an auto-draft, a trashed post, a protected page, an attachment, a disallowed type and a missing post
	 * are all refused for delete_post, even with every capability.
	 */
	public function test_inside_trash_post_the_objects_that_may_not_be_trashed_are_refused_for_delete_post(): void {
		$auto_draft = self::factory()->post->create(
			array(
				'post_status' => 'auto-draft',
				'post_author' => $this->admin_id(),
			)
		);
		$trashed    = $this->draft_post();
		wp_trash_post( $trashed );
		$front = $this->admin_post( array( 'post_type' => 'page' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );
		$attachment = $this->attachment();
		$disallowed = $this->disallowed_post();
		$this->act_as_agent(
			array(
				Capabilities::TRASH   => true,
				Capabilities::PUBLISH => true,
			)
		);

		$this->assertFalse( $this->may_delete_inside( self::TRASH, $auto_draft ), 'An auto-draft.' );
		$this->assertFalse( $this->may_delete_inside( self::TRASH, $trashed ), 'A post already in the trash.' );
		$this->assertFalse( $this->may_delete_inside( self::TRASH, $front ), 'The front page.' );
		$this->assertFalse( $this->may_delete_inside( self::TRASH, $attachment ), 'An attachment.' );
		$this->assertFalse( $this->may_delete_inside( self::TRASH, $disallowed ), 'A disallowed type.' );
		$this->assertFalse( $this->may_delete_inside( self::TRASH, self::MISSING_ID ), 'A missing post.' );

		update_option( 'show_on_front', 'posts' );
		update_option( 'page_on_front', 0 );
	}

	/**
	 * An earlier map_meta_cap filter's do_not_allow stays one, inside steadhand/trash-post and steadhand/restore-post alike.
	 */
	public function test_an_earlier_do_not_allow_is_never_turned_into_a_grant_inside_trash_or_restore(): void {
		$draft = $this->draft_post();
		$this->on(
			'map_meta_cap',
			static fn( array $caps, string $cap ): array => 'delete_post' === $cap ? array( 'do_not_allow' ) : $caps,
			1,
			4
		);
		$this->act_as_agent(
			array(
				Capabilities::TRASH   => true,
				Capabilities::PUBLISH => true,
			)
		);

		$this->assertFalse( $this->may_delete_inside( self::TRASH, $draft ) );
	}

	/**
	 * Inside steadhand/restore-post, delete_post is granted for a trashed post the caller trashed itself, with steadhand_trash, and read, edit
	 * and publish are not. The ownership rule (TrashRules::own_trash()) is what grants it.
	 */
	public function test_inside_restore_post_a_trashed_post_gets_delete_post_and_nothing_else(): void {
		$trashed = $this->draft_post();
		$this->act_as_agent(
			array(
				Capabilities::TRASH        => true,
				Capabilities::PUBLISH      => true,
				Capabilities::WRITE_DRAFTS => true,
			)
		);
		$this->trash( $trashed );

		$answers = $this->answers_inside( self::RESTORE, $trashed );

		$this->assertTrue( $answers['delete'], 'delete_post for a trashed post, inside restore-post.' );
		$this->assertFalse( $answers['read'], 'read_post stays refused for a trashed post.' );
		$this->assertFalse( $answers['edit'], 'edit_post stays refused for a trashed post.' );
		$this->assertFalse( $answers['publish'], 'publish_post stays refused for a trashed post.' );
	}

	/**
	 * Inside steadhand/restore-post, delete_post is refused for a post that is not in the trash, an attachment, a disallowed type, and a
	 * missing post. The grant is for a trashed post only.
	 */
	public function test_inside_restore_post_delete_post_is_refused_for_a_post_that_is_not_trashed_or_not_allowed(): void {
		$draft      = $this->draft_post();
		$attachment = $this->attachment();
		wp_update_post(
			array(
				'ID'          => $attachment,
				'post_status' => 'trash',
			)
		);
		$disallowed = $this->disallowed_post();
		wp_trash_post( $disallowed );
		$this->act_as_agent(
			array(
				Capabilities::TRASH   => true,
				Capabilities::PUBLISH => true,
			)
		);

		$this->assertFalse( $this->may_delete_inside( self::RESTORE, $draft ), 'A post that is not in the trash.' );
		$this->assertFalse( $this->may_delete_inside( self::RESTORE, $attachment ), 'A trashed attachment.' );
		$this->assertFalse( $this->may_delete_inside( self::RESTORE, $disallowed ), 'A trashed post of a disallowed type.' );
		$this->assertFalse( $this->may_delete_inside( self::RESTORE, self::MISSING_ID ), 'A missing post.' );
	}

	/**
	 * Inside steadhand/restore-post, delete_post is refused without steadhand_trash.
	 */
	public function test_inside_restore_post_delete_post_is_refused_without_steadhand_trash(): void {
		$trashed = $this->draft_post();
		wp_trash_post( $trashed );
		$this->act_as_agent( array( Capabilities::WRITE_DRAFTS => true ) );

		$this->assertFalse( $this->may_delete_inside( self::RESTORE, $trashed ) );
	}

	/**
	 * A trashed post is refused read, edit and publish inside every call, trash and restore included. Only delete_post, and only inside
	 * restore-post, is granted for one.
	 */
	public function test_read_edit_and_publish_of_a_trashed_post_are_refused_inside_every_call(): void {
		$trashed = $this->draft_post();
		wp_trash_post( $trashed );
		$this->act_as_agent(
			array(
				Capabilities::TRASH        => true,
				Capabilities::PUBLISH      => true,
				Capabilities::WRITE_DRAFTS => true,
			)
		);

		foreach ( array( self::TRASH, self::RESTORE, 'steadhand/get-post' ) as $ability ) {
			$answers = $this->answers_inside( $ability, $trashed, 'steadhand/get-post' !== $ability );
			$this->assertFalse( $answers['read'], "read_post on a trashed post inside $ability." );
			$this->assertFalse( $answers['edit'], "edit_post on a trashed post inside $ability." );
			$this->assertFalse( $answers['publish'], "publish_post on a trashed post inside $ability." );
		}
	}

	/**
	 * The innermost Steadhand call is the one a check sees: a read nested in a trash call answers with the read's name, and nothing
	 * outside a call has a name.
	 */
	public function test_the_innermost_ability_decides_and_a_nested_read_gets_no_delete_post(): void {
		$draft = $this->draft_post();
		$this->act_as_agent( array( Capabilities::TRASH => true ) );

		$this->assertSame( self::TRASH, $this->innermost_name_inside( self::TRASH ) );
		$this->assertSame( 'steadhand/get-post', $this->innermost_name_inside( 'steadhand/get-post' ) );

		$nested = $this->inside(
			self::TRASH,
			fn(): bool => (bool) $this->inside(
				'steadhand/get-post',
				static fn(): bool => current_user_can( 'delete_post', $draft ),
				false
			)
		);

		$this->assertFalse( $nested, 'A read nested inside a trash call gets no delete_post.' );
		$this->assertSame( '', Pipeline::current_ability_name() );
	}

	/**
	 * The mapping is not granted for a post in the trash inside trash-post, even for the agent that trashed it.
	 */
	public function test_inside_trash_post_a_trashed_post_is_refused_for_the_agent_that_trashed_it(): void {
		$post = $this->draft_post();
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $post );

		$this->assertFalse( $this->may_delete_inside( self::TRASH, $post ) );
	}

	/**
	 * Inside steadhand/restore-post, delete_post is granted for the trashed post the caller trashed, and refused for a trashed post an
	 * administrator trashed and for one another agent trashed. All three are in the trash and of an allowed type, so only the ownership
	 * rule (TrashRules::own_trash()) separates them.
	 */
	public function test_inside_restore_post_delete_post_is_granted_only_for_the_post_the_caller_trashed(): void {
		$own                 = $this->draft_post();
		$admin_trashed       = $this->draft_post();
		$other_agent_trashed = $this->draft_post();
		wp_trash_post( $admin_trashed );

		$caller = $this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $own );
		$this->act_as_agent( array( Capabilities::TRASH => true ) );
		$this->trash( $other_agent_trashed );
		wp_set_current_user( $caller );

		$answers = $this->inside(
			self::RESTORE,
			static fn(): array => array(
				'own'         => current_user_can( 'delete_post', $own ),
				'admin'       => current_user_can( 'delete_post', $admin_trashed ),
				'other_agent' => current_user_can( 'delete_post', $other_agent_trashed ),
			)
		);

		$this->assertTrue( $answers['own'], 'delete_post for the post the caller trashed.' );
		$this->assertFalse( $answers['admin'], 'delete_post for a post an administrator trashed.' );
		$this->assertFalse( $answers['other_agent'], 'delete_post for a post another agent trashed.' );
	}

	/**
	 * Inside steadhand/trash-post, delete_post is refused for the front page and the posts page, whatever show_on_front says. A plain
	 * published page is the control: it may be trashed.
	 */
	public function test_inside_trash_post_delete_post_is_refused_for_the_front_and_posts_page_whatever_show_on_front_says(): void {
		$front = $this->admin_post( array( 'post_type' => 'page' ) );
		$posts = $this->admin_post( array( 'post_type' => 'page' ) );
		$plain = $this->admin_post( array( 'post_type' => 'page' ) );
		update_option( 'show_on_front', 'posts' );
		update_option( 'page_on_front', $front );
		update_option( 'page_for_posts', $posts );
		$this->act_as_agent(
			array(
				Capabilities::TRASH   => true,
				Capabilities::PUBLISH => true,
			)
		);

		$this->assertFalse( $this->may_delete_inside( self::TRASH, $front ), 'The front page, with show_on_front set to posts.' );
		$this->assertFalse( $this->may_delete_inside( self::TRASH, $posts ), 'The posts page.' );
		$this->assertTrue( $this->may_delete_inside( self::TRASH, $plain ), 'Control: an ordinary published page.' );
	}
}
