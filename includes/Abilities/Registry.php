<?php
/**
 * Registers the Steadhand ability category and abilities with the WordPress Abilities API.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Abilities;

use Steadhand\Pipeline\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the list of Steadhand abilities and wires each one to the shared pipeline.
 */
final class Registry {

	/**
	 * Ability category slug.
	 *
	 * @var string
	 */
	public const CATEGORY = 'steadhand';

	/**
	 * Constructor.
	 *
	 * @param Pipeline $pipeline Pipeline every ability call goes through.
	 */
	public function __construct( private Pipeline $pipeline ) {}

	/**
	 * Returns every Steadhand ability.
	 *
	 * @return Ability[]
	 */
	public function abilities(): array {
		return array( new GetSiteInfo() );
	}

	/**
	 * Returns the names of every Steadhand ability.
	 *
	 * @return string[]
	 */
	public function names(): array {
		return array_map(
			static fn( Ability $ability ): string => $ability->name(),
			$this->abilities()
		);
	}

	/**
	 * Registers the Steadhand ability category.
	 */
	public function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Steadhand', 'steadhand' ),
				'description' => __( 'Abilities that let AI agents work on this site safely, under least-privilege capabilities and an audit log.', 'steadhand' ),
			)
		);
	}

	/**
	 * Registers every Steadhand ability.
	 */
	public function register_abilities(): void {
		foreach ( $this->abilities() as $ability ) {
			wp_register_ability( $ability->name(), $this->args_for( $ability ) );
		}
	}

	/**
	 * Builds the registration arguments for one ability.
	 *
	 * Steadhand abilities are exposed only through the Steadhand MCP server, never the
	 * adapter's default server or the core abilities REST API. Hence show_in_rest is false,
	 * mcp.public is false, and the ability is never marked meta.public.
	 *
	 * @param Ability $ability Ability to describe.
	 * @return array<string, mixed> Arguments for wp_register_ability().
	 */
	public function args_for( Ability $ability ): array {
		return array(
			'label'               => $ability->label(),
			'description'         => $ability->description(),
			'category'            => self::CATEGORY,
			'input_schema'        => $ability->input_schema(),
			'output_schema'       => $ability->output_schema(),
			'permission_callback' => fn( $input = null ) => $this->pipeline->check( $ability, is_array( $input ) ? $input : array() ),
			'execute_callback'    => fn( $input = null ) => $this->pipeline->run( $ability, $input ),
			'meta'                => array(
				'annotations'  => $ability->annotations(),
				'show_in_rest' => false,
				'mcp'          => array( 'public' => false ),
			),
		);
	}
}
