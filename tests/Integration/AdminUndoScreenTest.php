<?php
/**
 * The Undo button and the notices of the change-log screen's single view.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Admin\LogScreen;
use Steadhand\Admin\UndoEntry;
use Steadhand\Admin\UndoHandler;
use Steadhand\Log\DbLogger;
use Steadhand\Log\Entry;
use Steadhand\Plugin;
use Steadhand\Roles;

/**
 * Renders the screen as a given user and checks what it shows. The button appears only for an entry the administrator may undo,
 * the nonce is bound to the entry, every notice is a fixed text, and nothing from the query string is printed.
 */
final class AdminUndoScreenTest extends PostWriteTestCase {

	private const UPDATE = 'steadhand/update-post';

	/**
	 * The filter that installs the wp_die handler, kept so tearDown can remove it.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $die_filter = null;

	/**
	 * Filters this file adds, with their priorities, removed in tearDown by closure.
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
		$_GET = array();
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

	/**
	 * Renders the screen as the given user, with the request in $_GET.
	 *
	 * @param int                  $user_id The viewer.
	 * @param array<string,string> $query   The request values.
	 * @return string The HTML.
	 */
	private function render_as( int $user_id, array $query ): string {
		wp_set_current_user( $user_id );
		$_GET = $query;

		$screen = new LogScreen( new DbLogger(), ( new Plugin() )->registry() );
		ob_start();
		try {
			$screen->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	/**
	 * An entry of an agent, about a post, that an undo can reverse (every column the undo needs is set). Returns its ID.
	 *
	 * @param int                  $post   Post ID.
	 * @param array<string, mixed> $fields Columns to override.
	 */
	private function undoable_row( int $post, array $fields = array() ): int {
		return $this->insert_row(
			array_merge(
				array(
					'user_id'           => $this->other_agent(),
					'agent_label'       => 'Editor bot',
					'ability'           => self::UPDATE,
					'object_type'       => 'post',
					'object_id'         => $post,
					'result'            => Entry::RESULT_OK,
					'message'           => 'Changed a post',
					'undoable'          => 1,
					'snapshot_id'       => 11,
					'after_snapshot_id' => 12,
				),
				$fields
			)
		);
	}

	/**
	 * The request that opens one entry's single view.
	 *
	 * @param int $entry Entry ID.
	 * @return array<string, string>
	 */
	private function entry_query( int $entry ): array {
		return array(
			'page'  => LogScreen::SLUG,
			'entry' => (string) $entry,
		);
	}

	/**
	 * The nonce value in the form.
	 *
	 * @param string $html The screen.
	 */
	private function nonce_of( string $html ): string {
		$this->assertSame( 1, preg_match( '/name="_wpnonce" value="([^"]+)"/', $html, $matches ), 'The form must carry a nonce.' );

		return $matches[1];
	}

	public function test_the_undo_button_shows_for_an_entry_the_administrator_may_undo(): void {
		$post  = $this->admin_post();
		$entry = $this->undoable_row( $post );

		$html = $this->render_as( $this->admin_id(), $this->entry_query( $entry ) );

		$this->assertStringContainsString( 'action="' . admin_url( 'admin-post.php' ) . '"', $html );
		$this->assertStringContainsString( 'name="action" value="' . UndoHandler::ACTION . '"', $html );
		$this->assertStringContainsString( 'name="entry" value="' . $entry . '"', $html );
		$this->assertStringContainsString( 'Undo this change', $html );
		$this->assertStringContainsString( 'Puts the post&#039;s title, content and excerpt back', $html );
	}

	public function test_the_nonce_is_bound_to_the_entry_it_was_made_for(): void {
		$post  = $this->admin_post();
		$entry = $this->undoable_row( $post );

		$html  = $this->render_as( $this->admin_id(), $this->entry_query( $entry ) );
		$nonce = $this->nonce_of( $html );

		wp_set_current_user( $this->admin_id() );
		$this->assertNotFalse( wp_verify_nonce( $nonce, UndoHandler::NONCE_PREFIX . $entry ) );
		$this->assertFalse( wp_verify_nonce( $nonce, UndoHandler::NONCE_PREFIX . ( $entry + 1 ) ), 'A nonce for one entry must not pass for another.' );
	}

	public function test_the_button_is_not_shown_for_an_entry_already_undone(): void {
		$post  = $this->admin_post();
		$entry = $this->undoable_row(
			$post,
			array(
				'undone_by' => 999,
			)
		);

		$html = $this->render_as( $this->admin_id(), $this->entry_query( $entry ) );

		$this->assertStringNotContainsString( 'admin-post.php', $html );
		$this->assertStringNotContainsString( 'Undo this change', $html );
	}

	public function test_the_button_is_not_shown_for_a_blocked_row_or_an_entry_about_no_post(): void {
		$post    = $this->admin_post();
		$blocked = $this->undoable_row( $post, array( 'result' => Entry::RESULT_BLOCKED ) );
		$create  = $this->undoable_row(
			$post,
			array(
				'ability'  => 'steadhand/create-post',
				'undoable' => 0,
			)
		);
		$no_post = $this->undoable_row(
			0,
			array(
				'object_type' => '',
				'object_id'   => 0,
			)
		);

		foreach ( array( $blocked, $create, $no_post ) as $entry ) {
			$html = $this->render_as( $this->admin_id(), $this->entry_query( $entry ) );

			$this->assertStringNotContainsString( 'admin-post.php', $html, "No undo for entry $entry." );
		}
	}

	public function test_the_button_is_not_shown_to_a_viewer_who_may_not_edit_the_post(): void {
		$post  = $this->admin_post();
		$entry = $this->undoable_row( $post );

		$this->filter(
			'map_meta_cap',
			static function ( array $caps, string $cap, int $user_id, array $args ) use ( $post ): array {
				unset( $user_id );

				return 'edit_post' === $cap && (int) ( $args[0] ?? 0 ) === $post ? array( 'do_not_allow' ) : $caps;
			},
			10,
			4
		);

		$html = $this->render_as( $this->admin_id(), $this->entry_query( $entry ) );

		$this->assertStringContainsString( 'Steadhand change log', $html, 'The administrator still sees the screen.' );
		$this->assertStringNotContainsString( 'admin-post.php', $html );
		$this->assertStringNotContainsString( 'Undo this change', $html );
	}

	/**
	 * Each outcome code prints its fixed notice, in the class the outcome calls for.
	 */
	public function test_each_undo_code_prints_its_fixed_notice(): void {
		$post     = $this->admin_post();
		$entry    = $this->undoable_row(
			$post,
			array(
				'undone_by' => 999,
			)
		);
		$expected = array(
			'done'         => array( 'notice-success', 'The change was undone.' ),
			'changed'      => array( 'notice-error', 'changed after it. Nothing was changed.' ),
			'not_undoable' => array( 'notice-error', 'Steadhand cannot restore its saved state' ),
			'forbidden'    => array( 'notice-error', 'you may not edit its post' ),
			'partial'      => array( 'notice-warning', 'the post may have been changed' ),
			'log'          => array( 'notice-warning', 'could not record the undo' ),
			'failed'       => array( 'notice-warning', 'The undo did not complete.' ),
			'locked'       => array( 'notice-error', 'This post is open in the editor right now. Close the editor, wait a few minutes, and try again.' ),
		);

		foreach ( $expected as $code => $want ) {
			$html = $this->render_as(
				$this->admin_id(),
				array(
					'page'  => LogScreen::SLUG,
					'entry' => (string) $entry,
					'undo'  => $code,
				)
			);

			$this->assertStringContainsString( 'class="notice ' . $want[0] . '"', $html, "Code $code must print its notice class." );
			$this->assertStringContainsString( $want[1], $html, "Code $code must print its notice." );
		}
	}

	public function test_an_unknown_undo_code_prints_no_notice(): void {
		$post  = $this->admin_post();
		$entry = $this->undoable_row( $post );

		$html = $this->render_as(
			$this->admin_id(),
			array(
				'page'  => LogScreen::SLUG,
				'entry' => (string) $entry,
				'undo'  => 'not-a-code',
			)
		);

		$this->assertStringNotContainsString( 'class="notice', $html );
		$this->assertStringNotContainsString( 'not-a-code', $html );
	}

	public function test_a_script_in_the_undo_value_never_reaches_the_output(): void {
		$post  = $this->admin_post();
		$entry = $this->undoable_row( $post );

		$html = $this->render_as(
			$this->admin_id(),
			array(
				'page'  => LogScreen::SLUG,
				'entry' => (string) $entry,
				'undo'  => '<script>alert(1)</script>',
			)
		);

		$this->assertStringNotContainsString( '<script>alert(1)', $html );
		$this->assertStringNotContainsString( 'alert(1)', $html );
	}

	public function test_an_administrator_who_also_holds_the_agent_role_sees_the_log_but_no_undo_button(): void {
		$post  = $this->admin_post();
		$entry = $this->undoable_row( $post );

		$user = get_userdata( $this->admin_id() );
		$user->add_role( Roles::ROLE );

		$html = $this->render_as( $this->admin_id(), $this->entry_query( $entry ) );

		$this->assertStringContainsString( 'Steadhand change log', $html, 'The administrator still reads the log.' );
		$this->assertStringNotContainsString( 'Undo this change', $html );
		$this->assertStringNotContainsString( 'admin-post.php', $html );
	}

	public function test_an_admin_undo_entry_shows_that_it_has_no_diff_of_its_own(): void {
		$post  = $this->admin_post();
		$entry = $this->undoable_row(
			$post,
			array(
				'ability'           => UndoEntry::NAME,
				'undoable'          => 0,
				'snapshot_id'       => 0,
				'after_snapshot_id' => 0,
			)
		);

		$html = $this->render_as( $this->admin_id(), $this->entry_query( $entry ) );

		$this->assertStringContainsString( 'An undo has no diff of its own.', $html );
		$this->assertStringNotContainsString( 'Undo this change', $html, 'An undo cannot itself be undone.' );
	}

	public function test_the_tool_filter_offers_the_admin_undo(): void {
		$html = $this->render_as( $this->admin_id(), array( 'page' => LogScreen::SLUG ) );

		$this->assertStringContainsString( '<option value="steadhand/admin-undo">steadhand/admin-undo</option>', $html );
	}
}
