<?php
/**
 * A write ability whose log_input() throws, to check that the change log still gets a row.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\Ability;
use Steadhand\Capabilities;

/**
 * A write ability whose log_input() throws, to check that the change log still gets a row.
 */
final class ThrowingLogInputAbility extends Ability {

	public function name(): string {
		return 'steadhand/zz-throwing-log';
	}

	public function label(): string {
		return 'Throwing log input';
	}

	public function description(): string {
		return 'Test ability.';
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array( 'note' => array( 'type' => 'string' ) ),
		);
	}

	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array( 'ok' => array( 'type' => 'boolean' ) ),
		);
	}

	public function annotations(): array {
		return array(
			'readonly'    => false,
			'destructive' => false,
			'idempotent'  => false,
		);
	}

	public function capability(): string {
		return Capabilities::WRITE_DRAFTS;
	}

	public function authorize_object( array $input ): bool {
		unset( $input );

		return true;
	}

	public function log_input( array $input ): array {
		unset( $input );

		throw new \RuntimeException( 'log_input failed' );
	}

	public function execute( array $input ): array|\WP_Error {
		unset( $input );

		return array( 'ok' => true );
	}
}
