<?php
/**
 * Environment, debug settings and the active theme for the diagnostics report.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Diagnostics;

use Steadhand\Abilities\PostReader;

defined( 'ABSPATH' ) || exit;

/**
 * Reads server, WordPress and theme facts. Only values are returned: never the database host, name, user, prefix or
 * password, and never a file path (WP_DEBUG_LOG is reported as on or off).
 */
final class Environment {

	/**
	 * Builds the environment section.
	 *
	 * @return array<string, mixed>
	 */
	public static function report(): array {
		global $wpdb;

		$version = (string) $wpdb->db_version();
		$info    = (string) $wpdb->db_server_info();

		if ( false !== stripos( $info, 'mariadb' ) ) {
			$server = 'MariaDB';
		} elseif ( $wpdb->is_mysql && '' !== $version ) {
			$server = 'MySQL';
		} else {
			$server = 'unknown';
		}

		return array(
			'wordpress_version'   => PostReader::cut_utf8( (string) get_bloginfo( 'version' ), 50 ),
			'php_version'         => PHP_VERSION,
			'database'            => array(
				'server'  => $server,
				'version' => PostReader::cut_utf8( $version, 50 ),
			),
			'memory_limit'        => PostReader::cut_utf8( (string) ini_get( 'memory_limit' ), 50 ),
			'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
			'upload_max_filesize' => PostReader::cut_utf8( (string) ini_get( 'upload_max_filesize' ), 50 ),
			'is_multisite'        => is_multisite(),
			'environment_type'    => PostReader::cut_utf8( (string) wp_get_environment_type(), 50 ),
			'debug'               => self::debug(),
			'object_cache'        => (bool) wp_using_ext_object_cache(),
			'https'               => 'https' === wp_parse_url( home_url(), PHP_URL_SCHEME ),
			'timezone'            => PostReader::cut_utf8( (string) wp_timezone_string(), 100 ),
			'locale'              => PostReader::cut_utf8( (string) get_locale(), 20 ),
		);
	}

	/**
	 * The debug constants. WP_DEBUG_LOG is reported as on or off, and its path is never returned.
	 *
	 * @return array{wp_debug: bool, wp_debug_log: bool, wp_debug_display: bool, script_debug: bool}
	 */
	private static function debug(): array {
		$log = defined( 'WP_DEBUG_LOG' ) ? constant( 'WP_DEBUG_LOG' ) : false;

		return array(
			'wp_debug'         => defined( 'WP_DEBUG' ) && (bool) constant( 'WP_DEBUG' ),
			'wp_debug_log'     => true === $log || ( is_string( $log ) && '' !== $log ),
			'wp_debug_display' => defined( 'WP_DEBUG_DISPLAY' ) && (bool) constant( 'WP_DEBUG_DISPLAY' ),
			'script_debug'     => defined( 'SCRIPT_DEBUG' ) && (bool) constant( 'SCRIPT_DEBUG' ),
		);
	}

	/**
	 * The active theme and whether it is a child or a block theme.
	 *
	 * @return array{name: string, version: string, is_child: bool, parent_name: string, is_block_theme: bool}
	 */
	public static function theme(): array {
		$theme  = wp_get_theme();
		$parent = $theme->parent();

		return array(
			'name'           => PostReader::label_text( (string) $theme->get( 'Name' ), 200 ),
			'version'        => PostReader::label_text( (string) $theme->get( 'Version' ), 50 ),
			'is_child'       => false !== $parent,
			'parent_name'    => false !== $parent ? PostReader::label_text( (string) $parent->get( 'Name' ), 200 ) : '',
			'is_block_theme' => (bool) wp_is_block_theme(),
		);
	}
}
