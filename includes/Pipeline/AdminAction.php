<?php
/**
 * Marks an ability that only Pipeline::run_admin() may run: an administrator's action, never an agent's.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * An ability that implements this interface is run only by Pipeline::run_admin(), which requires an administrator who holds no
 * Steadhand agent role. Pipeline::run() and Pipeline::check() refuse it, so no MCP call can reach it even if it were
 * registered by mistake. It has no methods: the marker is the whole contract.
 */
interface AdminAction {
}
