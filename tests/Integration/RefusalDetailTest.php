<?php
/**
 * The publish-needed refusal (object_refusal_detail) and the modified date fallback, through the real pipeline.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\Ability;
use Steadhand\Abilities\PostReader;
use Steadhand\Capabilities;
use Steadhand\Pipeline\Pipeline;

/**
 * Covers the one case where a post write refusal names the missing capability: a post the agent can read, that is
 * published, scheduled or private, and that needs steadhand_publish. Every other object refusal keeps the generic text,
 * and the change log records the refusal as blocked either way.
 */
final class RefusalDetailTest extends PostWriteTestCase {

	private const DETAIL     = 'This post is published, scheduled or private. Changing it needs the steadhand_publish capability, which this agent does not hold. Nothing was changed.';
	private const GENERIC    = 'Not found, or this agent may not access it.';
	private const PUBLISH_TO = 'Setting a status other than draft needs the steadhand_publish capability, which this agent does not hold. The call was refused and nothing was saved.';
	private const HIDDEN     = 'steadhand_hidden';
	private const PARAGRAPH  = "<!-- wp:paragraph -->\n<p>Added</p>\n<!-- /wp:paragraph -->";

	/**
	 * The four write abilities whose object check uses the publish-needed detail.
	 */
	private const WRITE_ABILITIES = array(
		'steadhand/update-post',
		'steadhand/edit-blocks',
		'steadhand/set-post-terms',
		'steadhand/set-featured-image',
	);

	/**
	 * Removes the filters and the post type this file adds.
	 */
	protected function tearDown(): void {
		remove_all_filters( 'map_meta_cap', 20 );
		if ( post_type_exists( self::HIDDEN ) ) {
			unregister_post_type( self::HIDDEN );
		}
		parent::tearDown();
	}

	/**
	 * Arguments for one write ability that target the given post, with a term and an image the test created.
	 *
	 * @param string $ability Ability name.
	 * @param int    $post    Post ID.
	 * @param int    $term    Term ID for set-post-terms.
	 * @param int    $media   Image attachment ID for set-featured-image.
	 * @return array<string, mixed>
	 */
	private function args( string $ability, int $post, int $term = 0, int $media = 0 ): array {
		return match ( $ability ) {
			'steadhand/update-post'        => array(
				'id'    => $post,
				'title' => 'Changed title',
			),
			'steadhand/edit-blocks'        => array(
				'id'         => $post,
				'operations' => array(
					array(
						'op'     => 'append',
						'markup' => self::PARAGRAPH,
					),
				),
			),
			'steadhand/set-post-terms'     => array(
				'id'       => $post,
				'taxonomy' => 'post_tag',
				'terms'    => array( $term ),
			),
			'steadhand/set-featured-image' => array(
				'id'       => $post,
				'media_id' => $media,
			),
			default                        => $this->fail( "Unknown ability $ability." ),
		};
	}

	/**
	 * A tag and an image the write abilities can use.
	 *
	 * @return array{0: int, 1: int} Term ID and attachment ID.
	 */
	private function term_and_image(): array {
		$term  = (int) self::factory()->term->create( array( 'taxonomy' => 'post_tag' ) );
		$image = (int) self::factory()->attachment->create_upload_object( DIR_TESTDATA . '/images/canola.jpg' );

		return array( $term, $image );
	}

	/**
	 * Asserts that a refusal is the publish-needed one: code, status and text.
	 *
	 * @param mixed  $result  Ability result.
	 * @param string $message Expected message.
	 */
	private function assert_refused_with( mixed $result, string $message ): void {
		$this->assertWPError( $result, 'The call must be refused.' );
		$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
		$this->assertSame( 403, $result->get_error_data()['status'] ?? null );
		$this->assertSame( $message, $result->get_error_message() );
	}

