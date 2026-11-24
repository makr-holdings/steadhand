<?php
/**
 * Adds Steadhand's admin menu and its screen's styles.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the Steadhand menu: the change log, and the Agents screen beneath it. The menu needs manage_options, and each screen
 * checks it again when it renders.
 */
final class Admin {

	/**
	 * Hook suffixes of the change-log pages (the top-level menu and its first submenu entry), set when the menu is added.
	 *
	 * @var string[]
	 */
	private array $log_hook_suffixes = array();

	/**
	 * Constructor.
	 *
	 * @param LogScreen         $log_screen   The change-log screen.
	 * @param UndoHandler|null  $undo_handler The handler of the screen's Undo button. Null leaves the button's action unregistered;
	 *                                        Plugin always passes one.
	 * @param AgentsScreen|null $agents_screen The Agents screen. Null leaves the screen out of the menu; Plugin always passes one.
	 */
	public function __construct( private LogScreen $log_screen, private ?UndoHandler $undo_handler = null, private ?AgentsScreen $agents_screen = null ) {}

	/**
	 * Hooks the menu and the styles into WordPress.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );

		// Logged-in only: there is no nopriv variant, so a visitor cannot reach the undo.
		if ( null !== $this->undo_handler ) {
			add_action( 'admin_post_' . UndoHandler::ACTION, array( $this->undo_handler, 'handle' ) );
		}
	}

	/**
	 * Adds the menu page, its change-log entry and the Agents screen.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		$suffix = add_menu_page(
			__( 'Steadhand change log', 'steadhand' ),
			__( 'Steadhand', 'steadhand' ),
			'manage_options',
			LogScreen::SLUG,
			array( $this->log_screen, 'render' ),
			'dashicons-shield',
			81
		);

		$this->log_hook_suffixes[] = is_string( $suffix ) ? $suffix : '';

		$log_suffix = add_submenu_page(
			LogScreen::SLUG,
			__( 'Change log', 'steadhand' ),
			__( 'Change log', 'steadhand' ),
			'manage_options',
			LogScreen::SLUG,
			array( $this->log_screen, 'render' )
		);

		$this->log_hook_suffixes[] = is_string( $log_suffix ) ? $log_suffix : '';

		if ( null === $this->agents_screen ) {
			return;
		}

		$agents_suffix = add_submenu_page(
			LogScreen::SLUG,
			__( 'Agents', 'steadhand' ),
			__( 'Agents', 'steadhand' ),
			'manage_options',
			AgentsScreen::SLUG,
			array( $this->agents_screen, 'render' )
		);

		if ( is_string( $agents_suffix ) && '' !== $agents_suffix ) {
			// Runs before the page renders. A POST is handled here, so a new password can be shown in its own response.
			add_action( 'load-' . $agents_suffix, array( $this->agents_screen, 'load' ) );
		}
	}

	/**
	 * Loads core's revisions stylesheet, for the diff table, on the Steadhand page only.
	 *
	 * @param string $hook_suffix The current admin page's hook suffix.
	 * @return void
	 */
	public function enqueue_styles( string $hook_suffix ): void {
		if ( in_array( $hook_suffix, array_filter( $this->log_hook_suffixes ), true ) ) {
			wp_enqueue_style( 'revisions' );
		}
	}
}
