<?php
/**
 * The admin change-log screen: who may see it, what it shows, that every stored value is escaped, and that it writes nothing.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Admin\Admin;
use Steadhand\Admin\LogScreen;
use Steadhand\Log\DbLogger;
use Steadhand\Log\Entry;
use Steadhand\Log\Schema;
use Steadhand\Log\SnapshotStore;
use Steadhand\Plugin;

/**
 * Renders the screen as a given user and returns the HTML and the wp_die message, if any. Tests that need the
 * screen's own output read it from there.
 */
final class LogScreenTest extends PostWriteTestCase {

	/** Text that a refused viewer must never see. */
	private const SECRET = 'SECRET-LOG-LINE';

	/**
	 * The wp_die handler that throws, so a refusal can be caught.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $die_handler = null;

	/**
	 * The filter that installs $die_handler, kept so tearDown can remove it.
	 *
	 * @var \Closure|null
	 */
	private ?\Closure $die_filter = null;

	protected function setUp(): void {
		parent::setUp();
		$this->die_handler = static function ( $message ): void {
			// A test double: the message is compared by the test, and never printed.
			throw new \WPDieException( (string) $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- See the comment above.
		};
		$this->die_filter  = fn() => $this->die_handler;
		add_filter( 'wp_die_handler', $this->die_filter, PHP_INT_MAX );
	}

	protected function tearDown(): void {
		$_GET = array();
		if ( null !== $this->die_filter ) {
			remove_filter( 'wp_die_handler', $this->die_filter, PHP_INT_MAX );
		}
		remove_all_actions( 'steadhand_admin_log_toolbar' );
		remove_all_actions( 'steadhand_admin_log_entry' );
		wp_dequeue_style( 'revisions' );
		parent::tearDown();
	}

	/**
	 * Renders the screen as the given user, with the request in $_GET.
	 *
	 * @param int                  $user_id The viewer.
	 * @param array<string,string> $query   The request values.
	 * @return array{html: string, died: string|null}
	 */
	private function render_as( int $user_id, array $query = array() ): array {
		wp_set_current_user( $user_id );
		$_GET = $query;

		$screen = new LogScreen( new DbLogger(), ( new Plugin() )->registry() );
		$died   = null;

		ob_start();
		try {
			$screen->render();
		} catch ( \WPDieException $e ) {
			$died = $e->getMessage();
		} finally {
			$html = (string) ob_get_clean();
		}

		return array(
			'html' => $html,
			'died' => $died,
		);
	}

	/**
	 * A change-log row with the given text fields. Returns its ID.
	 *
	 * @param array<string, mixed> $fields Columns to set.
	 */
	private function log_row( array $fields = array() ): int {
		return $this->insert_row(
			array_merge(
				array(
					'user_id'     => $this->admin_id(),
					'agent_label' => 'Editor bot',
					'ability'     => 'steadhand/get-post',
					'object_type' => 'post',
					'object_id'   => 0,
					'result'      => Entry::RESULT_OK,
					'message'     => 'Read a post',
				),
				$fields
			)
		);
	}

	/**
	 * Creates a post and two revisions of it, and an update-post row between them. Made as the administrator, so the text is
	 * stored as given. Returns the row ID.
	 *
	 * @param string $before_title   Title before the change.
	 * @param string $before_content Content before the change.
	 * @param string $after_title    Title after the change.
	 * @param string $after_content  Content after the change.
	 */
	private function text_change( string $before_title, string $before_content, string $after_title, string $after_content ): int {
		wp_set_current_user( $this->admin_id() );
		$post   = $this->admin_post(
			array(
				'post_title'   => $after_title,
				'post_content' => $after_content,
			)
		);
		$before = (int) self::factory()->post->create(
			array(
				'post_type'    => 'revision',
				'post_parent'  => $post,
				'post_status'  => 'inherit',
				'post_title'   => $before_title,
				'post_content' => $before_content,
			)
		);
		$after  = (int) self::factory()->post->create(
			array(
				'post_type'    => 'revision',
				'post_parent'  => $post,
				'post_status'  => 'inherit',
				'post_title'   => $after_title,
				'post_content' => $after_content,
			)
		);

		return $this->log_row(
			array(
				'ability'           => 'steadhand/update-post',
				'object_id'         => $post,
				'snapshot_id'       => $before,
				'after_snapshot_id' => $after,
				'undoable'          => 1,
			)
		);
	}

	/**
	 * Creates a post tag with the given name, and returns its ID.
	 *
	 * @param string $name Term name.
	 */
	private function tag( string $name ): int {
		return (int) self::factory()->term->create(
			array(
				'taxonomy' => 'post_tag',
				'name'     => $name,
			)
		);
	}

	/**
	 * Sets a term's name in the terms table, without core's sanitizing. A test fixture only.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $name    The name to store.
	 */
	private function set_term_name( int $term_id, string $name ): void {
		global $wpdb;

		$wpdb->update( $wpdb->terms, array( 'name' => $name ), array( 'term_id' => $term_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture: the name is stored as an outside path would store it.
	}

	/**
	 * Counts every row of the change log.
	 */
	private function total_rows(): int {
		global $wpdb;

		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Test reads the table to check that nothing was written.
	}

	public function test_a_logged_out_visitor_is_refused_and_sees_no_log_data(): void {
		$this->log_row( array( 'message' => self::SECRET ) );

		$result = $this->render_as( 0 );

		$this->assertNotNull( $result['died'] );
		$this->assertStringContainsString( 'You are not allowed to view this page.', $result['died'] );
		$this->assertStringNotContainsString( self::SECRET, $result['html'] );
		$this->assertSame( '', $result['html'] );
	}

	public function test_a_subscriber_editor_and_agent_are_refused_and_see_no_log_data(): void {
		$this->log_row( array( 'message' => self::SECRET ) );
		$viewers = array(
			self::factory()->user->create( array( 'role' => 'subscriber' ) ),
			self::factory()->user->create( array( 'role' => 'editor' ) ),
			$this->make_agent(),
		);

		foreach ( $viewers as $viewer ) {
			$result = $this->render_as( $viewer );

			$this->assertNotNull( $result['died'], "User $viewer must be refused." );
			$this->assertStringNotContainsString( self::SECRET, $result['html'] );
			$this->assertStringNotContainsString( self::SECRET, (string) $result['died'] );
		}
	}

	public function test_an_agent_is_refused_even_when_it_holds_manage_options(): void {
		$this->log_row( array( 'message' => self::SECRET ) );
		$agent = $this->make_agent( array( 'manage_options' => true ) );

		$result = $this->render_as( $agent );

		$this->assertNotNull( $result['died'] );
		$this->assertStringNotContainsString( self::SECRET, $result['html'] );
	}

	public function test_an_administrator_sees_the_list(): void {
		$this->log_row( array( 'message' => 'Read a post for the admin' ) );

		$result = $this->render_as( $this->admin_id() );

		$this->assertNull( $result['died'] );
		$this->assertStringContainsString( 'Steadhand change log', $result['html'] );
		$this->assertStringContainsString( 'Read a post for the admin', $result['html'] );
		$this->assertStringContainsString( 'wp-list-table', $result['html'] );
	}

	public function test_the_menu_needs_manage_options_and_the_stylesheet_loads_on_its_page_only(): void {
		$admin = new Admin( new LogScreen( new DbLogger(), ( new Plugin() )->registry() ) );
		$admin->register();
		$this->assertNotFalse( has_action( 'admin_menu', array( $admin, 'add_menu' ) ) );

		$admin->add_menu();
		$found = null;
		foreach ( (array) $GLOBALS['menu'] as $item ) {
			if ( isset( $item[2] ) && LogScreen::SLUG === $item[2] ) {
				$found = $item;
			}
		}
		$this->assertIsArray( $found, 'The menu page must be registered.' );
		$this->assertSame( 'manage_options', $found[1] );

		$admin->enqueue_styles( 'index.php' );
		$this->assertFalse( wp_style_is( 'revisions', 'enqueued' ) );

		$admin->enqueue_styles( 'toplevel_page_steadhand-log' );
		$this->assertTrue( wp_style_is( 'revisions', 'enqueued' ) );

		remove_action( 'admin_menu', array( $admin, 'add_menu' ) );
		remove_action( 'admin_enqueue_scripts', array( $admin, 'enqueue_styles' ) );
	}

	public function test_every_stored_value_is_escaped_in_the_list_and_the_entry(): void {
		$payload = '<script>alert(1)</script>';
		$img     = '"><img src=x onerror=alert(1)>';

		$id = $this->log_row(
			array(
				'agent_label'     => $payload,
				'message'         => $img,
				'guardrail_notes' => $payload,
				'input'           => (string) wp_json_encode(
					array(
						'note'  => $payload,
						'other' => $img,
					)
				),
			)
		);

		$list = $this->render_as( $this->admin_id() );
		$this->assertStringNotContainsString( '<script>alert(1)', $list['html'] );
		$this->assertStringNotContainsString( '<img src=x', $list['html'] );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)', $list['html'] );

		$_GET  = array();
		$entry = $this->render_as( $this->admin_id(), array( 'entry' => (string) $id ) );
		$this->assertStringNotContainsString( '<script>alert(1)', $entry['html'] );
		$this->assertStringNotContainsString( '<img src=x', $entry['html'] );
		$this->assertStringContainsString( '&lt;img src=x', $entry['html'] );
	}

	public function test_post_text_in_the_diff_is_escaped(): void {
		$payload = '<script>alert(1)</script>';
		$img     = '"><img src=x onerror=alert(1)>';
		$id      = $this->text_change( 'Before', $payload, $img, 'After' );

		$this->assertStringContainsString( $payload, get_post( (int) $this->entry_row( $id )['snapshot_id'] )->post_content );

		$entry = $this->render_as( $this->admin_id(), array( 'entry' => (string) $id ) );

		$this->assertNull( $entry['died'] );
		$this->assertStringNotContainsString( '<script>alert(1)', $entry['html'] );
		$this->assertStringNotContainsString( '<img src=x', $entry['html'] );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)', $entry['html'] );
	}

	public function test_a_term_name_and_an_attachment_title_in_the_diff_are_escaped(): void {
		$payload = '<script>alert(1)</script>';
		wp_set_current_user( $this->admin_id() );
		// Core strips script text from a term name on insert, so the name is set in the table, as a stored name with markup
		// would be if it came from elsewhere. The screen must escape it.
		$tag = $this->tag( 'Plain' );
		$this->set_term_name( $tag, $payload );
		$image = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', 0 );
		wp_update_post(
			array(
				'ID'         => $image,
				'post_title' => '"><img src=x onerror=alert(1)>',
			)
		);
		$other = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg', 0 );

		$post   = $this->admin_post();
		$before = $this->snapshot_row(
			SnapshotStore::KIND_POST_TERMS,
			$post,
			array(
				'taxonomy' => 'post_tag',
				'term_ids' => array( $tag ),
			)
		);
		$after  = $this->snapshot_row(
			SnapshotStore::KIND_POST_TERMS,
			$post,
			array(
				'taxonomy' => 'post_tag',
				'term_ids' => array(),
			)
		);
		$terms  = $this->log_row(
			array(
				'ability'           => 'steadhand/set-post-terms',
				'object_id'         => $post,
				'snapshot_id'       => $before,
				'after_snapshot_id' => $after,
			)
		);
		$before = $this->snapshot_row(
			SnapshotStore::KIND_FEATURED_IMAGE,
			$post,
			array( 'featured_media' => $image )
		);
		$after  = $this->snapshot_row(
			SnapshotStore::KIND_FEATURED_IMAGE,
			$post,
			array( 'featured_media' => $other )
		);
		$media  = $this->log_row(
			array(
				'ability'           => 'steadhand/set-featured-image',
				'object_id'         => $post,
				'snapshot_id'       => $before,
				'after_snapshot_id' => $after,
			)
		);

		$terms_view = $this->render_as( $this->admin_id(), array( 'entry' => (string) $terms ) );
		$media_view = $this->render_as( $this->admin_id(), array( 'entry' => (string) $media ) );

		// The payloads reach the diff, escaped, so the assertions below are not passing on empty output.
		$this->assertStringContainsString( '&lt;script&gt;alert(1)', $terms_view['html'] );
		$this->assertStringContainsString( '&quot;&gt;&lt;img', $media_view['html'] );
		foreach ( array( $terms_view, $media_view ) as $view ) {
			$this->assertStringNotContainsString( '<script>alert(1)', $view['html'] );
			$this->assertStringNotContainsString( '<img src=x', $view['html'] );
		}
	}

	/**
	 * Inserts a saved state directly, as a write would have saved it, and returns its ID.
	 *
	 * @param string               $kind  Kind.
	 * @param int                  $post  Post ID.
	 * @param array<string, mixed> $state State.
	 */
	private function snapshot_row( string $kind, int $post, array $state ): int {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Test fixture: a saved state as a write would leave it.
			Schema::snapshots_table(),
			array(
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
				'user_id'     => $this->admin_id(),
				'kind'        => $kind,
				'object_type' => 'post',
				'object_id'   => $post,
				'state'       => (string) wp_json_encode( $state ),
			)
		);

		return (int) $wpdb->insert_id;
	}

