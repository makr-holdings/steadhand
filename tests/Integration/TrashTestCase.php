<?php
/**
 * Shared helpers for the trash and restore tests (steadhand/trash-post and steadhand/restore-post).
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Closure;
use Steadhand\Pipeline\Pipeline;
use Steadhand\Pipeline\PostWriter;

/**
 * Base for the trash and restore tests. An administrator owns the fixture posts. The tests add filters, actions and post types
 * through on() and register_post_type(), which are removed in tearDown(). A test that calls wp_delete_post() anywhere, through
 * either ability or the writer, fails in tearDown(), because the before_delete_post action counts every permanent delete.
 */
abstract class TrashTestCase extends PostWriteTestCase {

	protected const TRASH        = 'steadhand/trash-post';
	protected const RESTORE      = 'steadhand/restore-post';
	protected const TRASH_TOOL   = 'steadhand-trash-post';
	protected const RESTORE_TOOL = 'steadhand-restore-post';

	/** The generic refusal for an object the caller may not use, or that does not exist. */
	protected const NOT_FOUND = 'Not found, or this agent may not access it.';

	/** A post type that is registered but not allowed. */
	protected const DISALLOWED_TYPE = 'sh_trash_test';

	/** An ID no post has. */
	protected const MISSING_ID = 987654321;

	/**
	 * How many times wp_delete_post() ran (before_delete_post fires inside it).
	 *
	 * @var int
	 */
	protected int $permanent_deletes = 0;

	/**
	 * Filters and actions this test added, removed in tearDown().
	 *
	 * @var array<int, array{0: string, 1: callable, 2: int}>
	 */
	private array $added = array();

	/**
	 * Counts each permanent delete. Public, because WordPress calls it.
	 */
	public function count_permanent_delete(): void {
		++$this->permanent_deletes;
	}

	/**
	 * Registers the counter and the disallowed post type.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->on( 'before_delete_post', array( $this, 'count_permanent_delete' ), 10, 0 );
		register_post_type(
			self::DISALLOWED_TYPE,
			array(
				'public'       => true,
				'map_meta_cap' => true,
				'supports'     => array( 'title', 'editor' ),
			)
		);
	}

	/**
	 * Removes what the test added, and checks that no permanent delete happened.
	 */
	protected function tearDown(): void {
		foreach ( $this->added as $entry ) {
			remove_filter( $entry[0], $entry[1], $entry[2] );
		}
		$this->added = array();

		if ( post_type_exists( self::DISALLOWED_TYPE ) ) {
			unregister_post_type( self::DISALLOWED_TYPE );
		}

		$this->assertSame( 0, $this->permanent_deletes, 'Neither ability nor the writer may call wp_delete_post().' );

		parent::tearDown();
	}

	/**
	 * Adds a filter or action that tearDown() removes. The name and arguments match add_filter().
	 *
	 * @param string   $hook     Hook name.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted arguments.
	 */
	protected function on( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
		add_filter( $hook, $callback, $priority, $args );
		$this->added[] = array( $hook, $callback, $priority );
	}

	/**
	 * A draft post by the fixture administrator.
	 */
	protected function draft_post(): int {
		return $this->admin_post( array( 'post_status' => 'draft' ) );
	}

	/**
	 * A published post by the fixture administrator.
	 */
	protected function live_post(): int {
		return $this->admin_post( array( 'post_status' => 'publish' ) );
	}

	/**
	 * A post of the disallowed type, by the fixture administrator.
	 */
	protected function disallowed_post(): int {
		return self::factory()->post->create(
			array(
				'post_type'   => self::DISALLOWED_TYPE,
				'post_status' => 'publish',
				'post_author' => $this->admin_id(),
			)
		);
	}

