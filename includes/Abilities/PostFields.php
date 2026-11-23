<?php
/**
 * Shared code for the post write tools: the input fields, their checks, and the output shape.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Abilities;

use Steadhand\Capabilities;
use Steadhand\Guardrails\Engine;
use Steadhand\MetaCaps;

defined( 'ABSPATH' ) || exit;

/**
 * Helpers shared by create-post and update-post. Holds no state.
 *
 * Status rules (SPEC §4.2, §14.1):
 * - draft-like statuses are draft, pending and auto-draft. Live statuses are publish, future and private.
 * - a live status, and every create whose status is not draft (pending included), needs steadhand_publish.
 * - a date is allowed only with status future, and is required with it. It is read as UTC and must be at least
 *   MIN_SCHEDULE_SECONDS ahead and at most MAX_SCHEDULE_YEARS ahead.
 */
final class PostFields {

	/** Longest title, in characters. */
	public const MAX_TITLE_CHARS = 400;

	/** Longest content, in characters. The byte limit is enforced by the ContentLimits guardrail. */
	public const MAX_CONTENT_CHARS = 262144;

	/** Longest excerpt, in characters. */
	public const MAX_EXCERPT_CHARS = 5000;

	/** Longest slug, in characters. */
	public const MAX_SLUG_CHARS = 200;

	/** Longest date string accepted. */
	public const MAX_DATE_CHARS = 40;

	/** Slug shape: lowercase letters and digits, joined by single hyphens. \z, not $, so a trailing newline is refused. */
	public const SLUG_PATTERN = '^[a-z0-9]+(?:-[a-z0-9]+)*\z';

	/** Statuses an agent may set. */
	public const STATUSES = array( 'draft', 'pending', 'publish', 'future', 'private' );

	/** Statuses that count as live: published, scheduled or private. */
	public const LIVE_STATUSES = array( 'publish', 'future', 'private' );

	/** Statuses a post may have when it is edited: the six this plugin handles. Trash is never among them. */
	public const EDITABLE_STATUSES = array( 'draft', 'pending', 'auto-draft', 'publish', 'future', 'private' );

	/** A scheduled date must be at least this many seconds ahead. */
	public const MIN_SCHEDULE_SECONDS = 60;

	/** A scheduled date may be at most this many years ahead. */
	public const MAX_SCHEDULE_YEARS = 10;

	/** Most warnings one call returns. */
	public const MAX_WARNINGS = 10;

