<?php
/**
 * Handles the Undo button of the change-log screen: checks the request, runs the administrator's undo, and redirects back.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Admin;

use Steadhand\Log\DbLogger;
use Steadhand\Pipeline\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * The admin-post handler for steadhand_undo_entry (logged-in users only: there is no nopriv variant). Order: POST only; the entry
 * ID as digits only; the nonce for that entry; manage_options; then Pipeline::run_admin(). The result is a redirect to the entry's
 * single view with one short code in the query string. The codes are a fixed list (CODES), and the screen prints one fixed notice
 * for each. Nothing from the request is printed.
 */
final class UndoHandler {

	/** The admin-post action. The request carries the entry ID in the field entry. */
	public const ACTION = 'steadhand_undo_entry';

	/** The nonce action prefix: the nonce is bound to one entry ID. */
	public const NONCE_PREFIX = 'steadhand_undo_entry_';

	/**
	 * The short codes the redirect may carry in its undo query value. The screen ignores any other value.
	 *
	 * Each code says what happened: done (the change was undone), changed (refused because the post changed after the change),
	 * not_undoable (refused: nothing to restore, or already undone), forbidden (refused for this administrator), partial (the
	 * save may have changed the post), log (the change log could not record the undo, so it may or may not have happened) and
	 * failed (refused or failed, with nothing known to have changed, and the post should be checked) and locked (refused because
	 * another user is editing the post, so nothing was changed).
	 */
	public const CODES = array( 'done', 'changed', 'not_undoable', 'forbidden', 'partial', 'log', 'failed', 'locked' );

	/**
	 * The ability that undoes the entry.
	 *
	 * @var UndoEntry
	 */
	private UndoEntry $ability;

	/**
	 * Constructor.
	 *
	 * @param Pipeline $pipeline The pipeline that runs the undo (run_admin()).
	 * @param DbLogger $logger   The change log, which the undo reads and marks.
	 */
	public function __construct( private Pipeline $pipeline, DbLogger $logger ) {
		$this->ability = new UndoEntry( $logger );
	}

	/**
	 * The admin-post callback. Redirects to the entry's single view and stops the request.
	 *
	 * @return void
	 */
	public function handle(): void {
		$url = $this->process();

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Checks the request and runs the undo. Every refusal that is made before the undo ends the request with wp_die(), so nothing
	 * is changed. Returns the redirect URL for the outcome. Separate from handle() so that it can be tested.
	 *
	 * @return string The single view of the entry, with the code of the outcome in the undo query value.
	 */
	public function process(): string {
		$method = strtoupper( sanitize_text_field( wp_unslash( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Sanitized on this line; it is only compared.
		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'The undo must be sent as a form.', 'steadhand' ), esc_html__( 'Not allowed', 'steadhand' ), array( 'response' => 405 ) );
		}

		// The entry is read before the nonce check only to name the nonce; nothing is done until check_admin_referer() passes.
		$raw = isset( $_POST['entry'] ) ? sanitize_text_field( wp_unslash( $_POST['entry'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked by check_admin_referer() below, before anything is done.
		$id  = is_string( $raw ) && 1 === preg_match( '/^\d{1,18}\z/', $raw ) ? (int) $raw : 0;
		if ( $id < 1 ) {
			wp_die( esc_html__( 'The change to undo is not valid.', 'steadhand' ), esc_html__( 'Not allowed', 'steadhand' ), array( 'response' => 400 ) );
		}

		check_admin_referer( self::NONCE_PREFIX . $id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'steadhand' ), esc_html__( 'Forbidden', 'steadhand' ), array( 'response' => 403 ) );
		}

		$result = $this->pipeline->run_admin( $this->ability, array( 'id' => $id ) );

		$code = 'failed';
		if ( is_array( $result ) ) {
			$code = 'done';
		} elseif ( $result instanceof \WP_Error ) {
			$code = self::code_for( $result->get_error_code() );
		}

		return $this->entry_url( $id, $code );
	}

	/**
	 * The notice code for an error code of the undo. Every code not listed is 'failed', which says the post should be checked.
	 *
	 * @param string $error The WP_Error code.
	 * @return string One of CODES, other than 'done'.
	 */
	public static function code_for( string $error ): string {
		return match ( $error ) {
			'steadhand_refused_changed_since'                                      => 'changed',
			'steadhand_refused_not_undoable', 'steadhand_refused_invalid_template' => 'not_undoable',
			'steadhand_forbidden'                                                 => 'forbidden',
			'steadhand_refused_locked'                                            => 'locked',
			// steadhand_error (the check could not finish) maps to partial, although nothing may have changed. That is the safe
			// direction: the administrator is told to check the post, which is always right when the outcome is unknown.
			'steadhand_update_partial', 'steadhand_error'                         => 'partial',
			'steadhand_log_failed', 'steadhand_log_incomplete'                    => 'log',
			default                                                               => 'failed',
		};
	}

	/**
	 * The single view of an entry, with the outcome code.
	 *
	 * @param int    $id   Entry ID.
	 * @param string $code One of CODES.
	 * @return string
	 */
	private function entry_url( int $id, string $code ): string {
		return add_query_arg(
			array(
				'page'  => LogScreen::SLUG,
				'entry' => (string) $id,
				'undo'  => $code,
			),
			admin_url( 'admin.php' )
		);
	}
}
