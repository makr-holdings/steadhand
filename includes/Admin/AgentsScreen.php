<?php
/**
 * The Agents screen: lists the agent users, and shows the forms that create them, change their capabilities and manage their passwords.
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
 * Renders the Agents screen and reads its POST requests. The screen posts to its own URL. Create and add-password answer that POST
 * directly, and show a new password once in that response. Revoke and set-capabilities redirect with a fixed code, as the undo does.
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
	 * Runs the action of a POST request. Returns the redirect URL for revoke and set-capabilities, and null otherwise: create and
	 * add-password keep their result for the render, and a GET changes nothing.
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
			$username      = isset( $post['username'] ) && is_string( $post['username'] ) ? $post['username'] : '';
			$label         = isset( $post['label'] ) && is_string( $post['label'] ) ? $post['label'] : '';
			$this->outcome = $this->handler->create( $nonce, $username, $label );
			return null;
		}

		if ( AgentsHandler::ACTION_ADD_PASSWORD === $action ) {
			$label         = isset( $post['label'] ) && is_string( $post['label'] ) ? $post['label'] : '';
			$this->outcome = $this->handler->add_password( $nonce, $id, $label );
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

		return add_query_arg(
			array(
				'page'   => self::SLUG,
				'agents' => $result['code'],
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * Renders the page. Refuses everyone but an administrator who holds no agent role.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) || Agents::holds_agent_role( wp_get_current_user() ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'steadhand' ), esc_html__( 'Forbidden', 'steadhand' ), array( 'response' => 403 ) );
		}

		$available = wp_is_application_passwords_available();

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'Steadhand agents', 'steadhand' ) );

		$this->notice_for_request();

		if ( null !== $this->outcome && null !== $this->outcome['secret'] ) {
			$this->secret_panel( $this->outcome['secret'] );
		}

		if ( ! $available ) {
			$this->notice( 'warning', __( 'Application Passwords are not available on this site, most often because the site is not served over HTTPS. Agents cannot be given credentials until this is fixed, so the forms for creating an agent and adding a password are hidden.', 'steadhand' ) );
		} elseif ( current_user_can( 'create_users' ) ) {
			$this->create_form();
		}

		printf( '<h2>%s</h2>', esc_html__( 'Agents', 'steadhand' ) );

		$users = get_users(
			array(
				'role'    => Roles::ROLE,
				'number'  => self::LIST_LIMIT,
				'orderby' => 'login',
				'order'   => 'ASC',
			)
		);

		if ( array() === $users ) {
			printf( '<p>%s</p>', esc_html__( 'There are no agents yet.', 'steadhand' ) );
		}

		foreach ( $users as $user ) {
			if ( ! $user instanceof \WP_User ) {
				continue;
			}

			if ( Agents::is_agent( $user ) ) {
				$this->agent_block( $user, $available );
			} elseif ( Agents::holds_agent_role( $user ) ) {
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

		echo '</div>';
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
	 *
	 * @return void
	 */
	private function notice_for_request(): void {
		if ( null !== $this->outcome ) {
			$this->notice_for_code( $this->outcome['code'] );
			return;
		}

		$query = isset( $_GET['agents'] ) ? sanitize_text_field( wp_unslash( $_GET['agents'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only view; only a fixed code is printed.
		if ( is_string( $query ) ) {
			$this->notice_for_code( $query );
		}
	}

	/**
	 * Prints the notice of a result code. An unknown code prints nothing.
	 *
	 * @param string $code The result code.
	 * @return void
	 */
	private function notice_for_code( string $code ): void {
		$messages = self::messages();

		if ( ! in_array( $code, AgentsHandler::CODES, true ) || ! isset( $messages[ $code ] ) ) {
			return;
		}

		$this->notice( $messages[ $code ][0], $messages[ $code ][1] );
	}

	/**
	 * The fixed notice of every result code: its type and its text.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function messages(): array {
		return array(
			'created'                   => array( 'success', __( 'The agent was created. Its password is shown above.', 'steadhand' ) ),
			'password_added'            => array( 'success', __( 'The password was added. It is shown above.', 'steadhand' ) ),
			'password_revoked'          => array( 'success', __( 'The password was revoked.', 'steadhand' ) ),
			'passwords_revoked'         => array( 'success', __( 'All of the agent\'s passwords were revoked.', 'steadhand' ) ),
			'capabilities_saved'        => array( 'success', __( 'The capabilities were saved.', 'steadhand' ) ),
			'capabilities_mismatch'     => array( 'error', __( 'The capabilities could not be confirmed after saving. Check them before the agent is used.', 'steadhand' ) ),
			'invalid_username'          => array( 'error', __( 'The username is not valid. Use 1 to 60 lower-case letters, digits, underscores or hyphens, starting with a letter or digit.', 'steadhand' ) ),
			'username_taken'            => array( 'error', __( 'That username already exists.', 'steadhand' ) ),
			'invalid_label'             => array( 'error', __( 'The label is not valid. Use 1 to 60 characters on one line, without markup or leading or trailing spaces.', 'steadhand' ) ),
			'label_taken'               => array( 'error', __( 'The agent already has a password with that label.', 'steadhand' ) ),
			'too_many_passwords'        => array( 'error', __( 'The agent already has the most Application Passwords allowed. Revoke one first.', 'steadhand' ) ),
			'app_passwords_unavailable' => array( 'error', __( 'Application Passwords are not available for this user, so no password was made.', 'steadhand' ) ),
			'not_agent'                 => array( 'error', __( 'That user is not a dedicated agent, so nothing was changed.', 'steadhand' ) ),
			'password_not_found'        => array( 'error', __( 'That password was not found for this agent.', 'steadhand' ) ),
			'create_failed'             => array( 'error', __( 'The agent could not be created.', 'steadhand' ) ),
			'password_failed'           => array( 'error', __( 'The password could not be saved. Nothing was left behind.', 'steadhand' ) ),
			'capabilities_failed'       => array( 'error', __( 'The capabilities could not be saved.', 'steadhand' ) ),
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
	 * The form that creates an agent. Shown only to a viewer who holds create_users.
	 *
	 * @return void
	 */
	private function create_form(): void {
		echo '<h2>' . esc_html__( 'Create an agent', 'steadhand' ) . '</h2>';
		$this->open_form( AgentsHandler::ACTION_CREATE, AgentsHandler::nonce_action( AgentsHandler::ACTION_CREATE ) );
		echo '<table class="form-table" role="presentation"><tbody>';
		printf(
			'<tr><th scope="row"><label for="steadhand-agent-username">%s</label></th><td><input type="text" id="steadhand-agent-username" name="username" class="regular-text" maxlength="60" pattern="[a-z0-9][a-z0-9_\\-]{0,59}" required></td></tr>',
			esc_html__( 'Username', 'steadhand' )
		);
		printf(
			'<tr><th scope="row"><label for="steadhand-agent-label">%s</label></th><td><input type="text" id="steadhand-agent-label" name="label" class="regular-text" maxlength="60" required><p class="description">%s</p></td></tr>',
			esc_html__( 'Label of the first password', 'steadhand' ),
			esc_html__( 'The label appears in the change log, so name the agent so you can tell it apart from others.', 'steadhand' )
		);
		echo '</tbody></table>';
		submit_button( __( 'Create agent', 'steadhand' ) );
		echo '</form>';
	}

	/**
	 * One dedicated agent: its capabilities, its passwords, and the forms that change them, when the viewer may edit it.
	 *
	 * @param \WP_User $agent     The dedicated agent.
	 * @param bool     $available Whether Application Passwords are available on the site.
	 * @return void
	 */
	private function agent_block( \WP_User $agent, bool $available ): void {
		$can_edit = current_user_can( 'edit_user', $agent->ID );

		echo '<div class="steadhand-agent">';
		printf( '<h3>%s</h3>', esc_html( $agent->user_login ) );

		$this->capabilities_section( $agent, $can_edit );
		$this->passwords_section( $agent, $can_edit, $available );

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
			 * Fires after one dedicated agent's block on the Agents screen. Output printed here appears inside the block.
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

		echo '<h4>' . esc_html__( 'Capabilities', 'steadhand' ) . '</h4>';

		if ( ! $can_edit ) {
			$granted = array_filter( $offered, static fn( string $capability ): bool => Capabilities::user_has( $agent, $capability ) );
			printf( '<p>%s</p>', esc_html( array() === $granted ? __( 'None', 'steadhand' ) : implode( ', ', $granted ) ) );
			return;
		}

		$this->open_form( AgentsHandler::ACTION_CAPABILITIES, AgentsHandler::nonce_action( AgentsHandler::ACTION_CAPABILITIES, $agent->ID ), $agent->ID );
		echo '<fieldset>';
		foreach ( $offered as $capability ) {
			printf(
				'<p><label><input type="checkbox" name="capabilities[]" value="%1$s"%2$s> <code>%1$s</code> &ndash; %3$s</label></p>',
				esc_attr( $capability ),
				checked( Capabilities::user_has( $agent, $capability ), true, false ),
				esc_html( self::capability_text( $capability ) )
			);
		}
		echo '</fieldset>';
		submit_button( __( 'Save capabilities', 'steadhand' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * The application passwords of an agent (name, created, last used, last IP, never the hash), with a revoke button for each, and the
	 * form that adds one.
	 *
	 * @param \WP_User $agent     The agent.
	 * @param bool     $can_edit  Whether the viewer may edit the agent.
	 * @param bool     $available Whether Application Passwords are available on the site.
	 * @return void
	 */
	private function passwords_section( \WP_User $agent, bool $can_edit, bool $available ): void {
		$passwords = \WP_Application_Passwords::get_user_application_passwords( $agent->ID );

		echo '<h4>' . esc_html__( 'Application passwords', 'steadhand' ) . '</h4>';

		if ( array() === $passwords ) {
			printf( '<p>%s</p>', esc_html__( 'This agent has no application passwords, so it cannot connect.', 'steadhand' ) );
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			printf(
				'<th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th><th scope="col"><span class="screen-reader-text">%s</span></th>',
				esc_html__( 'Name', 'steadhand' ),
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
				esc_html__( 'Label of the new password', 'steadhand' )
			);
			submit_button( __( 'Add password', 'steadhand' ), 'secondary', 'submit', false );
			echo '</form>';
		}
	}

	/**
	 * Opens a form that posts to the screen, with its action field and nonce (and the target user, where there is one).
	 *
	 * @param string $action      The action field value.
	 * @param string $nonce_action The nonce action.
	 * @param int    $user_id     The target user, or 0 for none.
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
