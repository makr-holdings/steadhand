<?php
/**
 * S9: a write adds the post-field KSES filters only where they are missing, and removes only the ones it added.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

/**
 * Integration tests for which KSES filters a write adds and removes (S9).
 */
final class KsesScopeTest extends PostWriteTestCase {

	/**
	 * The KSES filters core adds for post fields, as hook and function.
	 */
	private const PAIRS = array(
		array( 'title_save_pre', 'wp_filter_kses' ),
		array( 'content_save_pre', 'wp_filter_post_kses' ),
		array( 'excerpt_save_pre', 'wp_filter_post_kses' ),
		array( 'content_filtered_save_pre', 'wp_filter_post_kses' ),
	);

	/**
	 * Removes all four post-field KSES filters, after the agent is set up (setting a user re-adds them).
	 */
	private function remove_all_kses(): void {
		foreach ( self::PAIRS as $pair ) {
			remove_filter( $pair[0], $pair[1] );
		}
	}

	/**
	 * Creates a post with a script in its title, as the agent. Returns the stored title.
	 */
	private function stored_title(): string {
		$result = $this->execute_ability(
			'steadhand/create-post',
			array(
				'title'   => 'Hi<script>x()</script>there',
				'content' => '<p>body</p>',
			)
		);
		$this->assertIsArray( $result );

		return get_post( $result['id'] )->post_title;
	}

	public function test_with_no_kses_filters_present_the_write_still_filters_and_leaves_none_behind(): void {
		$this->act_as_agent();
		$this->remove_all_kses();

		$title = $this->stored_title();

		$this->assertStringNotContainsString( '<script>', $title );
		foreach ( self::PAIRS as $pair ) {
			$this->assertFalse( has_filter( $pair[0], $pair[1] ), "{$pair[0]} must be absent after the write." );
		}
	}

	public function test_only_the_title_filter_missing_is_added_and_removed_again_while_another_components_content_filter_stays(): void {
		$this->act_as_agent();
		$this->remove_all_kses();
		add_filter( 'content_save_pre', 'wp_filter_post_kses' );

		$title = $this->stored_title();

		$this->assertStringNotContainsString( '<script>', $title, 'The title is still filtered.' );
		$this->assertFalse( has_filter( 'title_save_pre', 'wp_filter_kses' ), 'The title filter this write added is removed.' );
		$this->assertNotFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'A filter another component added stays.' );
	}

	public function test_when_every_kses_filter_is_already_present_none_is_removed(): void {
		$this->act_as_agent();
		$this->remove_all_kses();
		foreach ( self::PAIRS as $pair ) {
			add_filter( $pair[0], $pair[1] );
		}

		$this->stored_title();

		foreach ( self::PAIRS as $pair ) {
			$this->assertNotFalse( has_filter( $pair[0], $pair[1] ), "{$pair[0]} must still be present." );
		}
	}
}
