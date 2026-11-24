<?php
/**
 * The administrator's undo (steadhand/admin-undo, UndoEntry, UndoHandler, Pipeline::run_admin): who may use it, what it
 * restores for each kind of change, what it refuses, and what it records. Negative tests are the bulk of it.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\GetSiteInfo;
use Steadhand\Abilities\UndoChange;
use Steadhand\Capabilities;
use Steadhand\Admin\UndoEntry;
use Steadhand\Admin\UndoHandler;
use Steadhand\Guardrails\Engine;
use Steadhand\Guardrails\Result;
use Steadhand\Log\DbLogger;
use Steadhand\Log\Entry;
use Steadhand\Log\Schema;
use Steadhand\Pipeline\Pipeline;
use Steadhand\Plugin;
use Steadhand\Roles;

/**
 * Covers the admin undo from the handler down. The undo is a write by an administrator, so every refusal must leave the post
 * unchanged and must not mark the change-log entry.
 */
final class AdminUndoTest extends PostWriteTestCase {

	private const UPDATE_TOOL = 'steadhand-update-post';
	private const UPDATE      = 'steadhand/update-post';
	private const TERMS       = 'steadhand/set-post-terms';
	private const FEATURED    = 'steadhand/set-featured-image';
	private const ADMIN_UNDO  = 'steadhand/admin-undo';
	private const AGENT_UNDO  = 'steadhand/undo-change';

	/** An ID no change-log row has. */
	private const MISSING_ID = 987654321;

	/** The refusal the agent's undo-change gives for an entry that was already undone. */
	private const ALREADY = 'This change has already been undone, so it cannot be undone again. Nothing was changed.';

	/** The fixed refusal for a caller who is not an administrator without an agent role. */
	private const ADMIN_ONLY = 'Only an administrator who does not hold the Steadhand agent role may do this. Nothing was changed.';

	/**
	 * The agent, created on first use.
	 *
	 * @var int
	 */
	private int $agent = 0;

	/**
	 * The filter that installs the wp_die handler, kept so tearDown can remove it.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $die_filter = null;

	/**
	 * Filters this file adds, with their priorities, removed in tearDown by closure, so Steadhand's own filters stay.
	 *
	 * @var array<int, array{0: string, 1: \Closure, 2: int}>
	 */
	private array $added_list = array();

	protected function setUp(): void {
		parent::setUp();

		// A test double: the message is never printed, and a test that expects a refusal catches the exception.
		$handler          = static function ( $message ): void {
			throw new \WPDieException( (string) $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- See the comment above.
		};
		$this->die_filter = static fn() => $handler;
		add_filter( 'wp_die_handler', $this->die_filter, PHP_INT_MAX );
	}

	protected function tearDown(): void {
		foreach ( $this->added_list as $added ) {
			remove_filter( $added[0], $added[1], $added[2] );
		}
		$this->added_list = array();

		if ( null !== $this->die_filter ) {
			remove_filter( 'wp_die_handler', $this->die_filter, PHP_INT_MAX );
		}
		remove_all_filters( 'query' );
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'] );

		parent::tearDown();
	}

	/**
	 * Adds a filter this test removes again in tearDown, at the priority the test gives.
	 *
	 * @param string   $hook     Hook.
	 * @param \Closure $callback Callback.
	 * @param int      $priority Priority.
	 * @param int      $args     Accepted arguments.
	 */
	private function filter( string $hook, \Closure $callback, int $priority = 10, int $args = 1 ): void {
		add_filter( $hook, $callback, $priority, $args );
		$this->added_list[] = array( $hook, $callback, $priority );
	}

	private function agent(): int {
		if ( 0 === $this->agent ) {
			$this->agent = $this->make_agent( array( Capabilities::MEDIA => true ) );
		}

		return $this->agent;
	}

	private static function paragraph( string $text ): string {
		return "<!-- wp:paragraph -->\n<p>$text</p>\n<!-- /wp:paragraph -->";
	}

	/**
	 * A draft owned by the administrator, with a known title, content and excerpt.
	 *
	 * @param array<string, mixed> $fields Overrides.
	 */
	private function text_fixture( array $fields = array() ): int {
		return $this->admin_post(
			array_merge(
				array(
					'post_status'  => 'draft',
					'post_title'   => 'Before title',
					'post_content' => self::paragraph( 'Before' ),
					'post_excerpt' => 'Before excerpt',
				),
				$fields
			)
		);
	}

	/**
	 * Makes a text change as the agent, and returns the change-log entry it wrote.
	 *
	 * @param int                  $post   Post ID.
	 * @param array<string, mixed> $fields Fields to send.
	 */
	private function agent_text_change( int $post, array $fields ): int {
		wp_set_current_user( $this->agent() );
		$this->output_of( self::UPDATE_TOOL, array( 'id' => $post ) + $fields );

		return (int) $this->latest_row( $this->agent() )['id'];
	}

	/**
	 * Sets a post's terms in post_tag as the agent, and returns the change-log entry it wrote.
	 *
	 * @param int   $post  Post ID.
	 * @param int[] $terms Term IDs.
	 */
	private function agent_terms( int $post, array $terms ): int {
		wp_set_current_user( $this->agent() );
		$this->execute_ability(
			self::TERMS,
			array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => $terms,
				'mode'     => 'replace',
			)
		);

		return (int) $this->latest_row( $this->agent() )['id'];
	}

