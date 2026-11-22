<?php
/**
 * Registration of the nine read tools: the MCP tool list, annotations, strict schemas and exposure.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\Registry;
use Steadhand\Log\NullLogger;
use Steadhand\Pipeline\Pipeline;
use WP\MCP\Abilities\McpAbilityExposure;

/**
 * Covers SPEC §3.3, §5 and §10 for the read tools.
 */
final class ReadToolsRegistrationTest extends TestCase {

	private const ABILITIES = array(
		'steadhand/get-site-info',
		'steadhand/list-posts',
		'steadhand/get-post',
		'steadhand/get-blocks',
		'steadhand/get-rendered',
		'steadhand/list-terms',
		'steadhand/get-post-terms',
		'steadhand/list-menus',
		'steadhand/get-menu',
		'steadhand/list-media',
		'steadhand/get-media',
	);

	private const TOOLS = array(
		'steadhand-get-site-info',
		'steadhand-list-posts',
		'steadhand-get-post',
		'steadhand-get-blocks',
		'steadhand-get-rendered',
		'steadhand-list-terms',
		'steadhand-get-post-terms',
		'steadhand-list-menus',
		'steadhand-get-menu',
		'steadhand-list-media',
		'steadhand-get-media',
	);

	public function test_server_lists_exactly_the_eleven_read_tools(): void {
		wp_set_current_user( $this->make_agent() );

		$body  = $this->body( $this->mcp( 'tools/list', array(), $this->mcp_session() ) );
		$names = wp_list_pluck( $body['result']['tools'], 'name' );

		sort( $names );
		$expected = self::TOOLS;
		sort( $expected );
		$this->assertSame( $expected, $names );
	}

	public function test_registry_lists_the_abilities_in_the_documented_order(): void {
		$registry = new Registry( new Pipeline( new NullLogger() ) );

		$this->assertSame( self::ABILITIES, $registry->names() );
	}

	public function test_every_read_tool_is_readonly_not_destructive_and_idempotent(): void {
		foreach ( self::ABILITIES as $name ) {
			$meta = wp_get_ability( $name )->get_meta();

			$this->assertTrue( $meta['annotations']['readonly'], $name );
			$this->assertFalse( $meta['annotations']['destructive'], $name );
			$this->assertTrue( $meta['annotations']['idempotent'], $name );
		}
	}

	public function test_no_read_tool_is_public_or_exposed_outside_the_steadhand_server(): void {
		foreach ( self::ABILITIES as $name ) {
			$ability = wp_get_ability( $name );

			$this->assertFalse( McpAbilityExposure::is_public( $ability ), "$name must not reach the adapter default server." );
			$this->assertFalse( McpAbilityExposure::is_meta_public( $ability->get_meta() ), "$name must not be meta.public." );
			$this->assertFalse( (bool) ( $ability->get_meta()['show_in_rest'] ?? false ), "$name must not reach the core REST API." );
		}
	}

	public function test_every_schema_forbids_unknown_properties_at_every_object_level(): void {
		foreach ( self::ABILITIES as $name ) {
			$ability = wp_get_ability( $name );

			$this->assert_strict_objects( $ability->get_input_schema(), "$name input" );
			$this->assert_strict_objects( $ability->get_output_schema(), "$name output" );
		}
	}

	public function test_every_input_is_bounded(): void {
		foreach ( self::ABILITIES as $name ) {
			$this->assert_bounded_input( wp_get_ability( $name )->get_input_schema(), $name );
		}
	}

	/**
	 * Every object schema that declares properties forbids extra ones. Attribute bags (object schemas without properties)
	 * are the one documented exception, so they are not checked here.
	 *
	 * @param array<string, mixed> $schema Schema node.
	 * @param string               $where  Path, for the failure message.
	 */
	private function assert_strict_objects( array $schema, string $where ): void {
		if ( 'object' === ( $schema['type'] ?? null ) && isset( $schema['properties'] ) ) {
			$this->assertArrayHasKey( 'additionalProperties', $schema, "$where: object without additionalProperties" );
			$this->assertFalse( $schema['additionalProperties'], "$where: extra properties must be refused" );
			foreach ( $schema['properties'] as $property => $child ) {
				$this->assert_strict_objects( $child, "$where.$property" );
			}
		}

		if ( 'array' === ( $schema['type'] ?? null ) && isset( $schema['items'] ) && is_array( $schema['items'] ) ) {
			$this->assert_strict_objects( $schema['items'], "{$where}[]" );
		}
	}

	/**
	 * Integers have bounds, strings and array items have length limits or enums, and every array is capped.
	 *
	 * @param array<string, mixed> $schema Input schema.
	 * @param string               $name   Ability name, for the failure message.
	 */
	private function assert_bounded_input( array $schema, string $name ): void {
		foreach ( $schema['properties'] ?? array() as $property => $child ) {
			$type = $child['type'] ?? null;

			if ( 'integer' === $type ) {
				$this->assertArrayHasKey( 'minimum', $child, "$name.$property" );
				$this->assertArrayHasKey( 'maximum', $child, "$name.$property" );
			}
			if ( 'string' === $type && ! isset( $child['enum'] ) ) {
				$this->assertArrayHasKey( 'maxLength', $child, "$name.$property" );
			}
			if ( 'array' === $type ) {
				$this->assertArrayHasKey( 'maxItems', $child, "$name.$property" );
				$this->assertTrue( isset( $child['items']['enum'] ) || isset( $child['items']['maxLength'] ), "$name.$property items" );
			}
		}
	}
}
