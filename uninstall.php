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
	\Steadhand\Log\Schema::uninstall();

	// The markers steadhand/trash-post stores on a post it trashed, from the one list of keys (TrashRules::MARKER_KEYS). They are
	// removed from the current site's posts only, as the rest of uninstall is (SPEC D13). Like the rest, they are removed only when
	// the autoloader is present, so a source checkout without vendor/ removes none of them.
	array_map( 'delete_post_meta_by_key', \Steadhand\Pipeline\TrashRules::MARKER_KEYS );
}
