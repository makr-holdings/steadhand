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
 * Registers the Steadhand menu. The menu needs manage_options, and the screen checks it again when it renders.
 */
final class Admin {

	/**
	 * Hook suffix of the menu page, set when the menu is added. Empty until then.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Constructor.
	 *
	 * @param LogScreen        $log_screen   The change-log screen.
	 * @param UndoHandler|null $undo_handler The handler of the screen's Undo button. Null leaves the button's action unregistered;
	 *                                       Plugin always passes one.
	 */
	public function __construct( private LogScreen $log_screen, private ?UndoHandler $undo_handler = null ) {}

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
	 * Adds the menu page.
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

		$this->hook_suffix = is_string( $suffix ) ? $suffix : '';
	}

	/**
	 * Loads core's revisions stylesheet, for the diff table, on the Steadhand page only.
	 *
	 * @param string $hook_suffix The current admin page's hook suffix.
	 * @return void
	 */
	public function enqueue_styles( string $hook_suffix ): void {
		if ( '' !== $this->hook_suffix && $hook_suffix === $this->hook_suffix ) {
			wp_enqueue_style( 'revisions' );
		}
	}
}