	/**
	 * Sets a post's featured image as the agent, and returns the change-log entry it wrote.
	 *
	 * @param int $post     Post ID.
	 * @param int $media_id Attachment ID, or 0.
	 */
	private function agent_image( int $post, int $media_id ): int {
		wp_set_current_user( $this->agent() );
		$this->execute_ability(
			self::FEATURED,
			array(
				'id'       => $post,
				'media_id' => $media_id,
			)
		);

		return (int) $this->latest_row( $this->agent() )['id'];
	}

	private function tag( string $name ): int {
		return (int) self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => $name,
			)
		);
	}

	private function image(): int {
		return (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', 0 );
	}

	/**
	 * The post's term IDs in post_tag, ascending.
	 *
	 * @param int $post Post ID.
	 * @return int[]
	 */
	private function tag_ids( int $post ): array {
		$ids = array_map( 'intval', (array) wp_get_object_terms( $post, 'post_tag', array( 'fields' => 'ids' ) ) );
		sort( $ids, SORT_NUMERIC );

		return $ids;
	}

	/**
	 * The handler, built the way Plugin builds it, with a pipeline that has no guardrails (an administrator's undo runs none).
	 */
	private function handler(): UndoHandler {
		$logger = new DbLogger();

		return new UndoHandler( new Pipeline( $logger ), $logger );
	}

	/**
	 * Posts the undo form as the current user, through UndoHandler::process(), and returns the redirect URL. A refusal made
	 * before the undo ends in wp_die(), which throws WPDieException here.
	 *
	 * @param int         $entry Entry ID.
	 * @param string|null $nonce The nonce to send. Null sends the correct one for the entry.
	 */
	private function submit_undo( int $entry, ?string $nonce = null ): string {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'action'   => UndoHandler::ACTION,
			'entry'    => (string) $entry,
			'_wpnonce' => $nonce ?? wp_create_nonce( UndoHandler::NONCE_PREFIX . $entry ),
		);
		$_REQUEST                  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test fixture: the request the handler reads.

		return $this->handler()->process();
	}

	/**
	 * One query value of a redirect URL.
	 *
	 * @param string $url Redirect URL.
	 * @param string $key Query key.
	 */
	private function query_of( string $url, string $key ): string {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		return (string) ( $query[ $key ] ?? '' );
	}

	/**
	 * The undo code in a redirect URL.
	 *
	 * @param string $url Redirect URL.
	 */
	private function code_of( string $url ): string {
		return $this->query_of( $url, 'undo' );
	}

	/**
	 * Changes the post's content directly in its row, as another request would, with no revision.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content New content.
	 */
	private function direct_content( int $post_id, string $content ): void {
		global $wpdb;

		$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Another request's write, with no revision.
		clean_post_cache( $post_id );
	}

	/**
	 * The text columns of a post, which is what an undo restores.
	 *
	 * @param int $post Post ID.
	 * @return array<string, string>
	 */
	private function text_of_post( int $post ): array {
		$row = $this->row( $post );

		return array(
			'post_title'   => $row['post_title'],
			'post_content' => $row['post_content'],
			'post_excerpt' => $row['post_excerpt'],
			'post_status'  => $row['post_status'],
		);
	}

	/**
	 * Asserts that a refused undo left the post as it was and the entry unmarked.
	 *
	 * @param array<string, string> $before Post text before the call.
	 * @param int                   $post   Post ID.
	 * @param int                   $entry  Entry ID.
	 */
	private function assert_nothing_changed( array $before, int $post, int $entry ): void {
		$this->assertSame( $before, $this->text_of_post( $post ), 'A refused undo must leave the post as it was.' );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'], 'A refused undo must not mark the entry.' );
	}


	public function test_a_get_request_is_refused_and_changes_nothing(): void {
		$post   = $this->text_fixture();
		$entry  = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );
		$before = $this->text_of_post( $post );

		wp_set_current_user( $this->admin_id() );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = array(
			'entry'    => (string) $entry,
			'_wpnonce' => wp_create_nonce( UndoHandler::NONCE_PREFIX . $entry ),
		);

		$this->expectException( \WPDieException::class );
		try {
			$this->handler()->process();
		} finally {
			$this->assert_nothing_changed( $before, $post, $entry );
		}
	}

	public function test_a_missing_nonce_dies_and_changes_nothing(): void {
		$post   = $this->text_fixture();
		$entry  = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );
		$before = $this->text_of_post( $post );

		wp_set_current_user( $this->admin_id() );

		$this->expectException( \WPDieException::class );
		try {
			$this->submit_undo( $entry, '' );
		} finally {
			$this->assert_nothing_changed( $before, $post, $entry );
		}
	}

	public function test_a_nonce_for_another_entry_dies_and_changes_nothing(): void {
		$post   = $this->text_fixture();
		$first  = $this->agent_text_change( $post, array( 'title' => 'First agent title' ) );
		$second = $this->agent_text_change( $post, array( 'title' => 'Second agent title' ) );
		$before = $this->text_of_post( $post );

		wp_set_current_user( $this->admin_id() );

		$this->expectException( \WPDieException::class );
		try {
			$this->submit_undo( $second, wp_create_nonce( UndoHandler::NONCE_PREFIX . $first ) );
		} finally {
			$this->assert_nothing_changed( $before, $post, $second );
		}
	}

	public function test_a_nonce_made_for_another_action_is_refused(): void {
		$post   = $this->text_fixture();
		$entry  = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );
		$before = $this->text_of_post( $post );

		wp_set_current_user( $this->admin_id() );

		$this->expectException( \WPDieException::class );
		try {
			$this->submit_undo( $entry, wp_create_nonce( 'steadhand_undo_entry' ) );
		} finally {
			$this->assert_nothing_changed( $before, $post, $entry );
		}
	}

	public function test_an_editor_who_may_edit_the_post_is_refused_for_lacking_manage_options(): void {
		$post   = $this->text_fixture();
		$entry  = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );
		$before = $this->text_of_post( $post );

		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$this->assertTrue( current_user_can( 'edit_post', $post ), 'The editor must be able to edit the post, so only manage_options refuses.' );

		$this->expectException( \WPDieException::class );
		try {
			$this->submit_undo( $entry );
		} finally {
			$this->assert_nothing_changed( $before, $post, $entry );
		}
	}

	public function test_a_subscriber_is_refused_and_changes_nothing(): void {
		$post   = $this->text_fixture();
		$entry  = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );
		$before = $this->text_of_post( $post );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( \WPDieException::class );
		try {
			$this->submit_undo( $entry );
		} finally {
			$this->assert_nothing_changed( $before, $post, $entry );
		}
	}

	public function test_a_logged_out_visitor_is_refused_and_changes_nothing(): void {
		$post   = $this->text_fixture();
		$entry  = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );
		$before = $this->text_of_post( $post );

		wp_set_current_user( 0 );

		$this->expectException( \WPDieException::class );
		try {
			$this->submit_undo( $entry );
		} finally {
			$this->assert_nothing_changed( $before, $post, $entry );
		}
	}

	public function test_a_dedicated_agent_is_refused_and_changes_nothing(): void {
		$post   = $this->text_fixture();
		$entry  = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );
		$before = $this->text_of_post( $post );

		wp_set_current_user( $this->agent() );

		$this->expectException( \WPDieException::class );
		try {
			$this->submit_undo( $entry );
		} finally {
			$this->assert_nothing_changed( $before, $post, $entry );
		}
	}

	public function test_an_administrator_who_also_holds_the_agent_role_is_refused_by_the_pipeline(): void {
		$post   = $this->text_fixture();
		$entry  = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );
		$before = $this->text_of_post( $post );

		$both = $this->admin_id();
		( new \WP_User( $both ) )->add_role( Roles::ROLE );
		wp_set_current_user( $both );
		$this->assertTrue( current_user_can( 'manage_options' ) );

		$url = $this->submit_undo( $entry );

		$this->assertSame( 'forbidden', $this->code_of( $url ) );
		$this->assert_nothing_changed( $before, $post, $entry );
		$this->assertSame( Entry::RESULT_BLOCKED, $this->latest_row( $both )['result'] );
	}


	public function test_run_admin_refuses_an_ability_that_is_not_an_admin_action(): void {
		wp_set_current_user( $this->admin_id() );

		$this->expectException( \LogicException::class );
		( new Pipeline( new DbLogger() ) )->run_admin( new GetSiteInfo(), array() );
	}

	public function test_run_and_check_refuse_the_admin_undo_for_an_administrator_and_write_nothing(): void {
		$post     = $this->text_fixture();
		$entry    = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );
		$before   = $this->text_of_post( $post );
		$pipeline = new Pipeline( new DbLogger() );
		$ability  = new UndoEntry( new DbLogger() );

		wp_set_current_user( $this->admin_id() );
		$result = $pipeline->run( $ability, array( 'id' => $entry ) );
		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_error', $result->get_error_code() );
		$this->assertSame( Entry::RESULT_BLOCKED, $this->latest_row( $this->admin_id() )['result'] );

		$checked = $pipeline->check( $ability, array( 'id' => $entry ) );
		$this->assertWPError( $checked );
		$this->assertSame( 'steadhand_error', $checked->get_error_code() );

		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_run_and_check_refuse_the_admin_undo_for_an_agent_and_write_nothing(): void {
		$post     = $this->text_fixture();
		$entry    = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );
		$before   = $this->text_of_post( $post );
		$pipeline = new Pipeline( new DbLogger() );
		$ability  = new UndoEntry( new DbLogger() );

		wp_set_current_user( $this->agent() );
		$this->assertWPError( $pipeline->run( $ability, array( 'id' => $entry ) ) );
		$this->assertWPError( $pipeline->check( $ability, array( 'id' => $entry ) ) );

		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_run_admin_refuses_an_agent_with_a_fixed_text_and_logs_it_as_blocked(): void {
		$post   = $this->text_fixture();
		$entry  = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );
		$before = $this->text_of_post( $post );

		wp_set_current_user( $this->agent() );
		$result = ( new Pipeline( new DbLogger() ) )->run_admin( new UndoEntry( new DbLogger() ), array( 'id' => $entry ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
		$this->assertSame( self::ADMIN_ONLY, $result->get_error_message() );
		$this->assertSame( Entry::RESULT_BLOCKED, $this->latest_row( $this->agent() )['result'] );
		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_no_row_no_write_when_the_admin_undo_cannot_be_logged(): void {
		$post   = $this->text_fixture();
		$entry  = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );
		$before = $this->text_of_post( $post );

		wp_set_current_user( $this->admin_id() );
		$this->make_inserts_fail();

		$result = ( new Pipeline( new DbLogger() ) )->run_admin( new UndoEntry( new DbLogger() ), array( 'id' => $entry ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_log_failed', $result->get_error_code() );
		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_the_guardrail_engine_is_not_evaluated_for_the_admin_undo_but_still_blocks_the_agents_undo(): void {
		$post   = $this->text_fixture();
		$first  = $this->agent_text_change( $post, array( 'title' => 'First agent title' ) );
		$second = $this->agent_text_change( $post, array( 'title' => 'Second agent title' ) );

		$evaluated = 0;
		$rule      = new FakeRule(
			'vendor/block-everything',
			static function () use ( &$evaluated ): Result {
				++$evaluated;

				return Result::block( 'Blocked by the test rule.' );
			}
		);

		$engine = new Engine( array( $rule ), array() );

		// The administrator undoes the newest change, through a handler whose pipeline has the rule. The rule is not run.
		wp_set_current_user( $this->admin_id() );
		$logger  = new DbLogger();
		$handler = new UndoHandler( new Pipeline( $logger, $engine ), $logger );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'entry'    => (string) $second,
			'_wpnonce' => wp_create_nonce( UndoHandler::NONCE_PREFIX . $second ),
		);
		$_REQUEST                  = $_POST; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test fixture: the request the handler reads.
		$url                       = $handler->process();

		$this->assertSame( 'done', $this->code_of( $url ) );
		$this->assertSame( 0, $evaluated, 'The guardrail engine must not be evaluated for an administrator\'s undo.' );

		// The agent's undo-change of the older change, through the same rule, is blocked.
		wp_set_current_user( $this->agent() );
		$before = $this->text_of_post( $post );
		$result = ( new Pipeline( new DbLogger(), $engine ) )->run( new UndoChange( new DbLogger() ), array( 'id' => $first ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_guardrail_blocked', $result->get_error_code() );
		$this->assertSame( 1, $evaluated );
		$this->assertSame( $before, $this->text_of_post( $post ) );
	}


	public function test_an_administrator_restores_post_text_exactly_and_records_the_undo(): void {
		$post   = $this->text_fixture();
		$before = $this->text_of_post( $post );
		$entry  = $this->agent_text_change(
			$post,
			array(
				'title'   => 'Agent title',
				'content' => self::paragraph( 'Agent' ),
				'excerpt' => 'Agent excerpt',
			)
		);
		$this->assertNotSame( $before, $this->text_of_post( $post ) );

		wp_set_current_user( $this->admin_id() );
		$url = $this->submit_undo( $entry );

		$this->assertSame( 'done', $this->code_of( $url ) );
		$this->assertSame( (string) $entry, $this->query_of( $url, 'entry' ) );
		$this->assertSame( $before, $this->text_of_post( $post ) );

		$undo = $this->latest_row( $this->admin_id() );
		$this->assertSame( self::ADMIN_UNDO, $undo['ability'] );
		$this->assertSame( Entry::RESULT_OK, $undo['result'] );
		$this->assertSame( '0', (string) $undo['undoable'] );
		$this->assertSame( 'post', $undo['object_type'] );
		$this->assertSame( $post, (int) $undo['object_id'] );
		$this->assertSame( (int) $undo['id'], (int) $this->stored( $entry )['undone_by'] );
	}

	public function test_an_administrator_undoes_the_change_of_any_agent(): void {
		$post   = $this->text_fixture();
		$before = $this->text_of_post( $post );

		// A second agent made the change: the administrator may undo it all the same.
		$other = $this->other_agent();
		wp_set_current_user( $other );
		$this->execute_ability(
			self::UPDATE,
			array(
				'id'    => $post,
				'title' => 'Other agent title',
			)
		);
		$entry = (int) $this->latest_row( $other )['id'];
		$this->assertSame( $other, (int) $this->stored( $entry )['user_id'] );

		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'done', $this->code_of( $this->submit_undo( $entry ) ) );
		$this->assertSame( $before, $this->text_of_post( $post ) );
	}

	public function test_an_administrator_restores_the_terms_of_a_terms_change(): void {
		$post = $this->admin_post( array( 'post_status' => 'draft' ) );
		$one  = $this->tag( 'One' );
		$two  = $this->tag( 'Two' );
		wp_set_object_terms( $post, array( $one ), 'post_tag' );

		$entry = $this->agent_terms( $post, array( $two ) );
		$this->assertSame( array( $two ), $this->tag_ids( $post ) );

		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'done', $this->code_of( $this->submit_undo( $entry ) ) );
		$this->assertSame( array( $one ), $this->tag_ids( $post ) );

		$undo = $this->latest_row( $this->admin_id() );
		$this->assertSame( self::ADMIN_UNDO, $undo['ability'] );
		$this->assertSame( (int) $undo['id'], (int) $this->stored( $entry )['undone_by'] );
	}

	public function test_an_administrator_restores_a_featured_image_to_the_one_it_had(): void {
		$post   = $this->admin_post( array( 'post_status' => 'draft' ) );
		$first  = $this->image();
		$second = $this->image();
		set_post_thumbnail( $post, $first );

		$entry = $this->agent_image( $post, $second );
		$this->assertSame( $second, (int) get_post_thumbnail_id( $post ) );

		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'done', $this->code_of( $this->submit_undo( $entry ) ) );
		$this->assertSame( $first, (int) get_post_thumbnail_id( $post ) );
	}

	public function test_an_administrator_removes_a_featured_image_that_the_change_added(): void {
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$image = $this->image();

		$entry = $this->agent_image( $post, $image );
		$this->assertSame( $image, (int) get_post_thumbnail_id( $post ) );

		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'done', $this->code_of( $this->submit_undo( $entry ) ) );
		$this->assertSame( 0, (int) get_post_thumbnail_id( $post ) );
	}


	public function test_an_entry_already_undone_is_refused(): void {
		$post  = $this->text_fixture();
		$entry = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );
		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'done', $this->code_of( $this->submit_undo( $entry ) ) );
		$after_first = $this->text_of_post( $post );
		$undone_by   = (int) $this->stored( $entry )['undone_by'];

		$this->assertSame( 'not_undoable', $this->code_of( $this->submit_undo( $entry ) ) );
		$this->assertSame( $after_first, $this->text_of_post( $post ) );
		$this->assertSame( $undone_by, (int) $this->stored( $entry )['undone_by'] );
	}

	public function test_an_entry_that_is_not_eligible_is_refused(): void {
		$post   = $this->text_fixture();
		$before = $this->text_of_post( $post );
		$agent  = $this->agent();

		$created = $this->insert_row(
			array(
				'user_id'     => $agent,
				'ability'     => 'steadhand/create-post',
				'object_type' => 'post',
				'object_id'   => $post,
				'result'      => Entry::RESULT_OK,
				'undoable'    => 0,
			)
		);
		$blocked = $this->insert_row(
			array(
				'user_id'           => $agent,
				'ability'           => self::UPDATE,
				'object_type'       => 'post',
				'object_id'         => $post,
				'result'            => Entry::RESULT_BLOCKED,
				'undoable'          => 1,
				'snapshot_id'       => 5,
				'after_snapshot_id' => 6,
			)
		);

		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'not_undoable', $this->code_of( $this->submit_undo( $created ) ) );
		$this->assertSame( 'not_undoable', $this->code_of( $this->submit_undo( $blocked ) ) );
		$this->assertSame( $before, $this->text_of_post( $post ) );
	}

	public function test_an_unknown_entry_is_refused_with_the_generic_code(): void {
		wp_set_current_user( $this->admin_id() );

		$this->assertSame( 'forbidden', $this->code_of( $this->submit_undo( self::MISSING_ID ) ) );
	}

	public function test_post_text_edited_with_the_same_text_after_the_change_is_refused(): void {
		$post  = $this->text_fixture();
		$entry = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );
		$row   = $this->row( $post );

		// A newer revision with the same text: the change is no longer the newest one.
		self::factory()->post->create(
			array(
				'post_type'    => 'revision',
				'post_parent'  => $post,
				'post_status'  => 'inherit',
				'post_title'   => $row['post_title'],
				'post_content' => $row['post_content'],
				'post_excerpt' => $row['post_excerpt'],
			)
		);
		$before = $this->text_of_post( $post );

		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'changed', $this->code_of( $this->submit_undo( $entry ) ) );
		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_post_text_changed_directly_since_the_change_is_refused(): void {
		$post  = $this->text_fixture();
		$entry = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );
		$this->direct_content( $post, self::paragraph( 'Someone else' ) );
		$before = $this->text_of_post( $post );

		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'changed', $this->code_of( $this->submit_undo( $entry ) ) );
		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_a_text_change_whose_before_revision_was_pruned_is_refused(): void {
		$post            = $this->text_fixture();
		$entry           = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );
		$before_revision = (int) $this->stored( $entry )['snapshot_id'];
		wp_delete_post( $before_revision, true );
		$before = $this->text_of_post( $post );

		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'not_undoable', $this->code_of( $this->submit_undo( $entry ) ) );
		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_terms_changed_since_the_change_are_refused(): void {
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$one   = $this->tag( 'One' );
		$three = $this->tag( 'Three' );
		$entry = $this->agent_terms( $post, array( $one ) );
		wp_set_object_terms( $post, array( $three ), 'post_tag' );

		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'changed', $this->code_of( $this->submit_undo( $entry ) ) );
		$this->assertSame( array( $three ), $this->tag_ids( $post ) );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
	}

	public function test_the_featured_image_changed_since_the_change_is_refused(): void {
		$post   = $this->admin_post( array( 'post_status' => 'draft' ) );
		$first  = $this->image();
		$second = $this->image();
		$third  = $this->image();
		set_post_thumbnail( $post, $first );
		$entry = $this->agent_image( $post, $second );
		set_post_thumbnail( $post, $third );

		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'changed', $this->code_of( $this->submit_undo( $entry ) ) );
		$this->assertSame( $third, (int) get_post_thumbnail_id( $post ) );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
	}

	public function test_a_terms_state_that_names_another_post_is_not_undoable(): void {
		global $wpdb;

		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$other = $this->admin_post( array( 'post_status' => 'draft' ) );
		$one   = $this->tag( 'One' );
		$entry = $this->agent_terms( $post, array( $one ) );
		$row   = $this->stored( $entry );
		$this->assertNotSame( 0, (int) $row['snapshot_id'] );

		$wpdb->update( Schema::snapshots_table(), array( 'object_id' => $other ), array( 'id' => (int) $row['snapshot_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture: a saved state that names another post.
		$before = $this->text_of_post( $post );

		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'not_undoable', $this->code_of( $this->submit_undo( $entry ) ) );
		$this->assertSame( $before, $this->text_of_post( $post ) );
	}


	public function test_an_administrator_denied_edit_post_for_the_post_is_refused(): void {
		$post   = $this->text_fixture();
		$entry  = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );
		$before = $this->text_of_post( $post );

		$this->filter(
			'map_meta_cap',
			static fn( array $caps, string $cap, int $user_id, array $args ): array => 'edit_post' === $cap && (int) ( $args[0] ?? 0 ) === $post ? array( 'do_not_allow' ) : $caps,
			10,
			4
		);

		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'forbidden', $this->code_of( $this->submit_undo( $entry ) ) );
		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_an_administrator_denied_the_taxonomy_assign_capability_is_refused(): void {
		$post   = $this->admin_post( array( 'post_status' => 'draft' ) );
		$one    = $this->tag( 'One' );
		$entry  = $this->agent_terms( $post, array( $one ) );
		$before = $this->text_of_post( $post );
		$cap    = get_taxonomy( 'post_tag' )->cap->assign_terms;

		// The administrator is denied the taxonomy's assign capability, and keeps edit_post for the post.
		$this->filter(
			'map_meta_cap',
			static fn( array $caps, string $meta ): array => $cap === $meta ? array( 'do_not_allow' ) : $caps,
			10,
			4
		);

		wp_set_current_user( $this->admin_id() );
		$this->assertFalse( current_user_can( $cap ), 'The administrator must be denied the assign capability.' );
		$this->assertTrue( current_user_can( 'edit_post', $post ), 'The administrator must keep edit_post.' );
		$this->assertSame( 'forbidden', $this->code_of( $this->submit_undo( $entry ) ) );
		$this->assertSame( $before, $this->text_of_post( $post ) );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
	}

	public function test_a_post_in_the_trash_is_refused(): void {
		$post  = $this->text_fixture();
		$entry = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );
		wp_trash_post( $post );
		$before = $this->text_of_post( $post );

		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'forbidden', $this->code_of( $this->submit_undo( $entry ) ) );
		$this->assertSame( $before, $this->text_of_post( $post ) );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
	}

	/**
	 * Records each edit_post decision and each clean_post_cache, in order, in $events. The first clean_post_cache after the log row
	 * is reserved is the writer's re-read of the post, which runs right before the writer's $still_allowed callback. From that
	 * point edit_post is denied. So execute()'s own check must pass before the re-read, and only the writer's check after it can
	 * refuse.
	 *
	 * @param string[] $events Receives 'allowed', 'denied' and 'clean', in order.
	 */
	private function deny_edit_from_the_writers_reread( array &$events ): void {
		$armed = false;

		$this->filter(
			'clean_post_cache',
			static function () use ( &$armed, &$events ): void {
				$events[] = 'clean';
				$armed    = true;
			}
		);
		$this->filter(
			'map_meta_cap',
			static function ( array $caps, string $cap ) use ( &$armed, &$events ): array {
				if ( 'edit_post' !== $cap ) {
					return $caps;
				}

				$events[] = $armed ? 'denied' : 'allowed';

				return $armed ? array( 'do_not_allow' ) : $caps;
			},
			10,
			4
		);
	}

	/**
	 * Asserts that the refusal came from the writer's own check: execute()'s check passed before the writer's re-read, and the
	 * writer's check, after the re-read, was the one that denied.
	 *
	 * @param string[] $events The events the deny helper recorded.
	 */
	private function assert_the_writer_check_refused( array $events ): void {
		$clean = array_search( 'clean', $events, true );
		$this->assertIsInt( $clean, 'The writer must re-read the post before its check.' );
		$this->assertContains( 'allowed', array_slice( $events, 0, $clean ), "execute()'s own check must pass before the writer re-reads the post." );
		$this->assertSame(
			array( 'denied' ),
			array_values( array_intersect( array_slice( $events, $clean + 1 ), array( 'allowed', 'denied' ) ) ),
			"The writer's own check must be the one that refuses."
		);
	}

	public function test_a_capability_removed_after_execute_checked_stops_the_terms_write_in_the_writer(): void {
		$post   = $this->admin_post( array( 'post_status' => 'draft' ) );
		$one    = $this->tag( 'One' );
		$entry  = $this->agent_terms( $post, array( $one ) );
		$before = $this->text_of_post( $post );

		$events = array();
		$this->deny_edit_from_the_writers_reread( $events );

		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'forbidden', $this->code_of( $this->submit_undo( $entry ) ) );
		$this->assert_the_writer_check_refused( $events );
		$this->assertSame( array( $one ), $this->tag_ids( $post ), 'Nothing may be written when the check fails.' );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
		$this->assertSame( $before, $this->text_of_post( $post ) );
		$this->assertSame( Entry::RESULT_ERROR, $this->latest_row( $this->admin_id() )['result'] );
	}

	public function test_a_capability_removed_after_execute_checked_stops_the_featured_write_in_the_writer(): void {
		$post   = $this->admin_post( array( 'post_status' => 'draft' ) );
		$media  = $this->image();
		$entry  = $this->agent_image( $post, $media );
		$before = $this->text_of_post( $post );

		$events = array();
		$this->deny_edit_from_the_writers_reread( $events );

		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'forbidden', $this->code_of( $this->submit_undo( $entry ) ) );
		$this->assert_the_writer_check_refused( $events );
		$this->assertSame( $media, (int) get_post_thumbnail_id( $post ), 'Nothing may be written when the check fails.' );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
		$this->assertSame( $before, $this->text_of_post( $post ) );
		$this->assertSame( Entry::RESULT_ERROR, $this->latest_row( $this->admin_id() )['result'] );
	}

	/**
	 * Two agent changes of the featured image: the first set $first, the second set $second. Returns the post, the entry of the
	 * second change, and the two images.
	 *
	 * @return array{0: int, 1: int, 2: int, 3: int}
	 */
	private function two_featured_changes(): array {
		$post   = $this->admin_post( array( 'post_status' => 'draft' ) );
		$first  = $this->image();
		$second = $this->image();
		$this->agent_image( $post, $first );
		$entry = $this->agent_image( $post, $second );

		return array( $post, $entry, $first, $second );
	}

	public function test_a_featured_undo_is_refused_when_the_image_to_restore_is_no_longer_an_image(): void {
		[ $post, $entry, $first, $second ] = $this->two_featured_changes();

		global $wpdb;
		$wpdb->update( $wpdb->posts, array( 'post_mime_type' => 'application/pdf' ), array( 'ID' => $first ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The attachment is no longer an image: the state this test needs.
		clean_post_cache( $first );

		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'not_undoable', $this->code_of( $this->submit_undo( $entry ) ) );
		$this->assertSame( $second, (int) get_post_thumbnail_id( $post ), 'The current image must still be set, not removed.' );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
	}

	public function test_a_featured_undo_is_refused_for_a_post_type_without_thumbnail_support(): void {
		[ $post, $entry, , $second ] = $this->two_featured_changes();

		remove_post_type_support( 'post', 'thumbnail' );
		try {
			wp_set_current_user( $this->admin_id() );
			$this->assertSame( 'not_undoable', $this->code_of( $this->submit_undo( $entry ) ) );
		} finally {
			add_post_type_support( 'post', 'thumbnail' );
		}

		$this->assertSame( $second, (int) get_post_thumbnail_id( $post ), 'The current image must still be set.' );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
	}

	/**
	 * Sets a post's status directly in its row, as another request would, with no revision.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $status  New status.
	 */
	private function direct_status( int $post_id, string $status ): void {
		global $wpdb;

		$wpdb->update( $wpdb->posts, array( 'post_status' => $status ), array( 'ID' => $post_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Another request's write, with no revision.
		clean_post_cache( $post_id );
	}

	public function test_a_post_in_a_status_the_agent_path_does_not_edit_is_refused(): void {
		$post  = $this->text_fixture();
		$entry = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );
		$this->direct_status( $post, 'steadhand-held' );
		$before = $this->text_of_post( $post );

		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'forbidden', $this->code_of( $this->submit_undo( $entry ) ) );
		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_an_entry_with_no_user_is_refused_before_any_write(): void {
		$post  = $this->text_fixture();
		$entry = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );

		global $wpdb;
		$wpdb->update( Schema::table(), array( 'user_id' => 0 ), array( 'id' => $entry ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- An entry with no owner: the state this test needs.
		$before = $this->text_of_post( $post );

		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'not_undoable', $this->code_of( $this->submit_undo( $entry ) ) );
		$this->assert_nothing_changed( $before, $post, $entry );
	}

	/**
	 * Another user, who can be the holder of an edit lock.
	 */
	private function other_user(): int {
		return self::factory()->user->create( array( 'role' => 'editor' ) );
	}

	/**
	 * Gives the post's edit lock to a user, as a recent lock (core's _edit_lock meta: time, then user ID).
	 *
	 * @param int $post_id Post ID.
	 * @param int $user_id The lock holder.
	 */
	private function lock_post( int $post_id, int $user_id ): void {
		update_post_meta( $post_id, '_edit_lock', time() . ':' . $user_id );
	}

	public function test_an_undo_of_post_text_is_refused_while_another_user_holds_the_edit_lock(): void {
		$post   = $this->text_fixture();
		$entry  = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );
		$before = $this->text_of_post( $post );
		$this->lock_post( $post, $this->other_user() );

		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'locked', $this->code_of( $this->submit_undo( $entry ) ) );
		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_an_undo_of_terms_is_refused_while_another_user_holds_the_edit_lock(): void {
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$one   = $this->tag( 'One' );
		$entry = $this->agent_terms( $post, array( $one ) );
		$this->lock_post( $post, $this->other_user() );

		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'locked', $this->code_of( $this->submit_undo( $entry ) ) );
		$this->assertSame( array( $one ), $this->tag_ids( $post ), 'Nothing may be written while the post is locked.' );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
	}

	public function test_an_undo_of_a_featured_image_is_refused_while_another_user_holds_the_edit_lock(): void {
		$post  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$media = $this->image();
		$entry = $this->agent_image( $post, $media );
		$this->lock_post( $post, $this->other_user() );

		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'locked', $this->code_of( $this->submit_undo( $entry ) ) );
		$this->assertSame( $media, (int) get_post_thumbnail_id( $post ), 'Nothing may be written while the post is locked.' );
		$this->assertSame( 0, (int) $this->stored( $entry )['undone_by'] );
	}

	public function test_an_undo_is_allowed_when_the_administrator_holds_the_edit_lock_herself(): void {
		$post  = $this->text_fixture();
		$entry = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );
		$this->lock_post( $post, $this->admin_id() );

		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'done', $this->code_of( $this->submit_undo( $entry ) ) );
		$this->assertSame( 'Before title', get_post( $post )->post_title );
	}

	public function test_the_undo_is_refused_when_the_change_log_table_is_not_ready(): void {
		$post   = $this->text_fixture();
		$entry  = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );
		$before = $this->text_of_post( $post );

		$this->make_table_look_missing();
		wp_set_current_user( $this->admin_id() );
		$result = ( new Pipeline( new DbLogger() ) )->run_admin( new UndoEntry( new DbLogger() ), array( 'id' => $entry ) );

		$this->assertWPError( $result, 'With no change-log table, nothing may be restored.' );
		$this->assert_nothing_changed( $before, $post, $entry );
	}

	public function test_the_agents_undo_of_an_entry_the_administrator_undid_is_refused(): void {
		$post  = $this->text_fixture();
		$entry = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );
		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'done', $this->code_of( $this->submit_undo( $entry ) ) );
		$before = $this->text_of_post( $post );

		wp_set_current_user( $this->agent() );
		$result = $this->execute_ability( self::AGENT_UNDO, array( 'id' => $entry ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
		$this->assertSame( self::ALREADY, $result->get_error_message() );
		$this->assertSame( $before, $this->text_of_post( $post ) );
	}

	public function test_the_administrators_undo_of_an_entry_the_agent_undid_is_refused(): void {
		$post  = $this->text_fixture();
		$entry = $this->agent_text_change( $post, array( 'title' => 'Agent title' ) );
		wp_set_current_user( $this->agent() );
		$this->assertIsArray( $this->execute_ability( self::AGENT_UNDO, array( 'id' => $entry ) ) );
		$before = $this->text_of_post( $post );

		wp_set_current_user( $this->admin_id() );
		$this->assertSame( 'not_undoable', $this->code_of( $this->submit_undo( $entry ) ) );
		$this->assertSame( $before, $this->text_of_post( $post ) );
	}

	public function test_the_admin_undo_is_not_a_registered_ability_and_not_an_mcp_tool(): void {
		$names = ( new Plugin() )->registry()->names();
		$this->assertNotContains( self::ADMIN_UNDO, $names );
		$this->setExpectedIncorrectUsage( 'WP_Abilities_Registry::get_registered' );
		$this->assertNull( wp_get_ability( self::ADMIN_UNDO ) );

		$this->act_as_agent();
		$tools = wp_list_pluck( $this->body( $this->mcp( 'tools/list', array(), $this->mcp_session() ) )['result']['tools'], 'name' );
		$this->assertNotContains( 'steadhand-admin-undo', $tools );
	}
}
