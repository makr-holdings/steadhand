<?php
/**
 * The warnings a write returns when WordPress stores something other than what the agent asked for: a private post loses
 * its password, and a status WordPress changes is reported as stored.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;

/**
 * Covers the private and password warning, and the requested and stored status warning, for both write tools.
 */
final class PostWriteWarningsTest extends PostWriteTestCase {

	public function test_making_a_protected_post_private_says_the_password_is_removed_and_removes_it(): void {
		$post = $this->admin_post(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
				'post_content'  => 'c',
			)
		);
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'     => $post,
				'status' => 'private',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 'private', get_post( $post )->post_status );
		$this->assertSame( '', get_post( $post )->post_password, 'WordPress removes the password of a private post.' );
		$this->assertContains( 'Making a post private removes its password.', $out['warnings'] );
	}

	public function test_making_a_post_without_a_password_private_gives_no_password_warning(): void {
		$post = $this->admin_post(
			array(
				'post_status'  => 'publish',
				'post_content' => 'c',
			)
		);
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'     => $post,
				'status' => 'private',
			)
		);

		$this->assertIsArray( $out );
		$this->assertNotContains( 'Making a post private removes its password.', $out['warnings'] );
	}

	public function test_publishing_a_scheduled_post_is_stored_as_scheduled_and_the_warning_says_so(): void {
		$post = $this->admin_post(
			array(
				'post_status'  => 'future',
				'post_date'    => gmdate( 'Y-m-d H:i:s', time() + 86400 ),
				'post_content' => 'c',
			)
		);
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );

		$out = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'     => $post,
				'status' => 'publish',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 'future', get_post( $post )->post_status, 'A scheduled post cannot be published early by this tool.' );
		$this->assertSame( 'future', $out['status'] );
		$this->assertContains( 'WordPress stored the status "future" instead of "publish".', $out['warnings'] );
	}

	public function test_create_with_publish_and_a_stored_status_that_matches_gives_no_status_warning(): void {
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );

		$out = $this->execute_ability(
			'steadhand/create-post',
			array(
				'title'  => 'Published',
				'status' => 'publish',
			)
		);

		$this->assertIsArray( $out );
		$this->assertSame( 'publish', $out['status'] );
		$this->assertSame( array(), $out['warnings'] );
	}
}
