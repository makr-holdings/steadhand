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
 * The result is an array: 'code' (one of CODES), 'ok' (true for the success codes), 'user_id', 'secret' (for create and add-password
 * only: the new password, shown once by the screen; it is never stored, logged, passed to a hook or put in a redirect) and 'login'
 * (only for create_failed_cleanup: the login the administrator must check on the Users screen).
 *
 * No action lets an exception escape. A listener that throws is caught where Steadhand calls it, and the write it interrupted is either
 * completed and verified or undone. Where Steadhand cannot undo a write, the result says so with a code of its own.
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
		'capabilities_unverified',
		'invalid_username',
		'username_taken',
		'invalid_label',
		'label_taken',
		'too_many_passwords',
		'app_passwords_unavailable',
		'not_agent',
		'password_not_found',
		'create_failed',
		'create_failed_user_kept',
		'create_failed_cleanup',
		'extra_role',
		'password_failed',
		'revoke_failed',
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
		'revoke_failed',
		'not_agent',
		'capabilities_saved',
		'capabilities_mismatch',
		'capabilities_unverified',
		'capabilities_failed',
		'multisite',
	);

	/** How a rollback ended: the user was deleted. */
	private const ROLLED_BACK = 'rolled_back';

	/** How a rollback ended: the user was kept, with no role, no capability and no password, because content is attributed to it. */
	private const KEPT = 'kept';

	/** How a rollback ended: a step failed, so the user may still hold a role or a password. The administrator must check it. */
	private const CLEANUP = 'cleanup';

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
	 * Creates a dedicated agent with its first Application Password.
	 *
	 * $capabilities decides the agent's Steadhand capabilities. Null gives the role's defaults, and no user-level capability is written.
	 * An array is the list of checked boxes: only strings are read, and every Steadhand capability (Capabilities::all()) is written. An
	 * offered one is written as true when it was checked and as false when it was not; a capability that is not offered is always
	 * written as false, so a grant something else made to the agent role never reaches the agent. So an empty array gives an agent that
	 * cannot do anything.
	 *
	 * The order is: the login count and the first strict check (the user holds only the agent role), then the capability writes, which
	 * are read back, then the Application Password, then the steadhand_agent_created action, then the final strict check against the
	 * posted values. Each check is made on the user as it is then. The capability writes come before the password, so a refused agent
	 * never holds one. A failed check, or a listener that throws, rolls the request back (see roll_back()) and refuses. No password is
	 * returned then. This method does not fire steadhand_agent_capabilities_changed.
	 *
	 * @param string            $nonce        The nonce from the form.
	 * @param string            $username     The requested login name, after wp_unslash().
	 * @param string            $label        The label of the first Application Password, after wp_unslash().
	 * @param array<mixed>|null $capabilities Null for the role's defaults, or the checked capability names from the form.
	 * @return array{code: string, ok: bool, user_id: int, secret: ?array{username: string, password: string}}
	 */
	public function create( string $nonce, string $username, string $label, ?array $capabilities = null ): array {
		$refused = $this->guard( $nonce, self::nonce_action( self::ACTION_CREATE ), 'create_users' );
		if ( null !== $refused ) {
			return $refused;
		}

		// Null keeps the role's defaults. An array becomes an explicit true or false for every offered capability.
		$values = null === $capabilities ? null : $this->values_from( $capabilities );

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

		// The ID of the new user is taken from the first action core fires after the user's row is written, which runs before any
		// listener of user_register or set_user_role. So a listener that throws later cannot hide the user from the rollback.
		$new_id  = 0;
		$capture = static function ( $password, $user_id ) use ( &$new_id ): void {
			if ( 0 === $new_id ) {
				$new_id = (int) $user_id;
			}
		};
		add_action( 'wp_set_password', $capture, PHP_INT_MIN, 2 );

		try {
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
				return $this->after_failed_insert( $new_id, $username );
			}

			return $this->verify_new_agent( (int) $inserted, $username, $label, $values );
		} catch ( \Throwable ) {
			return $this->after_failed_insert( $new_id, $username );
		} finally {
			remove_action( 'wp_set_password', $capture, PHP_INT_MIN );
		}
	}

	/**
	 * The checks and writes after the user row exists: the login count, the new user's state, the capability writes (when $values is
	 * not null), the first Application Password, the steadhand_agent_created action, and the final check. Every refusal rolls the user
	 * back through refuse().
	 *
	 * @param int                     $user_id  The new user.
	 * @param string                  $username The posted login.
	 * @param string                  $label    The label of the first Application Password.
	 * @param array<string,bool>|null $values   Each Steadhand capability and the value it is written with (values_from()), or null for the role's defaults.
	 * @return array{code: string, ok: bool, user_id: int, secret: ?array{username: string, password: string}}
	 */
	private function verify_new_agent( int $user_id, string $username, string $label, ?array $values ): array {
		// Two requests can pass username_exists() together, and user_login has no unique index. Count the login again. A count that
		// cannot be read is a refusal.
		$count = $this->login_count( $username );
		if ( null === $count ) {
			return $this->refuse( $user_id, 'create_failed', $username );
		}

		if ( $count > 1 ) {
			return $this->refuse( $user_id, 'username_taken', $username );
		}

		// Before any capability is written, so the user must still hold only the agent role.
		$user    = $this->fresh_user( $user_id );
		$problem = $this->new_user_problem( $user, $username );
		if ( null !== $problem ) {
			return $this->refuse( $user_id, $problem, $username );
		}

		if ( null === $user || ! wp_is_application_passwords_available_for_user( $user ) ) {
			return $this->refuse( $user_id, 'app_passwords_unavailable', $username );
		}

		// The capabilities are written before the password, so a refused agent never holds one.
		if ( null !== $values && ! $this->grant_capabilities( $user_id, $values ) ) {
			return $this->refuse( $user_id, 'create_failed', $username );
		}

		$created = \WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => $label ) );
		if ( is_wp_error( $created ) ) {
			return $this->refuse( $user_id, 'password_failed', $username );
		}

		list( $password ) = $created;

		try {
			/**
			 * Fires after a Steadhand agent was created with its first Application Password, before the final check.
			 *
			 * The password is not passed. Listeners can read the user and the label, and nothing else of the secret. A listener can
			 * change the user, and the final check then refuses it. So the action may fire for an agent that is then rolled back.
			 *
			 * @param int    $user_id The new agent's user ID.
			 * @param string $label   The label of its first Application Password (the agent label in the change log).
			 */
			do_action( 'steadhand_agent_created', $user_id, $label );
		} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The comment below says why the catch is empty.
			// A listener must not break the action. The final check below decides, whatever the listener did.
		}

		// The final check is the same strict check, on the user as it is now, against the posted values.
		$problem = $this->new_user_problem( $this->fresh_user( $user_id ), $username, $values );
		if ( null !== $problem ) {
			return $this->refuse( $user_id, $problem, $username );
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
	 * The result of a create whose insert failed or threw. When the user's ID was seen, the user is rolled back like any other refusal.
	 * When it was not seen, nothing is deleted: this request cannot show which user, if any, it made, so a login that exists is
	 * reported for the administrator to check.
	 *
	 * @param int    $user_id  The ID seen after the insert, or 0.
	 * @param string $username The posted login.
	 * @return array{code: string, ok: bool, user_id: int, secret: null}
	 */
	private function after_failed_insert( int $user_id, string $username ): array {
		if ( $user_id > 0 ) {
			return $this->refuse( $user_id, 'create_failed', $username );
		}

		$left_behind = self::quietly(
			static fn(): bool => false !== username_exists( $username ),
			true
		);

		return $left_behind
			? $this->result( 'create_failed_cleanup', 0, null, $username )
			: $this->result( 'create_failed' );
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

		// The UUID of the password is taken from core's action, which fires once the password is saved. If a listener throws after that,
		// the password is revoked by its UUID.
		$saved_uuid = '';
		$capture    = static function ( $target, $item ) use ( &$saved_uuid, $user_id ): void {
			if ( '' === $saved_uuid && (int) $target === $user_id && is_array( $item ) ) {
				$saved_uuid = (string) ( $item['uuid'] ?? '' );
			}
		};
		add_action( 'wp_create_application_password', $capture, PHP_INT_MIN, 2 );

		try {
			$created = \WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => $label ) );
		} catch ( \Throwable ) {
			return '' === $saved_uuid
				? $this->result( 'password_failed', $user_id )
				: ( $this->revoke_one( $user_id, $saved_uuid ) ? $this->result( 'password_failed', $user_id ) : $this->result( 'revoke_failed', $user_id ) );
		} finally {
			remove_action( 'wp_create_application_password', $capture, PHP_INT_MIN );
		}

		if ( is_wp_error( $created ) ) {
			return $this->result( 'password_failed', $user_id );
		}

		list( $password, $item ) = $created;
		$uuid                    = (string) $item['uuid'];

		// Re-check after the write. Two requests can pass the limits together, and the target may have changed. A failed check revokes
		// the password this request made and shows nothing. If that revoke fails, the password may still work, and the screen says so.
		$fresh = $this->fresh_user( $user_id );
		if ( null === $fresh || ! Agents::is_agent( $fresh ) ) {
			return $this->undo_add( $user_id, $uuid, 'not_agent' );
		}

		$passwords = \WP_Application_Passwords::get_user_application_passwords( $user_id );
		if ( count( $passwords ) > self::MAX_PASSWORDS ) {
			return $this->undo_add( $user_id, $uuid, 'too_many_passwords' );
		}

		$same_label = count(
			array_filter(
				$passwords,
				static fn( array $saved ): bool => strtolower( (string) $saved['name'] ) === strtolower( $label )
			)
		);
		if ( $same_label > 1 ) {
			return $this->undo_add( $user_id, $uuid, 'label_taken' );
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

		return $this->revoke_one( $user_id, $uuid )
			? $this->result( 'password_revoked', $user_id )
			: $this->result( 'revoke_failed', $user_id );
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

		return self::quietly( fn(): bool => $this->revoke_every_password( $user_id ) )
			? $this->result( 'passwords_revoked', $user_id )
			: $this->result( 'revoke_failed', $user_id );
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

		try {
			foreach ( $values as $capability => $grant ) {
				$current->add_cap( $capability, $grant );
			}

			$fresh = $this->fresh_user( $user_id );
		} catch ( \Throwable ) {
			// A listener threw while the writes were made. Whether they all landed is not known, so nothing is claimed.
			return $this->result( 'capabilities_failed', $user_id );
		}

		if ( null === $fresh ) {
			return $this->result( 'capabilities_failed', $user_id );
		}

		// The writes are made. A user who is no longer a dedicated agent now grants nothing, and the screen says so.
		if ( ! Agents::is_agent( $fresh ) ) {
			return $this->result( 'capabilities_unverified', $user_id );
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

		// Any result other than 0 refuses: 1 is a match, and false is a pattern error. Neither may pass.
		if ( 0 !== preg_match( '/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $label ) ) {
			return false;
		}

		if ( 0 !== preg_match( '/^\p{Z}|\p{Z}\z/u', $label ) ) {
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
	 * The values a list of checked boxes sets, for every Steadhand capability in Capabilities::all(). Only strings are read. An offered
	 * capability maps to whether its name was in the list. Every other capability maps to false, so it is written as an explicit false
	 * and nothing the agent role grants reaches the agent. Only the names of Capabilities::all() are keys, so a name that is not a
	 * Steadhand capability is never written.
	 *
	 * @param array<mixed> $checked The posted values. Anything that is not a string is ignored.
	 * @return array<string,bool>
	 */
	private function values_from( array $checked ): array {
		$names   = array_values( array_filter( $checked, 'is_string' ) );
		$offered = $this->offered_capabilities();
		$values  = array();
		foreach ( Capabilities::all() as $capability ) {
			$values[ $capability ] = in_array( $capability, $offered, true ) && in_array( $capability, $names, true );
		}

		return $values;
	}

	/**
	 * Writes every Steadhand capability of a new agent, each with its value, and reads them back. The writes must stick: the user must
	 * still be a dedicated agent, and every capability must read back as the value it was written with. A throw is a failure.
	 *
	 * @param int                $user_id The new user.
	 * @param array<string,bool> $values  Each Steadhand capability and the value it is written with (values_from()).
	 * @return bool True when every write is in place and checked.
	 */
	private function grant_capabilities( int $user_id, array $values ): bool {
		try {
			$user = $this->fresh_user( $user_id );
			if ( null === $user ) {
				return false;
			}

			foreach ( $values as $capability => $grant ) {
				$user->add_cap( $capability, $grant );
			}

			$fresh = $this->fresh_user( $user_id );
			if ( null === $fresh || ! Agents::is_agent( $fresh ) ) {
				return false;
			}

			foreach ( $values as $capability => $grant ) {
				if ( Capabilities::user_has( $fresh, $capability ) !== $grant ) {
					return false;
				}
			}

			return true;
		} catch ( \Throwable ) {
			return false;
		}
	}

	/**
	 * Why a new user fails the strict check, or null when it passes. The login must be the posted one, and the user's stored roles and
	 * capabilities must be exactly the expected ones (holds_only_agent_role()). A user that stores anything outside the expected set gets
	 * 'extra_role'. Any other failure (a missing user, a wrong login, a missing role, a wrong value) gets 'create_failed'.
	 *
	 * @param \WP_User|null           $user     The user as it is now.
	 * @param string                  $username The posted login.
	 * @param array<string,bool>|null $values   The offered capabilities and the values the user must store, or null for the role's defaults.
	 * @return string|null
	 */
	private function new_user_problem( ?\WP_User $user, string $username, ?array $values = null ): ?string {
		if ( null === $user || $user->user_login !== $username ) {
			return 'create_failed';
		}

		if ( self::holds_only_agent_role( $user, $values ) ) {
			return null;
		}

		return self::holds_anything_else( $user, $values ) ? 'extra_role' : 'create_failed';
	}

	/**
	 * The stored capability map a new agent must have: the agent role set to true, and, when values were posted, each Steadhand
	 * capability with the value it was written with.
	 *
	 * @param array<string,bool>|null $values Each Steadhand capability and its value (values_from()), or null.
	 * @return array<string,bool>
	 */
	private static function expected_caps( ?array $values ): array {
		return array( Roles::ROLE => true ) + ( $values ?? array() );
	}

	/**
	 * Whether a new user holds exactly the expected roles and capabilities, and nothing else. That means two things, both checked against
	 * the stored data: the user's roles are exactly [steadhand_agent] (Agents::is_agent()), and the stored capability map ($user->caps, the
	 * wp_capabilities meta) has exactly the expected keys, each with exactly the expected value. The expected map is the agent role set to
	 * true, plus the offered capabilities when $values is given (expected_caps()). With $values null nothing else may be stored, not even a
	 * capability set to false. A value must be the bool expected, so a stored '1' or a true where false was written is refused.
	 *
	 * Call it with null before any capability is written, and with the posted values for the final check.
	 *
	 * The caps comparison is what catches a capability or role name that is not a registered role: such a name is stored in the caps
	 * but is not in the roles, so Agents::is_agent() alone would pass it.
	 *
	 * @param \WP_User                $user   The user.
	 * @param array<string,bool>|null $values The offered capabilities and the values the user must store, or null for the role's defaults.
	 * @return bool
	 */
	private static function holds_only_agent_role( \WP_User $user, ?array $values = null ): bool {
		$caps     = is_array( $user->caps ) ? $user->caps : array();
		$expected = self::expected_caps( $values );

		if ( ! Agents::is_agent( $user ) || array() !== array_diff_key( $caps, $expected ) || array() !== array_diff_key( $expected, $caps ) ) {
			return false;
		}

		foreach ( $expected as $capability => $grant ) {
			if ( ( $caps[ $capability ] ?? null ) !== $grant ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a new user stores any role or capability outside the expected set, whatever its value. A role other than the agent role
	 * counts, and so does a capability key that is not expected. A value that differs from the expected one on an expected key does not
	 * count here; holds_only_agent_role() refuses that.
	 *
	 * @param \WP_User                $user   The user.
	 * @param array<string,bool>|null $values The offered capabilities and the values the user must store, or null for the role's defaults.
	 * @return bool
	 */
	private static function holds_anything_else( \WP_User $user, ?array $values = null ): bool {
		$caps     = is_array( $user->caps ) ? array_map( 'strval', array_keys( $user->caps ) ) : array();
		$roles    = is_array( $user->roles ) ? $user->roles : array();
		$expected = array_map( 'strval', array_keys( self::expected_caps( $values ) ) );

		return array() !== array_diff( $caps, $expected ) || array() !== array_diff( $roles, array( Roles::ROLE ) );
	}

	/**
	 * How many users have exactly this login, or null when the count cannot be read. The comparison is the same as username_exists(),
	 * which is case-insensitive under the database's collation.
	 *
	 * @param string $username The login name.
	 * @return int|null
	 */
	private function login_count( string $username ): ?int {
		global $wpdb;

		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login = %s", $username ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- An exact count of one login, which the user cache cannot give; the table name is core's.

		return is_numeric( $count ) ? (int) $count : null;
	}

	/**
	 * Whether any post or link is attributed to the user. A count that cannot be read counts as attributed, so the user is kept.
	 *
	 * @param int $user_id The user ID.
	 * @return bool
	 */
	private function holds_attributed_content( int $user_id ): bool {
		global $wpdb;

		$posts = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d", $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Counts the rows attributed to one user; the table name is core's.
		$links = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->links} WHERE link_owner = %d", $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Counts the links attributed to one user; the table name is core's.

		if ( ! is_numeric( $posts ) || ! is_numeric( $links ) ) {
			return true;
		}

		return (int) $posts > 0 || (int) $links > 0;
	}

	/**
	 * Undoes a create that was refused after its user was written. The steps run in this order:
	 *
	 * 1. Every Application Password of the user is revoked. If that fails, the user may still hold a live password.
	 * 2. Posts and links attributed to the user are counted, after the revoke. Core's wp_delete_user() deletes them, so the user is
	 *    deleted only when nothing is attributed to it.
	 * 3. If nothing is attributed, the user is deleted. Its password meta goes with it.
	 * 4. Otherwise (or when the delete fails) the user is kept with no role and no capability, and the result says which way it ended:
	 *    kept, when the password is revoked and the capabilities are gone; cleanup, when any step failed and the administrator must check.
	 *
	 * Each step is guarded: a listener that throws makes that step count as failed, and the rollback goes on.
	 *
	 * @param int $user_id The user the request made.
	 * @return string One of ROLLED_BACK, KEPT or CLEANUP.
	 */
	private function roll_back( int $user_id ): string {
		$revoked = self::quietly( fn(): bool => $this->revoke_every_password( $user_id ) );
		$content = self::quietly( fn(): bool => $this->holds_attributed_content( $user_id ), true );

		if ( ! $content && self::quietly( fn(): bool => $this->delete_user( $user_id ) ) ) {
			return self::ROLLED_BACK;
		}

		$stripped = self::quietly( fn(): bool => $this->strip_user( $user_id ) );

		return $content && $revoked && $stripped ? self::KEPT : self::CLEANUP;
	}

	/**
	 * Refuses a create after its user was written: rolls the user back, then returns the refusal. The code is the one given when the
	 * user was deleted. A kept user gets create_failed_user_kept, and a user that could not be checked gets create_failed_cleanup with
	 * its login.
	 *
	 * @param int    $user_id  The user the request made.
	 * @param string $code     The refusal code.
	 * @param string $username The posted login.
	 * @return array{code: string, ok: bool, user_id: int, secret: null}
	 */
	private function refuse( int $user_id, string $code, string $username ): array {
		$ended = $this->roll_back( $user_id );

		if ( self::ROLLED_BACK === $ended ) {
			return $this->result( $code );
		}

		if ( self::KEPT === $ended ) {
			return $this->result( 'create_failed_user_kept', $user_id );
		}

		return $this->result( 'create_failed_cleanup', $user_id, null, $username );
	}

	/**
	 * Revokes every Application Password of a user, and checks that none is left. A throw counts as a failure.
	 *
	 * @param int $user_id The user ID.
	 * @return bool True when the user holds no Application Password afterwards.
	 */
	private function revoke_every_password( int $user_id ): bool {
		$deleted = \WP_Application_Passwords::delete_all_application_passwords( $user_id );

		return ! is_wp_error( $deleted ) && array() === \WP_Application_Passwords::get_user_application_passwords( $user_id );
	}

	/**
	 * Revokes one Application Password by its UUID. A throw counts as a failure.
	 *
	 * @param int    $user_id The user ID.
	 * @param string $uuid    The password's UUID.
	 * @return bool True when core reports the password deleted.
	 */
	private function revoke_one( int $user_id, string $uuid ): bool {
		return self::quietly( static fn(): bool => true === \WP_Application_Passwords::delete_application_password( $user_id, $uuid ) );
	}

	/**
	 * Deletes a user this request made, and checks that it is gone. A throw counts as a failure.
	 *
	 * @param int $user_id The user ID.
	 * @return bool
	 */
	private function delete_user( int $user_id ): bool {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$deleted = wp_delete_user( $user_id );

		return true === $deleted && null === $this->fresh_user( $user_id );
	}

	/**
	 * Removes every role and capability from a user this request made, and checks that none is left.
	 *
	 * @param int $user_id The user ID.
	 * @return bool
	 */
	private function strip_user( int $user_id ): bool {
		$user = $this->fresh_user( $user_id );
		if ( null === $user ) {
			return false;
		}

		$user->remove_all_caps();
		$after = $this->fresh_user( $user_id );

		return null !== $after && empty( $after->roles ) && empty( $after->caps );
	}

	/**
	 * Undoes the password of an add-password request that failed its re-check. If the revoke fails, the result says so, since the
	 * password may still work.
	 *
	 * @param int    $user_id The agent.
	 * @param string $uuid    The password the request made.
	 * @param string $code    The refusal code for the failed check.
	 * @return array{code: string, ok: bool, user_id: int, secret: null}
	 */
	private function undo_add( int $user_id, string $uuid, string $code ): array {
		return $this->revoke_one( $user_id, $uuid )
			? $this->result( $code, $user_id )
			: $this->result( 'revoke_failed', $user_id );
	}

	/**
	 * Runs a step and returns its boolean result. A throw returns $on_failure, so a listener that throws cannot escape a rollback.
	 *
	 * @param callable $step       The step.
	 * @param bool     $on_failure The value to return when the step throws.
	 * @return bool
	 */
	private static function quietly( callable $step, bool $on_failure = false ): bool {
		try {
			return (bool) $step();
		} catch ( \Throwable ) {
			return $on_failure;
		}
	}

	/**
	 * Builds a result.
	 *
	 * @param string                                         $code    One of CODES.
	 * @param int                                            $user_id The target user ID, or 0.
	 * @param array{username: string, password: string}|null $secret  The new password, for create and add-password only.
	 * @param string                                         $login   The login to check, for create_failed_cleanup only.
	 * @return array{code: string, ok: bool, user_id: int, secret: ?array{username: string, password: string}, login?: string}
	 */
	private function result( string $code, int $user_id = 0, ?array $secret = null, string $login = '' ): array {
		$result = array(
			'code'    => $code,
			'ok'      => in_array( $code, self::SUCCESS_CODES, true ),
			'user_id' => $user_id,
			'secret'  => $secret,
		);

		if ( '' !== $login ) {
			$result['login'] = $login;
		}

		return $result;
	}
}
