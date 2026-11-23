<?php
/**
 * A read-only test ability whose input check, object check or annotations can be made to throw.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\Ability;
use Steadhand\Capabilities;

/**
 * Drives the permission and execute callbacks through Registry::args_for() with a throw at one chosen point.
 */
final class PermissionThrowAbility extends Ability {

	/**
	 * Whether validate_input() throws.
	 *
	 * @var bool
	 */
	public bool $throw_in_validate = false;

	/**
	 * Whether authorize_object() throws.
	 *
	 * @var bool
	 */
	public bool $throw_in_authorize = false;

	/**
	 * Whether annotations() throws.
	 *
	 * @var bool
	 */
	public bool $throw_in_annotations = false;

	/**
	 * Whether input_schema() throws.
	 *
	 * @var bool
	 */
	public bool $throw_in_schema = false;

	/**
	 * Whether validate_input() returns false, which is neither true nor a WP_Error.
	 *
	 * @var bool
	 */
	public bool $return_false_from_validate = false;

	/**
	 * Whether execute() ran.
	 *
	 * @var bool
	 */
	public bool $executed = false;

	public function name(): string {
		return 'steadhand/zz-permission-throw';
	}

	public function label(): string {
		return 'Permission throw';
	}

	public function description(): string {
		return 'Test ability.';
	}

	public function input_schema(): array {
		if ( $this->throw_in_schema ) {
			throw new \RuntimeException( 'SECRET /var/www/secret-path' );
		}

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(),
		);
	}

	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array( 'ok' => array( 'type' => 'boolean' ) ),
		);
	}

	public function annotations(): array {
		if ( $this->throw_in_annotations ) {
			throw new \RuntimeException( 'SECRET /var/www/secret-path' );
		}

		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	public function capability(): string {
		return Capabilities::READ;
	}

	public function validate_input( array $input ): bool|\WP_Error {
		unset( $input );
		if ( $this->throw_in_validate ) {
			throw new \RuntimeException( 'SECRET /var/www/secret-path' );
		}

		if ( $this->return_false_from_validate ) {
			return false;
		}

		return true;
	}

	public function authorize_object( array $input ): bool {
		unset( $input );
		if ( $this->throw_in_authorize ) {
			throw new \RuntimeException( 'SECRET /var/www/secret-path' );
		}

		return true;
	}

	public function execute( array $input ): array|\WP_Error {
		unset( $input );
		$this->executed = true;

		return array( 'ok' => true );
	}
}
