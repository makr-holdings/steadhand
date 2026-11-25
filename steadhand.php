<?php
/**
 * Steadhand – Secure AI Agent Access for WordPress
 *
 * @package     Steadhand
 * @author      MAKR Holdings LLC
 * @copyright   2026 MAKR Holdings LLC
 * @license     GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       Steadhand – Secure AI Agent Access for WordPress
 * Plugin URI:        https://github.com/makr-holdings/steadhand
 * Description:       Lets AI agents work on your site safely over MCP: least-privilege agent users, guardrails against site-breaking writes, and an audit trail with undo.
 * Version:           0.3.1
 * Requires at least: 6.9
 * Requires PHP:      8.1
 * Requires Plugins:  mcp-adapter
 * Author:            MAKR Holdings LLC
 * Author URI:        https://makrholdings.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       steadhand
 *
 * Steadhand is free software: you can redistribute it and/or modify it under
 * the terms of the GNU General Public License as published by the Free Software
 * Foundation, either version 2 of the License, or (at your option) any later
 * version.
 *
 * Steadhand is distributed in the hope that it will be useful, but WITHOUT ANY
 * WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR
 * A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

define( 'STEADHAND_VERSION', '0.3.1' );
define( 'STEADHAND_FILE', __FILE__ );
define( 'STEADHAND_DIR', plugin_dir_path( __FILE__ ) );

if ( PHP_VERSION_ID < 80100 ) {
	// WordPress refuses to activate the plugin on old PHP, but a host can still load it. Explain, and do nothing else.
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Steadhand is inactive because it needs PHP 8.1 or newer.', 'steadhand' )
			);
		}
	);
	return;
}

if ( ! is_readable( STEADHAND_DIR . 'vendor/autoload.php' ) ) {
	// A source checkout without `composer install`. Explain, and do nothing else.
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html__( 'Steadhand is inactive: its autoloader is missing. Install a release zip, or run "composer install" in the plugin folder.', 'steadhand' )
			);
		}
	);
	return;
}

require_once STEADHAND_DIR . 'vendor/autoload.php';

// Dev builds only: the dev updater exists in includes/Dev/ and is absent from the WordPress.org zip.
if ( is_readable( STEADHAND_DIR . 'includes/Dev/Updater.php' ) ) {
	require_once STEADHAND_DIR . 'includes/Dev/Updater.php';
}

register_activation_hook( __FILE__, array( \Steadhand\Roles::class, 'install' ) );
register_activation_hook( __FILE__, array( \Steadhand\Log\Schema::class, 'install' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		( new \Steadhand\Plugin() )->boot( \Steadhand\Requirements::detect() );
	}
);