	public function test_invalid_filters_show_the_notice_and_no_rows(): void {
		$this->log_row( array( 'message' => self::SECRET ) );

		$result = $this->render_as( $this->admin_id(), array( 'agent' => '1 OR 1=1' ) );

		$this->assertStringContainsString( 'One of the filters is not valid, so no entries are shown.', $result['html'] );
		$this->assertStringNotContainsString( 'wp-list-table', $result['html'] );
		$this->assertStringNotContainsString( self::SECRET, $result['html'] );
	}

	public function test_a_list_filter_narrows_the_rows_shown(): void {
		$this->log_row(
			array(
				'message' => 'Shown ok row',
				'result'  => Entry::RESULT_OK,
			)
		);
		$this->log_row(
			array(
				'message' => 'Shown blocked row',
				'result'  => Entry::RESULT_BLOCKED,
			)
		);

		$result = $this->render_as( $this->admin_id(), array( 'result' => 'blocked' ) );

		$this->assertStringContainsString( 'Shown blocked row', $result['html'] );
		$this->assertStringNotContainsString( 'Shown ok row', $result['html'] );
	}

	public function test_a_missing_table_shows_the_notice_and_does_not_fail(): void {
		$this->log_row( array( 'message' => self::SECRET ) );
		$this->make_table_look_missing();

		$result = $this->render_as( $this->admin_id() );

		$this->assertNull( $result['died'] );
		$this->assertStringContainsString( 'The change log table is missing or incomplete.', $result['html'] );
		$this->assertStringNotContainsString( 'wp-list-table', $result['html'] );
		$this->assertStringNotContainsString( self::SECRET, $result['html'] );
	}

