<?php
/**
 * S9: a write adds the post-field KSES filters only where they are missing, and removes only the ones it added.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Pipeline\PostWriter;

/**
 * Integration tests for which KSES filters a write adds and removes (S9), and that no code running during an update's save
 * runs without them.
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

	/**
	 * Every KSES pair Steadhand relies on, as it stood in the save_post listener of the update below, keyed by hook.
	 *
	 * @return array<string, bool>
	 */
	private function kses_pairs_present(): array {
		$present = array();
		foreach ( self::PAIRS as $pair ) {
			$present[ $pair[0] ] = false !== has_filter( $pair[0], $pair[1] );
		}

		return $present;
	}

	public function test_during_an_update_save_all_four_kses_filters_are_in_place_and_a_nested_insert_is_filtered(): void {
		$post = $this->admin_post(
			array(
				'post_status' => 'draft',
				'post_title'  => 'Target',
			)
		);
		$this->act_as_agent();
		$seen  = null;
		$other = 0;
		add_action(
			'save_post',
			function ( int $id ) use ( $post, &$seen, &$other ): void {
				if ( $id !== $post || null !== $seen ) {
					return;
				}

				$seen  = $this->kses_pairs_present();
				$other = wp_insert_post(
					array(
						'post_type'    => 'post',
						'post_status'  => 'draft',
						'post_title'   => 'Nested<script>t()</script>',
						'post_content' => '<p>b</p><script>c()</script>',
					)
				);
			},
			999
		);

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'    => $post,
				'title' => 'Changed',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame(
			array(
				'title_save_pre'            => true,
				'content_save_pre'          => true,
				'excerpt_save_pre'          => true,
				'content_filtered_save_pre' => true,
			),
			$seen,
			'During the save, all four KSES filters are in place, whatever fields the call sends.'
		);
		$this->assertIsInt( $other );
		$this->assertStringNotContainsString( '<script', get_post( $other )->post_title, 'A post another write inserts during the save is filtered.' );
		$this->assertStringNotContainsString( '<script', get_post( $other )->post_content, 'A post another write inserts during the save is filtered.' );
	}

	public function test_a_nested_update_of_the_same_post_from_a_save_post_listener_is_filtered_and_not_given_the_restored_values(): void {
		$post = $this->admin_post(
			array(
				'post_status'  => 'draft',
				'post_title'   => 'Target',
				'post_content' => '<p>outer</p>',
			)
		);
		$this->act_as_agent();
		$nested = false;
		add_action(
			'save_post',
			static function ( int $id ) use ( $post, &$nested ): void {
				if ( $id !== $post || $nested ) {
					return;
				}

				$nested = true;
				PostWriter::update( $post, array( 'post_content' => '<p>nested</p><script>x()</script>' ) );
			},
			999
		);

		$out = $this->in_write(
			static fn(): int|\WP_Error => PostWriter::update( $post, array( 'post_title' => 'Outer title' ) )
		);

		$this->assertSame( $post, $out );
		$this->assertTrue( $nested, 'The nested update ran.' );
		$this->assertSame( '<p>nested</p>x()', get_post( $post )->post_content, 'The nested content is filtered, not replaced by the values the outer call restored.' );
	}
}
