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
}

// The markers steadhand/trash-post stores on a post it trashed (PostWriter::TRASHED_BY_META and TRASH_TIME_META).
delete_post_meta_by_key( '_steadhand_trashed_by' );
delete_post_meta_by_key( '_steadhand_trash_time' );
