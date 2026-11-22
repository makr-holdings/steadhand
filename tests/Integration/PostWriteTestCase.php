<?php
/**
 * Shared helpers for the post write tools' integration tests.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Roles;

/**
 * Base for create-post and update-post. An administrator owns the fixture posts, so the agent is never their author. The
 * change-log helpers come from LogRows.
 */
abstract class PostWriteTestCase extends LogTestCase {

	/**
	 * Removes the filters the write tests add.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'wp_revisions_to_keep' );
		remove_all_filters( 'steadhand_allowed_post_types' );
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
	 * Creates a post owned by the fixture administrator, and returns its ID. Published by default.
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
	 * Makes a dedicated agent the current user, and returns its ID.
	 *
	 * @param array<string, bool> $overrides User-level capability overrides.
	 */
	protected function act_as_agent( array $overrides = array() ): int {
		$agent = $this->make_agent( $overrides );
		wp_set_current_user( $agent );

		return $agent;
	}

	/**
	 * A GMT date and time this many seconds from now, in the form the date input takes.
	 *
	 * @param int $seconds Seconds from now, which may be negative.
	 */
	protected function gmt_in( int $seconds ): string {
		return gmdate( 'Y-m-d\TH:i:s\Z', time() + $seconds );
	}

	/**
	 * Runs an ability the way core does, as the current user: its permission callback, then its execute callback. Both are
	 * the callbacks the plugin registers (Registry::args_for()), built by a Plugin with the plugin's own guardrail engine.
	 *
	 * This is not WP_Ability::execute(). Core replaces any refusal from the permission callback with a generic
	 * ability_invalid_permissions error and a _doing_it_wrong notice, which would hide the refusal code this suite checks.
	 * MCP calls keep the real messages, and the refusal text tests use them.
	 *
	 * @param string               $name Ability name, e.g. steadhand/create-post.
	 * @param array<string, mixed> $args Input.
	 * @return array<string, mixed>|\WP_Error|true|false The output, or the refusal the permission callback returned.
	 */
	protected function execute_ability( string $name, array $args ): mixed {
		$registry = ( new \Steadhand\Plugin() )->registry();

		foreach ( $registry->abilities() as $ability ) {
			if ( $ability->name() !== $name ) {
				continue;
			}

			$callbacks = $registry->args_for( $ability );
			$allowed   = $callbacks['permission_callback']( $args );
			if ( true !== $allowed ) {
				return $allowed;
			}

			return $callbacks['execute_callback']( $args );
		}

		$this->fail( "No ability named $name." );
	}

	/**
	 * Calls a tool over MCP as the current user, and returns the result member of the JSON-RPC response.
	 *
	 * @param string               $tool Tool name.
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	protected function tool_result( string $tool, array $args = array() ): array {
		return $this->call_tool( $tool, $args )['result'];
	}

	/**
	 * Calls a tool that must succeed, and returns its structured output.
	 *
	 * @param string               $tool Tool name.
	 * @param array<string, mixed> $args Tool arguments.
	 * @return array<string, mixed>
	 */
	protected function output_of( string $tool, array $args = array() ): array {
		$result = $this->tool_result( $tool, $args );
		$this->assertFalse( $result['isError'], 'The tool must not fail: ' . ( $result['content'][0]['text'] ?? '' ) );

		return $result['structuredContent'];
	}

	/**
	 * Asserts that an MCP tool result is a refusal, and returns its text.
	 *
	 * @param array<string, mixed> $result Tool result.
	 */
	protected function refusal_text( array $result ): string {
		$this->assertTrue( $result['isError'], 'The call must be refused.' );
		$this->assertArrayNotHasKey( 'structuredContent', $result, 'A refused call must return no data.' );

		return (string) $result['content'][0]['text'];
	}

	/**
	 * The number of posts of every status and type in the table, including trash and revisions.
	 */
	protected function post_row_count(): int {
		global $wpdb;

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A fixed count of a core table.
	}

	/**
	 * The fields a write could change, read straight from the table, so that a byte-for-byte comparison is possible.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, string>
	 */
	protected function row( int $post_id ): array {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the post row the test compares.
			$wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE ID = %d", $post_id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Core table name.
			ARRAY_A
		);
		$this->assertIsArray( $row, "Post $post_id must exist." );

		return array_intersect_key(
			$row,
			array_flip(
				array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_author', 'post_name', 'post_modified', 'post_modified_gmt', 'post_type', 'post_parent', 'post_password', 'post_date', 'post_date_gmt' )
			)
		);
	}

	/**
	 * The revision IDs of a post, newest first.
	 *
	 * @param int $post_id Post ID.
	 * @return int[]
	 */
	protected function revision_ids( int $post_id ): array {
		return array_map( 'intval', wp_get_post_revisions( $post_id, array( 'fields' => 'ids' ) ) );
	}

	/**
	 * Asserts that a result is a refusal, and returns its code.
	 *
	 * @param mixed $result Ability result.
	 */
	protected function refusal_code( mixed $result ): string {
		$this->assertWPError( $result, 'The call must be refused.' );

		return $result->get_error_code();
	}

	/**
	 * Creates a second agent for the change-log checks.
	 */
	protected function other_agent(): int {
		return self::factory()->user->create( array( 'role' => Roles::ROLE ) );
	}
}
