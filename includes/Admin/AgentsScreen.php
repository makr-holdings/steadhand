<?php
/**
 * The Agents screen: a list of the agents, one agent's detail (its capabilities, its connections and the forms that change them), and the
 * form that creates an agent.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Admin;

use Steadhand\Agents;
use Steadhand\Capabilities;
use Steadhand\Roles;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Agents screen and reads its POST requests. The screen has three views of one page: the list (the default), one agent's
 * detail (agent=<id>) and the add form (view=new). The screen posts to its own URL. Create and add-password answer that POST directly,
 * and show a new password once in that response. Revoke, revoke-all and set-capabilities redirect with a fixed code, as the undo does.
 * Only an administrator who holds no agent role sees the screen. Every form's action is checked by AgentsHandler.
 */
final class AgentsScreen {

	/** The menu slug of the screen. */
	public const SLUG = 'steadhand-agents';

	/** The most agents listed. */
	public const LIST_LIMIT = 200;

	/**
	 * The result of this request's create or add-password action, when it had one. Held in memory for this response only.
	 *
	 * @var array{code: string, ok: bool, user_id: int, secret: ?array{username: string, password: string}}|null
	 */
	private ?array $outcome = null;

	/**
	 * Which view this POST's response shows, when the request was a create or an add-password: 'detail' with the user ID, or 'add'.
	 * Null for a GET, which takes its view from the query string.
	 *
	 * @var array{view: string, id: int}|null
	 */
	private ?array $after = null;

	/**
	 * What this request's create POST sent, when that create was refused, so the add view is drawn from it again: the login and the
	 * connection name as posted, and the checked capability names when the form sent its marker (null otherwise, so the defaults show).
	 * Null for a GET, and for a create that was made. Held in memory for this response only.
	 *
	 * @var array{username: string, label: string, capabilities: ?array<int, string>}|null
	 */
	private ?array $posted_create = null;

	/**
	 * Constructor.
	 *
	 * @param AgentsHandler $handler The handler of the screen's actions.
	 */
	public function __construct( private AgentsHandler $handler ) {}

	/**
	 * The load-{hook} callback. Runs the request's action, if it is a POST, and redirects where the action calls for it.
	 *
	 * @return void
	 */
	public function load(): void {
		$redirect = $this->process_post();

		if ( null !== $redirect ) {
			wp_safe_redirect( $redirect );
			exit;
		}

		// The page that shows a new password must not be cached or stored by the browser.
		if ( null !== $this->outcome && null !== $this->outcome['secret'] ) {
			nocache_headers();
		}
	}

