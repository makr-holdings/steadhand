<?php
/**
 * S2: a write sets the post date only when the caller sends one.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;

/**
 * Integration tests for the publish date of a post written through Steadhand (S2).
 */
final class PublishDateTest extends PostWriteTestCase {

	public function test_publishing_an_old_draft_gives_it_a_current_date(): void {
		$draft = $this->admin_post( array( 'post_status' => 'draft' ) );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Sets up a draft with an old date.
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_date'     => '2020-01-01 10:00:00',
				'post_date_gmt' => '0000-00-00 00:00:00',
			),
			array( 'ID' => $draft )
		);
		clean_post_cache( $draft );
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'     => $draft,
				'status' => 'publish',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 'publish', get_post( $draft )->post_status );
		$published = strtotime( get_post( $draft )->post_date_gmt . ' UTC' );
		$this->assertLessThan( 60, abs( time() - $published ), 'The draft must get a current date, not 2020.' );
	}

	public function test_a_scheduled_update_keeps_the_date_the_caller_asked_for(): void {
		$draft = $this->admin_post( array( 'post_status' => 'draft' ) );
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'     => $draft,
				'status' => 'future',
				'date'   => '2030-01-01T09:00:00Z',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 'future', get_post( $draft )->post_status );
		$this->assertSame( '2030-01-01 09:00:00', get_post( $draft )->post_date_gmt );
	}
}
