<?php
/**
 * PostWriter and Snapshots: the only post writes, and the revision snapshot taken before them.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Closure;
use Steadhand\Capabilities;
use Steadhand\Log\DbLogger;
use Steadhand\Pipeline\PostWriter;
use Steadhand\Pipeline\Pipeline;
use Steadhand\Pipeline\Snapshots;

/**
 * Covers SPEC §7 (post snapshots) and §14.1 (what an agent may write). Every write runs inside a real write ability,
 * because PostWriter and Snapshots refuse to run anywhere else.
 */
final class PostWriterTest extends LogTestCase {

	private const CUSTOM = 'steadhand_book';

	/**
	 * Removes the custom type and any filter this file adds.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'wp_revisions_to_keep' );
		if ( post_type_exists( self::CUSTOM ) ) {
			unregister_post_type( self::CUSTOM );
		}
		parent::tearDown();
	}

	/**
	 * Runs a callback inside a real write call made by the current user, and returns what it returned. Exceptions the
	 * callback throws are the test's to catch inside the callback: the pipeline would turn them into a generic error.
	 *
	 * @param Closure $work       The code under test.
	 * @param string  $capability Steadhand capability the probe write requires.
	 * @return mixed
	 */
	private function in_write( Closure $work, string $capability = Capabilities::WRITE_DRAFTS ): mixed {
		$captured          = null;
		$probe             = new WriteProbeAbility( $capability );
		$probe->on_execute = static function ( array $input ) use ( $work, &$captured ): array {
			unset( $input );
			$captured = $work();

			return array();
		};

		$result = ( new Pipeline( new DbLogger() ) )->run( $probe, array() );
		$this->assertIsArray( $result, 'The write probe must be allowed to run.' );

		return $captured;
	}

	public function test_insert_update_and_snapshot_all_refuse_to_run_outside_a_write(): void {
		wp_set_current_user( $this->make_agent() );
		$post_id = self::factory()->post->create();

		$this->assertThrows( \LogicException::class, static fn() => PostWriter::insert( array( 'post_type' => 'post' ) ) );
		$this->assertThrows( \LogicException::class, static fn() => PostWriter::update( $post_id, array( 'post_title' => 'x' ) ) );
		$this->assertThrows( \LogicException::class, static fn() => Snapshots::post_revision( $post_id ) );
	}

	/**
	 * Asserts that a callback throws the given exception class.
	 *
	 * @param string  $expected Exception class.
	 * @param Closure $call  Code that should throw.
	 */
	private function assertThrows( string $expected, Closure $call ): void {
		try {
			$call();
		} catch ( \Throwable $thrown ) {
			$this->assertInstanceOf( $expected, $thrown );
			return;
		}

		$this->fail( "Expected $expected to be thrown." );
	}

	public function test_insert_refuses_every_field_that_is_not_on_the_allowlist(): void {
		wp_set_current_user( $this->make_agent() );
		$before  = wp_count_posts()->publish + wp_count_posts()->draft;
		$refused = array();
		$fields  = array(
			'post_author'    => 1,
			'post_password'  => 'secret',
			'ID'             => 5,
			'meta_input'     => 'x',
			'tax_input'      => 'x',
			'post_parent'    => 0,
			'guid'           => 'x',
			'comment_status' => 'open',
		);

		$this->in_write(
			function () use ( $fields, &$refused ): void {
				foreach ( $fields as $key => $value ) {
					try {
						PostWriter::insert(
							array(
								'post_type' => 'post',
								$key        => $value,
							)
						);
					} catch ( \InvalidArgumentException ) {
						$refused[] = $key;
					}
				}
			}
		);

		$this->assertSame( array_keys( $fields ), $refused );
		$this->assertSame( $before, wp_count_posts()->publish + wp_count_posts()->draft, 'No post is created by a refused call.' );
	}

	public function test_update_refuses_every_field_that_is_not_on_the_allowlist_including_the_type(): void {
		wp_set_current_user( $this->make_agent() );
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$refused = array();
		$fields  = array(
			'post_author'   => 1,
			'post_type'     => 'page',
			'post_password' => 'secret',
			'ID'            => 5,
			'meta_input'    => 'x',
			'post_parent'   => 0,
		);

		$this->in_write(
			function () use ( $post_id, $fields, &$refused ): void {
				foreach ( $fields as $key => $value ) {
					try {
						PostWriter::update( $post_id, array( $key => $value ) );
					} catch ( \InvalidArgumentException ) {
						$refused[] = $key;
					}
				}
			}
		);

		$this->assertSame( array_keys( $fields ), $refused );
		$this->assertSame( 'post', get_post( $post_id )->post_type );
	}

	public function test_a_field_value_that_is_not_a_string_is_refused(): void {
		wp_set_current_user( $this->make_agent() );
		$refused = false;

		$this->in_write(
			function () use ( &$refused ): void {
				try {
					PostWriter::insert(
						array(
							'post_type'  => 'post',
							'post_title' => array( 'x' ),
						)
					);
				} catch ( \InvalidArgumentException ) {
					$refused = true;
				}
			}
		);

		$this->assertTrue( $refused );
	}