	/**
	 * Input properties shared by create-post and update-post. The status has no default here: create-post adds one,
	 * and update-post leaves it out so that a missing status means "leave it as it is".
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function properties(): array {
		return array(
			'title'   => array(
				'type'      => 'string',
				'maxLength' => self::MAX_TITLE_CHARS,
			),
			'content' => array(
				'type'      => 'string',
				'maxLength' => self::MAX_CONTENT_CHARS,
			),
			'excerpt' => array(
				'type'      => 'string',
				'maxLength' => self::MAX_EXCERPT_CHARS,
			),
			'slug'    => array(
				'type'      => 'string',
				'pattern'   => self::SLUG_PATTERN,
				'maxLength' => self::MAX_SLUG_CHARS,
			),
			'status'  => array(
				'type' => 'string',
				'enum' => self::STATUSES,
			),
			'date'    => array(
				'type'      => 'string',
				'format'    => 'date-time',
				'maxLength' => self::MAX_DATE_CHARS,
			),
		);
	}

	/**
	 * The output shape of both write tools. It names the post after the write, not the content, which get-post returns.
	 *
	 * @return array<string, mixed>
	 */
	public static function output_schema(): array {
		$warning = array(
			'type' => 'string',
		);

		$properties = array(
			'id'                       => PostReader::id_schema(),
			'post_type'                => array(
				'type'      => 'string',
				'maxLength' => 20,
			),
			'status'                   => array(
				'type'      => 'string',
				'maxLength' => 20,
			),
			'title'                    => array( 'type' => 'string' ),
			'slug'                     => array( 'type' => 'string' ),
			'link'                     => array(
				'type'   => 'string',
				'format' => 'uri',
			),
			'modified'                 => PostReader::date_schema(),
			'changed'                  => array( 'type' => 'boolean' ),
			'content_modified_on_save' => array( 'type' => 'boolean' ),
			'warnings'                 => array(
				'type'     => 'array',
				'items'    => $warning,
				'maxItems' => self::MAX_WARNINGS,
			),
			'guardrail_notes'          => array(
				'type'     => 'array',
				'items'    => $warning,
				'maxItems' => Engine::MAX_NOTES,
			),
		);

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array_keys( $properties ),
			'properties'           => $properties,
		);
	}

	/**
	 * Excerpts longer than this many bytes are kept in the change log as a digest.
	 */
	public const LOG_EXCERPT_BYTES = 500;

	/**
	 * The copy of a write's input that the change log keeps. The content is always a digest, because revisions hold the
	 * text and the log is not a second copy of it. An excerpt over LOG_EXCERPT_BYTES is a digest too. Everything else is
	 * kept as sent.
	 *
	 * A digest is the byte count and the SHA-256 of the SUBMITTED text, before WordPress filters it on save. It matches the
	 * stored content only when content_modified_on_save is false. A value that is not text (an input that failed
	 * validation can hold one) is stored as 0 bytes and its type, so the text of such a value is never kept.
	 *
	 * The SHA-256 is written as eight groups of eight hex digits, separated by colons (see group_hex()). The log redactor
	 * masks any run of 32 or more letters and digits as a token, and a bare 64-character hex digest would be masked. The
	 * groups keep it below that length. Colons are used, not spaces, because the redactor's payment-card rule accepts runs
	 * of digits separated by single spaces or dashes, and a space-separated digest could be partly masked as a card number.
	 * Removing the colons gives the digest back.
	 *
	 * @param array<string, mixed> $input Input: prepared, or as sent when it did not pass prepare().
	 * @return array<string, mixed>
	 */
	public static function log_copy( array $input ): array {
		if ( array_key_exists( 'content', $input ) ) {
			$input['content'] = self::digest( $input['content'] );
		}

		if ( array_key_exists( 'excerpt', $input ) && ( ! is_string( $input['excerpt'] ) || strlen( $input['excerpt'] ) > self::LOG_EXCERPT_BYTES ) ) {
			$input['excerpt'] = self::digest( $input['excerpt'] );
		}

		return $input;
	}

	/**
	 * The change-log form of one text field: its size and digest, or for a value that is not text, its type.
	 *
	 * @param mixed $value The field as sent.
	 * @return array{bytes: int, sha256?: string, type?: string}
	 */
	public static function digest( mixed $value ): array {
		if ( ! is_string( $value ) ) {
			return array(
				'bytes' => 0,
				'type'  => gettype( $value ),
			);
		}

		return array(
			'bytes'  => strlen( $value ),
			'sha256' => self::group_hex( hash( 'sha256', $value ) ),
		);
	}

	/**
	 * The log form of a hex digest: its groups of eight characters, separated by colons. This is the only place the format
	 * is built; the change log's tests and any reader of a stored digest must use it too.
	 *
	 * Colons, not spaces, because the redactor's payment-card rule (13 to 19 digits, optionally separated by single spaces
	 * or dashes, Luhn-valid) can match digits across a space-separated group and mask part of the digest as [card]. The
	 * redactor never changes a colon or a dot, so the digest is stored intact.
	 *
	 * @param string $hex A hex digest, such as a SHA-256 of 64 characters.
	 * @return string
	 */
	public static function group_hex( string $hex ): string {
		return implode( ':', str_split( $hex, 8 ) );
	}

	/**
	 * The warning for a status that WordPress did not store as asked, or null when it stored the status asked for. Core
	 * changes a publish to future when the date is in the future, for one.
	 *
	 * @param string $stored    The status as stored after the write.
	 * @param string $requested The status the caller asked for.
	 * @return string|null
	 */
	public static function status_warning( string $stored, string $requested ): ?string {
		if ( $stored === $requested ) {
			return null;
		}

		return sprintf(
			/* translators: 1: the status WordPress stored. 2: the status the agent asked for. */
			__( 'WordPress stored the status "%1$s" instead of "%2$s".', 'steadhand' ),
			$stored,
			$requested
		);
	}

	/**
	 * A refusal for input that is not allowed.
	 *
	 * @param string $message Message for the caller. It says what is allowed.
	 * @return \WP_Error
	 */
	public static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'steadhand_invalid_input', $message, array( 'status' => 400 ) );
	}

	/**
	 * A refusal for a status the caller may not set.
	 *
	 * @return \WP_Error
	 */
	public static function publish_refusal(): \WP_Error {
		return new \WP_Error(
			'steadhand_forbidden',
			__( 'Setting a status other than draft needs the steadhand_publish capability, which this agent does not hold. The call was refused and nothing was saved.', 'steadhand' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * The refusal for a post the caller can read but may not change because it is published, scheduled or private and the
	 * caller does not hold steadhand_publish. Returns null in every other case, so the generic object refusal applies.
	 *
	 * Only a post that PostReader::readable_post() returns can get this text, so it reveals nothing a read tool would
	 * not already show.
	 *
	 * @param int  $post_id   The post the call targets.
	 * @param bool $as_parent True when the post is the parent of an upload, which changes the wording only.
	 * @return \WP_Error|null The refusal, or null.
	 */
	public static function publish_needed_refusal( int $post_id, bool $as_parent = false ): ?\WP_Error {
		$post = PostReader::readable_post( $post_id );
		if ( null === $post ) {
			return null;
		}

		if ( Capabilities::PUBLISH !== MetaCaps::edit_capability( (string) $post->post_status ) ) {
			return null;
		}

		if ( Capabilities::user_has( wp_get_current_user(), Capabilities::PUBLISH ) ) {
			return null;
		}

		return new \WP_Error(
			'steadhand_forbidden',
			$as_parent
				? __( 'The parent post is published, scheduled or private. Attaching an image to it needs the steadhand_publish capability, which this agent does not hold. Nothing was uploaded.', 'steadhand' )
				: __( 'This post is published, scheduled or private. Changing it needs the steadhand_publish capability, which this agent does not hold. Nothing was changed.', 'steadhand' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Whether the current user holds steadhand_publish, read from its allcaps.
	 *
	 * @return bool
	 */
	public static function user_can_publish(): bool {
		return Capabilities::user_has( wp_get_current_user(), Capabilities::PUBLISH );
	}

	/**
	 * Checks that any title, content and excerpt sent are valid UTF-8.
	 *
	 * @param array<string, mixed> $input Validated input.
	 * @return true|\WP_Error
	 */
	public static function validate_text( array $input ): bool|\WP_Error {
		foreach ( array( 'title', 'content', 'excerpt' ) as $field ) {
			if ( isset( $input[ $field ] ) && ! mb_check_encoding( (string) $input[ $field ], 'UTF-8' ) ) {
				return self::invalid(
					/* translators: %s: name of an input field, such as title. */
					sprintf( __( 'The %s must be valid UTF-8 text.', 'steadhand' ), $field )
				);
			}
		}

		return true;
	}

	/**
	 * Checks the status and date together.
	 *
	 * A date is allowed only with status future, in the same call, and is required with it. For create-post the status
	 * defaults to draft when it is not sent. The date must be a real ISO 8601 date and time, at least
	 * MIN_SCHEDULE_SECONDS ahead and at most MAX_SCHEDULE_YEARS ahead.
	 *
	 * @param array<string, mixed> $input    Validated input.
	 * @param bool                 $creating True for create-post, where the status defaults to draft.
	 * @return true|\WP_Error
	 */
	public static function validate_schedule( array $input, bool $creating ): bool|\WP_Error {
		$has_date = array_key_exists( 'date', $input );
		$status   = array_key_exists( 'status', $input ) ? (string) $input['status'] : ( $creating ? 'draft' : null );

		if ( 'future' === $status && ! $has_date ) {
			return self::invalid( __( 'A post with status future needs a date, in ISO 8601 form, for when it is published.', 'steadhand' ) );
		}

		if ( $has_date && 'future' !== $status ) {
			return self::invalid( __( 'A date is allowed only with status future, sent in the same call.', 'steadhand' ) );
		}

		if ( ! $has_date ) {
			return true;
		}

		$when = PostReader::utc_bound( (string) $input['date'] );
		if ( null === $when ) {
			return self::invalid( __( 'date must be an ISO 8601 date and time, such as 2099-01-01T09:00:00Z.', 'steadhand' ) );
		}

		$now = time();
		if ( $when->getTimestamp() < $now + self::MIN_SCHEDULE_SECONDS ) {
			return self::invalid( __( 'date must be at least 60 seconds in the future.', 'steadhand' ) );
		}

		$latest = ( new \DateTimeImmutable( '@' . $now ) )->modify( '+' . self::MAX_SCHEDULE_YEARS . ' years' );
		if ( $when->getTimestamp() > $latest->getTimestamp() ) {
			return self::invalid(
				/* translators: %d: the number of years ahead a date may be. */
				sprintf( __( 'date can be at most %d years ahead.', 'steadhand' ), self::MAX_SCHEDULE_YEARS )
			);
		}

		return true;
	}

	/**
	 * A date in the input as the GMT string WordPress stores, or null when it is not a real date and time.
	 *
	 * @param string $date ISO 8601 date and time. Read as UTC when it has no offset.
	 * @return string|null Y-m-d H:i:s in UTC.
	 */
	public static function gmt_from_input( string $date ): ?string {
		$when = PostReader::utc_bound( $date );

		return null === $when ? null : $when->format( 'Y-m-d H:i:s' );
	}

	/**
	 * The warning for a slug that WordPress did not store as sent, or null when it stored the slug as sent.
	 *
	 * WordPress stores no slug for a pending post, and makes a published slug unique by adding a number. Either shows here.
	 *
	 * @param string $stored The slug as stored after the write.
	 * @param string $sent   The slug the caller sent.
	 * @return string|null
	 */
	public static function slug_warning( string $stored, string $sent ): ?string {
		if ( $stored === $sent ) {
			return null;
		}

		if ( '' === $stored ) {
			return sprintf(
				/* translators: %s: the slug the agent sent. */
				__( 'WordPress did not keep the slug "%s" for this status, so the post has no slug.', 'steadhand' ),
				$sent
			);
		}

		return sprintf(
			/* translators: 1: the slug WordPress stored. 2: the slug the agent sent. */
			__( 'WordPress saved the slug as "%1$s", not "%2$s".', 'steadhand' ),
			$stored,
			$sent
		);
	}

	/**
	 * Builds the output for one post after a write.
	 *
	 * @param \WP_Post $post                   The post, as it is stored after the write.
	 * @param bool     $changed                Whether the write changed the post.
	 * @param bool     $content_modified_on_save Whether WordPress changed a submitted field on save.
	 * @param string[] $warnings               Warnings for the caller.
	 * @return array<string, mixed>
	 */
	public static function output( \WP_Post $post, bool $changed, bool $content_modified_on_save, array $warnings ): array {
		$item = PostReader::item( $post );

		return array(
			'id'                       => (int) $post->ID,
			'post_type'                => (string) $item['type'],
			'status'                   => (string) $item['status'],
			'title'                    => (string) $item['title'],
			'slug'                     => (string) $item['slug'],
			'link'                     => (string) $item['link'],
			'modified'                 => PostReader::modified_utc( $post ),
			'changed'                  => $changed,
			'content_modified_on_save' => $content_modified_on_save,
			'warnings'                 => array_slice( array_values( $warnings ), 0, self::MAX_WARNINGS ),
			'guardrail_notes'          => array(),
		);
	}
}