	public function test_a_missing_entry_shows_the_notice(): void {
		$result = $this->render_as( $this->admin_id(), array( 'entry' => '987654321' ) );

		$this->assertStringContainsString( 'This entry does not exist. It may have been removed after the retention period.', $result['html'] );
	}

	public function test_the_toolbar_action_fires_on_the_list_with_the_validated_filters(): void {
		$seen = null;
		add_action(
			'steadhand_admin_log_toolbar',
			static function ( $filters ) use ( &$seen ): void {
				$seen = $filters;
			}
		);

		$this->render_as(
			$this->admin_id(),
			array(
				'result' => 'fixed',
				'agent'  => '',
			)
		);

		$this->assertIsArray( $seen );
		$this->assertSame( 'fixed', $seen['result'] );
		$this->assertSame(
			array( 'agent', 'ability', 'result', 'from', 'to', 'paged', 'entry' ),
			array_keys( $seen )
		);
	}

	public function test_the_entry_action_fires_with_the_typed_row_including_its_input(): void {
		$id   = $this->log_row( array( 'input' => '{"id":1}' ) );
		$seen = null;
		add_action(
			'steadhand_admin_log_entry',
			static function ( $entry ) use ( &$seen ): void {
				$seen = $entry;
			}
		);

		$this->render_as( $this->admin_id(), array( 'entry' => (string) $id ) );

		$this->assertIsArray( $seen );
		$this->assertSame( $id, $seen['id'] );
		$this->assertSame( '{"id":1}', $seen['input'] );
	}

