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
 * Runs the five actions of the Agents screen. Every action checks, in this order: the nonce, that the site is not a multisite network,
 * manage_options, that the caller holds no agent role, list_users, and the native WordPress capability for the act (create_users, or
 * edit_user for the target). A target must then be a dedicated agent (Agents::is_agent()). Nothing is changed before every check passes.
 * An authorisation failure ends the request with wp_die(); a validation failure or a refusal returns a result whose code names it.
 *
 * The result is an array: 'code' (one of CODES), 'ok' (true for the success codes), 'user_id' and 'secret' (for create and add-password
 * only: the new password, shown once by the screen; it is never stored, logged, passed to a hook or put in a redirect).
 */
final class AgentsHandler {

	/** The hidden form field that names the action. Its value is one of the ACTION_* constants. */
	public const FIELD = 'steadhand_agents_action';

	/** The field values of the five actions. */
	public const ACTION_CREATE       = 'create';
	public const ACTION_ADD_PASSWORD = 'add_password';
	public const ACTION_REVOKE       = 'revoke_password';
	public const ACTION_REVOKE_ALL   = 'revoke_all';
	public const ACTION_CAPABILITIES = 'set_capabilities';

	/** The most Application Passwords one agent may hold. */
	public const MAX_PASSWORDS = 10;

	/** The longest label (an Application Password name), in characters (code points, counted with mb_strlen()). */
	public const LABEL_MAX = 60;

	/** The shape of a username that is accepted. Nothing is sanitised: a value that does not match is refused. */
	public const USERNAME_PATTERN = '/^[a-z0-9][a-z0-9_-]{0,59}\z/';

	/** The shape of an Application Password UUID, as core generates it (lower case). */
	public const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

	/** Result codes of a successful action. */
	public const SUCCESS_CODES = array( 'created', 'password_added', 'password_revoked', 'passwords_revoked', 'capabilities_saved' );

	/**
	 * Every result code the screen can show. Each code is printed as one fixed, translated notice, never as the request's own text.
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
		'role_missing',
		'multisite',
	);

	/**
	 * The codes a redirect can carry: the results of revoke, revoke-all and set-capabilities. The screen reads only these from the
	 * ?agents= query string. The codes of the one-time panel (created, password_added and the refusals of those two actions) come only
	 * from the response to their own POST, so a URL cannot show them.
	 */
	public const REDIRECT_CODES = array(
		'password_revoked',
		'passwords_revoked',
		'password_not_found',
		'password_failed',
		'not_agent',
		'capabilities_saved',
		'capabilities_mismatch',
		'capabilities_failed',
		'multisite',
	);

	/**
	 * Constructor.
	 *
	 * @param Registry  $registry The ability registry. Its abilities decide which capabilities the screen offers.
	 * @param bool|null $network  Test seam only. Null, the value Plugin always uses, asks is_multisite(). A test passes true to take the
	 *                            multisite branch on a single-site test install. It is not a filter, and nothing in production sets it.
	 */
	public function __construct( private Registry $registry, private ?bool $network = null ) {}

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
	 * Whether this install is a multisite network. Agent management is not available on a network (SPEC D13), so every action is refused.
	 *
	 * @return bool
	 */
	public function is_network(): bool {
		return $this->network ?? is_multisite();
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
	 * The new user is checked after each write. It must hold the agent role and nothing else, and the login must be the one posted.
	 * A failed check deletes the user (and revokes the password, if one was made) and refuses. No password is returned then.
	 *
	 * @param string $nonce    The nonce from the form.
	 * @param string $username The requested login name, after wp_unslash().
	 * @param string $label    The label of the first Application Password, after wp_unslash().
	 * @return array{code: string, ok: bool, user_id: int, secret: ?array{username: string, password: string}}
	 */
	public function create( string $nonce, string $username, string $label ): array {
		$refused = $this->guard( $nonce, self::nonce_action( self::ACTION_CREATE ), 'create_users' );
		if ( null !== $refused ) {
			return $refused;
		}

		if ( 1 !== preg_match( self::USERNAME_PATTERN, $username ) || ! validate_username( $username ) ) {
			return $this->result( 'invalid_username' );
		}

		if ( username_exists( $username ) ) {
			return $this->result( 'username_taken' );
		}

		if ( ! self::is_valid_label( $label ) ) {
			return $this->result( 'invalid_label' );
		}

		// Without the role, wp_insert_user() gives the new user no role at all. Refuse before anything is created.
		if ( null === get_role( Roles::ROLE ) ) {
			return $this->result( 'role_missing' );
		}

		if ( ! wp_is_application_passwords_available() ) {
			return $this->result( 'app_passwords_unavailable' );
		}

		$inserted = wp_insert_user(
			array(
				'user_login'           => $username,
				// Random and never shown or stored elsewhere. The agent cannot sign in with it, because Lockdown refuses password sign-in.
				'user_pass'            => wp_generate_password( 64, true, true ),
				'role'                 => Roles::ROLE,
				'show_admin_bar_front' => 'false',
			)
		);

		if ( is_wp_error( $inserted ) || $inserted < 1 ) {
			return $this->result( 'create_failed' );
		}

		$user_id = (int) $inserted;

		// Two requests can pass username_exists() together, and user_login has no unique index. Count the exact login again.
		if ( $this->login_count( $username ) > 1 ) {
			$this->remove_user( $user_id );
			return $this->result( 'username_taken' );
		}

		$user = $this->fresh_user( $user_id );
		if ( null === $user || $user->user_login !== $username || ! $this->holds_only_agent_role( $user ) ) {
			$this->remove_user( $user_id );
			return $this->result( 'create_failed' );
		}

		if ( ! wp_is_application_passwords_available_for_user( $user ) ) {
			$this->remove_user( $user_id );
			return $this->result( 'app_passwords_unavailable' );
		}

		$created = \WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => $label ) );
		if ( is_wp_error( $created ) ) {
			$this->remove_user( $user_id );
			return $this->result( 'password_failed' );
		}

