<?php
/**
 * Shared helpers for the post read tools' integration tests.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

/**
 * Base for the read tools' tests. An administrator owns the fixtures, so the agent is never their author.
 */
abstract class ReadToolTestCase extends TestCase {

	/**
	 * A plugin-style post type registered with map_meta_cap off and read_post mapped to core's read capability. It is never
	 * allowed, so the read tools refuse its posts whatever the filter says (SPEC §14.1).
	 */
	protected const SECRET_TYPE = 'steadhand_secret';

	/**
	 * Registers the secret type for each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		register_post_type(
			self::SECRET_TYPE,
			array(
				'map_meta_cap'    => false,
				'capability_type' => 'secret_ro',
				'capabilities'    => array( 'read_post' => 'read' ),
			)
		);
	}

	/**
	 * Removes the secret type.
	 */
	protected function tearDown(): void {
		if ( post_type_exists( self::SECRET_TYPE ) ) {
			unregister_post_type( self::SECRET_TYPE );
		}
		parent::tearDown();
	}

	/**
	 * Administrator who owns the fixture posts.
	 *
	 * @var int|null
	 */
	private ?int $admin = null;

	/**
	 * Returns the fixture administrator's ID, creating the user on first use.
	 */
	protected function admin_id(): int {
		if ( null === $this->admin ) {
			$this->admin = self::factory()->user->create(
				array(
					'role'         => 'administrator',
					'display_name' => 'Site Owner',
				)
			);
		}

		return $this->admin;
	}

	/**
	 * Creates a published post owned by the fixture administrator, and returns its ID.
	 *
	 * @param array<string, mixed> $fields Post fields that override the defaults.
	 */
	protected function admin_post( array $fields = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_author' => $this->admin_id(),
					'post_status' => 'publish',
					'post_title'  => 'Fixture post',
				),
				$fields
			)
		);
	}

	/**
	 * Makes a dedicated agent the current user.
	 *
	 * @param array<string, bool> $overrides User-level capability overrides.
	 */
	protected function act_as_agent( array $overrides = array() ): void {
		wp_set_current_user( $this->make_agent( $overrides ) );
	}

	/**
	 * Calls a tool as the current user and returns the JSON-RPC result member.
	 *
	 * @param string               $tool Tool name.
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	protected function tool_result( string $tool, array $args = array() ): array {
		return $this->call_tool( $tool, $args )['result'];
	}

	/**
	 * Calls a tool that must succeed and returns its structured output.
	 *
	 * @param string               $tool Tool name.
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	protected function output( string $tool, array $args = array() ): array {
		$result = $this->tool_result( $tool, $args );
		$this->assertFalse( $result['isError'], 'The tool must not fail: ' . ( $result['content'][0]['text'] ?? '' ) );

		return $result['structuredContent'];
	}

	/**
	 * Asserts that a tool result is a refusal or error, and returns its text without the code suffix (see refusal_code()).
	 *
	 * @param array<string, mixed> $result Tool result.
	 */
	protected function refusal_text( array $result ): string {
		return $this->split_refusal_text( $result )[0];
	}

	/**
	 * Returns the Steadhand code of a refusal over MCP. Registry::with_code() appends it to the text as " (steadhand_<code>)",
	 * because the MCP Adapter sends only the text, so the code must be there.
	 *
	 * @param array<string, mixed> $result Tool result.
	 */
	protected function refusal_code( array $result ): string {
		return $this->split_refusal_text( $result )[1];
	}

	/**
	 * Splits the text of an MCP refusal into its message and its Steadhand code.
	 *
	 * @param array<string, mixed> $result Tool result.
	 * @return array{0: string, 1: string} The message without the suffix, and the code.
	 */
	private function split_refusal_text( array $result ): array {
		$this->assertTrue( $result['isError'], 'The call must be refused.' );
		$this->assertArrayNotHasKey( 'structuredContent', $result, 'A refused call must return no data.' );

		$text = (string) $result['content'][0]['text'];
		$this->assertMatchesRegularExpression( '/ \((steadhand_[a-z0-9_]+)\)$/', $text, 'A refusal must end with its Steadhand code.' );
		preg_match( '/ \((steadhand_[a-z0-9_]+)\)$/', $text, $matches );

		return array( substr( $text, 0, - strlen( $matches[0] ) ), $matches[1] );
	}

	/**
	 * Every array key at any depth of a decoded value.
	 *
	 * @param mixed $value Decoded value.
	 * @return string[]
	 */
	protected function keys_at_any_depth( mixed $value ): array {
		$keys = array();
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				if ( is_string( $key ) ) {
					$keys[] = $key;
				}
				$keys = array_merge( $keys, $this->keys_at_any_depth( $child ) );
			}
		}

		return $keys;
	}

	/**
	 * Calls a single-post tool on a trashed post, a missing ID and each disallowed type, including core's custom_css
	 * (published and trashed) and a private post of the secret type. Returns the refusal text for each case, keyed by
	 * case name. The texts must be identical, so an agent cannot tell the cases apart.
	 *
	 * @param string $tool Tool name.
	 * @return array<string, string>
	 */
	protected function single_post_refusal_texts( string $tool ): array {
		$attachment  = wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => 'Image',
				'post_mime_type' => 'image/jpeg',
				'post_author'    => $this->admin_id(),
			)
		);
		$block       = $this->admin_post( array( 'post_type' => 'wp_block' ) );
		$menu_item   = $this->admin_post( array( 'post_type' => 'nav_menu_item' ) );
		$parent      = $this->admin_post();
		$revision    = $this->admin_post(
			array(
				'post_type'   => 'revision',
				'post_status' => 'inherit',
				'post_parent' => $parent,
			)
		);
		$custom_css  = wp_insert_post(
			array(
				'post_type'    => 'custom_css',
				'post_status'  => 'publish',
				'post_title'   => 'Custom CSS',
				'post_name'    => 'steadhand-test-css',
				'post_content' => 'body { color: red; }',
				'post_author'  => $this->admin_id(),
			)
		);
		$trashed_css = wp_insert_post(
			array(
				'post_type'    => 'custom_css',
				'post_status'  => 'trash',
				'post_title'   => 'Trashed CSS',
				'post_name'    => 'steadhand-test-trashed-css',
				'post_content' => 'body { color: blue; }',
				'post_author'  => $this->admin_id(),
			)
		);
		$this->assertGreaterThan( 0, $custom_css, 'The custom_css fixture must exist.' );
		$this->assertGreaterThan( 0, $trashed_css, 'The trashed custom_css fixture must exist.' );

		$cases = array(
			'trashed'        => $this->admin_post( array( 'post_status' => 'trash' ) ),
			'missing'        => 999999999,
			'attachment'     => $attachment,
			'wp_block'       => $block,
			'nav_menu_item'  => $menu_item,
			'revision'       => $revision,
			'custom_css'     => $custom_css,
			'trashed_css'    => $trashed_css,
			'secret_private' => $this->admin_post(
				array(
					'post_type'   => self::SECRET_TYPE,
					'post_status' => 'private',
				)
			),
		);

		$texts = array();
		foreach ( $cases as $case => $id ) {
			$texts[ $case ] = $this->refusal_text( $this->tool_result( $tool, array( 'id' => $id ) ) );
		}

		return $texts;
	}
}
