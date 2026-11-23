<?php
/**
 * The steadhand/set-featured-image ability: sets, replaces and removes the featured image, with its permissions, refusals,
 * guardrails, the change log and the lost-update check.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Closure;

/**
 * Covers SPEC §5 (set-featured-image), §6.1, §7, §10 and §14.1. Every refusal test checks that the post's stored thumbnail
 * is the same afterwards.
 */
final class SetFeaturedImageTest extends PostWriteTestCase {

	private const ABILITY   = 'steadhand/set-featured-image';
	private const TOOL_NAME = 'steadhand-set-featured-image';
	private const NOT_FOUND = 'Not found, or this agent may not access it.';
	private const NO_THUMB  = 'sh_nothumb_test';
	private const NO_META   = 'sh_nometa_test';

	/**
	 * Attachment IDs this test created, deleted with their files in tearDown().
	 *
	 * @var int[]
	 */
	private array $created = array();

	/**
	 * Removes the filters, types and attachments this file adds.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'get_post_metadata' );
		remove_all_filters( 'steadhand_rate_limit_per_minute' );
		remove_all_filters( 'steadhand_allowed_post_types' );

		foreach ( array( self::NO_THUMB, self::NO_META ) as $type ) {
			if ( post_type_exists( $type ) ) {
				unregister_post_type( $type );
			}
		}

		foreach ( $this->created as $id ) {
			if ( null !== get_post( $id ) ) {
				wp_delete_attachment( $id, true );
			}
		}
		$this->created = array();

		parent::tearDown();
	}

	/**
	 * Calls the ability as the current user.
	 *
	 * @param array<string, mixed> $args Input.
	 * @return mixed
	 */
	private function set( array $args ): mixed {
		return $this->execute_ability( self::ABILITY, $args );
	}

	/**
	 * A JPEG attachment in the media library, optionally with a parent. Recorded for cleanup.
	 *
	 * @param int $parent_id Parent post ID, or 0.
	 */
	private function image( int $parent_id = 0 ): int {
		$id              = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', $parent_id );
		$this->created[] = $id;

		return $id;
	}

	/**
	 * A PDF attachment with no file on disk. Recorded for cleanup.
	 */
	private function pdf(): int {
		$id              = (int) wp_insert_attachment(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'application/pdf',
				'post_title'     => 'Specification',
				'post_author'    => $this->admin_id(),
			),
			false,
			0
		);
		$this->created[] = $id;

