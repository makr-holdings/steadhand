<?php
/**
 * The active plugin list for the diagnostics report.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Diagnostics;

use Steadhand\Abilities\PostReader;

defined( 'ABSPATH' ) || exit;

/**
 * Lists the active plugins by name and version. Update status comes only from the update_plugins transient that already
 * exists: this class never asks WordPress to check for updates, and never makes an outbound request.
 */
final class Plugins {

	/**
	 * Most plugins listed.
	 */
	public const MAX_ITEMS = 100;

	/**
	 * Builds the plugins section.
	 *
	 * @return array{active_count: int, items: array<int, array{name: string, version: string, update_available: bool}>, truncated: bool}
	 */
	public static function report(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all     = get_plugins();
		$active  = get_option( 'active_plugins', array() );
		$updates = self::updates();
		$items   = array();

		foreach ( is_array( $active ) ? $active : array() as $file ) {
			if ( ! is_string( $file ) || ! isset( $all[ $file ] ) || ! is_array( $all[ $file ] ) ) {
				continue;
			}

			$items[] = array(
				'name'             => PostReader::label_text( (string) ( $all[ $file ]['Name'] ?? '' ), 200 ),
				'version'          => PostReader::label_text( (string) ( $all[ $file ]['Version'] ?? '' ), 100 ),
				'update_available' => isset( $updates[ $file ] ),
			);
		}

		usort(
			$items,
			static function ( array $a, array $b ): int {
				$by_name = strcasecmp( $a['name'], $b['name'] );

				return 0 !== $by_name ? $by_name : strcmp( $a['version'], $b['version'] );
			}
		);

		return array(
			'active_count' => count( $items ),
			'items'        => array_slice( $items, 0, self::MAX_ITEMS ),
			'truncated'    => count( $items ) > self::MAX_ITEMS,
		);
	}

	/**
	 * The plugin files that the stored update check lists as having an update.
	 *
	 * @return array<string, mixed>
	 */
	private static function updates(): array {
		$value = Transients::get_site( 'update_plugins' );

		$response = array();
		if ( is_object( $value ) && isset( $value->response ) ) {
			$response = $value->response;
		} elseif ( is_array( $value ) && isset( $value['response'] ) ) {
			$response = $value['response'];
		}

		return is_array( $response ) ? $response : array();
	}
}