	/**
	 * A PDF attachment with no file on disk, by the fixture administrator.
	 */
	protected function attachment(): int {
		return (int) wp_insert_attachment(
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
	}

	/**
	 * Moves a post to the trash as the current user, through the trash ability.
	 *
	 * @param int $post_id Post ID.
	 * @return mixed
	 */
	protected function trash( int $post_id ): mixed {
		return $this->execute_ability( self::TRASH, array( 'id' => $post_id ) );
	}

	/**
	 * Restores a post as the current user, through the restore ability.
	 *
	 * @param int $post_id Post ID.
	 * @return mixed
	 */
	protected function restore( int $post_id ): mixed {
		return $this->execute_ability( self::RESTORE, array( 'id' => $post_id ) );
	}

	/**
	 * The post's status, read uncached. 'missing' when there is no post.
	 *
	 * @param int $post_id Post ID.
	 */
	protected function status_of( int $post_id ): string {
		clean_post_cache( $post_id );
		$post = get_post( $post_id );

		return $post instanceof \WP_Post ? (string) $post->post_status : 'missing';
	}

	/**
	 * A post meta value, read from the table and not from the cache.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 */
	protected function meta_of( int $post_id, string $key ): string {
		wp_cache_delete( $post_id, 'post_meta' );

		return (string) get_post_meta( $post_id, $key, true );
	}

	/**
	 * Everything a refused or unchanged call must leave as it was: the status, the text, the slug and the trash markers, with the
	 * core trash keys.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, string>
	 */
	protected function state( int $post_id ): array {
		clean_post_cache( $post_id );
		$post = get_post( $post_id );

		return array(
			'status'      => $post instanceof \WP_Post ? (string) $post->post_status : 'missing',
			'title'       => $post instanceof \WP_Post ? (string) $post->post_title : '',
			'content'     => $post instanceof \WP_Post ? (string) $post->post_content : '',
			'excerpt'     => $post instanceof \WP_Post ? (string) $post->post_excerpt : '',
			'name'        => $post instanceof \WP_Post ? (string) $post->post_name : '',
			'by'          => $this->meta_of( $post_id, PostWriter::TRASHED_BY_META ),
			'marker_time' => $this->meta_of( $post_id, PostWriter::TRASH_TIME_META ),
			'core_time'   => $this->meta_of( $post_id, PostWriter::TRASH_CORE_TIME_META ),
			'core_status' => $this->meta_of( $post_id, '_wp_trash_meta_status' ),
		);
	}

	/**
	 * Asserts a refusal with its code, and optionally its text, which must be the text without the code suffix.
	 *
	 * @param mixed       $result  The result.
	 * @param string      $code    Expected code.
	 * @param string|null $message Expected text, or null to skip it.
	 */
	protected function assert_refused( mixed $result, string $code, ?string $message = null ): void {
		$this->assertWPError( $result, 'The call must be refused.' );
		$this->assertSame( $code, $result->get_error_code() );
		if ( null !== $message ) {
			$this->assertSame( $message, $result->get_error_message() );
		}
	}

	/**
	 * Asserts the generic refusal that a missing, trashed, disallowed or unreadable post gets.
	 *
	 * @param mixed $result The result.
	 */
	protected function assert_generic( mixed $result ): void {
		$this->assert_refused( $result, 'steadhand_forbidden', self::NOT_FOUND );
	}

	/**
	 * Runs a check inside a real call of an ability with the given name, and returns the answer. A write call unless $write is false.
	 *
	 * @param string  $ability Ability name, e.g. steadhand/trash-post.
	 * @param Closure $check   Returns the value to report.
	 * @param bool    $write   Whether the call writes.
	 * @return mixed
	 */
	protected function inside( string $ability, Closure $check, bool $write = true ): mixed {
		$result = ( new Pipeline( new SpyLogger() ) )->run( new NamedProbe( $ability, $check, $write ), array() );
		$this->assertIsArray( $result, 'The probe call must be allowed to run.' );

		return $result['answer'];
	}

	/**
	 * Whether the agent may delete a post, asked from inside a call of the given ability.
	 *
	 * @param string $ability Ability name the probe runs as.
	 * @param int    $post_id Post ID.
	 * @return bool
	 */
	protected function may_delete_inside( string $ability, int $post_id ): bool {
		return (bool) $this->inside( $ability, static fn(): bool => current_user_can( 'delete_post', $post_id ) );
	}

	/**
	 * The ability name that Pipeline::current_ability_name() reports from inside a call of the given ability.
	 *
	 * @param string $ability Ability name the probe runs as.
	 */
	protected function innermost_name_inside( string $ability ): string {
		return (string) $this->inside( $ability, static fn(): string => Pipeline::current_ability_name() );
	}
}
