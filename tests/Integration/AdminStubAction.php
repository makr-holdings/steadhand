<?php
/**
 * A test ability that only Pipeline::run_admin() may run, with each of its checks made to throw or refuse on request.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\Ability;
use Steadhand\Abilities\PostReader;
use Steadhand\Capabilities;
use Steadhand\Pipeline\AdminAction;

/**
 * Its object check allows every call unless a flag says otherwise, so a refusal in a test comes from the pipeline's own checks.
 * The execute() call is recorded in $executed, which a test reads to prove that nothing ran.
 */
final class AdminStubAction extends Ability implements AdminAction {

	/**
	 * Whether validate_input() throws.
	 *
	 * @var bool
	 */
	public bool $throw_in_validate = false;

	/**
	 * Whether validate_input() returns false, which is neither true nor a WP_Error.
	 *
	 * @var bool
	 */
	public bool $return_false_from_validate = false;

	/**
	 * Whether authorize_object() throws.
	 *
	 * @var bool
	 */
	public bool $throw_in_authorize = false;

	/**
	 * Whether execute() ran.
	 *
	 * @var bool
	 */
	public bool $executed = false;

	public function name(): string {
		return 'steadhand/zz-admin-stub';
	}

	public function label(): string {
		return 'Admin stub';
	}

	public function description(): string {
		return 'Test ability for the administrator path.';
	}

	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'id' ),
			'properties'           => array(
				'id' => PostReader::id_schema(),
			),
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
			'idempotent'  => true,
		);
	}

	public function capability(): string {
		return Capabilities::WRITE_DRAFTS;
	}

	public function validate_input( array $input ): bool|\WP_Error {
		unset( $input );
		if ( $this->throw_in_validate ) {
			throw new \RuntimeException( 'SECRET /var/www/secret-path' );
		}

		return ! $this->return_false_from_validate;
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
