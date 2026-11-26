<?php
/**
 * Handles the Guardrails screen's Save button: checks the request, saves the presets, and redirects back.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Admin;

use Steadhand\Agents;
use Steadhand\Guardrails\Presets;

defined( 'ABSPATH' ) || exit;

/**
 * The admin-post handler for steadhand_save_presets (logged-in users only: there is no nopriv variant). Order: POST only; the
 * nonce; then, on a single site, manage_options and no agent role; then the marker field. Only the known preset IDs are read from
 * the request, and each is saved as true or false. The result is a redirect to the Guardrails screen with one fixed code in the
 * query string. The screen prints one notice for each code, and nothing else from the request is printed.
 */
final class GuardrailsHandler {

	/** The admin-post action. */
	public const ACTION = 'steadhand_save_presets';

	/** The nonce action of the form. */
	public const NONCE_ACTION = 'steadhand_save_presets';

	/** The form field that holds the checked preset IDs, as an array. */
	public const FIELD = 'presets';

	/** The marker field the form always sends, so that no box checked means every preset is off. */
	public const MARKER = 'presets_present';

	/** The query key the redirect uses for the result code. */
	public const QUERY_KEY = 'guardrails';

	/**
	 * The codes the redirect may carry. saved: the presets were stored. forbidden: the request was refused for this user, or the site
	 * is a multisite network, so nothing changed. failed: the presets were not stored, or the form did not carry the marker.
	 */
	public const CODES = array( 'saved', 'forbidden', 'failed' );

	/**
	 * Constructor.
	 *
	 * @param bool|null $network Test seam only. Null, which Plugin always passes, means is_multisite().
	 */
	public function __construct( private ?bool $network = null ) {}

	/**
	 * The admin-post callback. Redirects to the Guardrails screen and stops the request.
	 *
	 * @return void
	 */
	public function handle(): void {
		$url = $this->process();

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Checks the request and saves the presets. A refused request whose nonce is wrong, or a GET, ends with wp_die() and changes
	 * nothing. Returns the redirect URL for the other outcomes. Separate from handle() so that it can be tested.
	 *
	 * @return string The Guardrails screen, with the result code in the query string.
	 */
	public function process(): string {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Sanitized on this line; it is only compared.
		if ( 'POST' !== $method ) {
			wp_die( esc_html__( 'The presets must be saved from the form.', 'steadhand' ), esc_html__( 'Not allowed', 'steadhand' ), array( 'response' => 405 ) );
		}

		$post  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked on the next line, before anything is read or saved.
		$nonce = isset( $post['_wpnonce'] ) && is_string( $post['_wpnonce'] ) ? $post['_wpnonce'] : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Your request could not be verified. Reload the page and try again.', 'steadhand' ), esc_html__( 'Not allowed', 'steadhand' ), array( 'response' => 403 ) );
		}

		if ( $this->is_network() || ! current_user_can( 'manage_options' ) || Agents::holds_agent_role( wp_get_current_user() ) ) {
			return $this->redirect_to( 'forbidden' );
		}

		// A form without the marker is not this screen's form, so nothing is saved.
		if ( ! isset( $post[ self::MARKER ] ) ) {
			return $this->redirect_to( 'failed' );
		}

		// A presets field that is not a list is a malformed request. Reading it as empty would turn every preset off, so nothing is saved.
		if ( isset( $post[ self::FIELD ] ) && ! is_array( $post[ self::FIELD ] ) ) {
			return $this->redirect_to( 'failed' );
		}

		$checked = array();
		$posted  = isset( $post[ self::FIELD ] ) ? $post[ self::FIELD ] : array();
		foreach ( $posted as $value ) {
			if ( is_string( $value ) ) {
				$checked[] = $value;
			}
		}

		// Every known ID gets a value, and an ID that is not in the list is never read.
		$state = array();
		foreach ( Presets::ids() as $id ) {
			$state[ $id ] = in_array( $id, $checked, true );
		}

		$before = Presets::states();
		if ( ! Presets::save( $state ) ) {
			return $this->redirect_to( 'failed' );
		}

		$after = Presets::states();
		if ( $after !== $before ) {
			$this->announce( $after, $before, get_current_user_id() );
		}

		return $this->redirect_to( 'saved' );
	}

	/**
	 * Whether this install is a multisite network. The presets are refused there (SPEC D13).
	 *
	 * @return bool
	 */
	private function is_network(): bool {
		return $this->network ?? is_multisite();
	}

	/**
	 * Runs the steadhand_presets_changed action (docs/HOOKS.md). The save is already stored, so a listener that throws changes nothing.
	 *
	 * @param array<string, bool> $current  The presets after the save.
	 * @param array<string, bool> $previous The presets before the save.
	 * @param int                 $user_id  The administrator who saved them.
	 * @return void
	 */
	private function announce( array $current, array $previous, int $user_id ): void {
		try {
			/**
			 * Fires after the Guardrails screen saved a change to the presets.
			 *
			 * @param array<string,bool> $current  Every known preset, as id => enabled, after the save.
			 * @param array<string,bool> $previous Every known preset, as id => enabled, before the save.
			 * @param int                $user_id  The administrator who saved them.
			 */
			do_action( 'steadhand_presets_changed', $current, $previous, $user_id );
		} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The comment below says why the catch is empty.
			// A listener must not undo the save or break the redirect.
		}
	}

	/**
	 * The Guardrails screen, with one result code in the query string.
	 *
	 * @param string $code One of CODES.
	 * @return string
	 */
	private function redirect_to( string $code ): string {
		return add_query_arg(
			array(
				'page'          => GuardrailsScreen::SLUG,
				self::QUERY_KEY => $code,
			),
			admin_url( 'admin.php' )
		);
	}
}
