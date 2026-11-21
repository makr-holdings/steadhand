<?php
/**
 * Checks the plugin's runtime dependencies and reports anything missing.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand;

defined( 'ABSPATH' ) || exit;

/**
 * Dependency check. When something is missing, Steadhand shows a notice and registers nothing.
 */
final class Requirements {

	/**
	 * Record which dependencies are present.
	 *
	 * @param bool $has_abilities_api Whether the Abilities API is available.
	 * @param bool $has_mcp_adapter   Whether the MCP Adapter is available.
	 */
	public function __construct( private bool $has_abilities_api, private bool $has_mcp_adapter ) {}

	/**
	 * Detect the dependencies in the running environment.
	 *
	 * @return self
	 */
	public static function detect(): self {
		return new self(
			function_exists( 'wp_register_ability' ),
			class_exists( '\WP\MCP\Core\McpAdapter' )
		);
	}

	/**
	 * Whether every dependency is present.
	 *
	 * @return bool
	 */
	public function are_met(): bool {
		return $this->has_abilities_api && $this->has_mcp_adapter;
	}

	/**
	 * Human-readable labels for the missing dependencies, in display order.
	 *
	 * @return string[]
	 */
	public function missing(): array {
		$missing = array();

		if ( ! $this->has_abilities_api ) {
			$missing[] = __( 'Abilities API (WordPress 6.9+)', 'steadhand' );
		}

		if ( ! $this->has_mcp_adapter ) {
			$missing[] = __( 'MCP Adapter', 'steadhand' );
		}

		return $missing;
	}

	/**
	 * Print an error notice naming the missing dependencies.
	 *
	 * Only users who can activate plugins see it. Everyone else gets no output.
	 *
	 * @return void
	 */
	public function render_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %s: comma-separated list of missing dependencies. */
					__( 'Steadhand is inactive because it needs: %s.', 'steadhand' ),
					implode( ', ', $this->missing() )
				)
			)
		);
	}
}
