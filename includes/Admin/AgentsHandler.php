<?php
/**
 * The state-changing actions of the Agents screen: create an agent, add or revoke its Application Passwords, and set its capabilities.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Admin;

use Steadhand\Abilities\Registry;
use Steadhand\Agents;
use Steadhand\Capabilities;
use Steadhand\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Runs the four actions of the Agents screen. Every action checks, in this order: the nonce, manage_options, that the caller holds no
 * agent role, and the native WordPress capability for the act (create_users, or edit_user for the target). A target must then be a
 * dedicated agent (Agents::is_agent()). Nothing is changed before every check passes. An authorisation failure ends the request with
 * wp_die(); a validation failure or a refusal returns a result whose code names it.
 *
 * The result is an array: 'code' (one of CODES), 'ok' (true for the success codes), 'user_id' and 'secret' (for create and add-password
 * only: the new password, shown once by the screen; it is never stored, logged, passed to a hook or put in a redirect).
 */
final class AgentsHandler {

	/** The hidden form field that names the action. Its value is one of the ACTION_* constants. */
	public const FIELD = 'steadhand_agents_action';

	/** The field values of the four actions. */
	public const ACTION_CREATE       = 'create';
	public const ACTION_ADD_PASSWORD = 'add_password';
	public const ACTION_REVOKE       = 'revoke_password';
	public const ACTION_REVOKE_ALL   = 'revoke_all';
	public const ACTION_CAPABILITIES = 'set_capabilities';

	/** The most Application Passwords one agent may hold. */
	public const MAX_PASSWORDS = 10;

	/** The longest label (an Application Password name), in characters. */
	public const LABEL_MAX = 60;

