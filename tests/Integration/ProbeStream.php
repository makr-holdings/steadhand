<?php
/**
 * A stream wrapper that counts the calls made to it, for the log reader tests.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

/**
 * Nothing this wrapper does is read from. Any call to it is a leak to the filesystem layer, so the count must stay at
 * zero.
 */
final class ProbeStream {

	/**
	 * Number of calls made to this wrapper.
	 *
	 * @var int
	 */
	public static int $calls = 0;

	/**
	 * Records a stat call.
	 *
	 * @param string $path  Path.
	 * @param int    $flags Flags.
	 * @return bool Always false.
	 */
	public function url_stat( string $path, int $flags ): bool {
		unset( $path, $flags );
		++self::$calls;

		return false;
	}
}
