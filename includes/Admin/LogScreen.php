<?php
/**
 * The admin screen of the change log: a filterable list, and one entry with its diff. Read-only.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Admin;

use Steadhand\Abilities\Registry;
use Steadhand\Abilities\UndoRules;
use Steadhand\Agents;
use Steadhand\Log\DbLogger;
use Steadhand\Log\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the change-log page. It changes nothing: no option, no row and no post is written here. Every stored value is
 * attacker-influenced, so each one is escaped at the point of output. The only output that is not escaped here is the HTML
 * that wp_text_diff() and paginate_links() return, which core escapes itself.
 */
final class LogScreen {

	/** The page slug, also the menu slug. */
	public const SLUG = 'steadhand-log';

	/** Entries per page of the list. */
	public const PER_PAGE = 50;

	/** Most characters of a message shown in the list. */
	private const MESSAGE_CHARS = 200;

	/**
	 * Constructor.
	 *
	 * @param DbLogger $logger   The change log to read from.
	 * @param Registry $registry The ability registry, for the tool filter.
	 */
	public function __construct( private DbLogger $logger, private Registry $registry ) {}

	/**
	 * Renders the page. Refuses everyone but an administrator, and an agent even when it holds manage_options.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) || Agents::is_agent( wp_get_current_user() ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'steadhand' ), 403 );
		}

		// This is a read-only view that changes nothing, so no nonce applies. LogFilters validates every value it reads.
		$query   = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Read-only view; the values are validated by LogFilters::from_request().
		$filters = LogFilters::from_request( is_array( $query ) ? $query : array() );

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'Steadhand change log', 'steadhand' ) );

		if ( ! Schema::ready() ) {
			$this->notice( 'error', __( 'The change log table is missing or incomplete. Deactivate and reactivate Steadhand to recreate it.', 'steadhand' ) );
		} elseif ( $filters->entry_id() > 0 ) {
			$this->render_entry( $filters, LogFilters::undo_code( is_array( $query ) ? $query : array() ) );
		} else {
			$this->render_list( $filters );
		}

		echo '</div>';
	}

	/**
	 * The list view: the filter form, then one page of entries.
	 *
	 * @param LogFilters $filters The validated filters.
	 * @return void
	 */
	private function render_list( LogFilters $filters ): void {
		$this->filter_form( $filters );

		$this->announce_toolbar( $filters->values() );

		if ( ! $filters->is_valid() ) {
			$this->notice( 'warning', __( 'One of the filters is not valid, so no entries are shown.', 'steadhand' ) );
			return;
		}

		$result = $this->logger->query_all( $filters->to_query(), $filters->page(), self::PER_PAGE );
		if ( is_wp_error( $result ) ) {
			$this->notice( 'error', $result->get_error_message() );
			return;
		}

		$total = (int) $result['total'];
		if ( 0 === $total ) {
			printf( '<p>%s</p>', esc_html__( 'No entries match these filters.', 'steadhand' ) );
			return;
		}

		printf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: %s: number of change-log entries. */
					_n( '%s entry', '%s entries', $total, 'steadhand' ),
					number_format_i18n( $total )
				)
			)
		);

		$this->table( $filters, $result['items'] );
		$this->pagination( $filters, $total );
	}

	/**
	 * The GET form that filters the list. It has no nonce: it submits nothing that changes state.
	 *
	 * @param LogFilters $filters The validated filters, which fill the form.
	 * @return void
	 */
	private function filter_form( LogFilters $filters ): void {
		$values = $filters->values();

		printf( '<form method="get" action="%s">', esc_url( admin_url( 'admin.php' ) ) );
		printf( '<input type="hidden" name="page" value="%s">', esc_attr( self::SLUG ) );

		$this->select( 'agent', __( 'Agent', 'steadhand' ), $this->agent_options( $values['agent'] ), $values['agent'] );
		$this->select( 'ability', __( 'Tool', 'steadhand' ), $this->tool_options(), $values['ability'] );
		$this->select(
			'result',
			__( 'Result', 'steadhand' ),
			array(
				''        => __( 'All results', 'steadhand' ),
				'ok'      => 'ok',
				'blocked' => 'blocked',
				'fixed'   => 'fixed',
				'error'   => 'error',
			),
			$values['result']
		);

		printf(
			'<label for="steadhand-log-from">%s</label> <input type="date" id="steadhand-log-from" name="from" value="%s"> ',
			esc_html__( 'From', 'steadhand' ),
			esc_attr( $values['from'] )
		);
		printf(
			'<label for="steadhand-log-to">%s</label> <input type="date" id="steadhand-log-to" name="to" value="%s"> ',
			esc_html__( 'To', 'steadhand' ),
			esc_attr( $values['to'] )
		);
		printf( '<button type="submit" class="button">%s</button> ', esc_html__( 'Filter', 'steadhand' ) );
		printf( '<a href="%s">%s</a>', esc_url( $this->list_url() ), esc_html__( 'Reset', 'steadhand' ) );
		echo '</form>';
	}

	/**
	 * One select of the filter form.
	 *
	 * @param string               $name     The request key.
	 * @param string               $label    The visible label.
	 * @param array<string,string> $options  Option value => text. The empty value means "all".
	 * @param string               $selected The selected value.
	 * @return void
	 */
	private function select( string $name, string $label, array $options, string $selected ): void {
		printf(
			'<label for="steadhand-log-%1$s">%2$s</label> <select id="steadhand-log-%1$s" name="%1$s">',
			esc_attr( $name ),
			esc_html( $label )
		);
		foreach ( $options as $value => $text ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( (string) $value ),
				( (string) $value === $selected ) ? ' selected' : '',
				esc_html( $text )
			);
		}
		echo '</select> ';
	}

	/**
	 * The agent choices: every user that appears in the log, and the selected agent even when it does not appear there, so the
	 * select never shows "All agents" under an active filter. A deleted user is named by its ID.
	 *
	 * @param string $selected The agent ID the filter selects, or ''.
	 * @return array<string,string>
	 */
	private function agent_options( string $selected ): array {
		$options = array( '' => __( 'All agents', 'steadhand' ) );
		$ids     = $this->logger->users();
		if ( '' !== $selected && ! in_array( (int) $selected, $ids, true ) ) {
			$ids[] = (int) $selected;
		}
		foreach ( $ids as $user_id ) {
			$user = get_userdata( $user_id );

			$options[ (string) $user_id ] = $user instanceof \WP_User
				? $user->user_login
				: sprintf(
					/* translators: %d: ID of a user that no longer exists. */
					__( 'User #%d (deleted)', 'steadhand' ),
					$user_id
				);
		}

		return $options;
	}

	/**
	 * The tool choices: every Steadhand ability.
	 *
	 * @return array<string,string>
	 */
	private function tool_options(): array {
		$options = array( '' => __( 'All tools', 'steadhand' ) );
		$names   = $this->registry->names();
		$names[] = UndoEntry::NAME;
		foreach ( $names as $name ) {
			$options[ $name ] = $name;
		}

		return $options;
	}

	/**
	 * The table of entries.
	 *
	 * @param LogFilters                       $filters The validated filters, for the links.
	 * @param array<int, array<string, mixed>> $items   One page of entries.
	 * @return void
	 */
	private function table( LogFilters $filters, array $items ): void {
		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		foreach ( array( __( 'Time', 'steadhand' ), __( 'Agent', 'steadhand' ), __( 'Tool', 'steadhand' ), __( 'Object', 'steadhand' ), __( 'Result', 'steadhand' ), __( 'Message', 'steadhand' ) ) as $heading ) {
			printf( '<th scope="col">%s</th>', esc_html( $heading ) );
		}
		echo '<th scope="col"></th></tr></thead><tbody>';

		if ( array() === $items ) {
			printf( '<tr><td colspan="7">%s</td></tr>', esc_html__( 'No entries on this page.', 'steadhand' ) );
		}

		foreach ( $items as $item ) {
			$this->list_row( $filters, $item );
		}

		echo '</tbody></table>';
	}

	/**
	 * One row of the list.
	 *
	 * @param LogFilters           $filters The validated filters, for the link.
	 * @param array<string, mixed> $item    One entry.
	 * @return void
	 */
	private function list_row( LogFilters $filters, array $item ): void {
		$created = (string) $item['created_at'];
		$result  = (string) $item['result'];
		if ( (int) $item['undone_by'] > 0 ) {
			$result .= ' (' . __( 'Undone', 'steadhand' ) . ')';
		}

		echo '<tr>';
		printf( '<td title="%s">%s</td>', esc_attr( $created . ' UTC' ), esc_html( $this->site_time( $created ) ) );
		printf( '<td>%s</td>', esc_html( $this->agent_name( (int) $item['user_id'], (string) $item['agent_label'] ) ) );
		printf( '<td>%s</td>', esc_html( (string) $item['ability'] ) );
		printf( '<td>%s</td>', esc_html( $this->object_label( (string) $item['object_type'], (int) $item['object_id'] ) ) );
		printf( '<td>%s</td>', esc_html( $result ) );
		$message = $this->may_see_object( $item ) ? $this->excerpt( (string) $item['message'] ) : $this->hidden();
		printf( '<td>%s</td>', esc_html( $message ) );
		printf(
			'<td><a href="%s">%s</a></td>',
			esc_url( $this->url( $filters, array( 'entry' => (string) $item['id'] ) ) ),
			esc_html__( 'View', 'steadhand' )
		);
		echo '</tr>';
	}

	/**
	 * Pagination with the filters kept in every link. paginate_links() escapes its own output.
	 *
	 * @param LogFilters $filters The validated filters.
	 * @param int        $total   Entries that match.
	 * @return void
	 */
	private function pagination( LogFilters $filters, int $total ): void {
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages < 2 ) {
			return;
		}

		$base = add_query_arg( 'paged', '%#%', $this->url( $filters, array() ) );

		echo '<div class="tablenav"><div class="tablenav-pages">';
		// paginate_links() returns HTML that core has escaped. Its links are built from $base, and core also merges in every
		// query argument of the current request URI (general-template.php), with the values url-encoded.
		echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- paginate_links() returns escaped HTML.
			array(
				'base'    => $base,
				'format'  => '',
				'current' => $filters->page(),
				'total'   => $pages,
			)
		);
		echo '</div></div>';
	}

	/**
	 * The single view of one entry: the details, the stored input, the undo button, the diff.
	 *
	 * @param LogFilters $filters The validated filters, for the back link.
	 * @param string     $undo    The outcome code of an undo that redirected here (UndoHandler::CODES), or ''.
	 * @return void
	 */
	private function render_entry( LogFilters $filters, string $undo ): void {
		printf( '<p><a href="%s">%s</a></p>', esc_url( $this->url( $filters, array() ) ), esc_html__( 'Back to the change log', 'steadhand' ) );

		$row = $this->logger->find_any( $filters->entry_id() );
		if ( null === $row ) {
			$this->notice( 'warning', __( 'This entry does not exist. It may have been removed after the retention period.', 'steadhand' ) );
			return;
		}

		if ( '' !== $undo ) {
			$this->undo_notice( $undo );
		}

		$this->details( $row );
		$this->announce_entry( $row );
		$this->undo_form( $row );

		echo '<h2>' . esc_html__( 'Change', 'steadhand' ) . '</h2>';
		$this->diff( $row );
	}

	/**
	 * Runs the steadhand_admin_log_toolbar action (docs/HOOKS.md). A listener that throws cannot break the screen.
	 *
	 * @param array<string, string> $values The validated filters.
	 * @return void
	 */
	private function announce_toolbar( array $values ): void {
		try {
			do_action( 'steadhand_admin_log_toolbar', $values );
		} catch ( \Throwable ) {
			return;
		}
	}

	/**
	 * Runs the steadhand_admin_log_entry action (docs/HOOKS.md). A listener that throws cannot break the screen.
	 *
	 * @param array<string, mixed> $row The entry, with its input.
	 * @return void
	 */
	private function announce_entry( array $row ): void {
		try {
			do_action( 'steadhand_admin_log_entry', $row );
		} catch ( \Throwable ) {
			return;
		}
	}

	/**
	 * The stored fields of one entry, in a two-column table.
	 *
	 * @param array<string, mixed> $row The entry, as DbLogger::find_any() returns it.
	 * @return void
	 */
	private function details( array $row ): void {
		$visible = $this->may_see_object( $row );
		$created = (string) $row['created_at'];
		$user_id = (int) $row['user_id'];
		$user    = get_userdata( $user_id );
		$login   = $user instanceof \WP_User ? $user->user_login : sprintf(
			/* translators: %d: ID of a user that no longer exists. */
			__( 'User #%d (deleted)', 'steadhand' ),
			$user_id
		);

		echo '<table class="widefat striped"><tbody>';
		$this->detail( __( 'ID', 'steadhand' ), (string) $row['id'] );
		$this->detail( __( 'Time', 'steadhand' ), $this->site_time( $created ) . ' (' . $created . ' UTC)' );
		$this->detail( __( 'Agent user', 'steadhand' ), $login );
		$this->detail( __( 'Agent label', 'steadhand' ), $this->or_none( (string) $row['agent_label'] ) );
		$this->detail( __( 'Tool', 'steadhand' ), (string) $row['ability'] );
		$this->detail( __( 'Object', 'steadhand' ), $this->object_label( (string) $row['object_type'], (int) $row['object_id'] ) );
		$this->detail( __( 'Result', 'steadhand' ), (string) $row['result'] );
		$this->detail( __( 'Message', 'steadhand' ), $visible ? $this->or_none( (string) $row['message'] ) : $this->hidden() );
		$this->detail( __( 'Guardrail notes', 'steadhand' ), $visible ? $this->or_none( (string) $row['guardrail_notes'] ) : $this->hidden() );
		$this->detail( __( 'Snapshot ID', 'steadhand' ), $this->id_or_none( (int) $row['snapshot_id'] ) );
		$this->detail( __( 'After-state snapshot ID', 'steadhand' ), $this->id_or_none( (int) $row['after_snapshot_id'] ) );
		$this->detail( __( 'Undoable', 'steadhand' ), (int) $row['undoable'] > 0 ? __( 'Yes', 'steadhand' ) : __( 'No', 'steadhand' ) );

		if ( (int) $row['undone_by'] > 0 ) {
			printf(
				'<tr><th scope="row">%s</th><td><a href="%s">#%d</a></td></tr>',
				esc_html__( 'Undone by', 'steadhand' ),
				esc_url( $this->entry_url( (int) $row['undone_by'] ) ),
				(int) $row['undone_by']
			);
		} else {
			$this->detail( __( 'Undone by', 'steadhand' ), __( 'Not undone', 'steadhand' ) );
		}

		if ( $visible ) {
			printf(
				'<tr><th scope="row">%s</th><td><pre>%s</pre></td></tr>',
				esc_html__( 'Input', 'steadhand' ),
				esc_html( $this->pretty_input( (string) $row['input'] ) )
			);
		} else {
			$this->detail( __( 'Input', 'steadhand' ), $this->hidden() );
		}
		echo '</tbody></table>';
	}

	/**
	 * One row of the details table.
	 *
	 * @param string $label The label.
	 * @param string $value The value, as text.
	 * @return void
	 */
	private function detail( string $label, string $value ): void {
		printf( '<tr><th scope="row">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ) );
	}

	/**
	 * The diff section for one entry: a text diff, a terms change or an image change, or the reason there is none.
	 *
	 * @param array<string, mixed> $row The entry.
	 * @return void
	 */
	private function diff( array $row ): void {
		$diff = EntryDiff::for_entry( $row );

		switch ( $diff['status'] ) {
			case EntryDiff::STATUS_NONE:
				printf( '<p>%s</p>', esc_html__( 'This entry has no saved before and after states, so there is no change to show.', 'steadhand' ) );
				return;

			case EntryDiff::STATUS_UNAVAILABLE:
				printf( '<p>%s</p>', esc_html__( 'The saved states for this change cannot be read, or do not belong to it, so no change is shown.', 'steadhand' ) );
				return;

			case EntryDiff::STATUS_UNDO:
				printf( '<p>%s</p>', esc_html__( 'An undo has no diff of its own. See the entry it reversed.', 'steadhand' ) );
				return;

			case EntryDiff::STATUS_FORBIDDEN:
				printf( '<p>%s</p>', esc_html__( 'You may not edit this post, so its content is not shown.', 'steadhand' ) );
				return;
		}

		if ( EntryDiff::KIND_TEXT === $diff['kind'] ) {
			$this->text_diff( $diff['sections'] );
		} elseif ( EntryDiff::KIND_TERMS === $diff['kind'] ) {
			$this->terms_diff( $diff['sections'] );
		} else {
			$this->image_diff( $diff['sections'] );
		}
	}

	/**
	 * The text diff of the title, content and excerpt.
	 *
	 * @param array<int, array<string, mixed>> $sections The changed fields.
	 * @return void
	 */
	private function text_diff( array $sections ): void {
		if ( array() === $sections ) {
			printf( '<p>%s</p>', esc_html__( 'The title, content and excerpt are the same before and after this change.', 'steadhand' ) );
			return;
		}

		foreach ( $sections as $section ) {
			// wp_text_diff() escapes every line with htmlspecialchars(), and its title is printed as given, so the title is
			// escaped here. Its output is HTML that core has escaped.
			echo wp_text_diff( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_text_diff() escapes the diff lines itself (htmlspecialchars); the title is escaped with esc_html() below.
				(string) $section['before'],
				(string) $section['after'],
				array( 'title' => esc_html( (string) $section['label'] ) )
			);
		}
	}

	/**
	 * The terms that were removed and added, with their names.
	 *
	 * @param array<int, array<string, mixed>> $sections One section: the taxonomy, and the removed and added terms.
	 * @return void
	 */
	private function terms_diff( array $sections ): void {
		foreach ( $sections as $section ) {
			printf( '<p>%s %s</p>', esc_html__( 'Taxonomy:', 'steadhand' ), esc_html( (string) $section['taxonomy'] ) );
			$this->term_list( __( 'Removed', 'steadhand' ), $section['removed'] );
			$this->term_list( __( 'Added', 'steadhand' ), $section['added'] );
		}
	}

	/**
	 * A list of terms, each with its ID, and its name when the term still exists.
	 *
	 * @param string                                        $label The heading.
	 * @param array<int, array{id: int, name: string|null}> $terms The terms.
	 * @return void
	 */
	private function term_list( string $label, array $terms ): void {
		printf( '<p>%s</p><ul>', esc_html( $label ) );
		if ( array() === $terms ) {
			printf( '<li>%s</li>', esc_html__( 'None', 'steadhand' ) );
		}

		foreach ( $terms as $term ) {
			$text = null === $term['name']
				? sprintf(
					/* translators: %d: ID of a term that no longer exists. */
					__( 'Missing term #%d', 'steadhand' ),
					$term['id']
				)
				: sprintf( '%s (#%d)', $term['name'], $term['id'] );

			printf( '<li>%s</li>', esc_html( $text ) );
		}

		echo '</ul>';
	}

	/**
	 * The featured image before and after the change.
	 *
	 * @param array<int, array<string, mixed>> $sections One section: the image before and after.
	 * @return void
	 */
	private function image_diff( array $sections ): void {
		$section = $sections[0] ?? null;
		if ( ! is_array( $section ) ) {
			return;
		}

		echo '<table class="widefat"><tbody>';
		$this->detail( __( 'Before', 'steadhand' ), $this->image_text( $section['before'] ) );
		$this->detail( __( 'After', 'steadhand' ), $this->image_text( $section['after'] ) );
		echo '</tbody></table>';
	}

	/**
	 * The text for one featured image: none, a title with its ID, or a missing attachment with its ID.
	 *
	 * @param array{id: int, title: string|null} $image The image.
	 * @return string
	 */
	private function image_text( array $image ): string {
		if ( 0 === $image['id'] ) {
			return __( 'No image', 'steadhand' );
		}

		if ( null === $image['title'] ) {
			return sprintf(
				/* translators: %d: ID of an attachment that no longer exists. */
				__( 'Missing attachment #%d', 'steadhand' ),
				$image['id']
			);
		}

		return sprintf( '%s (#%d)', $image['title'], $image['id'] );
	}

	/**
	 * The Undo form of an entry, when the administrator may undo it: the entry is one an undo can reverse (UndoRules), the viewer
	 * may see the entry's post, and may edit it. The form posts to admin-post.php, with the entry ID and a nonce bound to that ID.
	 * Nothing is shown for any other entry.
	 *
	 * @param array<string, mixed> $row The entry.
	 * @return void
	 */
	private function undo_form( array $row ): void {
		$id = (int) $row['id'];
		// A dedicated agent or an administrator who holds the agent role never sees the button; the handler refuses them too.
		if ( Agents::holds_agent_role( wp_get_current_user() ) || ! UndoRules::structurally_eligible( $row ) || ! $this->may_see_object( $row ) || ! current_user_can( 'edit_post', (int) $row['object_id'] ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Undo', 'steadhand' ) . '</h2>';
		printf( '<p>%s</p>', esc_html( $this->undo_sentence( UndoRules::kind_of( $row ) ) ) );

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( UndoHandler::ACTION ) );
		printf( '<input type="hidden" name="entry" value="%s">', esc_attr( (string) $id ) );
		wp_nonce_field( UndoHandler::NONCE_PREFIX . $id );
		printf( '<p><button type="submit" class="button button-secondary">%s</button></p>', esc_html__( 'Undo this change', 'steadhand' ) );
		echo '</form>';
	}

	/**
	 * What the Undo button will do, for a kind of change.
	 *
	 * @param string $kind One of the UndoRules::KIND_ constants.
	 * @return string
	 */
	private function undo_sentence( string $kind ): string {
		return match ( $kind ) {
			UndoRules::KIND_TERMS    => __( "Puts the post's terms in this taxonomy back to the set they had before this change.", 'steadhand' ),
			UndoRules::KIND_FEATURED => __( "Sets the post's featured image back to the one it had before this change, or removes it when it had none.", 'steadhand' ),
			default                  => __( "Puts the post's title, content and excerpt back to what they were before this change.", 'steadhand' ),
		};
	}

	/**
	 * The fixed notice for an undo outcome. Only the code is read from the request; the text is always one of these.
	 *
	 * @param string $code One of UndoHandler::CODES.
	 * @return void
	 */
	private function undo_notice( string $code ): void {
		$text = match ( $code ) {
			'done'         => __( 'The change was undone. The undo is recorded in the change log.', 'steadhand' ),
			'changed'      => __( 'The change was not undone, because the post, its terms or its image changed after it. Nothing was changed.', 'steadhand' ),
			'not_undoable' => __( 'The change was not undone, because Steadhand cannot restore its saved state, or it was already undone. Nothing was changed.', 'steadhand' ),
			'forbidden'    => __( 'The change was not undone. The entry does not exist, or you may not edit its post. Nothing was changed.', 'steadhand' ),
			'partial'      => __( 'The undo did not finish as expected, so the post may have been changed. Check the post before you continue.', 'steadhand' ),
			'log'          => __( 'The change log could not record the undo. If the post was changed, check it before you continue.', 'steadhand' ),
			'locked'       => __( 'This post is open in the editor right now. Close the editor, wait a few minutes, and try again.', 'steadhand' ),
			default        => __( 'The undo did not complete. Check the post before you try again.', 'steadhand' ),
		};

		$type = match ( $code ) {
			'done'                    => 'success',
			'partial', 'log', 'failed' => 'warning',
			default                    => 'error',
		};

		$this->notice( $type, $text );
	}

	/**
	 * A notice box. The text is escaped here.
	 *
	 * @param string $type    One of error, warning, success or info.
	 * @param string $message The text.
	 * @return void
	 */
	private function notice( string $type, string $message ): void {
		printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );
	}

	/**
	 * An admin URL of this screen, with the set filters kept. The page number and the entry are not kept.
	 *
	 * @param LogFilters           $filters The validated filters.
	 * @param array<string,string> $extra   Query values to add or replace.
	 * @return string
	 */
	private function url( LogFilters $filters, array $extra ): string {
		$args = array( 'page' => self::SLUG );
		foreach ( $filters->values() as $key => $value ) {
			if ( '' !== $value && 'paged' !== $key && 'entry' !== $key ) {
				$args[ $key ] = $value;
			}
		}

		return add_query_arg( array_merge( $args, $extra ), admin_url( 'admin.php' ) );
	}

	/**
	 * The URL of the list with no filters: the Reset link.
	 *
	 * @return string
	 */
	private function list_url(): string {
		return add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) );
	}

	/**
	 * The URL of one entry's single view.
	 *
	 * @param int $id Entry ID.
	 * @return string
	 */
	private function entry_url( int $id ): string {
		return add_query_arg(
			array(
				'page'  => self::SLUG,
				'entry' => (string) $id,
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * A stored time, in the site's timezone and format.
	 *
	 * @param string $utc The time, 'Y-m-d H:i:s' in UTC.
	 * @return string
	 */
	private function site_time( string $utc ): string {
		return wp_date(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			(int) strtotime( $utc . ' UTC' )
		);
	}

	/**
	 * The agent's name: its login, or its ID when the user was deleted, with the label it was called by.
	 *
	 * @param int    $user_id User ID.
	 * @param string $label   The stored agent label.
	 * @return string
	 */
	private function agent_name( int $user_id, string $label ): string {
		$user = get_userdata( $user_id );
		$name = $user instanceof \WP_User
			? $user->user_login
			: sprintf(
				/* translators: %d: ID of a user that no longer exists. */
				__( 'User #%d (deleted)', 'steadhand' ),
				$user_id
			);

		return '' === $label ? $name : $name . ' (' . $label . ')';
	}

	/**
	 * The object an entry is about, as type #ID.
	 *
	 * @param string $type The object type.
	 * @param int    $id   The object ID.
	 * @return string
	 */
	private function object_label( string $type, int $id ): string {
		return '' === $type ? '#' . $id : $type . ' #' . $id;
	}

	/**
	 * The first characters of a message, with an ellipsis when it is cut.
	 *
	 * @param string $text The message.
	 * @return string
	 */
	private function excerpt( string $text ): string {
		if ( mb_strlen( $text ) <= self::MESSAGE_CHARS ) {
			return $text;
		}

		return mb_substr( $text, 0, self::MESSAGE_CHARS ) . '…';
	}

	/**
	 * Whether the viewer may see the fields that can carry post text: the message, the guardrail notes and the input. An entry
	 * about no post (another object type, or object ID 0) is always shown. An entry about a post is shown only when the viewer
	 * may edit that post, and an entry whose post no longer exists is not shown.
	 *
	 * @param array<string, mixed> $row The entry.
	 * @return bool
	 */
	private function may_see_object( array $row ): bool {
		$object_id = (int) ( $row['object_id'] ?? 0 );
		if ( 'post' !== ( $row['object_type'] ?? '' ) || $object_id <= 0 ) {
			return true;
		}

		if ( ! get_post( $object_id ) instanceof \WP_Post ) {
			return false;
		}

		return current_user_can( 'edit_post', $object_id );
	}

	/**
	 * The text shown in place of a field the viewer may not see.
	 *
	 * @return string
	 */
	private function hidden(): string {
		return __( 'Hidden: you are not allowed to edit this post.', 'steadhand' );
	}

	/**
	 * The text, or "None" when it is empty.
	 *
	 * @param string $text The text.
	 * @return string
	 */
	private function or_none( string $text ): string {
		return '' === $text ? __( 'None', 'steadhand' ) : $text;
	}

	/**
	 * The ID, or "None" for 0.
	 *
	 * @param int $id The ID.
	 * @return string
	 */
	private function id_or_none( int $id ): string {
		return $id > 0 ? (string) $id : __( 'None', 'steadhand' );
	}

	/**
	 * The stored input, pretty-printed. Text that is not JSON is shown as it is stored.
	 *
	 * @param string $input The stored input.
	 * @return string
	 */
	private function pretty_input( string $input ): string {
		$decoded = json_decode( $input, false, 512 );
		if ( null === $decoded && 'null' !== trim( $input ) ) {
			return $input;
		}

		$json = wp_json_encode( $decoded, JSON_PRETTY_PRINT );

		return false === $json ? $input : $json;
	}
}
