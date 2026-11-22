<?php
/**
 * Reads existing transients for the diagnostics report, without deleting expired ones.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a transient the way get_transient() does, except that an expired one is reported as missing and left in place.
 *
 * Core's get_transient() deletes an expired value when it reads it. The diagnostics ability must write nothing, so it reads
 * the stored value and its timeout itself. With a persistent object cache, WordPress keeps transients in the cache, so the
 * core functions are used there (they do not write to the database).
 */
final class Transients {

	/**
	 * Reads a site-wide transient. Single-site only in M1 (SPEC D13).
	 *
	 * @param string $name Transient name, without any prefix.
	 * @return mixed The stored value, or false when it is missing or expired.
	 */
	public static function get_site( string $name ): mixed {
		if ( wp_using_ext_object_cache() ) {
			return get_site_transient( $name );
		}

		return self::stored( '_site_transient_', $name, true );
	}

	/**
	 * Reads a per-site transient.
	 *
	 * @param string $name Transient name, without any prefix.
	 * @return mixed The stored value, or false when it is missing or expired.
	 */
	public static function get( string $name ): mixed {
		if ( wp_using_ext_object_cache() ) {
			return get_transient( $name );
		}

		return self::stored( '_transient_', $name, false );
	}

	/**
	 * Reads a transient's value and timeout from the options table, and ignores it when it has expired.
	 *
	 * @param string $prefix Option name prefix: _transient_ or _site_transient_.
	 * @param string $name   Transient name.
	 * @param bool   $site   Whether to read through the site option API.
	 * @return mixed
	 */
	private static function stored( string $prefix, string $name, bool $site ): mixed {
		$timeout = $site ? get_site_option( $prefix . 'timeout_' . $name, false ) : get_option( $prefix . 'timeout_' . $name, false );
		if ( false !== $timeout && (int) $timeout < time() ) {
			return false;
		}

		return $site ? get_site_option( $prefix . $name, false ) : get_option( $prefix . $name, false );
	}
}
