<?php
/**
 * The attachment read rule, layer one: MediaReader::readable_attachment(), the only way the media tools get an
 * attachment (SPEC §14.1).
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Closure;
use Steadhand\Abilities\MediaReader;
use Steadhand\Capabilities;
use Steadhand\Pipeline\Pipeline;

/**
 * Each case runs inside a real pipeline call through ProbeAbility, or outside one, where core's own answer applies.
 */
final class MediaReaderTest extends MediaToolTestCase {

	/**
	 * Runs $check inside a real pipeline call as the current user and returns its value.
	 *
	 * @param Closure $check Check; receives the sanitised input and returns a value to compare.
	 * @param string  $cap   Steadhand capability the probe requires.
	 * @return mixed
	 */
	private function inside_a_call( Closure $check, string $cap = Capabilities::READ ): mixed {
		$result = ( new Pipeline( new SpyLogger() ) )->run( new ProbeAbility( $cap, $check ), array( 'post_id' => 1 ) );
		$this->assertIsArray( $result, 'The probe call must be allowed to run.' );

		return $result['value'];
	}

	/**
	 * Wraps a check so its value is returned inside the probe's output.
	 *
	 * @param Closure $check Check.
	 */
	private function as_value( Closure $check ): Closure {
		return static fn( array $input ): array => array( 'value' => $check( $input ) );
	}

	public function test_inside_a_call_an_agent_gets_an_attachment_whose_parent_is_another_authors_draft(): void {
		$attachment = $this->image( $this->admin_post( array( 'post_status' => 'draft' ) ) );
		wp_set_current_user( $this->make_agent() );

		$post = $this->inside_a_call(
			$this->as_value( fn(): ?int => MediaReader::readable_attachment( $attachment )?->ID )
		);

		$this->assertSame( $attachment, $post );
	}

	public function test_inside_a_call_an_agent_gets_an_unattached_and_a_private_parent_attachment(): void {
		$unattached = $this->image();
		$private    = $this->image( $this->admin_post( array( 'post_status' => 'private' ) ) );
		wp_set_current_user( $this->make_agent() );

		$this->assertSame(
			array( $unattached, $private ),
			$this->inside_a_call(
				$this->as_value(
					static fn(): array => array(
						MediaReader::readable_attachment( $unattached )?->ID,
						MediaReader::readable_attachment( $private )?->ID,
					)
				)
			)
		);
	}

	public function test_inside_a_call_a_trashed_attachment_is_refused(): void {
		$trashed = $this->image();
		wp_trash_post( $trashed );
		wp_set_current_user( $this->make_agent() );

		$this->assertNull(
			$this->inside_a_call( $this->as_value( static fn(): ?int => MediaReader::readable_attachment( $trashed )?->ID ) )
		);
	}

	public function test_a_regular_post_is_never_an_attachment_even_when_the_agent_may_read_it(): void {
		$post = $this->admin_post( array( 'post_status' => 'publish' ) );
		wp_set_current_user( $this->make_agent() );

		$this->assertTrue( current_user_can( 'read_post', $post ), 'Control: the agent may read the post itself.' );
		$this->assertNull(
			$this->inside_a_call( $this->as_value( static fn(): ?int => MediaReader::readable_attachment( $post )?->ID ) )
		);
	}

	public function test_a_missing_id_is_refused(): void {
		wp_set_current_user( $this->make_agent() );

		$this->assertNull(
			$this->inside_a_call( $this->as_value( static fn(): ?int => MediaReader::readable_attachment( 999999999 )?->ID ) )
		);
	}

	public function test_an_agent_without_steadhand_read_is_refused_inside_a_call(): void {
		$attachment = $this->image( $this->admin_post( array( 'post_status' => 'draft' ) ) );
		wp_set_current_user( $this->make_agent( array( Capabilities::READ => false ) ) );

		$this->assertNull(
			$this->inside_a_call(
				$this->as_value( static fn(): ?int => MediaReader::readable_attachment( $attachment )?->ID ),
				Capabilities::CONNECT
			)
		);
	}

	public function test_outside_a_call_core_answers_and_a_subscriber_is_refused_a_draft_parents_attachment(): void {
		$attachment = $this->image( $this->admin_post( array( 'post_status' => 'draft' ) ) );
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );

		$this->assertFalse( Pipeline::is_running() );
		$this->assertNull( MediaReader::readable_attachment( $attachment ), 'A subscriber must not read a draft parent\'s file.' );
	}

	public function test_outside_a_call_an_agent_is_refused_a_draft_parents_attachment_by_core(): void {
		$attachment = $this->image( $this->admin_post( array( 'post_status' => 'draft' ) ) );
		wp_set_current_user( $this->make_agent() );

		$this->assertNull( MediaReader::readable_attachment( $attachment ) );
	}

	public function test_readable_attachment_applies_the_parent_and_status_rule_for_an_administrator_outside_a_call(): void {
		$wp_block      = $this->plain( 'application/pdf', $this->admin_post( array( 'post_type' => 'wp_block' ) ) );
		$own_private   = $this->plain( 'application/pdf', 0, array( 'post_status' => 'private' ) );
		$secret_parent = $this->admin_post(
			array(
				'post_type'   => self::SECRET_TYPE,
				'post_status' => 'private',
			)
		);
		$secret        = $this->plain( 'application/pdf', $secret_parent );
		wp_set_current_user( $this->admin_id() );

		$this->assertFalse( Pipeline::is_running() );
		foreach ( array(
			'wp_block parent'    => $wp_block,
			'own status private' => $own_private,
			'secret parent'      => $secret,
		) as $case => $attachment ) {
			$this->assertTrue( current_user_can( 'read_post', $attachment ), "Control: core lets an administrator read the $case attachment." );
			$this->assertNull( MediaReader::readable_attachment( $attachment ), "The rule must refuse the $case attachment." );
		}
	}

	public function test_outside_a_call_a_trashed_attachment_is_refused_even_when_core_would_allow_it(): void {
		$agent   = $this->make_agent();
		$trashed = $this->plain_by( $agent, 'application/pdf' );
		wp_trash_post( $trashed );
		wp_set_current_user( $agent );

		$this->assertTrue( current_user_can( 'read_post', $trashed ), 'Control: core lets the author read its own trashed attachment.' );
		$this->assertNull( MediaReader::readable_attachment( $trashed ) );
	}
}