	public function test_insert_always_sets_the_author_to_the_current_user(): void {
		$agent = $this->make_agent();
		wp_set_current_user( $agent );

		$post_id = $this->in_write(
			static fn(): int|\WP_Error => PostWriter::insert(
				array(
					'post_type'   => 'post',
					'post_title'  => 'Mine',
					'post_status' => 'draft',
				)
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertSame( $agent, (int) get_post( $post_id )->post_author );
	}

	public function test_update_never_changes_the_author(): void {
		$agent = $this->make_agent();
		$other = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $agent );
		$post_id = self::factory()->post->create(
			array(
				'post_author' => $other,
				'post_status' => 'draft',
			)
		);

		$this->in_write( static fn(): int|\WP_Error => PostWriter::update( $post_id, array( 'post_title' => 'Edited' ) ) );

		$this->assertSame( $other, (int) get_post( $post_id )->post_author );
		$this->assertSame( 'Edited', get_post( $post_id )->post_title );
	}

	public function test_insert_refuses_a_type_that_is_not_allowed(): void {
		wp_set_current_user( $this->make_agent() );

		foreach ( array( 'attachment', 'wp_block', 'nav_menu_item' ) as $type ) {
			$result = $this->in_write(
				static fn(): int|\WP_Error => PostWriter::insert(
					array(
						'post_type'  => $type,
						'post_title' => 'x',
					)
				)
			);

			$this->assertWPError( $result, "A $type must not be created." );
			$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
		}
	}

	public function test_update_refuses_a_post_whose_stored_type_is_not_allowed(): void {
		wp_set_current_user( $this->make_agent() );
		register_post_type( self::CUSTOM, array( 'public' => false ) );
		$post_id = self::factory()->post->create(
			array(
				'post_type'   => self::CUSTOM,
				'post_status' => 'draft',
			)
		);

		$result = $this->in_write( static fn(): int|\WP_Error => PostWriter::update( $post_id, array( 'post_title' => 'Changed' ) ) );

		$this->assertWPError( $result );
		$this->assertNotSame( 'Changed', get_post( $post_id )->post_title );
	}

	public function test_update_refuses_a_trashed_post(): void {
		wp_set_current_user( $this->make_agent() );
		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'trash',
				'post_title'  => 'Original',
			)
		);

		$result = $this->in_write( static fn(): int|\WP_Error => PostWriter::update( $post_id, array( 'post_title' => 'Changed' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
		$this->assertSame( 'Original', get_post( $post_id )->post_title );
	}

	public function test_quotes_and_backslashes_are_stored_exactly(): void {
		wp_set_current_user( $this->make_agent() );
		$title   = 'He said "hi" and it\'s \\ done';
		$content = '<p>Quote "x" and back\\slash and \\n kept</p>';

		$post_id = $this->in_write(
			static fn(): int|\WP_Error => PostWriter::insert(
				array(
					'post_type'    => 'post',
					'post_title'   => $title,
					'post_content' => $content,
					'post_status'  => 'draft',
				)
			)
		);

		$this->assertSame( $title, get_post( $post_id )->post_title );
		$this->assertSame( $content, get_post( $post_id )->post_content );
	}

	public function test_kses_is_applied_even_when_its_filters_were_removed_beforehand(): void {
		wp_set_current_user( $this->make_agent() );
		kses_remove_filters();
		$this->assertFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'Control: KSES is off before the write.' );

		$post_id = $this->in_write(
			static fn(): int|\WP_Error => PostWriter::insert(
				array(
					'post_type'    => 'post',
					'post_content' => '<p>ok</p><script>alert(1)</script>',
					'post_status'  => 'draft',
				)
			)
		);

		$this->assertIsInt( $post_id );
		$this->assertStringNotContainsString( '<script', get_post( $post_id )->post_content );
		$this->assertFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'The filters this write added are removed again.' );
	}