	public function test_update_post_setting_a_live_status_without_publish_gets_the_new_publish_text(): void {
		$agent  = $this->act_as_agent();
		$draft  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$before = $this->row( $draft );
		$count  = $this->post_row_count();

		$result = $this->execute_ability(
			'steadhand/update-post',
			array(
				'id'     => $draft,
				'status' => 'publish',
			)
		);
		$this->assert_refused_with( $result, self::PUBLISH_TO );
		$this->assertSame( $before, $this->row( $draft ) );
		$this->assertSame( 'blocked', $this->latest_row( $agent )['result'] );

		$created = $this->execute_ability(
			'steadhand/create-post',
			array(
				'title'  => 'New',
				'status' => 'publish',
			)
		);
		$this->assert_refused_with( $created, self::PUBLISH_TO );
		$this->assertSame( $count, $this->post_row_count() );
	}

	public function test_the_publish_text_reaches_the_agent_over_mcp_and_nothing_changes(): void {
		$this->act_as_agent();
		$draft  = $this->admin_post( array( 'post_status' => 'draft' ) );
		$before = $this->row( $draft );

		$text = $this->refusal_text(
			$this->tool_result(
				'steadhand-update-post',
				array(
					'id'     => $draft,
					'status' => 'publish',
				)
			)
		);

		$this->assertSame( self::PUBLISH_TO, $text );
		$this->assertSame( $before, $this->row( $draft ) );
	}

	public function test_a_readable_published_post_gets_the_publish_text_from_every_write_ability(): void {
		[ $term, $image ] = $this->term_and_image();
		$future           = gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS );

