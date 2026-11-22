<?php
/**
 * Site Health counts from the result that Site Health already stored.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the health-check-site-status-result transient, which WordPress writes when someone runs Site Health in wp-admin.
 *
 * No Site Health test is run from here. Those tests make loopback and outbound HTTP requests. If the transient is
 * missing, expired or not in the expected shape, the section says so and reports no counts.
 */
final class SiteHealth {

	/**
	 * Transient name that Site Health stores its counts under.
	 */
	public const TRANSIENT = 'health-check-site-status-result';

	/**
	 * Largest count accepted as valid.
	 */
	public const MAX_COUNT = 100000;

	/**
	 * Builds the site_health section.
	 *
	 * @return array{available: bool, good: int|null, recommended: int|null, critical: int|null}
	 */
	public static function report(): array {
		$counts = self::counts( Transients::get( self::TRANSIENT ) );

		if ( null === $counts ) {
			return array(
				'available'   => false,
				'good'        => null,
				'recommended' => null,
				'critical'    => null,
			);
		}

		return array(
			'available'   => true,
			'good'        => $counts['good'],
			'recommended' => $counts['recommended'],
			'critical'    => $counts['critical'],
		);
	}

	/**
	 * Parses the stored value. Anything that is not a JSON object of three non-negative integers gives null.
	 *
	 * @param mixed $stored The stored transient value.
	 * @return array{good: int, recommended: int, critical: int}|null
	 */
	public static function counts( mixed $stored ): ?array {
		if ( ! is_string( $stored ) ) {
			return null;
		}

		$data = json_decode( $stored, true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$counts = array();
		foreach ( array( 'good', 'recommended', 'critical' ) as $key ) {
			$value = $data[ $key ] ?? null;
			if ( ! is_int( $value ) || $value < 0 || $value > self::MAX_COUNT ) {
				return null;
			}
			$counts[ $key ] = $value;
		}

		return $counts;
	}
}