	/** The shape of a username that is accepted. Nothing is sanitised: a value that does not match is refused. */
	public const USERNAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,59}\z/';

	/** The shape of an Application Password UUID, as core generates it (lower case). */
	public const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

	/** Result codes of a successful action. */
	public const SUCCESS_CODES = array( 'created', 'password_added', 'password_revoked', 'passwords_revoked', 'capabilities_saved' );

	/**
	 * Every result code the screen can show. The codes that come back in a redirect (revoke and capabilities) are whitelisted by the
	 * screen through this list. Each code is printed as one fixed, translated notice, never as the request's own text.
	 */
	public const CODES = array(
		'created',
		'password_added',
		'password_revoked',
		'passwords_revoked',
		'capabilities_saved',
		'capabilities_mismatch',
		'invalid_username',
		'username_taken',
		'invalid_label',
		'label_taken',
		'too_many_passwords',
		'app_passwords_unavailable',
		'not_agent',
		'password_not_found',
		'create_failed',
		'password_failed',
		'capabilities_failed',
	);

	/**
	 * Constructor.
	 *
	 * @param Registry $registry The ability registry. Its abilities decide which capabilities the screen offers.
	 */
	public function __construct( private Registry $registry ) {}

	/**
	 * The nonce action for one of the actions. A target user is named in the action where there is one, so a nonce for one user
	 * is refused for another.
	 *
	 * @param string $action  One of the ACTION_* constants.
	 * @param int    $user_id Target user ID, or 0 for create.
	 * @return string
	 */
	public static function nonce_action( string $action, int $user_id = 0 ): string {
		return 0 === $user_id ? 'steadhand_agents_' . $action : 'steadhand_agents_' . $action . '_' . $user_id;
	}

	/**
	 * The capabilities the screen offers: steadhand_connect, plus every Steadhand capability that a registered ability needs.
	 *
	 * @return string[] In canonical order.
	 */
	public function offered_capabilities(): array {
		$offered = array_unique( array_merge( array( Capabilities::CONNECT ), $this->registry->capabilities_used() ) );

		return array_values( array_intersect( Capabilities::all(), $offered ) );
	}

	/**
	 * Creates a dedicated agent with its first Application Password. The agent has the role's default capabilities only.
	 *
	 * @param string $nonce    The nonce from the form.
	 * @param string $username The requested login name, after wp_unslash().
	 * @param string $label    The label of the first Application Password, after wp_unslash().
	 * @return array{code: string, ok: bool, user_id: int, secret: ?array{username: string, password: string}}
	 */
	public function create( string $nonce, string $username, string $label ): array {
		$this->guard( $nonce, self::nonce_action( self::ACTION_CREATE ), 'create_users' );

		if ( 1 !== preg_match( self::USERNAME_PATTERN, $username ) || ! validate_username( $username ) ) {
			return $this->result( 'invalid_username' );
		}

		if ( username_exists( $username ) ) {
			return $this->result( 'username_taken' );
		}

		if ( ! self::is_valid_label( $label ) ) {
			return $this->result( 'invalid_label' );
		}

		if ( ! wp_is_application_passwords_available() ) {
			return $this->result( 'app_passwords_unavailable' );
		}

		$user_id = wp_insert_user(
			array(
				'user_login'           => $username,
				// Random and never shown or stored elsewhere. The agent cannot sign in with it, because Lockdown refuses password sign-in.
				'user_pass'            => wp_generate_password( 64, true, true ),
				'role'                 => Roles::ROLE,
				'show_admin_bar_front' => 'false',
			)
		);

		if ( is_wp_error( $user_id ) || $user_id < 1 ) {
			return $this->result( 'create_failed' );
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User || ! wp_is_application_passwords_available_for_user( $user ) ) {
			$this->remove_user( $user_id );
			return $this->result( 'app_passwords_unavailable' );
		}

		$created = \WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => $label ) );
		if ( is_wp_error( $created ) ) {
			$this->remove_user( $user_id );
			return $this->result( 'password_failed' );
		}

		list( $password ) = $created;

		try {
			/**
			 * Fires after a Steadhand agent was created with its first Application Password.
			 *
			 * The password is not passed. Listeners can read the user and the label, and nothing else of the secret.
			 *
			 * @param int    $user_id The new agent's user ID.
			 * @param string $label   The label of its first Application Password (the agent label in the change log).
			 */
			do_action( 'steadhand_agent_created', $user_id, $label );
		} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The comment below says why the catch is empty.
			// A listener must not break the action. The agent exists and its password is returned to the screen either way.
		}

		return $this->result(
			'created',
			$user_id,
			array(
				'username' => $username,
				'password' => $password,
			)
		);
	}

	/**
	 * Adds an Application Password to a dedicated agent.
	 *
	 * @param string $nonce   The nonce from the form.
	 * @param int    $user_id The agent.
	 * @param string $label   The new password's label, after wp_unslash().
	 * @return array{code: string, ok: bool, user_id: int, secret: ?array{username: string, password: string}}
	 */
	public function add_password( string $nonce, int $user_id, string $label ): array {
		$this->guard( $nonce, self::nonce_action( self::ACTION_ADD_PASSWORD, $user_id ), 'edit_user', $user_id );

		$user = $this->agent( $user_id );
		if ( null === $user ) {
			return $this->result( 'not_agent' );
		}

		if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
			return $this->result( 'app_passwords_unavailable' );
		}

		if ( ! self::is_valid_label( $label ) ) {
			return $this->result( 'invalid_label' );
		}

		if ( count( \WP_Application_Passwords::get_user_application_passwords( $user_id ) ) >= self::MAX_PASSWORDS ) {
			return $this->result( 'too_many_passwords' );
		}

		if ( \WP_Application_Passwords::application_name_exists_for_user( $user_id, $label ) ) {
			return $this->result( 'label_taken' );
		}

		$created = \WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => $label ) );
		if ( is_wp_error( $created ) ) {
			return $this->result( 'password_failed', $user_id );
		}

		list( $password ) = $created;

		return $this->result(
			'password_added',
			$user_id,
			array(
				'username' => $user->user_login,
				'password' => $password,
			)
		);
	}

	/**
	 * Revokes one Application Password of a dedicated agent. The UUID must be a well-formed UUID that belongs to that agent.
	 *
	 * @param string $nonce   The nonce from the form.
	 * @param int    $user_id The agent.
	 * @param string $uuid    The password's UUID, after wp_unslash().
	 * @return array{code: string, ok: bool, user_id: int, secret: null}
	 */
	public function revoke( string $nonce, int $user_id, string $uuid ): array {
		$this->guard( $nonce, self::nonce_action( self::ACTION_REVOKE, $user_id ), 'edit_user', $user_id );

		if ( null === $this->agent( $user_id ) ) {
			return $this->result( 'not_agent' );
		}

		if ( 1 !== preg_match( self::UUID_PATTERN, $uuid ) || null === \WP_Application_Passwords::get_user_application_password( $user_id, $uuid ) ) {
			return $this->result( 'password_not_found', $user_id );
		}

		$deleted = \WP_Application_Passwords::delete_application_password( $user_id, $uuid );
		if ( is_wp_error( $deleted ) ) {
			return $this->result( 'password_failed', $user_id );
		}

		return $this->result( 'password_revoked', $user_id );
	}

	/**
	 * Revokes every Application Password of a dedicated agent.
	 *
	 * @param string $nonce   The nonce from the form.
	 * @param int    $user_id The agent.
	 * @return array{code: string, ok: bool, user_id: int, secret: null}
	 */
	public function revoke_all( string $nonce, int $user_id ): array {
		$this->guard( $nonce, self::nonce_action( self::ACTION_REVOKE_ALL, $user_id ), 'edit_user', $user_id );

		if ( null === $this->agent( $user_id ) ) {
			return $this->result( 'not_agent' );
		}

		\WP_Application_Passwords::delete_all_application_passwords( $user_id );

		return $this->result( 'passwords_revoked', $user_id );
	}

	/**
	 * Sets the capabilities of a dedicated agent. $posted holds the names of the boxes that were checked. Only offered capabilities are
	 * read from it; every other name is ignored. Each offered capability is set to an explicit true or false, and the stored value is
	 * read back to check it.
	 *
	 * @param string   $nonce   The nonce from the form.
	 * @param int      $user_id The agent.
	 * @param string[] $posted  The checked capability names.
	 * @return array{code: string, ok: bool, user_id: int, secret: null}
	 */
	public function set_capabilities( string $nonce, int $user_id, array $posted ): array {
		$this->guard( $nonce, self::nonce_action( self::ACTION_CAPABILITIES, $user_id ), 'edit_user', $user_id );

		$user = $this->agent( $user_id );
		if ( null === $user ) {
			return $this->result( 'not_agent' );
		}

		$checked = array_values( array_filter( $posted, 'is_string' ) );
		$values  = array();
		foreach ( $this->offered_capabilities() as $capability ) {
			$values[ $capability ] = in_array( $capability, $checked, true );
		}

		foreach ( $values as $capability => $grant ) {
			$user->add_cap( $capability, $grant );
		}

		$fresh = get_userdata( $user_id );
		if ( ! $fresh instanceof \WP_User ) {
			return $this->result( 'capabilities_failed', $user_id );
		}

		foreach ( $values as $capability => $grant ) {
			if ( Capabilities::user_has( $fresh, $capability ) !== $grant ) {
				return $this->result( 'capabilities_mismatch', $user_id );
			}
		}

		try {
			/**
			 * Fires after an agent's capabilities were saved.
			 *
			 * @param int                $user_id      The agent.
			 * @param array<string,bool> $capabilities The offered capabilities and the values they were saved with.
			 */
			do_action( 'steadhand_agent_capabilities_changed', $user_id, $values );
		} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The comment below says why the catch is empty.
			// A listener must not break the action. The capabilities are saved either way.
		}

		return $this->result( 'capabilities_saved', $user_id );
	}

	/**
	 * Whether a label may name an Application Password: 1 to 60 characters, one line, no control characters, valid UTF-8, no
	 * leading or trailing spaces, and unchanged by sanitize_text_field(). A label that would be changed is refused, not fixed.
	 *
	 * @param string $label The label, after wp_unslash().
	 * @return bool
	 */
	public static function is_valid_label( string $label ): bool {
		if ( '' === $label || trim( $label ) !== $label || ! mb_check_encoding( $label, 'UTF-8' ) ) {
			return false;
		}

		if ( mb_strlen( $label ) > self::LABEL_MAX || 1 === preg_match( '/[\x00-\x1F\x7F]/', $label ) ) {
			return false;
		}

		return sanitize_text_field( $label ) === $label;
	}

	/**
	 * Checks the request before any change. Nonce first, then manage_options, then the agent-role rule, then the native capability.
	 *
	 * @param string $nonce        The nonce from the form.
	 * @param string $nonce_action The nonce action the form was made with.
	 * @param string $capability   The native WordPress capability for the act.
	 * @param int    $object_id    The target user for edit_user, or 0 for a check without an object.
	 * @return void Returns only when every check passes. Otherwise it ends the request with wp_die().
	 */
	private function guard( string $nonce, string $nonce_action, string $capability, int $object_id = 0 ): void {
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_die( esc_html__( 'Your request could not be verified. Reload the page and try again.', 'steadhand' ), esc_html__( 'Not allowed', 'steadhand' ), array( 'response' => 403 ) );
		}

		if ( ! current_user_can( 'manage_options' ) || Agents::holds_agent_role( wp_get_current_user() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'steadhand' ), esc_html__( 'Forbidden', 'steadhand' ), array( 'response' => 403 ) );
		}

		$allowed = 0 === $object_id ? current_user_can( $capability ) : current_user_can( $capability, $object_id );
		if ( ! $allowed ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'steadhand' ), esc_html__( 'Forbidden', 'steadhand' ), array( 'response' => 403 ) );
		}
	}

	/**
	 * The target as a dedicated agent, or null: a user ID that is not a dedicated agent (an administrator, a mixed-role user, a
	 * user that does not exist, or ID 0) is refused by every action.
	 *
	 * @param int $user_id The target user ID.
	 * @return \WP_User|null
	 */
	private function agent( int $user_id ): ?\WP_User {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;

		return $user instanceof \WP_User && Agents::is_agent( $user ) ? $user : null;
	}

	/**
	 * Deletes a user this request just created, after a failed step, so no half-made agent stays.
	 *
	 * @param int $user_id The user ID.
	 * @return void
	 */
	private function remove_user( int $user_id ): void {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		wp_delete_user( $user_id );
	}

	/**
	 * Builds a result.
	 *
	 * @param string                                         $code    One of CODES.
	 * @param int                                            $user_id The target user ID, or 0.
	 * @param array{username: string, password: string}|null $secret  The new password, for create and add-password only.
	 * @return array{code: string, ok: bool, user_id: int, secret: ?array{username: string, password: string}}
	 */
	private function result( string $code, int $user_id = 0, ?array $secret = null ): array {
		return array(
			'code'    => $code,
			'ok'      => in_array( $code, self::SUCCESS_CODES, true ),
			'user_id' => $user_id,
			'secret'  => $secret,
		);
	}
}