		list( $password, $item ) = $created;

		// A listener of the password action, or of anything before it, may have changed the user. Check once more, and if the check
		// fails, revoke the password this request made, delete the user and show nothing.
		$verified = $this->fresh_user( $user_id );
		if ( null === $verified || $verified->user_login !== $username || ! $this->holds_only_agent_role( $verified ) ) {
			\WP_Application_Passwords::delete_application_password( $user_id, (string) $item['uuid'] );
			$this->remove_user( $user_id );
			return $this->result( 'create_failed' );
		}

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
				'username' => $verified->user_login,
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
		$refused = $this->guard( $nonce, self::nonce_action( self::ACTION_ADD_PASSWORD, $user_id ), 'edit_user', $user_id );
		if ( null !== $refused ) {
			return $refused;
		}

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

		list( $password, $item ) = $created;
		$uuid                    = (string) $item['uuid'];

		// Re-check after the write. Two requests can pass the limits together, and the target may have changed. A failed check revokes
		// the password this request made and shows nothing.
		$fresh = $this->fresh_user( $user_id );
		if ( null === $fresh || ! Agents::is_agent( $fresh ) ) {
			\WP_Application_Passwords::delete_application_password( $user_id, $uuid );
			return $this->result( 'not_agent', $user_id );
		}

		$passwords = \WP_Application_Passwords::get_user_application_passwords( $user_id );
		if ( count( $passwords ) > self::MAX_PASSWORDS ) {
			\WP_Application_Passwords::delete_application_password( $user_id, $uuid );
			return $this->result( 'too_many_passwords', $user_id );
		}

		$same_label = count(
			array_filter(
				$passwords,
				static fn( array $saved ): bool => strtolower( (string) $saved['name'] ) === strtolower( $label )
			)
		);
		if ( $same_label > 1 ) {
			\WP_Application_Passwords::delete_application_password( $user_id, $uuid );
			return $this->result( 'label_taken', $user_id );
		}

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
		$refused = $this->guard( $nonce, self::nonce_action( self::ACTION_REVOKE, $user_id ), 'edit_user', $user_id );
		if ( null !== $refused ) {
			return $refused;
		}

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
		$refused = $this->guard( $nonce, self::nonce_action( self::ACTION_REVOKE_ALL, $user_id ), 'edit_user', $user_id );
		if ( null !== $refused ) {
			return $refused;
		}

		if ( null === $this->agent( $user_id ) ) {
			return $this->result( 'not_agent' );
		}

		\WP_Application_Passwords::delete_all_application_passwords( $user_id );

