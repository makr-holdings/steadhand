<?php
/**
 * S3: core's requirements beyond the post type's own capabilities survive the mapping. Read paths are unchanged.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;

/**
 * Integration tests for core capabilities that survive the post mapping (S3).
 */
final class CoreExtraCapsTest extends PostWriteTestCase {

	use InsideCall;

	/**
	 * Removes the option, and the filters the tests add.
	 */
	protected function tearDown(): void {
		delete_option( 'wp_page_for_privacy_policy' );
		parent::tearDown();
	}

	/**
	 * A draft page that is the privacy policy page, and an agent holding both post capabilities.
	 *
	 * @return int[] The page ID and the agent ID.
	 */
	private function privacy_page(): array {
		$page = $this->admin_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_content' => 'policy',
			)
		);
		update_option( 'wp_page_for_privacy_policy', $page );
		$agent = $this->act_as_agent( array( Capabilities::PUBLISH => true ) );

		return array( $page, $agent );
	}

	public function test_an_agent_cannot_edit_a_draft_privacy_policy_page_and_the_row_is_identical(): void {
		list( $page ) = $this->privacy_page();
		$before       = $this->row( $page );

		$result = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $page,
				'content' => 'agent policy',
			)
		);

		$this->assertSame( 'steadhand_forbidden', $this->refusal_code( $result ) );
		$this->assertSame( $before, $this->row( $page ) );
	}

	public function test_an_agent_cannot_publish_a_draft_privacy_policy_page_and_the_row_is_identical(): void {
		list( $page ) = $this->privacy_page();
		$before       = $this->row( $page );

		$result = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'     => $page,
				'status' => 'publish',
			)
		);

		$this->assertSame( 'steadhand_forbidden', $this->refusal_code( $result ) );
		$this->assertSame( $before, $this->row( $page ) );
	}

	public function test_inside_a_write_call_edit_post_is_false_for_the_privacy_page_and_true_for_an_ordinary_page(): void {
		list( $page ) = $this->privacy_page();
		$ordinary     = $this->admin_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
			)
		);

		$answers = $this->inside_write_call(
			static fn(): array => array(
				'privacy'  => current_user_can( 'edit_post', $page ),
				'ordinary' => current_user_can( 'edit_post', $ordinary ),
			)
		);

		$this->assertSame(
			array(
				'privacy'  => false,
				'ordinary' => true,
			),
			$answers
		);
	}

	public function test_an_ordinary_draft_page_is_still_editable(): void {
		$page = $this->admin_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'draft',
				'post_content' => 'old',
			)
		);
		$this->act_as_agent();

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $page,
				'content' => 'new',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 'new', get_post( $page )->post_content );
	}

	public function test_a_capability_another_plugin_adds_at_priority_9_makes_the_edit_refused(): void {
		$draft = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'old',
			)
		);
		$this->act_as_agent();
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap ): array {
				if ( 'edit_post' === $cap ) {
					$caps[] = 'some_plugin_cap';
				}

				return $caps;
			},
			9,
			2
		);
		$before = $this->row( $draft );

		$result = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'      => $draft,
				'content' => 'new',
			)
		);

		$this->assertSame( 'steadhand_forbidden', $this->refusal_code( $result ) );
		$this->assertSame( $before, $this->row( $draft ) );
	}

	public function test_a_capability_another_plugin_adds_to_publish_post_refuses_the_publish(): void {
		$draft = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_content' => 'old',
			)
		);
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $cap ): array {
				if ( 'publish_post' === $cap ) {
					$caps[] = 'some_plugin_cap';
				}

				return $caps;
			},
			9,
			2
		);
		$before = $this->row( $draft );

		$result = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'     => $draft,
				'status' => 'publish',
			)
		);

		$this->assertSame( 'steadhand_forbidden', $this->refusal_code( $result ) );
		$this->assertSame( $before, $this->row( $draft ) );
	}

	public function test_reading_the_privacy_policy_page_through_get_post_still_works(): void {
		list( $page ) = $this->privacy_page();
		$this->act_as_agent();

		$out = $this->execute_ability( 'steadhand/get-post', array( 'id' => $page ) );

		$this->assertIsArray( $out, 'read_post is not changed by the extra-requirement rule.' );
	}
}
