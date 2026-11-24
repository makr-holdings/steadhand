<?php
/**
 * Plugin wiring: builds the services and hooks them into WordPress.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand;

defined( 'ABSPATH' ) || exit;

/**
 * Composes Steadhand's services and registers their hooks. Holds no logic of its own.
 */
final class Plugin {

	/**
	 * Ability registry.
	 *
	 * @var Abilities\Registry
	 */
	private Abilities\Registry $registry;

	/**
	 * MCP server registration.
	 *
	 * @var Server
	 */
	private Server $server;

	/**
	 * Agent lockdown: keeps agent credentials to the Steadhand endpoint.
	 *
	 * @var Lockdown
	 */
	private Lockdown $lockdown;

	/**
	 * Post capability mapping for dedicated agents (read_post in M1).
	 *
	 * @var MetaCaps
	 */
	private MetaCaps $meta_caps;

	/**
	 * Keeps user_has_cap filters from granting a Steadhand capability.
	 *
	 * @var CapabilityGuard
	 */
	private CapabilityGuard $capability_guard;

	/**
	 * The admin menu and the change-log screen.
	 *
	 * @var Admin\Admin
	 */
	private Admin\Admin $admin;

	/**
	 * Constructor. Builds the service graph; registers no hooks.
	 */
	public function __construct() {
		$change_log = new Log\DbLogger();

		// The rate limit reads the registry's write names when a write is checked, so it is built after the registry.
		$engine = new Guardrails\Engine(
			array(
				new Guardrails\Rules\RateLimit( $change_log, fn(): array => $this->registry->write_names() ),
				new Guardrails\Rules\DailyUploadLimit( $change_log ),
				new Guardrails\Rules\EditLock(),
			),
			array(
				new Guardrails\Rules\ContentLimits(),
				new Guardrails\Rules\BlockMarkup(),
			)
		);

		$pipeline               = new Pipeline\Pipeline( $change_log, $engine );
		$this->registry         = new Abilities\Registry( $pipeline, $change_log );
		$this->server           = new Server( $this->registry );
		$this->lockdown         = new Lockdown();
		$this->meta_caps        = new MetaCaps();
		$this->capability_guard = new CapabilityGuard();
		$this->admin            = new Admin\Admin(
			new Admin\LogScreen( $change_log, $this->registry ),
			new Admin\UndoHandler( $pipeline, $change_log )
		);
	}

	/**
	 * Returns the ability registry.
	 *
	 * @return Abilities\Registry
	 */
	public function registry(): Abilities\Registry {
		return $this->registry;
	}

	/**
	 * Returns the MCP server registration.
	 *
	 * @return Server
	 */
	public function server(): Server {
		return $this->server;
	}

	/**
	 * Hooks Steadhand into WordPress, or shows a notice if a dependency is missing.
	 *
	 * The lockdown, the capability guard and the change-log screen are registered first and whatever the requirements,
	 * because they depend only on WordPress core and the log table. Without the lockdown, an agent's application password
	 * would work on core REST and wp-login while the MCP Adapter is missing. Without the guard, a user_has_cap filter could
	 * grant a Steadhand capability. Without the screen, the audit trail could not be read while a dependency is missing.
	 * Only the ability and server wiring needs the dependencies.
	 *
	 * @param Requirements $requirements The detected dependency state.
	 * @return bool True when the ability and server hooks were registered.
	 */
	public function boot( Requirements $requirements ): bool {
		$this->lockdown->register();
		$this->capability_guard->register();
		$this->admin->register();

		if ( ! $requirements->are_met() ) {
			add_action( 'admin_notices', array( $requirements, 'render_notice' ) );
			return false;
		}

		// Before any ability is registered, so the table exists when the first call is logged.
		Log\Schema::maybe_upgrade();

		$this->meta_caps->register();
		add_action( 'wp_abilities_api_categories_init', array( $this->registry, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this->registry, 'register_abilities' ) );
		add_action( 'mcp_adapter_init', array( $this->server, 'register' ) );

		return true;
	}
}