	public function test_the_actions_do_not_fire_for_a_refused_viewer(): void {
		$fired = false;
		add_action(
			'steadhand_admin_log_toolbar',
			static function () use ( &$fired ): void {
				$fired = true;
			}
		);
		add_action(
			'steadhand_admin_log_entry',
			static function () use ( &$fired ): void {
				$fired = true;
			}
		);
		$id = $this->log_row();

		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$this->render_as( $subscriber, array( 'entry' => (string) $id ) );
		$this->render_as( 0 );

		$this->assertFalse( $fired );
	}

	public function test_a_listener_that_throws_does_not_break_either_view(): void {
		$id     = $this->log_row( array( 'message' => 'Still shown' ) );
		$throws = static function (): void {
			throw new \RuntimeException( 'listener failed' );
		};
		add_action( 'steadhand_admin_log_toolbar', $throws );
		add_action( 'steadhand_admin_log_entry', $throws );

		$list = $this->render_as( $this->admin_id() );
		$this->assertStringContainsString( 'Still shown', $list['html'] );

		$entry = $this->render_as( $this->admin_id(), array( 'entry' => (string) $id ) );
		$this->assertStringContainsString( 'Input', $entry['html'] );
	}

	public function test_a_long_list_links_its_pages_and_keeps_the_filters(): void {
		for ( $i = 0; $i < 51; $i++ ) {
			$this->log_row( array( 'message' => 'Row ' . $i ) );
		}

		$result = $this->render_as( $this->admin_id(), array( 'result' => 'ok' ) );

		// Asserted inside the pagination only, so the View links cannot make the check pass.
		$pages = $this->pagination_html( $result['html'] );
		$this->assertStringContainsString( 'paged=2', $pages );
		$this->assertStringContainsString( 'result=ok', $pages );
	}

