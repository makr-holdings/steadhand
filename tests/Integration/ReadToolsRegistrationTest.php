<?php
/**
 * Registration of the thirteen read tools and the ten write tools: the MCP tool list, annotations, strict schemas and exposure.
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
		'steadhand/get-diagnostics',
		'steadhand/get-change-log',
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
		'steadhand-get-diagnostics',
		'steadhand-get-change-log',
	);

	/**
	 * The eleven write tools, which come after the read tools in the registry.
	 */
	private const WRITE_ABILITIES = array(
		'steadhand/create-post',
		'steadhand/update-post',
		'steadhand/edit-blocks',
		'steadhand/set-post-terms',
		'steadhand/upload-media',
		'steadhand/set-featured-image',
		'steadhand/trash-post',
		'steadhand/restore-post',
		'steadhand/update-menu',
		'steadhand/update-option',
		'steadhand/undo-change',
	);

	private const WRITE_TOOLS = array(
		'steadhand-create-post',
		'steadhand-update-post',
		'steadhand-edit-blocks',
		'steadhand-set-post-terms',
		'steadhand-upload-media',
		'steadhand-set-featured-image',
		'steadhand-trash-post',
		'steadhand-restore-post',
		'steadhand-update-menu',
		'steadhand-update-option',
		'steadhand-undo-change',
	);

	/**
	 * Every ability the registry holds, read and write. The sweeps below that do not depend on the annotations use it.
	 */
	private const ALL_ABILITIES = array(
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
		'steadhand/get-diagnostics',
		'steadhand/get-change-log',
		'steadhand/create-post',
		'steadhand/update-post',
		'steadhand/edit-blocks',
		'steadhand/set-post-terms',
		'steadhand/upload-media',
		'steadhand/set-featured-image',
		'steadhand/trash-post',
		'steadhand/restore-post',
		'steadhand/update-menu',
		'steadhand/update-option',
		'steadhand/undo-change',
	);

	public function test_server_lists_exactly_the_read_and_write_tools(): void {
		wp_set_current_user( $this->make_agent() );

		$body  = $this->body( $this->mcp( 'tools/list', array(), $this->mcp_session() ) );
		$names = wp_list_pluck( $body['result']['tools'], 'name' );

		sort( $names );
		$expected = array_merge( self::TOOLS, self::WRITE_TOOLS );
		sort( $expected );
		$this->assertSame( $expected, $names );
	}

	public function test_registry_lists_the_abilities_in_the_documented_order(): void {
		$registry = new Registry( new Pipeline( new NullLogger() ) );

		$this->assertSame( array_merge( self::ABILITIES, self::WRITE_ABILITIES ), $registry->names() );
	}

	public function test_every_write_tool_is_not_readonly_and_the_update_is_destructive(): void {
		foreach ( self::WRITE_ABILITIES as $name ) {
			$meta = wp_get_ability( $name )->get_meta();

			$this->assertFalse( $meta['annotations']['readonly'], $name );
		}
		$this->assertTrue( wp_get_ability( 'steadhand/update-post' )->get_meta()['annotations']['destructive'] );
		$this->assertTrue( wp_get_ability( 'steadhand/edit-blocks' )->get_meta()['annotations']['destructive'] );
		$this->assertFalse( wp_get_ability( 'steadhand/edit-blocks' )->get_meta()['annotations']['idempotent'] );
		$this->assertTrue( wp_get_ability( 'steadhand/set-post-terms' )->get_meta()['annotations']['destructive'] );
		$this->assertTrue( wp_get_ability( 'steadhand/set-post-terms' )->get_meta()['annotations']['idempotent'] );
		$this->assertFalse( wp_get_ability( 'steadhand/create-post' )->get_meta()['annotations']['destructive'] );
		$this->assertFalse( wp_get_ability( 'steadhand/upload-media' )->get_meta()['annotations']['destructive'] );
		$this->assertFalse( wp_get_ability( 'steadhand/upload-media' )->get_meta()['annotations']['idempotent'] );
		$this->assertTrue( wp_get_ability( 'steadhand/set-featured-image' )->get_meta()['annotations']['destructive'] );
		$this->assertTrue( wp_get_ability( 'steadhand/set-featured-image' )->get_meta()['annotations']['idempotent'] );
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
		foreach ( self::ALL_ABILITIES as $name ) {
			$ability = wp_get_ability( $name );

			$this->assertFalse( McpAbilityExposure::is_public( $ability ), "$name must not reach the adapter default server." );
			$this->assertFalse( McpAbilityExposure::is_meta_public( $ability->get_meta() ), "$name must not be meta.public." );
			$this->assertFalse( (bool) ( $ability->get_meta()['show_in_rest'] ?? false ), "$name must not reach the core REST API." );
		}
	}

	public function test_every_schema_forbids_unknown_properties_at_every_object_level(): void {
		foreach ( self::ALL_ABILITIES as $name ) {
			$ability = wp_get_ability( $name );

			$this->assert_strict_objects( $ability->get_input_schema(), "$name input" );
			$this->assert_strict_objects( $ability->get_output_schema(), "$name output" );
		}
	}

	public function test_every_input_is_bounded(): void {
		foreach ( self::ALL_ABILITIES as $name ) {
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
	 * Integers have bounds, strings and array items have length limits or enums, and every array is capped. The items of an
	 * array of objects are checked property by property.
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
				// Items are bounded by an enum, a length, an object schema, or, for integer IDs, a minimum and a maximum.
				$items = $child['items'] ?? array();
				$this->assertTrue(
					isset( $items['enum'] ) || isset( $items['maxLength'] ) || 'object' === ( $items['type'] ?? null ) || ( isset( $items['minimum'], $items['maximum'] ) && 'integer' === ( $items['type'] ?? null ) ),
					"$name.$property items"
				);

				// An array of objects is bounded when each of its properties is.
				if ( 'object' === ( $items['type'] ?? null ) ) {
					$this->assert_bounded_input( $items, "{$name}.{$property}[]" );
				}
			}
		}
	}
}
