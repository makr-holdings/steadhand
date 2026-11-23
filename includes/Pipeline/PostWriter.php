<?php
/**
 * The only place Steadhand writes posts: inserts and updates, with a fixed set of fields.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Pipeline;

use Steadhand\Abilities\BlockEditor;
use Steadhand\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Writes posts for the write abilities (SPEC §14.1, CLAUDE.md "Layout" rule). Every post write in Steadhand goes through
 * here, and only while Pipeline::is_writing() is true.
 *
 * Rules that hold for every write:
 * - Only the fields listed in INSERT_FIELDS or UPDATE_FIELDS are accepted. Anything else (post_author, post_password,
 *   ID, meta_input, tax_input, post_parent and so on) is an InvalidArgumentException.
 * - post_author is always the current user on insert, and is never passed on update. post_type is never passed on
 *   update.
 * - post_type must be allowed (PostTypes::is_allowed()). An update to a post whose stored type is not allowed, or to a
 *   trashed post, is refused.
 * - The four post-field KSES filters are in place for every write, insert or update, for every field. Steadhand never
 *   removes a KSES filter or a Global Styles filter.
 * - On update, a field the call does not send is put back as it is stored, for that one post. See update().
 */
final class PostWriter {

	/** Fields an insert may set. */
	private const INSERT_FIELDS = array( 'post_type', 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_status', 'post_date_gmt' );

	/** Fields an update may set. */
	private const UPDATE_FIELDS = array( 'post_title', 'post_content', 'post_excerpt', 'post_name', 'post_status', 'post_date_gmt' );

	/** The zero GMT date WordPress stores for "no date". */
	private const ZERO_DATE = '0000-00-00 00:00:00';

	/**
	 * The save filters core adds for post fields (kses_init_filters() in wp-includes/kses.php), by post field. Each is
	 * added at priority 10, as core adds it. Each entry is the hook and the function. Every write has all four in place.
	 */
	private const KSES_FILTERS = array(
		'post_title'            => array( 'title_save_pre', 'wp_filter_kses' ),
		'post_content'          => array( 'content_save_pre', 'wp_filter_post_kses' ),
		'post_excerpt'          => array( 'excerpt_save_pre', 'wp_filter_post_kses' ),
		'post_content_filtered' => array( 'content_filtered_save_pre', 'wp_filter_post_kses' ),
	);

	/**
	 * Creates a post as the current user.
	 *
	 * @param array<string, string> $fields Fields to set. post_type is required and must be allowed.
	 * @return int|\WP_Error The new post ID, or the error WordPress reported.
	 * @throws \LogicException            When called outside a write.
	 * @throws \InvalidArgumentException  When a field is not allowed, or a value is not a string.
	 */
	public static function insert( array $fields ): int|\WP_Error {
		self::assert_writing();
		self::assert_fields( $fields, self::INSERT_FIELDS );

		$type = $fields['post_type'] ?? '';
		if ( ! is_string( $type ) || ! PostTypes::is_allowed( $type ) ) {
			return new \WP_Error( 'steadhand_forbidden', __( 'Not found, or this agent may not access it.', 'steadhand' ), array( 'status' => 403 ) );
		}

		$data                = self::dated( $fields );
		$data['post_type']   = $type;
		$data['post_author'] = get_current_user_id();

		return self::with_filters_added(
			self::bracket_filters(),
			static fn(): int|\WP_Error => wp_insert_post( wp_slash( $data ), true )
		);
	}

	/**
	 * Updates a post as the current user. The author and type stay as they are.
	 *
	 * Core's wp_update_post() saves every stored field again, through the save filters, not only the fields the call sends.
	 * The four KSES filters are in place for the whole write, for every field, as they are for an insert. No filter is
	 * removed for any field, so no code that runs during the save, such as a save_post listener that writes another post or
	 * a revision insert, runs without them.
	 *
	 * To keep the fields the call does not send byte-identical, update() records their stored values before the write: the
	 * title, content, excerpt and filtered content that $fields does not name. A wp_insert_post_data filter at PHP_INT_MAX puts
	 * each one back, after the save filters have run, and sets it slashed, because core passes $data slashed to that filter
	 * and unslashes it afterwards. The filter acts only on this post's own update (the update flag, the ID and the stored
	 * post type must all match), and only the first time it matches, so a nested update of the same post from a save_post
	 * listener is filtered as usual and is not given these values again. It is removed in finally, whatever happens. The
	 * fields the call sends are never touched by it: they keep the value core computes with the KSES filters.
	 *
	 * The one limit: code that changes the row after wp_insert_post_data (a filter that runs later at PHP_INT_MAX, or a direct
	 * write to the table) can still alter an unsent field. UpdatePost reads the row again after the write and reports
	 * steadhand_update_partial when such a field differs from what was stored before the write.
	 *
	 * @param int                   $post_id Post ID.
	 * @param array<string, string> $fields  Fields to set.
	 * @return int|\WP_Error The post ID, or the error WordPress reported.
	 * @throws \LogicException            When called outside a write.
	 * @throws \InvalidArgumentException  When a field is not allowed, or a value is not a string.
	 */
	public static function update( int $post_id, array $fields ): int|\WP_Error {
		self::assert_writing();
		self::assert_fields( $fields, self::UPDATE_FIELDS );

		$post = self::fresh_post( $post_id );
		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		// The stored value of each field the call does not send, as the raw row holds it.
		$keep = array();
		foreach ( array_keys( self::KSES_FILTERS ) as $field ) {
			if ( ! array_key_exists( $field, $fields ) ) {
				$keep[ $field ] = (string) $post->$field;
			}
		}

		return self::save( $post, $fields, $keep );
	}

	/**
	 * Writes the post content made of kept bytes of the stored content and sent markup, filtered run by run (see assemble()).
	 *
	 * The stored content must still be the content the segments were computed from, or nothing is written and the refusal is
	 * steadhand_refused_content_changed. The title, excerpt and filtered content are put back as they are stored, and the
	 * content is put back as assembled, after the save filters have run. So the save filters never reach the kept bytes, and
	 * only the sent runs are filtered.
	 *
	 * @param int                                                                       $post_id     Post ID.
	 * @param string                                                                    $stored_sha256 SHA-256 of the stored content the segments were computed from.
	 * @param array<int, array{type: string, start?: int, length?: int, text?: string}> $segments  Segments, as BlockEditor::segments() gives them.
	 * @param string                                                                    $preview_sha256 SHA-256 of the content the guardrails checked.
	 * @return int|\WP_Error The post ID, or the refusal: steadhand_forbidden, steadhand_refused_content_changed, steadhand_refused_unsupported_markup, steadhand_update_failed, or the error WordPress reported.
	 * @throws \LogicException            When called outside a write.
	 * @throws \InvalidArgumentException  When a segment is not well formed.
	 */
	public static function splice( int $post_id, string $stored_sha256, array $segments, string $preview_sha256 ): int|\WP_Error {
		self::assert_writing();

		$post = self::fresh_post( $post_id );
		if ( $post instanceof \WP_Error ) {
			return $post;
		}

		if ( ! hash_equals( $stored_sha256, hash( 'sha256', (string) $post->post_content ) ) ) {
			return self::content_changed();
		}

		$content = self::assemble( $post_id, (string) $post->post_content, $segments );
		if ( is_wp_error( $content ) ) {
			return $content;
		}

		// The plan took this hash from its own preview, and checked that preview against the content the guardrails saw. So the
		// content assembled here must be the one the guardrails saw. A different result means a filter gave a different output
		// between the steps, so nothing is written.
		if ( ! hash_equals( $preview_sha256, hash( 'sha256', $content ) ) ) {
			return self::preview_changed();
		}

		return self::save(
			$post,
			array( 'post_content' => $content ),
			array(
				'post_title'            => (string) $post->post_title,
				'post_excerpt'          => (string) $post->post_excerpt,
				'post_content_filtered' => (string) $post->post_content_filtered,
				'post_content'          => $content,
			)
		);
	}

	/**
	 * The save filters a write puts in place for its whole save, as triples of hook, function and priority: the four KSES
	 * filters, and the custom CSS strip for blocks, which core adds for users without edit_css. The custom CSS pair is
	 * used only when core has the function. Priority 8 is core's, so the strip runs before the global styles filter (9) and KSES (10).
	 *
	 * @return array<int, array{0: string, 1: string, 2: int}>
	 */
	private static function bracket_filters(): array {
		$filters = array();
		foreach ( self::KSES_FILTERS as $pair ) {
			$filters[] = array( $pair[0], $pair[1], 10 );
		}

		if ( function_exists( 'wp_strip_custom_css_from_blocks' ) ) {
			$filters[] = array( 'content_save_pre', 'wp_strip_custom_css_from_blocks', 8 );
			$filters[] = array( 'content_filtered_save_pre', 'wp_strip_custom_css_from_blocks', 8 );
		}

		return $filters;
	}

	/**
	 * Runs a step with every bracket filter that is missing added, and removes only the ones this call added. Used for every save
	 * and every filtered preview, so one list decides the filters.
	 *
	 * @param array<int, array{0: string, 1: string, 2: int}> $pairs Filters to have in place.
	 * @param callable                                        $step  The step.
	 * @return mixed What the step returned.
	 */
	private static function with_filters_added( array $pairs, callable $step ): mixed {
		$added = array();
		foreach ( $pairs as $pair ) {
			if ( false === has_filter( $pair[0], $pair[1] ) ) {
				add_filter( $pair[0], $pair[1], $pair[2] );
				$added[] = $pair;
			}
		}

		try {
			return $step();
		} finally {
			foreach ( array_reverse( $added ) as $pair ) {
				remove_filter( $pair[0], $pair[1], $pair[2] );
			}
		}
	}

	/**
	 * Runs a step while a revision of the post is saved, and makes every revision of that post, and not an autosave, mirror
	 * the post's row as it is stored at that moment: its title, content and excerpt, read again uncached. A revision is
	 * made from the row, never from anything in the call, so a revision matches the row even when the save filters would
	 * change the stored markup (the agent's KSES filters would otherwise strip what an administrator stored).
	 *
	 * Used by save() and by Snapshots::post_revision(). The filter applies to inserts of a revision whose parent is $post_id
	 * only. It is removed in finally.
	 *
	 * @param int      $post_id Post whose revisions mirror its row.
	 * @param callable $step    The step that saves the revision.
	 * @return mixed What the step returned.
	 */
	public static function with_revision_mirror( int $post_id, callable $step ): mixed {
		$mirror = static function ( array $data, array $postarr, array $unsanitized_postarr, bool $update ) use ( $post_id ): array {
			unset( $postarr, $unsanitized_postarr );

			if ( $update || 'revision' !== ( $data['post_type'] ?? null ) || (int) ( $data['post_parent'] ?? 0 ) !== $post_id ) {
				return $data;
			}

			if ( str_ends_with( (string) ( $data['post_name'] ?? '' ), '-autosave-v1' ) ) {
				return $data;
			}

			clean_post_cache( $post_id );
			$parent = get_post( $post_id );
			if ( ! $parent instanceof \WP_Post ) {
				return $data;
			}

			foreach ( array( 'post_title', 'post_excerpt', 'post_content' ) as $field ) {
				// $data is slashed here, as core passes it. Core unslashes it after this filter, so the stored bytes come back.
				$data[ $field ] = wp_slash( (string) $parent->$field );
			}

			return $data;
		};

		add_filter( 'wp_insert_post_data', $mirror, PHP_INT_MAX, 4 );
		try {
			return $step();
		} finally {
			remove_filter( 'wp_insert_post_data', $mirror, PHP_INT_MAX );
		}
	}

	/**
	 * The refusal for content that WordPress produced differently from the content that was checked. The one text for it.
	 *
	 * @return \WP_Error
	 */
	public static function preview_changed(): \WP_Error {
		return new \WP_Error(
			'steadhand_update_failed',
			__( 'The content WordPress produced for this save was not the same as the content that was checked, so nothing was changed. Try again.', 'steadhand' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * The refusal for a post whose content changed after it was read for a write. The one text for it.
	 *
	 * @return \WP_Error
	 */
	public static function content_changed(): \WP_Error {
		return new \WP_Error(
			'steadhand_refused_content_changed',
			__( 'The post changed while this call was running, so nothing was changed. Call steadhand/get-blocks again, then send the operations again.', 'steadhand' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * Reads the post uncached, with the refusals every write makes: missing, trashed or of a type that is not allowed.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Post|\WP_Error
	 */
	private static function fresh_post( int $post_id ): \WP_Post|\WP_Error {
		// Read again, uncached: the post may have been trashed or changed since the caller looked at it, and the values read
		// here are the ones put back by save().
		clean_post_cache( $post_id );
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'trash' === $post->post_status || ! PostTypes::is_allowed( $post->post_type ) ) {
			return new \WP_Error( 'steadhand_forbidden', __( 'Not found, or this agent may not access it.', 'steadhand' ), array( 'status' => 403 ) );
		}

		return $post;
	}

	/**
	 * Saves the fields with the four KSES filters in place for the whole save, and puts back the values in $keep after the save
	 * filters have run. Shared by update() and splice().
	 *
	 * A value in $keep is put back only in its field of the save's data, and only once: a nested update of this post, from a
	 * save_post listener, is filtered as usual and does not get the values again. The filter is removed in finally.
	 *
	 * @param \WP_Post              $post   The post as read for this write.
	 * @param array<string, string> $fields Fields to set.
	 * @param array<string, string> $keep   Values to put back, by field.
	 * @return int|\WP_Error
	 */
	private static function save( \WP_Post $post, array $fields, array $keep ): int|\WP_Error {
		$data       = self::dated( $fields );
		$data['ID'] = $post->ID;

		// Core gives a draft that has no date the time it is published. edit_date makes core use the date it is given, so it
		// is set only when the caller sends one. Otherwise a draft published later keeps no old date.
		if ( array_key_exists( 'post_date_gmt', $fields ) ) {
			$data['edit_date'] = true;
		}

		$target_id = (int) $post->ID;
		$post_type = (string) $post->post_type;
		$armed     = true;
		$restore   = static function ( array $data, array $postarr, array $unsanitized_postarr, bool $update ) use ( &$armed, $target_id, $post_type, $keep ): array {
			unset( $unsanitized_postarr );

			if ( ! $armed || ! $update || (int) ( $postarr['ID'] ?? 0 ) !== $target_id || ( $data['post_type'] ?? null ) !== $post_type ) {
				return $data;
			}

			// Once only: a nested update of this post, from a save_post listener, must not get these values again.
			$armed = false;
			foreach ( $keep as $field => $value ) {
				if ( array_key_exists( $field, $data ) ) {
					// $data is slashed here, as core passes it. Core unslashes it after this filter, so the stored bytes come back.
					$data[ $field ] = wp_slash( $value );
				}
			}

			return $data;
		};

		add_filter( 'wp_insert_post_data', $restore, PHP_INT_MAX, 4 );
		try {
			return self::with_filters_added(
				self::bracket_filters(),
				static fn(): int|\WP_Error => self::with_revision_mirror(
					(int) $post->ID,
					static fn(): int|\WP_Error => wp_update_post( wp_slash( $data ), true )
				)
			);
		} finally {
			remove_filter( 'wp_insert_post_data', $restore, PHP_INT_MAX );
		}
	}

	/**
	 * The content as WordPress would store it, with the save filters applied and nothing written. Used to check whether a save
	 * would change the content, before the save.
	 *
	 * The filters are the ones update() runs: the four KSES filters, with every other filter the save runs on post_content
	 * (sanitize_post_field() in the 'db' context: pre_post_content and content_save_pre, which carry the custom CSS strip,
	 * invalid entity conversion, tag balancing when enabled, and any plugin filter). The emoji step is the one wp_insert_post()
	 * runs after sanitize_post() when the content column is utf8 or utf8mb3. The result is unslashed, as wp_insert_post() unslashes
	 * before the row is written.
	 *
	 * Must be called during a write call (Pipeline::is_write_call()), because the filters it runs are the ones a write runs.
	 *
	 * @param int    $post_id Post ID. Passed to the filters, as the save passes it.
	 * @param string $content Content, unslashed.
	 * @return string The content as the save would store it.
	 * @throws \LogicException When called outside a write.
	 */
	public static function filtered_content( int $post_id, string $content ): string {
		self::assert_write_call();

		return (string) self::with_filters_added(
			self::bracket_filters(),
			static function () use ( $post_id, $content ): string {
				global $wpdb;

				$value = sanitize_post_field( 'post_content', wp_slash( $content ), $post_id, 'db' );
				$value = (string) $value;

				$charset = $wpdb->get_col_charset( $wpdb->posts, 'post_content' );
				if ( 'utf8' === $charset || 'utf8mb3' === $charset ) {
					$value = wp_encode_emoji( $value );
				}

				return wp_unslash( $value );
			}
		);
	}

	/**
	 * The post content the segments describe, with the sent runs filtered. See assemble_report().
	 *
	 * @param int                                                                       $post_id  Post ID.
	 * @param string                                                                    $stored   The stored content the segments refer to.
	 * @param array<int, array{type: string, start?: int, length?: int, text?: string}> $segments Segments, in document order.
	 * @return string
	 * @throws \LogicException           When called outside a write call.
	 * @throws \InvalidArgumentException When a segment is not well formed, or does not fit the stored content.
	 */
	public static function assemble( int $post_id, string $stored, array $segments ): string|\WP_Error {
		$report = self::assemble_report( $post_id, $stored, $segments );

		return is_wp_error( $report ) ? $report : $report['content'];
	}

	/**
	 * Builds the content the segments describe, and whether the save filters changed any sent run.
	 *
	 * A kept segment is the bytes of $stored in its range, verbatim: it is never filtered. Adjacent sent segments are one run,
	 * and each run is filtered once as a whole (filtered_content()). The segments are checked strictly first (see runs()), and
	 * anything that cannot be written safely is refused, with nothing written:
	 *
	 * 1. Stored HTML left open before a sent run (an unterminated script, comment, attribute or tag, as WP_HTML_Tag_Processor reads
	 *    it) refuses the call, because the sent markup would be read inside it.
	 * 2. A sent run that is not complete blocks after the filter refuses the call (steadhand_refused_unsupported_markup).
	 * 3. The structure of the content is checked against the stored content: every stored block that the segments keep is still
	 *    there, with its name at its new offset (see BlockEditor::preserved()).
	 *
	 * Callers are write calls, and it is called while the guardrails preview a write as well as while it is written, so it is
	 * guarded by Pipeline::is_write_call(), not is_writing().
	 *
	 * @param int                                                                       $post_id  Post ID. Passed to the filters.
	 * @param string                                                                    $stored   The stored content the segments refer to.
	 * @param array<int, array{type: string, start?: int, length?: int, text?: string}> $segments Segments, in document order.
	 * @return array{content: string, altered: bool}|\WP_Error The content, and whether a filter changed any sent run; or a refusal.
	 * @throws \LogicException           When called outside a write call.
	 * @throws \InvalidArgumentException When a segment is not well formed, or does not fit the stored content.
	 */
	public static function assemble_report( int $post_id, string $stored, array $segments ): array|\WP_Error {
		self::assert_write_call();

		$runs = self::runs( $stored, $segments );
		if ( null === BlockEditor::spans( $stored ) ) {
			return self::unsupported( __( 'Steadhand cannot read the block structure of this post the way WordPress does, so it cannot edit single blocks in it. Nothing was changed. Use steadhand/update-post with the complete new content instead.', 'steadhand' ) );
		}

		$content    = '';
		$altered    = false;
		$kept       = array();
		$sent       = array();
		$stored_end = 0;
		foreach ( $runs as $run ) {
			if ( 'kept' === $run['type'] ) {
				// A kept range that does not start where the previous kept range ended follows a removed or replaced span, or a sent
				// run. The bytes on either side were not adjacent in the stored content. Two things must hold: the assembled content
				// before the join leaves no HTML open, and so does the stored content before the range, because the removed span may
				// have been what kept the range inert (a textarea, say, that the following script was inside).
				if ( $run['start'] !== $stored_end && ( self::leaves_html_open( $content ) || self::leaves_html_open( substr( $stored, 0, $run['start'] ) ) ) ) {
					return self::unsupported( __( 'The stored content leaves HTML open where the edit removes the content between two of its parts, for example a script, a comment or an attribute that is not closed. Steadhand cannot tell how the content after the removal would be read. Nothing was changed. A person must fix the post in the block editor.', 'steadhand' ) );
				}

				$kept[]     = array(
					'start'       => $run['start'],
					'end'         => $run['start'] + $run['length'],
					'final_start' => strlen( $content ),
				);
				$content   .= substr( $stored, $run['start'], $run['length'] );
				$stored_end = $run['start'] + $run['length'];
				continue;
			}

			if ( '' === $run['text'] ) {
				continue;
			}

			if ( self::leaves_html_open( $content ) ) {
				return self::unsupported( __( 'The stored content leaves HTML open before the place where the markup you sent would go, for example a script, a comment or an attribute that is not closed. Steadhand cannot tell where the sent markup would land. Nothing was changed. A person must fix the post in the block editor.', 'steadhand' ) );
			}

			$filtered = self::filtered_content( $post_id, $run['text'] );
			if ( $filtered !== $run['text'] ) {
				$altered = true;
			}

			if ( null === BlockEditor::spans( $filtered ) ) {
				return self::unsupported( __( 'The markup you sent does not stay complete blocks once WordPress has filtered it, so nothing was changed. Send each block with its closing comment, and do not close a block you did not open.', 'steadhand' ) );
			}

			if ( ! BlockEditor::has_comment_check( $filtered ) ) {
				return self::unsupported( __( 'The markup you sent contains an HTML comment that is not a block comment, so nothing was changed. Send complete blocks only; use the core/more or core/nextpage block for a break.', 'steadhand' ) );
			}

			$sent[]   = array(
				'at'   => strlen( $content ),
				'text' => $filtered,
			);
			$content .= $filtered;
		}

		// A sent run that leaves HTML open would change how the stored bytes after it are read, so that is refused too.
		foreach ( $sent as $run ) {
			$end = $run['at'] + strlen( $run['text'] );
			if ( $end < strlen( $content ) && self::leaves_html_open( substr( $content, 0, $end ) ) ) {
				return self::unsupported( __( 'The markup you sent leaves HTML open, for example an unclosed script or textarea, so the stored content after it would be read differently. Nothing was changed. Send markup that closes what it opens.', 'steadhand' ) );
			}
		}

		// WordPress removes core/more delimiters from the content it shows, with a pattern that can run past a void delimiter. This
		// checks the whole final content: every match must be one whole delimiter, or an untouched match of the stored content.
		if ( false !== BlockEditor::display_split_problem( $content, $stored, $kept ) ) {
			return self::unsupported( __( 'This edit would make WordPress drop part of the content when it shows the post, so nothing was changed. If you sent a core/more block, send it with its opening and closing comments, not as a self-closing block.', 'steadhand' ) );
		}

		if ( ! BlockEditor::aligned( $stored, $kept, $sent, $content ) ) {
			return self::unsupported( __( 'The markup you sent, together with the stored content around it, would form a block that neither is on its own, so nothing was changed. Send complete blocks only, and do not send HTML comments that are not block comments.', 'steadhand' ) );
		}

		if ( ! BlockEditor::preserved( $stored, $kept, $content ) ) {
			return self::unsupported( __( 'The edit would change blocks it does not name, so nothing was changed. Use steadhand/update-post with the complete new content instead.', 'steadhand' ) );
		}

		return array(
			'content' => $content,
			'altered' => $altered,
		);
	}

	/**
	 * Whether the HTML before a point is left open, as WP_HTML_Tag_Processor reads it: an unterminated script, style, textarea,
	 * title, xmp or iframe, an unterminated comment, or an unterminated tag or attribute value. Fails closed when the processor
	 * or its paused_at_incomplete_token() method is missing (it is in WordPress 6.5 and later, so in every supported version).
	 *
	 * @param string $html HTML before the point.
	 */
	private static function leaves_html_open( string $html ): bool {
		if ( ! class_exists( 'WP_HTML_Tag_Processor' ) || ! method_exists( 'WP_HTML_Tag_Processor', 'paused_at_incomplete_token' ) ) {
			return true;
		}

		$processor = new \WP_HTML_Tag_Processor( $html );
		$tokens    = 0;
		while ( $processor->next_token() ) {
			++$tokens; // Read every token: the state after the last one is what matters.
		}

		return $processor->paused_at_incomplete_token();
	}

	/**
	 * A refusal for markup that cannot be written as it was sent.
	 *
	 * @param string $message Message for the caller.
	 * @return \WP_Error
	 */
	private static function unsupported( string $message ): \WP_Error {
		return new \WP_Error( 'steadhand_refused_unsupported_markup', $message, array( 'status' => 409 ) );
	}

	/**
	 * Checks the segments and merges adjacent sent segments into runs.
	 *
	 * @param string                                                                    $stored   Stored content.
	 * @param array<int, array{type: string, start?: int, length?: int, text?: string}> $segments Segments.
	 * @return array<int, array{type: string, start?: int, length?: int, text?: string}>
	 * @throws \InvalidArgumentException When a segment is not well formed, or does not fit the stored content.
	 */
	private static function runs( string $stored, array $segments ): array {
		$runs   = array();
		$cursor = 0;

		foreach ( $segments as $segment ) {
			if ( ! is_array( $segment ) ) {
				throw new \InvalidArgumentException( 'Every segment must be an array.' );
			}

			if ( 'kept' === ( $segment['type'] ?? null ) ) {
				self::assert_keys( $segment, array( 'type', 'start', 'length' ) );
				$start  = $segment['start'];
				$length = $segment['length'];
				if ( ! is_int( $start ) || ! is_int( $length ) || $start < 0 || $length < 0 ) {
					throw new \InvalidArgumentException( 'A kept segment needs a start and a length that are integers, not negative.' );
				}
				if ( $start < $cursor || $start + $length > strlen( $stored ) ) {
					throw new \InvalidArgumentException( 'Kept segments must be ascending, must not overlap, and must lie inside the stored content.' );
				}

				$cursor = $start + $length;
				$runs[] = array(
					'type'   => 'kept',
					'start'  => $start,
					'length' => $length,
				);
				continue;
			}

			if ( 'sent' === ( $segment['type'] ?? null ) ) {
				self::assert_keys( $segment, array( 'type', 'text' ) );
				if ( ! is_string( $segment['text'] ) ) {
					throw new \InvalidArgumentException( 'A sent segment needs text that is a string.' );
				}

				$last = count( $runs ) > 0 ? $runs[ count( $runs ) - 1 ] : null;
				if ( null !== $last && 'sent' === $last['type'] ) {
					$runs[ count( $runs ) - 1 ]['text'] .= $segment['text'];
				} else {
					$runs[] = array(
						'type' => 'sent',
						'text' => $segment['text'],
					);
				}
				continue;
			}

			throw new \InvalidArgumentException( 'A segment must be of type kept or sent.' );
		}

		return $runs;
	}

	/**
	 * Refuses a segment with a key it should not have, or without one it needs.
	 *
	 * @param array<string, mixed> $segment Segment.
	 * @param string[]             $keys    The keys it must have, and no others.
	 * @throws \InvalidArgumentException When the keys differ.
	 */
	private static function assert_keys( array $segment, array $keys ): void {
		$have = array_keys( $segment );
		sort( $have );
		sort( $keys );
		if ( $have !== $keys ) {
			throw new \InvalidArgumentException( 'A segment has keys other than the ones its type takes.' );
		}
	}

	/**
	 * Refuses a call made outside a write call. Unlike assert_writing(), this is true while the guardrails run, which is when
	 * the content is previewed.
	 *
	 * @throws \LogicException When Pipeline::is_write_call() is false.
	 */
	private static function assert_write_call(): void {
		if ( ! Pipeline::is_write_call() ) {
			throw new \LogicException( 'This helper may only be called during a write call.' );
		}
	}

	/**
	 * Whether a stored post's title, content or excerpt differs from what was submitted. Compares only the fields that
	 * were submitted, byte for byte. A missing post counts as different.
	 *
	 * @param int                   $post_id Post ID.
	 * @param array<string, string> $fields  Fields that were submitted, unslashed.
	 * @return bool
	 */
	public static function stored_differs( int $post_id, array $fields ): bool {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post ) {
			return true;
		}

		foreach ( array( 'post_title', 'post_content', 'post_excerpt' ) as $field ) {
			if ( array_key_exists( $field, $fields ) && (string) $fields[ $field ] !== (string) $post->$field ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Adds the local date that matches a GMT date, so the two agree. Leaves the zero date alone.
	 *
	 * @param array<string, string> $fields Fields.
	 * @return array<string, string>
	 */
	private static function dated( array $fields ): array {
		$gmt = $fields['post_date_gmt'] ?? '';
		if ( '' !== $gmt && self::ZERO_DATE !== $gmt ) {
			$fields['post_date']     = get_date_from_gmt( $gmt );
			$fields['post_date_gmt'] = $gmt;
		}

		return $fields;
	}

	/**
	 * Refuses a call made outside a write.
	 *
	 * @throws \LogicException When Pipeline::is_writing() is false.
	 */
	private static function assert_writing(): void {
		if ( ! Pipeline::is_writing() ) {
			throw new \LogicException( 'PostWriter may only be called from a write ability.' );
		}
	}

	/**
	 * Checks that every key is allowed and every value is a string.
	 *
	 * @param array<mixed, mixed> $fields  Fields.
	 * @param string[]            $allowed Allowed keys.
	 * @throws \InvalidArgumentException When a key is not allowed, or a value is not a string.
	 */
	private static function assert_fields( array $fields, array $allowed ): void {
		foreach ( $fields as $key => $value ) {
			if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) {
				throw new \InvalidArgumentException( 'PostWriter does not accept a field that is not on its allowlist.' );
			}

			if ( ! is_string( $value ) ) {
				throw new \InvalidArgumentException( 'PostWriter needs every field value to be a string.' );
			}
		}
	}
}
