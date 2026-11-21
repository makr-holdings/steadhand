<?php
/**
 * Uninstall handler: removes the Steadhand agent role and capabilities.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
	\Steadhand\Roles::uninstall();
}
