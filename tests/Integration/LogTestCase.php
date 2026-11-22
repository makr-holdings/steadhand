<?php
/**
 * Base for the change-log tests.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

/**
 * Base for the change-log tests. The table helpers come from the LogRows trait.
 */
abstract class LogTestCase extends TestCase {

	use LogRows;
}