		return $this->result( 'passwords_revoked', $user_id );
	}

	/**
	 * Sets the capabilities of a dedicated agent. $posted holds the names of the boxes that were checked. Only offered capabilities are
	 * read from it; every other name is ignored. Each offered capability is set to an explicit true or false, and the stored value is
	 * read back to check it. The user is re-read just before the writes and again after them, and must be a dedicated agent both times.
	 *
	 * @param string   $nonce   The nonce from the form.
	 * @param int      $user_id The agent.
	 * @param string[] $posted  The checked capability names.
	 * @return array{code: string, ok: bool, user_id: int, secret: null}
	 */
	public function set_capabilities( string $nonce, int $user_id, array $posted ): array {
		$refused = $this->guard( $nonce, self::nonce_action( self::ACTION_CAPABILITIES, $user_id ), 'edit_user', $user_id );
		if ( null !== $refused ) {
			return $refused;
		}

		if ( null === $this->agent( $user_id ) ) {
			return $this->result( 'not_agent' );
		}

		$checked = array_values( array_filter( $posted, 'is_string' ) );
		$values  = array();
		foreach ( $this->offered_capabilities() as $capability ) {
			$values[ $capability ] = in_array( $capability, $checked, true );
		}

		$current = $this->fresh_user( $user_id );
		if ( null === $current || ! Agents::is_agent( $current ) ) {
			return $this->result( 'not_agent', $user_id );
		}

		foreach ( $values as $capability => $grant ) {
			$current->add_cap( $capability, $grant );
		}

		$fresh = $this->fresh_user( $user_id );
		if ( null === $fresh ) {
			return $this->result( 'capabilities_failed', $user_id );
		}

		if ( ! Agents::is_agent( $fresh ) ) {
			return $this->result( 'not_agent', $user_id );
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
	 * Whether a label may name an Application Password. It is 1 to 60 characters (code points, by mb_strlen()) on one line, and it
	 * must be valid UTF-8 with no control, format or line- or paragraph-separator characters (\p{Cc}, \p{Cf}, \p{Zl}, \p{Zp}), so no
	 * right-to-left override, zero-width character or NUL. It may not start or end with a separator or space character (\p{Z}, which
	 * includes NBSP and U+3000). It must also be unchanged by sanitize_text_field(), which removes markup. A label that would be changed
	 * is refused, not fixed.
	 *
	 * @param string $label The label, after wp_unslash().
	 * @return bool
	 */
	public static function is_valid_label( string $label ): bool {
		// preg_match() returns false, not 1, for a string that is not valid UTF-8.
		if ( '' === $label || 1 !== preg_match( '//u', $label ) ) {
			return false;
		}

		if ( mb_strlen( $label, 'UTF-8' ) > self::LABEL_MAX ) {
			return false;
		}

		if ( 1 === preg_match( '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $label ) ) {
			return false;
		}

		if ( 1 === preg_match( '/^\p{Z}|\p{Z}\z/u', $label ) ) {
			return false;
		}

		return sanitize_text_field( $label ) === $label;
	}

	/**
	 * Checks the request before any change. Nonce first, then the multisite refusal, then manage_options, then the agent-role rule, then
	 * list_users, then the native capability.
	 *
	 * @param string $nonce        The nonce from the form.
	 * @param string $nonce_action The nonce action the form was made with.
	 * @param string $capability   The native WordPress capability for the act.
	 * @param int    $object_id    The target user for edit_user, or 0 for a check without an object.
	 * @return array{code: string, ok: bool, user_id: int, secret: null}|null Null when every check passes. A multisite install returns the
	 *                                                                         'multisite' result. Any other failure ends the request with wp_die().
	 */
	private function guard( string $nonce, string $nonce_action, string $capability, int $object_id = 0 ): ?array {
		if ( ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			wp_die( esc_html__( 'Your request could not be verified. Reload the page and try again.', 'steadhand' ), esc_html__( 'Not allowed', 'steadhand' ), array( 'response' => 403 ) );
		}

		if ( $this->is_network() ) {
			return $this->result( 'multisite', $object_id );
		}

		if ( ! current_user_can( 'manage_options' ) || Agents::holds_agent_role( wp_get_current_user() ) || ! current_user_can( 'list_users' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'steadhand' ), esc_html__( 'Forbidden', 'steadhand' ), array( 'response' => 403 ) );
		}

		$allowed = 0 === $object_id ? current_user_can( $capability ) : current_user_can( $capability, $object_id );
		if ( ! $allowed ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'steadhand' ), esc_html__( 'Forbidden', 'steadhand' ), array( 'response' => 403 ) );
		}

		return null;
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
	 * The user, read again from the database (the user cache is cleared first), or null when it does not exist.
	 *
	 * @param int $user_id The user ID.
	 * @return \WP_User|null
	 */
	private function fresh_user( int $user_id ): ?\WP_User {
		clean_user_cache( $user_id );
		$user = get_userdata( $user_id );

		return $user instanceof \WP_User && $user->exists() ? $user : null;
	}

	/**
	 * Whether a freshly created user holds nothing but the agent role. That means two things, both checked against the stored data:
	 * the user's roles are exactly [steadhand_agent] (Agents::is_agent()), and the stored capability map ($user->caps, the
	 * wp_capabilities meta) has exactly one key, steadhand_agent, set to true. Nothing else may be stored, not even a capability set to
	 * false. Used only right after creation, when no Steadhand capability has been granted yet.
	 *
	 * @param \WP_User $user The user.
	 * @return bool
	 */
	private function holds_only_agent_role( \WP_User $user ): bool {
		$caps = is_array( $user->caps ) ? $user->caps : array();

		return Agents::is_agent( $user ) && array( Roles::ROLE ) === array_keys( $caps ) && true === ( $caps[ Roles::ROLE ] ?? null );
	}

	/**
	 * How many users have exactly this login. The comparison is binary, so it is case-sensitive.
	 *
	 * @param string $username The login name.
	 * @return int
	 */
	private function login_count( string $username ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE BINARY user_login = %s", $username ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- An exact count of one login, which the user cache cannot give; the table name is core's.
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