	/**
	 * Runs the action of a POST request. Returns the redirect URL for revoke, revoke-all and set-capabilities, and null otherwise: create
	 * and add-password keep their result for the render, and a GET changes nothing.
	 *
	 * @return string|null
	 */
	public function process_post(): ?string {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Sanitized on this line; it is only compared.
		if ( 'POST' !== $method ) {
			return null;
		}

		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Each action checks its nonce first, in AgentsHandler::guard().
		$action = isset( $post[ AgentsHandler::FIELD ] ) && is_string( $post[ AgentsHandler::FIELD ] ) ? $post[ AgentsHandler::FIELD ] : '';
		$nonce  = isset( $post['_wpnonce'] ) && is_string( $post['_wpnonce'] ) ? $post['_wpnonce'] : '';
		$user   = isset( $post['user_id'] ) && is_string( $post['user_id'] ) ? $post['user_id'] : '';
		$id     = 1 === preg_match( '/^\d{1,18}\z/', $user ) ? (int) $user : 0;

		if ( AgentsHandler::ACTION_CREATE === $action ) {
			// The create form sends capabilities_present with its boxes, so that no box checked means no capability. A form without the
			// marker gets the role's defaults.
			$capabilities = null;
			if ( isset( $post['capabilities_present'] ) ) {
				$posted       = isset( $post['capabilities'] ) && is_array( $post['capabilities'] ) ? $post['capabilities'] : array();
				$capabilities = array_values( array_filter( $posted, 'is_string' ) );
			}
			$username      = isset( $post['username'] ) && is_string( $post['username'] ) ? $post['username'] : '';
			$label         = isset( $post['label'] ) && is_string( $post['label'] ) ? $post['label'] : '';
			$this->outcome = $this->handler->create( $nonce, $username, $label, $capabilities );
			// A refused create shows the add form again, drawn from what was posted, so a box the administrator unchecked stays unchecked.
			$this->posted_create = 'created' === $this->outcome['code']
				? null
				: array(
					'username'     => $username,
					'label'        => $label,
					'capabilities' => $capabilities,
				);
			// A new agent is shown at once, with its password. A refused create shows the add form again.
			$this->after = 'created' === $this->outcome['code']
				? array(
					'view' => 'detail',
					'id'   => $this->outcome['user_id'],
				)
				: array(
					'view' => 'add',
					'id'   => 0,
				);
			return null;
		}

		if ( AgentsHandler::ACTION_ADD_PASSWORD === $action ) {
			$label         = isset( $post['label'] ) && is_string( $post['label'] ) ? $post['label'] : '';
			$this->outcome = $this->handler->add_password( $nonce, $id, $label );
			$this->after   = array(
				'view' => 'detail',
				'id'   => $id,
			);
			return null;
		}

		if ( AgentsHandler::ACTION_REVOKE === $action ) {
			$uuid   = isset( $post['uuid'] ) && is_string( $post['uuid'] ) ? $post['uuid'] : '';
			$result = $this->handler->revoke( $nonce, $id, $uuid );
		} elseif ( AgentsHandler::ACTION_REVOKE_ALL === $action ) {
			$result = $this->handler->revoke_all( $nonce, $id );
		} elseif ( AgentsHandler::ACTION_CAPABILITIES === $action ) {
			$posted = isset( $post['capabilities'] ) && is_array( $post['capabilities'] ) ? $post['capabilities'] : array();
			$result = $this->handler->set_capabilities( $nonce, $id, array_values( array_filter( $posted, 'is_string' ) ) );
		} else {
			wp_die( esc_html__( 'That action is not valid.', 'steadhand' ), esc_html__( 'Not allowed', 'steadhand' ), array( 'response' => 400 ) );
		}

		// The redirect returns to the agent's detail view. The detail view validates the ID, so a forged one shows nothing.
		$args = array(
			'page'   => self::SLUG,
			'agents' => $result['code'],
		);
		if ( $id > 0 ) {
			$args['agent'] = (string) $id;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Renders the page. Refuses everyone but an administrator who holds no agent role and may list users. On a multisite network it
	 * shows only the notice that agent management is not available there (SPEC D13): no forms and no list.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) || Agents::holds_agent_role( wp_get_current_user() ) || ! current_user_can( 'list_users' ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'steadhand' ), esc_html__( 'Forbidden', 'steadhand' ), array( 'response' => 403 ) );
		}

		echo '<div class="wrap">';

		if ( $this->handler->is_network() ) {
			printf( '<h1>%s</h1>', esc_html__( 'Steadhand agents', 'steadhand' ) );
			$this->notice( 'warning', __( 'Agent management is not available on multisite networks.', 'steadhand' ) );
			echo '</div>';
			return;
		}

		$available = wp_is_application_passwords_available();
		$view      = $this->resolve_view( $available );

		printf( '<h1 class="wp-heading-inline">%s</h1>', esc_html__( 'Steadhand agents', 'steadhand' ) );
		if ( 'list' === $view['mode'] && $available && current_user_can( 'create_users' ) ) {
			printf(
				' <a href="%s" class="page-title-action">%s</a>',
				esc_url( $this->view_url( array( 'view' => 'new' ) ) ),
				esc_html__( 'Add agent', 'steadhand' )
			);
		}
		echo '<hr class="wp-header-end">';

		$printed = $this->notice_for_request();

		if ( null !== $this->outcome && null !== $this->outcome['secret'] ) {
			$this->secret_panel( $this->outcome['secret'] );
		}

		if ( ! $available ) {
			$this->notice( 'warning', __( 'Application Passwords are not available on this site, most often because the site is not served over HTTPS. Agents cannot be given credentials until this is fixed, so the forms for creating an agent and adding a connection are hidden.', 'steadhand' ) );
		}

		if ( $view['refused'] && 'not_agent' !== $printed ) {
			if ( null === $this->after ) {
				// A GET attempted nothing, so its notice says only that the user is not an agent.
				$this->notice( 'error', __( 'That user is not a dedicated agent.', 'steadhand' ) );
			} else {
				$this->notice_for_code( 'not_agent' );
			}
		}

		if ( 'add' === $view['mode'] ) {
			$this->add_view();
		} elseif ( 'detail' === $view['mode'] && null !== $view['agent'] ) {
			$this->agent_view( $view['agent'], $available );
		} else {
			$this->list_view( $view['hidden'] );
		}

		echo '</div>';
	}

	/**
	 * Decides which view to show. A POST's response shows the view its action set; a GET reads view=new or agent=<digits>. A detail
	 * request for a user who is not a dedicated agent falls back to the list, and the list then leaves that user out, so nothing about
	 * it is printed. The add view needs Application Passwords and create_users; without them it falls back to the list.
	 *
	 * @param bool $available Whether Application Passwords are available on the site.
	 * @return array{mode: string, agent: ?\WP_User, hidden: int, refused: bool}
	 */
	private function resolve_view( bool $available ): array {
		$requested = null !== $this->after
			? $this->after
			: $this->requested_view();

		if ( 'add' === $requested['view'] ) {
			if ( $available && current_user_can( 'create_users' ) ) {
				return array(
					'mode'    => 'add',
					'agent'   => null,
					'hidden'  => 0,
					'refused' => false,
				);
			}
		} elseif ( 'detail' === $requested['view'] ) {
			$agent = $this->agent_for( $requested['id'] );
			if ( null !== $agent ) {
				return array(
					'mode'    => 'detail',
					'agent'   => $agent,
					'hidden'  => 0,
					'refused' => false,
				);
			}

			return array(
				'mode'    => 'list',
				'agent'   => null,
				'hidden'  => $requested['id'],
				'refused' => true,
			);
		}

		return array(
			'mode'    => 'list',
			'agent'   => null,
			'hidden'  => 0,
			'refused' => false,
		);
	}

	/**
	 * The view a GET asks for. view=new (exact) is the add view; agent=<1 to 18 digits> is the detail view; anything else is the list.
	 *
	 * @return array{view: string, id: int}
	 */
	private function requested_view(): array {
		if ( 'new' === $this->query_value( 'view' ) ) {
			return array(
				'view' => 'add',
				'id'   => 0,
			);
		}

		$agent = $this->query_value( 'agent' );
		if ( 1 === preg_match( '/^\d{1,18}\z/', $agent ) ) {
			return array(
				'view' => 'detail',
				'id'   => (int) $agent,
			);
		}

		return array(
			'view' => 'list',
			'id'   => 0,
		);
	}

	/**
	 * One value of the query string, as a string, or '' when it is missing or not a string. The value only chooses a view; it is never
	 * printed.
	 *
	 * @param string $key The query key.
	 * @return string
	 */
	private function query_value( string $key ): string {
		if ( ! isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view: the value only selects a view and is never printed.
			return '';
		}

		$value = wp_unslash( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only view: the value is compared with a fixed string or matched against digits, and is never printed.

		return is_string( $value ) ? $value : '';
	}

	/**
	 * The dedicated agent with this ID, or null. An administrator, a user who holds the agent role with another role, a missing user and
	 * the ID 0 all give null.
	 *
	 * @param int $id The user ID.
	 * @return \WP_User|null
	 */
	private function agent_for( int $id ): ?\WP_User {
		if ( $id <= 0 ) {
			return null;
		}

		$user = get_userdata( $id );

		return $user instanceof \WP_User && Agents::is_agent( $user ) ? $user : null;
	}

	/**
	 * An admin URL of this screen, with the given query values added.
	 *
	 * @param array<string, string> $args Query values.
	 * @return string
	 */
	private function view_url( array $args ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * The one-time panel: the username, the password, and the endpoint. It is printed only in the response to the POST that made it.
	 *
	 * @param array{username: string, password: string} $secret The new credentials.
	 * @return void
	 */
	private function secret_panel( array $secret ): void {
		echo '<div class="notice notice-success"><h2>' . esc_html__( 'New agent password', 'steadhand' ) . '</h2>';
		printf( '<p>%s <code>%s</code></p>', esc_html__( 'Username:', 'steadhand' ), esc_html( $secret['username'] ) );
		printf( '<p>%s <code>%s</code></p>', esc_html__( 'Application password:', 'steadhand' ), esc_html( $secret['password'] ) );
		printf( '<p>%s <code>%s</code></p>', esc_html__( 'MCP endpoint:', 'steadhand' ), esc_html( rest_url( 'steadhand/mcp' ) ) );
		printf( '<p><strong>%s</strong></p>', esc_html__( 'This password is shown once. Copy it now. It cannot be shown again.', 'steadhand' ) );
		echo '</div>';
	}

	/**
	 * Shows the notice of this request's action, or of the code in the query string. Only a code from the fixed list is printed.
	 * A code in the query string must be one a redirect can carry (AgentsHandler::REDIRECT_CODES). The one-time panel's codes, such as
	 * created and password_added, are shown only from this request's own outcome.
	 *
	 * @return string The code whose notice was printed, or '' when none was.
	 */
	private function notice_for_request(): string {
		if ( null !== $this->outcome ) {
			$this->notice_for_code( $this->outcome['code'], (string) ( $this->outcome['login'] ?? '' ) );
			return $this->outcome['code'];
		}

		$query = $this->query_value( 'agents' );
		if ( in_array( $query, AgentsHandler::REDIRECT_CODES, true ) ) {
			$this->notice_for_code( $query );
			return $query;
		}

		return '';
	}

	/**
	 * Prints the notice of a result code. An unknown code prints nothing.
	 *
	 * @param string $code  The result code.
	 * @param string $login The login to check, for create_failed_cleanup only.
	 * @return void
	 */
	private function notice_for_code( string $code, string $login = '' ): void {
		$messages = self::messages();

		if ( ! in_array( $code, AgentsHandler::CODES, true ) || ! isset( $messages[ $code ] ) ) {
			return;
		}

		$text = 'create_failed_cleanup' === $code ? sprintf( $messages[ $code ][1], $login ) : $messages[ $code ][1];

		$this->notice( $messages[ $code ][0], $text );
	}

	/**
	 * The fixed notice of every result code: its type and its text.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function messages(): array {
		return array(
			'created'                   => array( 'success', __( 'The agent was created. Its password is shown below.', 'steadhand' ) ),
			'password_added'            => array( 'success', __( 'The connection was added. Its password is shown below.', 'steadhand' ) ),
			'password_revoked'          => array( 'success', __( 'The password was revoked.', 'steadhand' ) ),
			'passwords_revoked'         => array( 'success', __( 'All of the agent\'s passwords were revoked.', 'steadhand' ) ),
			'capabilities_saved'        => array( 'success', __( 'The capabilities were saved.', 'steadhand' ) ),
			'capabilities_mismatch'     => array( 'error', __( 'The capabilities could not be confirmed after saving. Check them before the agent is used.', 'steadhand' ) ),
			'capabilities_unverified'   => array( 'error', __( 'The capabilities were saved, but this user is no longer a dedicated agent, so they grant nothing. Check the user\'s roles on the Users screen.', 'steadhand' ) ),
			'invalid_username'          => array( 'error', __( 'The username is not valid. Use 1 to 60 lower-case letters, digits, underscores or hyphens, starting with a letter or digit.', 'steadhand' ) ),
			'username_taken'            => array( 'error', __( 'That username already exists.', 'steadhand' ) ),
			'invalid_label'             => array( 'error', __( 'The connection name is not valid. Use 1 to 60 characters on one line, with no markup, no invisible or control characters, and no leading or trailing spaces.', 'steadhand' ) ),
			'label_taken'               => array( 'error', __( 'The agent already has a connection with that name.', 'steadhand' ) ),
			'too_many_passwords'        => array( 'error', __( 'The agent already has the most Application Passwords allowed. Revoke one first.', 'steadhand' ) ),
			'app_passwords_unavailable' => array( 'error', __( 'Application Passwords are not available for this user, so no password was made.', 'steadhand' ) ),
			'not_agent'                 => array( 'error', __( 'That user is not a dedicated agent, so nothing was changed.', 'steadhand' ) ),
			'password_not_found'        => array( 'error', __( 'That password was not found for this agent.', 'steadhand' ) ),
			'create_failed'             => array( 'error', __( 'The agent could not be created.', 'steadhand' ) ),
			'create_failed_user_kept'   => array( 'error', __( 'The agent could not be created. A user with no role and no password access was left behind because content on this site is attributed to its ID. Remove it on the Users screen and choose what to do with that content.', 'steadhand' ) ),
			/* translators: %s: the login name the administrator entered. */
			'create_failed_cleanup'     => array( 'error', __( 'The agent could not be created, and removing the user it made did not finish. Check the Users screen for the login %s. The user may still have a role or a password, so remove it there.', 'steadhand' ) ),
			'extra_role'                => array( 'error', __( 'The agent could not be created, because another plugin gave the new user an extra role or capability. Steadhand only creates users whose single role is the agent role.', 'steadhand' ) ),
			'password_failed'           => array( 'error', __( 'The password could not be saved. Nothing was left behind.', 'steadhand' ) ),
			'revoke_failed'             => array( 'error', __( 'The password could not be revoked. It may still work. Try again, or remove it on the user\'s profile.', 'steadhand' ) ),
			'capabilities_failed'       => array( 'error', __( 'The capabilities could not be confirmed. Check them on the Users screen before the agent is used.', 'steadhand' ) ),
			'role_missing'              => array( 'error', __( 'The Steadhand agent role is missing. Deactivate and reactivate Steadhand to restore it.', 'steadhand' ) ),
			'multisite'                 => array( 'warning', __( 'Agent management is not available on multisite networks.', 'steadhand' ) ),
		);
	}

	/**
	 * Prints a notice box.
	 *
	 * @param string $type    success, warning or error.
	 * @param string $message The text, already translated. It is escaped here.
	 * @return void
	 */
	private function notice( string $type, string $message ): void {
		$class = 'notice-' . $type;
		printf( '<div class="notice %s"><p>%s</p></div>', esc_attr( $class ), esc_html( $message ) );
	}

	/**
	 * The list view: one table of the dedicated agents, with the warning for a user who holds the agent role and another role. The
	 * user $hidden is left out of the warnings, so a refused detail request prints nothing about that user. The list has no forms.
	 *
	 * @param int $hidden A user ID to leave out, or 0 for none.
	 * @return void
	 */
	private function list_view( int $hidden ): void {
		$users = get_users(
			array(
				'role'    => Roles::ROLE,
				'number'  => self::LIST_LIMIT,
				'orderby' => 'login',
				'order'   => 'ASC',
			)
		);

		$agents = array();
		foreach ( $users as $user ) {
			if ( ! $user instanceof \WP_User ) {
				continue;
			}

			if ( Agents::is_agent( $user ) ) {
				$agents[] = $user;
			} elseif ( Agents::holds_agent_role( $user ) && $user->ID !== $hidden ) {
				$this->notice(
					'warning',
					sprintf(
						/* translators: %s: the login name of the account. */
						__( '%s: This user also has another role, so it is not an agent and cannot connect (see D14). Remove the agent role from this account on the Users screen.', 'steadhand' ),
						$user->user_login
					)
				);
			}
		}

		if ( array() === $agents ) {
			printf( '<p>%s</p>', esc_html__( 'There are no agents yet.', 'steadhand' ) );
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		printf(
			'<th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th><th scope="col"><span class="screen-reader-text">%s</span></th>',
			esc_html__( 'Agent', 'steadhand' ),
			esc_html__( 'Capabilities', 'steadhand' ),
			esc_html__( 'Connections', 'steadhand' ),
			esc_html__( 'Last used', 'steadhand' ),
			esc_html__( 'Manage', 'steadhand' )
		);
		echo '</tr></thead><tbody>';

		foreach ( $agents as $agent ) {
			$this->list_row( $agent );
		}

		echo '</tbody></table>';
	}

	/**
	 * One agent's row of the list: its login, its granted capabilities, its connection count, the newest use of any connection, and the
	 * link to its detail view.
	 *
	 * @param \WP_User $agent The dedicated agent.
	 * @return void
	 */
	private function list_row( \WP_User $agent ): void {
		$passwords = \WP_Application_Passwords::get_user_application_passwords( $agent->ID );

		$newest = 0;
		foreach ( $passwords as $password ) {
			$used = $password['last_used'] ?? null;
			if ( is_int( $used ) && $used > $newest ) {
				$newest = $used;
			}
		}

		$granted = array_filter( $this->handler->offered_capabilities(), static fn( string $capability ): bool => Capabilities::user_has( $agent, $capability ) );
		$texts   = array_map( static fn( string $capability ): string => self::capability_label( $capability ), array_values( $granted ) );
		$url     = $this->view_url( array( 'agent' => (string) $agent->ID ) );

		echo '<tr>';
		printf( '<td><a href="%s">%s</a></td>', esc_url( $url ), esc_html( $agent->user_login ) );
		printf( '<td>%s</td>', esc_html( array() === $texts ? __( 'None', 'steadhand' ) : implode( ', ', $texts ) ) );
		printf( '<td>%s</td>', esc_html( (string) count( $passwords ) ) );
		printf( '<td>%s</td>', esc_html( $this->format_time( $newest > 0 ? $newest : null ) ) );
		printf( '<td><a href="%s" class="button button-small">%s</a></td>', esc_url( $url ), esc_html__( 'Manage', 'steadhand' ) );
		echo '</tr>';
	}

	/**
	 * The add view: a link back to the list, then the form that creates an agent.
	 *
	 * @return void
	 */
	private function add_view(): void {
		printf( '<p><a href="%s">%s</a></p>', esc_url( $this->view_url( array() ) ), esc_html__( 'Back to all agents', 'steadhand' ) );
		$this->create_form();
	}

	/**
	 * The form that creates an agent. Shown only to a viewer who holds create_users. The capability boxes are pre-checked as the role's
	 * defaults say, or, after a refused create, as the administrator posted them (create_form() reads posted_create). capabilities_present
	 * tells the handler that the boxes decide, so no box checked means no capability.
	 *
	 * @return void
	 */
	private function create_form(): void {
		// The posted values of a refused create, or null for the defaults and empty fields. Only this property is read here.
		$posted = $this->posted_create;

		echo '<h2>' . esc_html__( 'Create an agent', 'steadhand' ) . '</h2>';
		$this->open_form( AgentsHandler::ACTION_CREATE, AgentsHandler::nonce_action( AgentsHandler::ACTION_CREATE ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		printf(
			'<tr><th scope="row"><label for="steadhand-agent-username">%s</label></th><td><input type="text" id="steadhand-agent-username" name="username" class="regular-text" maxlength="60" pattern="[a-z0-9][a-z0-9_\\-]{0,59}" required value="%s"></td></tr>',
			esc_html__( 'Username', 'steadhand' ),
			esc_attr( $posted['username'] ?? '' )
		);

		// A refused create that sent the marker keeps the boxes as posted: checked exactly when the name was posted and is offered.
		// Otherwise (a GET, or a form without the marker) the role's defaults decide.
		$defaults = Capabilities::agent_defaults();
		printf( '<tr><th scope="row">%s</th><td><fieldset>', esc_html__( 'Capabilities', 'steadhand' ) );
		foreach ( $this->handler->offered_capabilities() as $capability ) {
			$checked = null === ( $posted['capabilities'] ?? null )
				? ( $defaults[ $capability ] ?? false ) === true
				: in_array( $capability, $posted['capabilities'], true );
			$this->capability_checkbox( $capability, $checked );
		}
		echo '<input type="hidden" name="capabilities_present" value="1"></fieldset></td></tr>';

		printf(
			'<tr><th scope="row"><label for="steadhand-agent-label">%s</label></th><td><input type="text" id="steadhand-agent-label" name="label" class="regular-text" maxlength="60" required value="%s"><p class="description">%s</p></td></tr>',
			esc_html__( 'Connection name', 'steadhand' ),
			esc_attr( $posted['label'] ?? '' ),
			esc_html__( 'A name for the app that will use this password, such as Claude Desktop. It is shown next to the agent in the change log. You can add more connections to the agent later.', 'steadhand' )
		);
		echo '</tbody></table>';
		submit_button( __( 'Create agent', 'steadhand' ) );
		echo '</form>';
	}

	/**
	 * The detail view of one dedicated agent: a link back to the list, its login, its capabilities, its connections, the change-log link,
	 * and the steadhand_admin_agent_row action. The forms are shown only when the viewer may edit the agent.
	 *
	 * @param \WP_User $agent     The dedicated agent.
	 * @param bool     $available Whether Application Passwords are available on the site.
	 * @return void
	 */
	private function agent_view( \WP_User $agent, bool $available ): void {
		$can_edit = current_user_can( 'edit_user', $agent->ID );

		printf( '<p><a href="%s">%s</a></p>', esc_url( $this->view_url( array() ) ), esc_html__( 'Back to all agents', 'steadhand' ) );

		echo '<div class="steadhand-agent">';
		printf( '<h2>%s</h2>', esc_html( $agent->user_login ) );

		$this->capabilities_section( $agent, $can_edit );
		$this->connections_section( $agent, $can_edit, $available );

		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url(
				add_query_arg(
					array(
						'page'  => LogScreen::SLUG,
						'agent' => (string) $agent->ID,
					),
					admin_url( 'admin.php' )
				)
			),
			esc_html__( 'View this agent\'s change-log entries', 'steadhand' )
		);

		try {
			/**
			 * Fires after one dedicated agent's detail on the Agents screen. Output printed here appears inside the agent's block.
			 *
			 * @param \WP_User $agent The dedicated agent.
			 */
			do_action( 'steadhand_admin_agent_row', $agent );
		} catch ( \Throwable ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The comment below says why the catch is empty.
			// A listener must not break the screen.
		}

		echo '</div>';
	}

	/**
	 * The capability checkboxes of an agent, or a plain list when the viewer may not edit it.
	 *
	 * @param \WP_User $agent    The agent.
	 * @param bool     $can_edit Whether the viewer may edit the agent.
	 * @return void
	 */
	private function capabilities_section( \WP_User $agent, bool $can_edit ): void {
		$offered = $this->handler->offered_capabilities();

		echo '<h3>' . esc_html__( 'Capabilities', 'steadhand' ) . '</h3>';

		if ( ! $can_edit ) {
			$granted = array_filter( $offered, static fn( string $capability ): bool => Capabilities::user_has( $agent, $capability ) );
			printf( '<p>%s</p>', esc_html( array() === $granted ? __( 'None', 'steadhand' ) : implode( ', ', $granted ) ) );
			return;
		}

		$this->open_form( AgentsHandler::ACTION_CAPABILITIES, AgentsHandler::nonce_action( AgentsHandler::ACTION_CAPABILITIES, $agent->ID ), $agent->ID );
		echo '<fieldset>';
		foreach ( $offered as $capability ) {
			$this->capability_checkbox( $capability, Capabilities::user_has( $agent, $capability ) );
		}
		echo '</fieldset>';
		submit_button( __( 'Save capabilities', 'steadhand' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * One capability checkbox, with its name in code and what it allows. Used by the create form and the agent's capabilities.
	 *
	 * @param string $capability A Steadhand capability.
	 * @param bool   $checked    Whether the box is checked.
	 * @return void
	 */
	private function capability_checkbox( string $capability, bool $checked ): void {
		printf(
			'<p><label><input type="checkbox" name="capabilities[]" value="%1$s"%2$s> <code>%1$s</code> &ndash; %3$s</label></p>',
			esc_attr( $capability ),
			checked( $checked, true, false ),
			esc_html( self::capability_text( $capability ) )
		);
	}

	/**
	 * The connections of an agent: each is an Application Password, with a revoke button for each, and the form that adds one. The table
	 * shows the name, created, last used and last IP, never the hash.
	 *
	 * @param \WP_User $agent     The agent.
	 * @param bool     $can_edit  Whether the viewer may edit the agent.
	 * @param bool     $available Whether Application Passwords are available on the site.
	 * @return void
	 */
	private function connections_section( \WP_User $agent, bool $can_edit, bool $available ): void {
		$passwords = \WP_Application_Passwords::get_user_application_passwords( $agent->ID );

		echo '<h3>' . esc_html__( 'Connections', 'steadhand' ) . '</h3>';
		printf( '<p>%s</p>', esc_html__( 'Each connection is an Application Password that one app uses to sign in as this agent.', 'steadhand' ) );

		if ( array() === $passwords ) {
			printf( '<p>%s</p>', esc_html__( 'This agent has no connections, so it cannot connect.', 'steadhand' ) );
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			printf(
				'<th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th><th scope="col"><span class="screen-reader-text">%s</span></th>',
				esc_html__( 'Connection name', 'steadhand' ),
				esc_html__( 'Created', 'steadhand' ),
				esc_html__( 'Last used', 'steadhand' ),
				esc_html__( 'Last IP', 'steadhand' ),
				esc_html__( 'Actions', 'steadhand' )
			);
			echo '</tr></thead><tbody>';

			foreach ( $passwords as $password ) {
				echo '<tr>';
				printf( '<td>%s</td>', esc_html( (string) ( $password['name'] ?? '' ) ) );
				printf( '<td>%s</td>', esc_html( $this->format_time( $password['created'] ?? null ) ) );
				printf( '<td>%s</td>', esc_html( $this->format_time( $password['last_used'] ?? null ) ) );
				printf( '<td>%s</td>', esc_html( (string) ( $password['last_ip'] ?? '' ) ) );
				echo '<td>';
				if ( $can_edit ) {
					$this->open_form( AgentsHandler::ACTION_REVOKE, AgentsHandler::nonce_action( AgentsHandler::ACTION_REVOKE, $agent->ID ), $agent->ID );
					printf( '<input type="hidden" name="uuid" value="%s">', esc_attr( (string) ( $password['uuid'] ?? '' ) ) );
					submit_button( __( 'Revoke', 'steadhand' ), 'small', 'submit', false );
					echo '</form>';
				}
				echo '</td></tr>';
			}

			echo '</tbody></table>';

			if ( $can_edit ) {
				$this->open_form( AgentsHandler::ACTION_REVOKE_ALL, AgentsHandler::nonce_action( AgentsHandler::ACTION_REVOKE_ALL, $agent->ID ), $agent->ID );
				submit_button( __( 'Revoke all passwords', 'steadhand' ), 'delete', 'submit', false );
				echo '</form>';
			}
		}

		if ( $can_edit && $available ) {
			$this->open_form( AgentsHandler::ACTION_ADD_PASSWORD, AgentsHandler::nonce_action( AgentsHandler::ACTION_ADD_PASSWORD, $agent->ID ), $agent->ID );
			printf(
				'<p><label>%s <input type="text" name="label" class="regular-text" maxlength="60" required></label></p>',
				esc_html__( 'Connection name', 'steadhand' )
			);
			submit_button( __( 'Add connection', 'steadhand' ), 'secondary', 'submit', false );
			echo '</form>';
		}
	}

	/**
	 * Opens a form that posts to the screen, with its action field and nonce (and the target user, where there is one).
	 *
	 * @param string $action       The action field value.
	 * @param string $nonce_action The nonce action.
	 * @param int    $user_id      The target user, or 0 for none.
	 * @return void
	 */
	private function open_form( string $action, string $nonce_action, int $user_id = 0 ): void {
		printf(
			'<form method="post" action="%s">',
			esc_url( add_query_arg( 'page', self::SLUG, admin_url( 'admin.php' ) ) )
		);
		printf( '<input type="hidden" name="%s" value="%s">', esc_attr( AgentsHandler::FIELD ), esc_attr( $action ) );
		if ( $user_id > 0 ) {
			printf( '<input type="hidden" name="user_id" value="%s">', esc_attr( (string) $user_id ) );
		}
		printf( '<input type="hidden" name="_wpnonce" value="%s">', esc_attr( wp_create_nonce( $nonce_action ) ) );
	}

	/**
	 * A timestamp as a site date, or a dash when there is none.
	 *
	 * @param mixed $timestamp Unix time, or null.
	 * @return string
	 */
	private function format_time( mixed $timestamp ): string {
		if ( ! is_int( $timestamp ) || $timestamp < 1 ) {
			return '-';
		}

		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
	}

	/**
	 * A short name of a capability, for the list of agents.
	 *
	 * @param string $capability The capability.
	 * @return string
	 */
	private static function capability_label( string $capability ): string {
		return match ( $capability ) {
			Capabilities::CONNECT      => __( 'Connect', 'steadhand' ),
			Capabilities::READ         => __( 'Read', 'steadhand' ),
			Capabilities::WRITE_DRAFTS => __( 'Write drafts', 'steadhand' ),
			Capabilities::PUBLISH      => __( 'Publish', 'steadhand' ),
			Capabilities::MEDIA        => __( 'Media', 'steadhand' ),
			default                    => $capability,
		};
	}

	/**
	 * What a capability allows, in one plain sentence.
	 *
	 * @param string $capability A Steadhand capability.
	 * @return string
	 */
	private static function capability_text( string $capability ): string {
		return match ( $capability ) {
			Capabilities::CONNECT      => __( 'Connect to the Steadhand endpoint at all. Turn this off to disable the agent without deleting it.', 'steadhand' ),
			Capabilities::READ         => __( 'Read posts and pages (not the trash), blocks, terms, menus, media lists, site information, diagnostics and its own change log.', 'steadhand' ),
			Capabilities::WRITE_DRAFTS => __( 'Create posts, which are always drafts, and edit drafts, pending and auto-draft posts. It cannot publish.', 'steadhand' ),
			Capabilities::PUBLISH      => __( 'Edit and publish live posts (published, scheduled or private). This changes the public site.', 'steadhand' ),
			Capabilities::MEDIA        => __( 'Upload media and set featured images. An uploaded file is public at once, at its uploads URL. This changes the public site.', 'steadhand' ),
			default                    => $capability,
		};
	}
}