		foreach ( array( 'publish', 'private', 'future' ) as $status ) {
			foreach ( self::WRITE_ABILITIES as $ability ) {
				$this->act_as_agent( array( Capabilities::MEDIA => true ) );
				$fields = array( 'post_status' => $status );
				if ( 'future' === $status ) {
					$fields['post_date']     = get_date_from_gmt( $future );
					$fields['post_date_gmt'] = $future;
				}
				$post   = $this->admin_post( $fields );
				$before = $this->row( $post );
				$this->assertSame( $status, get_post_status( $post ), 'The fixture must keep the status under test.' );

				$result = $this->execute_ability( $ability, $this->args( $ability, $post, $term, $image ) );
				$this->assert_refused_with( $result, self::DETAIL );
				$this->assertSame( $before, $this->row( $post ), "$ability must change nothing on a $status post." );

				$log = $this->latest_row( get_current_user_id() );
				$this->assertSame( 'blocked', $log['result'], "$ability must log one blocked row." );
				$this->assertSame( self::DETAIL, $log['message'], "$ability must log the refusal text." );
			}
		}
	}

	public function test_a_readable_published_post_gets_the_generic_text_when_the_read_check_is_denied_by_map_meta_cap(): void {
		[ $term, $image ] = $this->term_and_image();
		$this->act_as_agent( array( Capabilities::MEDIA => true ) );
		$post = $this->admin_post( array( 'post_status' => 'publish' ) );

		// The post is published and the agent lacks publish, but read_post is denied, so the post is not readable and the
		// publish text must not reveal that it exists.
		add_filter(
			'map_meta_cap',
			static fn( array $caps, string $cap ): array => 'read_post' === $cap ? array( 'do_not_allow' ) : $caps,
			20,
			2
		);

		foreach ( self::WRITE_ABILITIES as $ability ) {
			$result = $this->execute_ability( $ability, $this->args( $ability, $post, $term, $image ) );
			$this->assertWPError( $result, $ability );
			$this->assertSame( self::GENERIC, $result->get_error_message(), "$ability must get the generic text." );
		}
	}

	/**
	 * Arguments for upload-media that send a small valid PNG with the given parent.
	 *
	 * @param int $parent_id Parent post ID.
	 * @return array<string, mixed>
	 */
	private function upload_args( int $parent_id ): array {
		return array(
			'filename'    => 'detail.png',
			'data_base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAgAAAAICAIAAABLbSncAAAAEUlEQVR42mPQqDiBFTEMLQkAAtVaAbl+5LMAAAAASUVORK5CYII=',
			'parent'      => $parent_id,
		);
	}

	public function test_upload_media_names_the_capability_only_for_a_readable_live_parent(): void {
		$parent_text = 'The parent post is published, scheduled or private. Attaching an image to it needs the steadhand_publish capability, which this agent does not hold. Nothing was uploaded.';

		$this->act_as_agent( array( Capabilities::MEDIA => true ) );
		$before = (int) wp_count_posts( 'attachment' )->inherit;

		$published = $this->admin_post( array( 'post_status' => 'publish' ) );
		$this->assert_refused_with( $this->execute_ability( 'steadhand/upload-media', $this->upload_args( $published ) ), $parent_text );
		$this->assertSame( 'blocked', $this->latest_row( get_current_user_id() )['result'] );

		$missing = $this->execute_ability( 'steadhand/upload-media', $this->upload_args( 999999 ) );
		$this->assertWPError( $missing );
		$this->assertSame( self::GENERIC, $missing->get_error_message(), 'A missing parent must get the generic text.' );

		$trashed = $this->admin_post( array( 'post_status' => 'publish' ) );
		wp_trash_post( $trashed );
		$result = $this->execute_ability( 'steadhand/upload-media', $this->upload_args( $trashed ) );
		$this->assertWPError( $result );
		$this->assertSame( self::GENERIC, $result->get_error_message(), 'A trashed parent must get the generic text.' );

		$this->assertSame( $before, (int) wp_count_posts( 'attachment' )->inherit, 'No refused upload may store an attachment.' );
	}

	public function test_upload_media_names_the_parent_capability_for_a_private_or_scheduled_parent(): void {
		$parent_text = 'The parent post is published, scheduled or private. Attaching an image to it needs the steadhand_publish capability, which this agent does not hold. Nothing was uploaded.';
		$future      = gmdate( 'Y-m-d H:i:s', time() + 2 * DAY_IN_SECONDS );

		$this->act_as_agent( array( Capabilities::MEDIA => true ) );
		$private = $this->admin_post( array( 'post_status' => 'private' ) );
		$this->assert_refused_with( $this->execute_ability( 'steadhand/upload-media', $this->upload_args( $private ) ), $parent_text );

		$scheduled = $this->admin_post(
			array(
				'post_status'   => 'future',
				'post_date'     => get_date_from_gmt( $future ),
				'post_date_gmt' => $future,
			)
		);
		$this->assert_refused_with( $this->execute_ability( 'steadhand/upload-media', $this->upload_args( $scheduled ) ), $parent_text );
	}

	public function test_upload_media_names_the_parent_capability_for_an_agent_without_write_drafts(): void {
		$parent_text = 'The parent post is published, scheduled or private. Attaching an image to it needs the steadhand_publish capability, which this agent does not hold. Nothing was uploaded.';

		$this->act_as_agent(
			array(
				Capabilities::READ         => true,
				Capabilities::MEDIA        => true,
				Capabilities::WRITE_DRAFTS => false,
			)
		);
		$published = $this->admin_post( array( 'post_status' => 'publish' ) );

		$this->assert_refused_with( $this->execute_ability( 'steadhand/upload-media', $this->upload_args( $published ) ), $parent_text );
	}

	public function test_set_featured_image_names_the_publish_capability_for_a_live_post_and_a_missing_image(): void {
		$this->act_as_agent( array( Capabilities::MEDIA => true ) );
		$post   = $this->admin_post( array( 'post_status' => 'publish' ) );
		$before = $this->row( $post );

		// The media_id names no attachment, so the object check fails and the publish detail is the refusal. The
		// publish text takes precedence over the missing image (SPEC §5, set-featured-image).
		$result = $this->execute_ability(
			'steadhand/set-featured-image',
			array(
				'id'       => $post,
				'media_id' => 999999,
			)
		);
		$this->assert_refused_with( $result, self::DETAIL );
		$this->assertSame( $before, $this->row( $post ) );
	}

	public function test_a_missing_post_gets_the_generic_text_from_every_write_ability(): void {
		[ $term, $image ] = $this->term_and_image();
		$this->act_as_agent( array( Capabilities::MEDIA => true ) );

		foreach ( self::WRITE_ABILITIES as $ability ) {
			$result = $this->execute_ability( $ability, $this->args( $ability, 999999, $term, $image ) );
			$this->assertWPError( $result );
			$this->assertSame( self::GENERIC, $result->get_error_message(), "$ability must not name the capability for a missing post." );
		}
	}

	public function test_a_trashed_published_post_gets_the_generic_text_from_every_write_ability(): void {
		[ $term, $image ] = $this->term_and_image();

		foreach ( self::WRITE_ABILITIES as $ability ) {
			$this->act_as_agent( array( Capabilities::MEDIA => true ) );
			$post = $this->admin_post( array( 'post_status' => 'trash' ) );

			$this->assertSame( self::GENERIC, $this->execute_ability( $ability, $this->args( $ability, $post, $term, $image ) )->get_error_message(), $ability );
		}
	}

	public function test_a_published_post_of_a_type_that_is_not_allowed_gets_the_generic_text(): void {
		register_post_type( self::HIDDEN, array( 'public' => true ) );
		[ $term, $image ] = $this->term_and_image();

		foreach ( self::WRITE_ABILITIES as $ability ) {
			$this->act_as_agent( array( Capabilities::MEDIA => true ) );
			$post = $this->admin_post(
				array(
					'post_type'   => self::HIDDEN,
					'post_status' => 'publish',
				)
			);

			$this->assertSame( self::GENERIC, $this->execute_ability( $ability, $this->args( $ability, $post, $term, $image ) )->get_error_message(), $ability );
		}
	}

	public function test_a_published_post_the_agent_cannot_read_gets_the_generic_text(): void {
		[ $term, $image ] = $this->term_and_image();

		foreach ( array( 'steadhand/update-post', 'steadhand/edit-blocks', 'steadhand/set-post-terms' ) as $ability ) {
			$this->act_as_agent( array( Capabilities::READ => false ) );
			$post = $this->admin_post( array( 'post_status' => 'publish' ) );

			$this->assertSame( self::GENERIC, $this->execute_ability( $ability, $this->args( $ability, $post, $term, $image ) )->get_error_message(), $ability );
		}

		// set-featured-image checks steadhand_read in its input rule, before the object check, so this agent gets the
		// capability refusal instead of the object refusal. It must not get the publish detail either.
		$this->act_as_agent(
			array(
				Capabilities::READ  => false,
				Capabilities::MEDIA => true,
			)
		);
		$post    = $this->admin_post( array( 'post_status' => 'publish' ) );
		$refusal = $this->execute_ability( 'steadhand/set-featured-image', $this->args( 'steadhand/set-featured-image', $post, 0, $image ) );
		$this->assertWPError( $refusal );
		$this->assertSame( 'This user needs the steadhand_read capability to call steadhand/set-featured-image.', $refusal->get_error_message() );
		$this->assertNotSame( self::DETAIL, $refusal->get_error_message() );
	}

	public function test_a_draft_gets_the_generic_text_for_an_agent_that_holds_publish_only(): void {
		[ $term ] = $this->term_and_image();
		$this->act_as_agent(
			array(
				Capabilities::WRITE_DRAFTS => false,
				Capabilities::PUBLISH      => true,
			)
		);
		$draft = $this->admin_post( array( 'post_status' => 'draft' ) );

		$this->assertSame( self::GENERIC, $this->execute_ability( 'steadhand/update-post', $this->args( 'steadhand/update-post', $draft ) )->get_error_message() );
		$this->assertSame( self::GENERIC, $this->execute_ability( 'steadhand/set-post-terms', $this->args( 'steadhand/set-post-terms', $draft, $term ) )->get_error_message() );
	}

	public function test_a_private_post_gets_the_generic_text_when_the_agent_holds_publish_but_the_edit_check_fails(): void {
		// The publish capability is held, so the detail names nothing; the edit check is refused by another rule.
		add_filter(
			'map_meta_cap',
			static fn( array $caps, string $cap ): array => 'edit_post' === $cap ? array( 'do_not_allow' ) : $caps,
			20,
			2
		);
		$this->act_as_agent(
			array(
				Capabilities::WRITE_DRAFTS => false,
				Capabilities::PUBLISH      => true,
			)
		);
		$private = $this->admin_post( array( 'post_status' => 'private' ) );
		$before  = $this->row( $private );

		$result = $this->execute_ability( 'steadhand/update-post', $this->args( 'steadhand/update-post', $private ) );
		$this->assertSame( self::GENERIC, $result->get_error_message() );
		$this->assertSame( $before, $this->row( $private ) );
	}

	public function test_an_ability_whose_detail_throws_or_returns_null_gets_the_generic_text(): void {
		$this->act_as_agent();

		foreach ( array( true, false ) as $throws ) {
			$double = new class( $throws ) extends Ability {
				public function __construct( private bool $throws ) {}

				public function name(): string {
					return 'steadhand/probe-refusal-detail';
				}

				public function label(): string {
					return 'Probe';
				}

				public function description(): string {
					return 'Test-only ability.';
				}

				public function input_schema(): array {
					return array(
						'type'                 => 'object',
						'properties'           => array(),
						'additionalProperties' => false,
					);
				}

				public function output_schema(): array {
					return array( 'type' => 'object' );
				}

				public function annotations(): array {
					return array(
						'readonly'    => true,
						'destructive' => false,
						'idempotent'  => true,
					);
				}

				public function capability(): string {
					return Capabilities::READ;
				}

				public function authorize_object( array $input ): bool {
					unset( $input );
					return false;
				}

				public function object_refusal_detail( array $input ): ?\WP_Error {
					unset( $input );
					if ( $this->throws ) {
						throw new \RuntimeException( 'Detail failed.' );
					}

					return null;
				}

				public function execute( array $input ): array {
					unset( $input );
					return array();
				}
			};

			$result = ( new Pipeline( new \Steadhand\Log\DbLogger() ) )->run( $double, array() );
			$this->assertWPError( $result );
			$this->assertSame( self::GENERIC, $result->get_error_message() );
		}
	}

	public function test_a_draft_created_by_create_post_has_a_modified_time_and_get_post_returns_it(): void {
		$this->act_as_agent();

		$created = $this->output_of(
			'steadhand-create-post',
			array(
				'title'   => 'Fresh draft',
				'content' => self::PARAGRAPH,
			)
		);
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $created['modified'] );

		$read = $this->execute_ability( 'steadhand/get-post', array( 'id' => $created['id'] ) );
		$this->assertIsArray( $read );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $read['modified_gmt'] );
	}

	public function test_modified_utc_gives_null_when_the_local_date_is_not_a_date(): void {
		$text = new \WP_Post(
			(object) array(
				'post_modified'     => 'not a date',
				'post_modified_gmt' => '0000-00-00 00:00:00',
			)
		);
		$this->assertNull( PostReader::modified_utc( $text ) );

		$impossible = new \WP_Post(
			(object) array(
				'post_modified'     => '2026-02-30 10:00:00',
				'post_modified_gmt' => '0000-00-00 00:00:00',
			)
		);
		$this->assertNull( PostReader::modified_utc( $impossible ) );
	}

	public function test_modified_utc_converts_a_local_date_from_the_site_timezone_when_the_gmt_date_is_zero(): void {
		$previous = get_option( 'timezone_string' );
		update_option( 'timezone_string', 'America/New_York' );

		try {
			// 10:00 in New York on 23 November 2026 is standard time, UTC-5, so 15:00 UTC.
			$post = new \WP_Post(
				(object) array(
					'post_modified'     => '2026-11-23 10:00:00',
					'post_modified_gmt' => '0000-00-00 00:00:00',
				)
			);
			$this->assertSame( '2026-11-23T15:00:00Z', PostReader::modified_utc( $post ) );
		} finally {
			update_option( 'timezone_string', $previous );
		}
	}
}