	public function test_filters_that_were_already_there_are_not_removed_by_a_write(): void {
		wp_set_current_user( $this->make_agent() );
		$this->assertNotFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ), 'Control: KSES is on for an agent.' );

		$this->in_write(
			static fn(): int|\WP_Error => PostWriter::insert(
				array(
					'post_type'    => 'post',
					'post_content' => '<p>x</p>',
					'post_status'  => 'draft',
				)
			)
		);

		$this->assertNotFalse( has_filter( 'content_save_pre', 'wp_filter_post_kses' ) );
	}

	public function test_stored_differs_is_true_when_kses_stripped_a_tag_and_false_for_clean_content(): void {
		wp_set_current_user( $this->make_agent() );
		$dirty = '<p>x</p><script>y</script>';
		$clean = '<p>clean <strong>text</strong></p>';

		$ids = $this->in_write(
			static fn(): array => array(
				PostWriter::insert(
					array(
						'post_type'    => 'post',
						'post_content' => $dirty,
						'post_status'  => 'draft',
					)
				),
				PostWriter::insert(
					array(
						'post_type'    => 'post',
						'post_content' => $clean,
						'post_status'  => 'draft',
					)
				),
			)
		);

		$this->assertTrue( PostWriter::stored_differs( $ids[0], array( 'post_content' => $dirty ) ) );
		$this->assertFalse( PostWriter::stored_differs( $ids[1], array( 'post_content' => $clean ) ) );
	}

	public function test_stored_differs_compares_only_the_submitted_fields(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => 'Same',
				'post_content' => 'Stored body',
			)
		);

		$this->assertFalse( PostWriter::stored_differs( $post_id, array( 'post_title' => 'Same' ) ) );
		$this->assertTrue( PostWriter::stored_differs( $post_id, array( 'post_title' => 'Other' ) ) );
	}

	public function test_stored_differs_treats_a_missing_post_as_different(): void {
		$this->assertTrue( PostWriter::stored_differs( 999999999, array( 'post_title' => 'x' ) ) );
	}

	public function test_insert_with_a_gmt_date_stores_the_matching_local_date(): void {
		// WordPress clears the date of a draft, so the post is scheduled: a future post needs steadhand_publish.
		wp_set_current_user( $this->make_agent( array( Capabilities::PUBLISH => true ) ) );

		$post_id = $this->in_write(
			static fn(): int|\WP_Error => PostWriter::insert(
				array(
					'post_type'     => 'post',
					'post_title'    => 'Later',
					'post_status'   => 'future',
					'post_date_gmt' => '2031-02-03 04:05:06',
				)
			),
			Capabilities::PUBLISH
		);

		$this->assertIsInt( $post_id, 'WordPress refused the insert, so there is no date to check.' );
		$post = get_post( $post_id );
		$this->assertSame( '2031-02-03 04:05:06', $post->post_date_gmt );
		$this->assertSame( get_date_from_gmt( '2031-02-03 04:05:06' ), $post->post_date );
	}

	public function test_post_revision_returns_a_revision_of_the_content_before_the_change(): void {
		wp_set_current_user( $this->make_agent() );
		$post_id = self::factory()->post->create(
			array(
				'post_content' => 'Before the change',
				'post_status'  => 'draft',
			)
		);

		$revision = $this->in_write(
			static function () use ( $post_id ): int {
				$revision = Snapshots::post_revision( $post_id );
				PostWriter::update( $post_id, array( 'post_content' => 'After the change' ) );

				return $revision;
			}
		);

		$this->assertGreaterThan( 0, $revision );
		$this->assertSame( 'Before the change', get_post( $revision )->post_content );
		$this->assertSame( $post_id, (int) get_post( $revision )->post_parent );
		$this->assertSame( 'After the change', get_post( $post_id )->post_content );
	}

	public function test_post_revision_returns_zero_when_revisions_are_disabled(): void {
		wp_set_current_user( $this->make_agent() );
		add_filter( 'wp_revisions_to_keep', static fn(): int => 0 );
		$post_id = self::factory()->post->create(
			array(
				'post_content' => 'Kept nowhere',
				'post_status'  => 'draft',
			)
		);

		$revision = $this->in_write( static fn(): int => Snapshots::post_revision( $post_id ) );

		$this->assertSame( 0, $revision );
	}

	public function test_post_revision_returns_zero_for_a_missing_post(): void {
		wp_set_current_user( $this->make_agent() );

		$this->assertSame( 0, $this->in_write( static fn(): int => Snapshots::post_revision( 999999999 ) ) );
	}

	public function test_a_write_by_an_agent_with_publish_can_set_a_published_status(): void {
		wp_set_current_user( $this->make_agent( array( Capabilities::PUBLISH => true ) ) );

		$post_id = $this->in_write(
			static fn(): int|\WP_Error => PostWriter::insert(
				array(
					'post_type'   => 'post',
					'post_title'  => 'Live',
					'post_status' => 'publish',
				)
			),
			Capabilities::PUBLISH
		);

		$this->assertSame( 'publish', get_post( $post_id )->post_status );
	}

	public function test_update_refuses_a_string_value_for_every_field_that_is_not_on_the_update_allowlist(): void {
		wp_set_current_user( $this->make_agent() );
		$post_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$refused = array();
		$fields  = array(
			'post_author'   => '1',
			'post_password' => 'secret',
			'post_parent'   => '0',
			'ID'            => '5',
			'post_type'     => 'page',
		);

		$this->in_write(
			function () use ( $post_id, $fields, &$refused ): void {
				foreach ( $fields as $key => $value ) {
					try {
						PostWriter::update( $post_id, array( $key => $value ) );
					} catch ( \InvalidArgumentException ) {
						$refused[] = $key;
					}
				}
			}
		);

		$this->assertSame( array_keys( $fields ), $refused, 'Every field must be refused by name, whatever its type.' );
		$this->assertSame( 'draft', get_post( $post_id )->post_status );
		$this->assertSame( 'page' === get_post( $post_id )->post_type ? 'page' : 'post', get_post( $post_id )->post_type );
	}
}