		return $id;
	}

	/**
	 * The featured image a post stores, read from the table and not from the object cache. 0 when there is none.
	 *
	 * @param int $post_id Post ID.
	 */
	private function thumb( int $post_id ): int {
		wp_cache_delete( $post_id, 'post_meta' );

		return (int) get_post_meta( $post_id, '_thumbnail_id', true );
	}

	/**
	 * A draft post by the fixture administrator.
	 */
	private function draft(): int {
		return $this->admin_post( array( 'post_status' => 'draft' ) );
	}

	/**
	 * Asserts that a refusal has the code, and that the stored thumbnail is still the one it was.
	 *
	 * @param mixed  $result    The ability's result.
	 * @param string $code      Expected code.
	 * @param int    $post_id   The post.
	 * @param int    $thumbnail The thumbnail the post had before the call.
	 * @return \WP_Error
	 */
	private function refused_leaving( mixed $result, string $code, int $post_id, int $thumbnail ): \WP_Error {
		$this->assertWPError( $result, 'The call must be refused.' );
		$this->assertSame( $code, $result->get_error_code() );
		$this->assertSame( $thumbnail, $this->thumb( $post_id ), 'A refused call must leave the featured image as it was.' );

		return $result;
	}

	/**
	 * Runs a change once, the first time the post's thumbnail is read after this is set up. The ability reads the thumbnail
	 * first, and the writer reads it again: the change lands between the two reads. The first read still gets the value before
	 * the change.
	 *
	 * @param int      $post_id The post.
	 * @param callable $change  What to do.
	 */
	private function once_after_the_thumbnail_is_read( int $post_id, callable $change ): void {
		$fired = false;
		add_filter(
			'get_post_metadata',
			static function ( $value, $object_id, $meta_key, $single ) use ( &$fired, $post_id, $change ) {
				if ( $fired || (int) $object_id !== $post_id || '_thumbnail_id' !== $meta_key ) {
					return $value;
				}

				$fired  = true;
				$stored = get_post_meta( $post_id, $meta_key, true ); // $fired is set, so this read is not intercepted.
				$change();

				return $single ? $stored : array( $stored );
			},
			10,
			4
		);
	}

	/**
	 * Counts the writes made to the post's thumbnail meta, by the three meta actions.
	 *
	 * @param Closure $work The code under test.
	 * @return int
	 */
	private function count_thumbnail_writes( Closure $work ): int {
		$count = 0;
		$hook  = static function ( $meta_id, $object_id, $meta_key ) use ( &$count ) {
			unset( $meta_id, $object_id );
			if ( '_thumbnail_id' === $meta_key ) {
				++$count;
			}
		};
		add_action( 'added_post_meta', $hook, 10, 3 );
		add_action( 'updated_post_meta', $hook, 10, 3 );
		add_action( 'deleted_post_meta', $hook, 10, 3 );

		try {
			$work();
		} finally {
			remove_action( 'added_post_meta', $hook, 10 );
			remove_action( 'updated_post_meta', $hook, 10 );
			remove_action( 'deleted_post_meta', $hook, 10 );
		}

		return $count;
	}

	/**
	 * A post's featured image can be set, replaced and removed. The output lists the previous image and carries the warning.
	 */
	public function test_an_image_can_be_set_replaced_and_removed(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$post  = $this->draft();
		$first = $this->image();
		$next  = $this->image();

		$set = $this->set(
			array(
				'id'       => $post,
				'media_id' => $first,
			)
		);
		$this->assertIsArray( $set );
		$this->assertTrue( $set['changed'] );
		$this->assertSame( $first, $this->thumb( $post ) );
		$this->assertSame( $first, $set['featured_media'] );
		$this->assertSame( 0, $set['previous_featured_media'] );
		$this->assertSame( $post, $set['id'] );
		$this->assertIsString( $set['link'] );
		$this->assertSame( array( 'Steadhand cannot undo this change to the featured image yet. previous_featured_media holds the image the post had before.' ), $set['warnings'] );
		$this->assertSame( array(), $set['guardrail_notes'] );

		$valid = rest_validate_value_from_schema( $set, wp_get_ability( self::ABILITY )->get_output_schema(), 'output' );
		$this->assertNotInstanceOf( \WP_Error::class, $valid, 'The output must match the output schema.' );

		$replaced = $this->set(
			array(
				'id'       => $post,
				'media_id' => $next,
			)
		);
		$this->assertTrue( $replaced['changed'] );
		$this->assertSame( $next, $this->thumb( $post ) );
		$this->assertSame( $first, $replaced['previous_featured_media'] );

		$removed = $this->set(
			array(
				'id'       => $post,
				'media_id' => 0,
			)
		);
		$this->assertTrue( $removed['changed'] );
		$this->assertSame( 0, $this->thumb( $post ) );
		$this->assertSame( 0, $removed['featured_media'] );
		$this->assertSame( $next, $removed['previous_featured_media'] );
	}

	/**
	 * The image the post already has changes nothing and writes no meta.
	 */
	public function test_asking_for_the_current_image_changes_nothing(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$post  = $this->draft();
		$image = $this->image();
		$this->set(
			array(
				'id'       => $post,
				'media_id' => $image,
			)
		);

		$result = null;
		$writes = $this->count_thumbnail_writes(
			function () use ( $post, $image, &$result ): void {
				$result = $this->set(
					array(
						'id'       => $post,
						'media_id' => $image,
					)
				);
			}
		);

		$this->assertIsArray( $result );
		$this->assertFalse( $result['changed'] );
		$this->assertSame( array(), $result['warnings'] );
		$this->assertSame( $image, $result['featured_media'] );
		$this->assertSame( $image, $result['previous_featured_media'] );
		$this->assertSame( 0, $writes, 'No meta may be written for a call that changes nothing.' );

		$none = $this->set(
			array(
				'id'       => $this->draft(),
				'media_id' => 0,
			)
		);
		$this->assertFalse( $none['changed'] );
	}

	/**
	 * Without steadhand_media the call is refused, and the thumbnail is unchanged.
	 */
	public function test_refused_without_steadhand_media(): void {
		$this->act_as_agent( array( 'steadhand_media' => false ) );
		$post  = $this->draft();
		$image = $this->image();

		$result = $this->set(
			array(
				'id'       => $post,
				'media_id' => $image,
			)
		);

		$this->refused_leaving( $result, 'steadhand_forbidden', $post, 0 );
		$this->assertStringContainsString( 'steadhand_media', $result->get_error_message() );
	}

	/**
	 * Without steadhand_read the call is refused, even with steadhand_media.
	 */
	public function test_refused_without_steadhand_read(): void {
		$this->act_as_agent(
			array(
				'steadhand_media' => true,
				'steadhand_read'  => false,
			)
		);
		$post   = $this->draft();
		$result = $this->set(
			array(
				'id'       => $post,
				'media_id' => $this->image(),
			)
		);

		$this->refused_leaving( $result, 'steadhand_forbidden', $post, 0 );
		$this->assertStringContainsString( 'steadhand_read', $result->get_error_message() );
	}

	/**
	 * A draft needs steadhand_write_drafts. An agent without it is refused with the one text.
	 */
	public function test_a_draft_needs_write_drafts(): void {
		$this->act_as_agent(
			array(
				'steadhand_media'        => true,
				'steadhand_write_drafts' => false,
			)
		);
		$post   = $this->draft();
		$result = $this->set(
			array(
				'id'       => $post,
				'media_id' => $this->image(),
			)
		);

		$this->refused_leaving( $result, 'steadhand_forbidden', $post, 0 );
		$this->assertSame( self::NOT_FOUND, $result->get_error_message() );
	}

	/**
	 * A published post needs steadhand_publish. The agent with write_drafts only is refused; one with publish may set it.
	 */
	public function test_a_published_post_needs_publish(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$live  = $this->admin_post();
		$image = $this->image();

		$result = $this->set(
			array(
				'id'       => $live,
				'media_id' => $image,
			)
		);
		$this->refused_leaving( $result, 'steadhand_forbidden', $live, 0 );
		$this->assertSame( self::NOT_FOUND, $result->get_error_message() );

		$this->act_as_agent(
			array(
				'steadhand_media'   => true,
				'steadhand_publish' => true,
			)
		);
		$allowed = $this->set(
			array(
				'id'       => $live,
				'media_id' => $image,
			)
		);
		$this->assertTrue( $allowed['changed'] );
		$this->assertSame( $image, $this->thumb( $live ) );
	}

	/**
	 * A missing, trashed, disallowed or otherwise unreadable post gets the same refusal text, and nothing changes.
	 */
	public function test_a_missing_trashed_or_disallowed_post_gets_the_same_refusal(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$image = $this->image();

		$trashed = $this->draft();
		wp_trash_post( $trashed );
		$block = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_status' => 'publish',
			)
		);
		$posts = array(
			'a missing post'          => 987654321,
			'a trashed post'          => $trashed,
			'a disallowed type'       => $block,
			'an attachment as a post' => $image,
		);

		foreach ( $posts as $case => $post ) {
			$result = $this->set(
				array(
					'id'       => $post,
					'media_id' => $image,
				)
			);
			$this->assertWPError( $result, $case );
			$this->assertSame( 'steadhand_forbidden', $result->get_error_code(), $case );
			$this->assertSame( self::NOT_FOUND, $result->get_error_message(), $case );
		}
	}

	/**
	 * A post trashed after the permission check, while the guardrails run, is refused by the ability's own re-check with the
	 * not-found refusal, and nothing is written.
	 */
	public function test_a_post_trashed_after_the_check_is_refused_as_not_found(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$post  = $this->draft();
		$image = $this->image();
		$done  = false;

		add_filter(
			'steadhand_rate_limit_per_minute',
			static function ( $limit ) use ( $post, &$done ) {
				if ( ! $done ) {
					$done = true;
					wp_trash_post( $post );
				}

				return $limit;
			}
		);

		$result = $this->set(
			array(
				'id'       => $post,
				'media_id' => $image,
			)
		);

		$this->refused_leaving( $result, 'steadhand_not_found', $post, 0 );
		$this->assertSame( self::NOT_FOUND, $result->get_error_message() );
		$this->assertSame( 404, $result->get_error_data()['status'] );
		$this->assertTrue( $done, 'The post must be trashed during the guardrails.' );
		$this->assertSame( 'trash', get_post_status( $post ) );
	}

	/**
	 * A post type that is registered without meta mapping is refused, even when the filter adds it to the allowed list.
	 */
	public function test_a_post_type_with_map_meta_cap_off_is_refused_even_when_the_filter_allows_it(): void {
		register_post_type(
			self::NO_META,
			array(
				'public'       => true,
				'supports'     => array( 'thumbnail' ),
				'map_meta_cap' => false,
			)
		);
		add_filter(
			'steadhand_allowed_post_types',
			static fn( array $types ): array => array_merge( $types, array( self::NO_META ) )
		);
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$post = self::factory()->post->create(
			array(
				'post_type'   => self::NO_META,
				'post_status' => 'draft',
				'post_author' => $this->admin_id(),
			)
		);

		$result = $this->set(
			array(
				'id'       => $post,
				'media_id' => $this->image(),
			)
		);

		$this->refused_leaving( $result, 'steadhand_forbidden', $post, 0 );
		$this->assertSame( self::NOT_FOUND, $result->get_error_message() );
	}

	/**
	 * A post type that does not support a featured image is refused with a message that says so.
	 */
	public function test_a_post_type_without_thumbnail_support_is_refused_with_a_clear_message(): void {
		register_post_type(
			self::NO_THUMB,
			array(
				'public'       => true,
				'supports'     => array( 'title' ),
				'map_meta_cap' => true,
			)
		);
		add_filter(
			'steadhand_allowed_post_types',
			static fn( array $types ): array => array_merge( $types, array( self::NO_THUMB ) )
		);
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$post = self::factory()->post->create(
			array(
				'post_type'   => self::NO_THUMB,
				'post_status' => 'draft',
				'post_author' => $this->admin_id(),
			)
		);

		$result = $this->set(
			array(
				'id'       => $post,
				'media_id' => $this->image(),
			)
		);

		$this->refused_leaving( $result, 'steadhand_invalid_input', $post, 0 );
		$this->assertStringContainsString( 'does not support a featured image', $result->get_error_message() );
	}

	/**
	 * A media_id that is not an image (a PDF) is refused, and nothing changes.
	 */
	public function test_a_media_id_that_is_not_an_image_is_refused(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$post = $this->draft();

		$result = $this->set(
			array(
				'id'       => $post,
				'media_id' => $this->pdf(),
			)
		);

		$this->refused_leaving( $result, 'steadhand_invalid_input', $post, 0 );
		$this->assertStringContainsString( 'media_id must be an image', $result->get_error_message() );
	}

	/**
	 * An attachment the caller may not read gets the same refusal text: one whose parent is a private post of a type that is not
	 * allowed, a trashed one, a missing ID, and a post ID given as a media ID.
	 */
	public function test_an_unreadable_media_id_gets_the_refusal_text(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$post = $this->draft();

		$block  = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_status' => 'private',
			)
		);
		$hidden = $this->image( $block );

		$trashed = $this->image();
		wp_update_post(
			array(
				'ID'          => $trashed,
				'post_status' => 'trash',
			)
		);

		$media_ids = array(
			'a child of a hidden parent' => $hidden,
			'a trashed attachment'       => $trashed,
			'a missing attachment'       => 987654321,
			'a post, not an attachment'  => $post,
		);

		foreach ( $media_ids as $case => $media_id ) {
			$result = $this->set(
				array(
					'id'       => $post,
					'media_id' => $media_id,
				)
			);
			$this->assertWPError( $result, $case );
			$this->assertSame( self::NOT_FOUND, $result->get_error_message(), $case );
			$this->assertSame( 0, $this->thumb( $post ), $case );
		}
	}

	/**
	 * Input the schema refuses: a missing field, zero as the post, a negative media ID, and an extra property.
	 */
	public function test_input_the_schema_refuses_changes_nothing(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$post  = $this->draft();
		$image = $this->image();

		$inputs = array(
			'a missing media_id'  => array( 'id' => $post ),
			'a missing id'        => array( 'media_id' => $image ),
			'post 0'              => array(
				'id'       => 0,
				'media_id' => $image,
			),
			'a negative media_id' => array(
				'id'       => $post,
				'media_id' => -1,
			),
			'an extra property'   => array(
				'id'       => $post,
				'media_id' => $image,
				'title'    => 'nope',
			),
		);

		foreach ( $inputs as $case => $input ) {
			$result = $this->set( $input );
			$this->assertWPError( $result, $case );
			$this->assertSame( 'steadhand_invalid_input', $result->get_error_code(), $case );
			$this->assertSame( 0, $this->thumb( $post ), $case );
		}
	}

	/**
	 * A post someone is editing in the block editor is refused by the edit-lock guardrail.
	 */
	public function test_an_edit_locked_post_is_blocked_and_nothing_changes(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$post = $this->draft();
		update_post_meta( $post, '_edit_lock', time() . ':' . $this->admin_id() );

		$result = $this->set(
			array(
				'id'       => $post,
				'media_id' => $this->image(),
			)
		);

		$this->refused_leaving( $result, 'steadhand_guardrail_blocked', $post, 0 );
		$this->assertSame( 422, $result->get_error_data()['status'] );
	}

	/**
	 * The rate limit applies: the second change in the window is refused.
	 */
	public function test_the_rate_limit_applies(): void {
		add_filter( 'steadhand_rate_limit_per_minute', static fn(): int => 1 );
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$post  = $this->draft();
		$first = $this->image();

		$this->assertTrue(
			$this->set(
				array(
					'id'       => $post,
					'media_id' => $first,
				)
			)['changed']
		);

		$result = $this->set(
			array(
				'id'       => $post,
				'media_id' => $this->image(),
			)
		);

		$this->refused_leaving( $result, 'steadhand_guardrail_blocked', $post, $first );
	}

	/**
	 * A featured image changed between the read and the write is refused, the other change is kept, and nothing is lost.
	 */
	public function test_a_featured_image_changed_between_the_read_and_the_write_is_refused(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$post   = $this->draft();
		$other  = $this->image();
		$wanted = $this->image();

		$this->once_after_the_thumbnail_is_read(
			$post,
			fn() => update_post_meta( $post, '_thumbnail_id', $other )
		);

		$result = $this->set(
			array(
				'id'       => $post,
				'media_id' => $wanted,
			)
		);

		$this->refused_leaving( $result, 'steadhand_refused_content_changed', $post, $other );
		$this->assertSame( 409, $result->get_error_data()['status'] );
	}

	/**
	 * A publish made between the read and the write: the permission check runs again on the post as it is, and refuses.
	 */
	public function test_a_publish_between_the_check_and_the_write_is_refused(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$post  = $this->draft();
		$image = $this->image();

		$this->once_after_the_thumbnail_is_read(
			$post,
			fn() => wp_update_post(
				array(
					'ID'          => $post,
					'post_status' => 'publish',
				)
			)
		);

		$result = $this->set(
			array(
				'id'       => $post,
				'media_id' => $image,
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
		$this->assertSame( 0, $this->thumb( $post ) );
		$this->assertSame( 'publish', get_post_status( $post ) );
	}

	/**
	 * A success writes one ok row for the post, and a refusal one blocked row. Neither holds anything but the IDs.
	 */
	public function test_the_log_records_the_post_and_the_outcome(): void {
		$agent = $this->act_as_agent( array( 'steadhand_media' => true ) );
		$post  = $this->draft();
		$image = $this->image();

		$this->set(
			array(
				'id'       => $post,
				'media_id' => $image,
			)
		);

		$row = $this->latest_row( $agent );
		$this->assertSame( 'ok', $row['result'] );
		$this->assertSame( 'post', $row['object_type'] );
		$this->assertSame( $post, (int) $row['object_id'] );
		$this->assertSame( 0, (int) $row['undoable'] );

		$this->set(
			array(
				'id'       => 987654321,
				'media_id' => $image,
			)
		);
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );
	}

	/**
	 * The tool works over MCP as an agent calls it.
	 */
	public function test_the_tool_works_over_mcp(): void {
		$this->act_as_agent( array( 'steadhand_media' => true ) );
		$post  = $this->draft();
		$image = $this->image();

		$output = $this->output_of(
			self::TOOL_NAME,
			array(
				'id'       => $post,
				'media_id' => $image,
			)
		);

		$this->assertTrue( $output['changed'] );
		$this->assertSame( $image, $this->thumb( $post ) );
	}
}