	/**
	 * The pagination block of a rendered list.
	 *
	 * @param string $html The rendered page.
	 */
	private function pagination_html( string $html ): string {
		$start = strpos( $html, '<div class="tablenav-pages">' );
		$this->assertIsInt( $start, 'The pagination must be rendered.' );
		$end = strpos( $html, '</div></div>', (int) $start );

		return substr( $html, (int) $start, (int) $end - (int) $start );
	}

	/**
	 * Denies edit_post for one post, or for every post when $post is null. Returns the filter, which the caller removes.
	 *
	 * @param int|null $post The post, or null for all.
	 */
	private function deny_edit( ?int $post ): \Closure {
		$deny = static function ( array $caps, string $cap, int $user_id, array $args ) use ( $post ): array {
			unset( $user_id );
			$matches = null === $post || ( isset( $args[0] ) && (int) $args[0] === $post );

			return ( 'edit_post' === $cap && $matches ) ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $deny, 10, 4 );

		return $deny;
	}

	/**
	 * Sets columns of an existing change-log row, as a fixture.
	 *
	 * @param int                  $id     Row ID.
	 * @param array<string, mixed> $fields Columns to set.
	 */
	private function set_row_fields( int $id, array $fields ): void {
		global $wpdb;

		$wpdb->update( Schema::table(), $fields, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture: the row is set as a write would leave it.
	}

	/**
	 * A post edit whose message, guardrail notes and input all carry the word "Private". Returns the entry ID.
	 */
	private function private_edit(): int {
		$id   = $this->text_change( 'Private before title', '<p>Private before</p>', 'Private after title', '<p>Private after</p>' );
		$post = (int) $this->entry_row( $id )['object_id'];

		$this->set_row_fields(
			$id,
			array(
				'input'           => (string) wp_json_encode(
					array(
						'id'      => $post,
						'title'   => 'Private after title',
						'content' => '<p>Private after</p>',
					)
				),
				'message'         => 'Private message MARKER',
				'guardrail_notes' => 'Private note MARKER',
			)
		);

		return $id;
	}

	public function test_a_viewer_who_may_not_edit_the_post_sees_no_message_notes_or_input(): void {
		$id   = $this->private_edit();
		$post = (int) $this->entry_row( $id )['object_id'];

		$deny = $this->deny_edit( $post );
		try {
			$list  = $this->render_as( $this->admin_id() );
			$entry = $this->render_as( $this->admin_id(), array( 'entry' => (string) $id ) );
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
		}

		$hidden = 'Hidden: you are not allowed to edit this post.';
		$this->assertStringContainsString( $hidden, $list['html'] );
		$this->assertStringNotContainsString( 'Private', $list['html'] );
		$this->assertStringNotContainsString( 'MARKER', $list['html'] );

		$this->assertSame( 3, substr_count( $entry['html'], $hidden ), 'Message, guardrail notes and input must each be hidden.' );
		$this->assertStringContainsString( 'You may not edit this post', $entry['html'] );
		$this->assertStringNotContainsString( 'Private', $entry['html'] );
		$this->assertStringNotContainsString( 'MARKER', $entry['html'] );
	}

	public function test_a_viewer_who_may_edit_the_post_sees_its_message_notes_and_input(): void {
		$id = $this->private_edit();

		$list  = $this->render_as( $this->admin_id() );
		$entry = $this->render_as( $this->admin_id(), array( 'entry' => (string) $id ) );

		$this->assertStringContainsString( 'Private message MARKER', $list['html'] );
		$this->assertStringNotContainsString( 'Hidden: you are not allowed', $list['html'] );
		$this->assertStringContainsString( 'Private note MARKER', $entry['html'] );
		$this->assertStringContainsString( 'Private after title', $entry['html'] );
		$this->assertStringNotContainsString( 'Hidden: you are not allowed', $entry['html'] );
	}

	public function test_an_entry_about_no_post_is_shown_even_when_the_viewer_may_edit_no_post(): void {
		$id = $this->log_row(
			array(
				'object_type'     => 'post',
				'object_id'       => 0,
				'message'         => 'Zero object message',
				'guardrail_notes' => 'Zero object note',
				'input'           => '{"x":1}',
			)
		);

		$deny = $this->deny_edit( null );
		try {
			$list  = $this->render_as( $this->admin_id() );
			$entry = $this->render_as( $this->admin_id(), array( 'entry' => (string) $id ) );
		} finally {
			remove_filter( 'map_meta_cap', $deny, 10 );
		}

		$this->assertStringContainsString( 'Zero object message', $list['html'] );
		$this->assertStringContainsString( 'Zero object message', $entry['html'] );
		$this->assertStringContainsString( 'Zero object note', $entry['html'] );
		$this->assertStringNotContainsString( 'Hidden: you are not allowed', $entry['html'] );
	}

	public function test_the_selected_agent_is_offered_even_when_it_is_not_in_the_log(): void {
		$this->log_row( array( 'message' => 'Admin row' ) );
		$lonely = (int) self::factory()->user->create( array( 'user_login' => 'lonely_agent' ) );

		$result = $this->render_as( $this->admin_id(), array( 'agent' => (string) $lonely ) );

		$this->assertStringContainsString( 'value="' . $lonely . '" selected>lonely_agent</option>', $result['html'] );
		$this->assertStringNotContainsString( '<option value="" selected>All agents', $result['html'] );
	}

	public function test_an_undo_entry_says_it_has_no_diff_of_its_own(): void {
		$id = $this->log_row( array( 'ability' => 'steadhand/undo-change' ) );

		$result = $this->render_as( $this->admin_id(), array( 'entry' => (string) $id ) );

		$this->assertStringContainsString( 'An undo has no diff of its own. See the entry it reversed.', $result['html'] );
	}

	public function test_rendering_both_views_writes_nothing(): void {
		$id     = $this->text_change( 'Before title', '<p>Before</p>', 'After title', '<p>After</p>' );
		$post   = (int) $this->entry_row( $id )['object_id'];
		$rows   = $this->total_rows();
		$before = get_post( $post );
		$revs   = count( wp_get_post_revisions( $post ) );

		$this->render_as( $this->admin_id() );
		$this->render_as( $this->admin_id(), array( 'entry' => (string) $id ) );

		$this->assertSame( $rows, $this->total_rows() );
		$after = get_post( $post );
		$this->assertSame( $before->post_title, $after->post_title );
		$this->assertSame( $before->post_content, $after->post_content );
		$this->assertSame( $before->post_modified, $after->post_modified );
		$this->assertSame( $revs, count( wp_get_post_revisions( $post ) ) );
	}

	/**
	 * The stored row, read the way the screen reads it, for a change that the test made.
	 *
	 * @param int $id Row ID.
	 * @return array<string, mixed>
	 */
	private function entry_row( int $id ): array {
		return ( new DbLogger() )->find_any( $id ) ?? array();
	}
}
