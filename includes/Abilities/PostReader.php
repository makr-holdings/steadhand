<?php
/**
 * Shared code for the post read tools: output shapes, size limits, dates, excerpts and the block walker.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Abilities;

use Steadhand\PostTypes;

defined( 'ABSPATH' ) || exit;

/**
 * Helpers shared by list-posts, get-post, get-blocks and get-rendered. Holds no state.
 *
 * Output rules that every read tool follows:
 * - Titles and excerpts are plain text (entities decoded). They are never run through get_the_title() or the_excerpt(),
 *   so no "Private:" or "Protected:" prefix is added.
 * - Dates are ISO 8601 in UTC (for example 2026-11-21T10:00:00Z), or null when the stored date is zero or unreadable. The one
 *   exception is modified_gmt, which falls back to the local modified date when WordPress stores a zero GMT date (modified_utc()).
 * - Passwords, emails, logins and post meta are never returned. The author is {id, name} with the display name only.
 * - Content and HTML are capped at MAX_CONTENT_BYTES and cut on a UTF-8 boundary. Large content never fails a call.
 */
final class PostReader {

	/**
	 * Largest content or HTML string a tool returns, in bytes (256 KB). Longer values are cut and flagged.
	 */
	public const MAX_CONTENT_BYTES = 262144;

	/**
	 * Most block nodes get-blocks returns, counting nested blocks.
	 */
	public const MAX_BLOCKS = 2000;

	/**
	 * Deepest level get-blocks returns. A root block is at depth 0, so levels 0 to MAX_DEPTH - 1 are returned.
	 */
	public const MAX_DEPTH = 20;

	/**
	 * Words in a list excerpt.
	 */
	public const EXCERPT_WORDS = 55;

	/**
	 * Largest post ID the schemas accept.
	 */
	public const MAX_ID = 2147483647;

	/**
	 * Behavioral annotations shared by every read tool.
	 *
	 * @return array{readonly: bool, destructive: bool, idempotent: bool}
	 */
	public static function read_annotations(): array {
		return array(
			'readonly'    => true,
			'destructive' => false,
			'idempotent'  => true,
		);
	}

	/**
	 * The one per-object read check for the post tools (SPEC §14.1). Returns the post only when every rule holds: it
	 * exists, it is not in the trash, its type is allowed (PostTypes::is_allowed()), and the current user passes
	 * read_post. Every single-post tool gets its post here, in authorize_object() and in execute(), and list-posts
	 * checks each row here.
	 *
	 * The type and trash rules are checked here and not only in MetaCaps. A post type registered with map_meta_cap off
	 * is never allowed, so the type rule refuses it whatever its capability names are. WordPress can rename its
	 * read_post capability to one the agent holds (for example read) before the map_meta_cap filter runs, so MetaCaps
	 * would not see the call for such a type.
	 *
	 * @param int $post_id Post ID.
	 * @return \WP_Post|null The post if the current user may read it, otherwise null. Refusals are not told apart.
	 */
	public static function readable_post( int $post_id ): ?\WP_Post {
		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'trash' === $post->post_status || ! PostTypes::is_allowed( $post->post_type ) ) {
			return null;
		}

		return current_user_can( 'read_post', $post->ID ) ? $post : null;
	}

	/**
	 * The refusal every object-level miss gets: the same text whether the post is missing, trashed, of a type that is
	 * not allowed, or not readable. Callers must not add detail.
	 *
	 * @return \WP_Error
	 */
	public static function not_found(): \WP_Error {
		return new \WP_Error( 'steadhand_not_found', __( 'Not found, or this agent may not access it.', 'steadhand' ), array( 'status' => 404 ) );
	}

	/**
	 * Schema for a post ID input.
	 *
	 * @return array<string, mixed>
	 */
	public static function id_schema(): array {
		return array(
			'type'    => 'integer',
			'minimum' => 1,
			'maximum' => self::MAX_ID,
		);
	}

	/**
	 * Output properties shared by every post item: the list fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function item_properties(): array {
		return array(
			'id'                 => self::id_schema(),
			'type'               => array(
				'type'      => 'string',
				'maxLength' => 20,
			),
			'status'             => array(
				'type'      => 'string',
				'maxLength' => 20,
			),
			'title'              => array( 'type' => 'string' ),
			'slug'               => array( 'type' => 'string' ),
			'date_gmt'           => self::date_schema(),
			'modified_gmt'       => self::date_schema(),
			'author'             => array(
				'type'                 => 'object',
				'additionalProperties' => false,
				'required'             => array( 'id', 'name' ),
				'properties'           => array(
					'id'   => array(
						'type'    => 'integer',
						'minimum' => 0,
					),
					'name' => array( 'type' => 'string' ),
				),
			),
			'excerpt'            => array( 'type' => 'string' ),
			'password_protected' => array( 'type' => 'boolean' ),
			'parent'             => array(
				'type'    => 'integer',
				'minimum' => 0,
			),
			'link'               => array(
				'type'   => 'string',
				'format' => 'uri',
			),
		);
	}

	/**
	 * Schema for a nullable ISO 8601 UTC date.
	 *
	 * @return array<string, mixed>
	 */
	public static function date_schema(): array {
		return array(
			'type'   => array( 'string', 'null' ),
			'format' => 'date-time',
		);
	}

	/**
	 * Builds the list fields for one post.
	 *
	 * @param \WP_Post $post Post the caller may read.
	 * @return array<string, mixed>
	 */
	public static function item( \WP_Post $post ): array {
		return array(
			'id'                 => (int) $post->ID,
			'type'               => (string) $post->post_type,
			'status'             => (string) $post->post_status,
			'title'              => self::plain_text( (string) $post->post_title ),
			'slug'               => self::utf8( (string) $post->post_name ),
			'date_gmt'           => self::iso_utc( (string) $post->post_date_gmt ),
			'modified_gmt'       => self::modified_utc( $post ),
			'author'             => self::author( $post ),
			'excerpt'            => self::excerpt( $post ),
			'password_protected' => '' !== (string) $post->post_password,
			'parent'             => (int) $post->post_parent,
			'link'               => (string) get_permalink( $post ),
		);
	}

	/**
	 * The author as {id, name}. The name is the display name, and nothing else about the user is returned.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{id: int, name: string}
	 */
	public static function author( \WP_Post $post ): array {
		$user = get_userdata( (int) $post->post_author );

		return array(
			'id'   => (int) $post->post_author,
			'name' => $user ? self::plain_text( (string) $user->display_name ) : '',
		);
	}

	/**
	 * A short plain-text excerpt: the excerpt field if set, otherwise the content. Shortcodes, tags and block comments
	 * are removed, and no content filters run.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function excerpt( \WP_Post $post ): string {
		$source = '' !== trim( (string) $post->post_excerpt ) ? (string) $post->post_excerpt : (string) $post->post_content;
		$text   = self::plain_text( wp_strip_all_tags( strip_shortcodes( $source ) ) );

		return wp_trim_words( $text, self::EXCERPT_WORDS, '…' );
	}

	/**
	 * Reads an ISO 8601 bound (after or before) as a UTC instant. A value without an offset is read as UTC.
	 *
	 * The calendar date is checked before DateTimeImmutable is used, because DateTimeImmutable rolls 2026-02-30 over to
	 * 2 March. Hour 24 and minute 60 are refused for the same reason.
	 *
	 * @param string $value Bound as the caller sent it.
	 * @return \DateTimeImmutable|null The instant in UTC, or null when the value is not a real date and time.
	 */
	public static function utc_bound( string $value ): ?\DateTimeImmutable {
		if ( 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})(?:[Tt ](\d{2}):(\d{2}))?/', $value, $parts ) ) {
			if ( ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
				return null;
			}
			if ( isset( $parts[4] ) && '' !== $parts[4] && ( (int) $parts[4] > 23 || (int) $parts[5] > 59 ) ) {
				return null;
			}
		}

		try {
			return ( new \DateTimeImmutable( $value, new \DateTimeZone( 'UTC' ) ) )->setTimezone( new \DateTimeZone( 'UTC' ) );
		} catch ( \Exception ) {
			return null;
		}
	}

	/**
	 * The date_query for an optional after and before, in $input. The post_date column holds site-local time, and it is
	 * set for every post, including drafts that were never published (their post_date_gmt is zero). So each bound is
	 * converted to the site time zone, and the comparison is inclusive.
	 *
	 * @param array<string, mixed> $input Validated input; after and before are optional ISO 8601 strings.
	 * @return array<string, mixed> The date_query, or an empty array when neither bound is set.
	 */
	public static function date_query( array $input ): array {
		$clauses = array();
		foreach ( array( 'after', 'before' ) as $bound ) {
			if ( ! isset( $input[ $bound ] ) || '' === $input[ $bound ] ) {
				continue;
			}
			$date = self::utc_bound( (string) $input[ $bound ] );
			if ( null === $date ) {
				continue;
			}

			$local     = $date->setTimezone( wp_timezone() );
			$clauses[] = array(
				'column'    => 'post_date',
				$bound      => array(
					'year'   => (int) $local->format( 'Y' ),
					'month'  => (int) $local->format( 'n' ),
					'day'    => (int) $local->format( 'j' ),
					'hour'   => (int) $local->format( 'G' ),
					'minute' => (int) $local->format( 'i' ),
					'second' => (int) $local->format( 's' ),
				),
				'inclusive' => true,
			);
		}

		return $clauses ? array_merge( array( 'relation' => 'AND' ), $clauses ) : array();
	}

	/**
	 * The last-modified time of a post as ISO 8601 UTC, or null when none is stored.
	 *
	 * WordPress stores post_modified_gmt as zero on a draft that was never saved after creation, so when it is zero the
	 * local post_modified is read in the site's current timezone (wp_timezone()) and converted to UTC instead. A local
	 * date that is not a real date, such as 2026-02-30, gives null.
	 *
	 * @param \WP_Post $post The post.
	 */
	public static function modified_utc( \WP_Post $post ): ?string {
		$gmt = self::iso_utc( (string) $post->post_modified_gmt );
		if ( null !== $gmt ) {
			return $gmt;
		}

		$local = (string) $post->post_modified;
		if ( '' === $local || str_starts_with( $local, '0000-00-00' ) ) {
			return null;
		}

		$date   = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $local, wp_timezone() );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return null;
		}

		return $date->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Converts a stored GMT date to ISO 8601 UTC, or returns null for an empty or zero or unreadable date.
	 *
	 * @param string $gmt Date as stored, Y-m-d H:i:s in GMT.
	 */
	public static function iso_utc( string $gmt ): ?string {
		if ( '' === $gmt || str_starts_with( $gmt, '0000-00-00' ) ) {
			return null;
		}

		$date   = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $gmt, new \DateTimeZone( 'UTC' ) );
		$errors = \DateTimeImmutable::getLastErrors();
		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) ) {
			return null;
		}

		return $date->format( 'Y-m-d\TH:i:s\Z' );
	}

	/**
	 * Removes invalid UTF-8 bytes. Stored text is normally valid, but one bad byte would break the JSON response.
	 *
	 * @param string $text Text.
	 */
	public static function utf8( string $text ): string {
		return wp_check_invalid_utf8( $text, true );
	}

	/**
	 * A label as plain text: tags removed, entities decoded, cut to $max_bytes on a UTF-8 boundary. Used for titles and
	 * other short labels that come from site data (media titles, alt text, captions and menu item titles).
	 *
	 * @param string $raw       Value as stored.
	 * @param int    $max_bytes Longest result, in bytes.
	 */
	public static function label_text( string $raw, int $max_bytes ): string {
		return self::cut_utf8( self::plain_text( wp_strip_all_tags( self::utf8( $raw ) ) ), $max_bytes );
	}

	/**
	 * Plain text: valid UTF-8 with every HTML entity decoded.
	 *
	 * @param string $text Text as stored.
	 */
	public static function plain_text( string $text ): string {
		return html_entity_decode( self::utf8( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	/**
	 * Caps a content string at MAX_CONTENT_BYTES.
	 *
	 * @param string $content Content, already made valid UTF-8.
	 * @return array{value: string, truncated: bool, bytes: int} The value kept, whether it was cut, and the full size.
	 */
	public static function cap_content( string $content ): array {
		$bytes = strlen( $content );

		return array(
			'value'     => $bytes > self::MAX_CONTENT_BYTES ? self::cut_utf8( $content, self::MAX_CONTENT_BYTES ) : $content,
			'truncated' => $bytes > self::MAX_CONTENT_BYTES,
			'bytes'     => $bytes,
		);
	}

	/**
	 * Cuts a string to at most $max_bytes without splitting a UTF-8 character. Needs no mbstring.
	 *
	 * A cut can land inside a character, which leaves at most three bytes of it. Those are trimmed. The loop is bounded
	 * so that invalid input cannot make it slow; invalid input yields '' rather than a broken string.
	 *
	 * @param string $text      Valid UTF-8 text.
	 * @param int    $max_bytes Largest size in bytes.
	 */
	public static function cut_utf8( string $text, int $max_bytes ): string {
		if ( strlen( $text ) <= $max_bytes ) {
			return $text;
		}

		$cut   = substr( $text, 0, max( 0, $max_bytes ) );
		$valid = 1 === preg_match( '//u', $cut );
		for ( $i = 0; $i < 4 && ! $valid; $i++ ) {
			$cut   = substr( $cut, 0, -1 );
			$valid = 1 === preg_match( '//u', $cut );
		}

		return $valid ? $cut : '';
	}

	/**
	 * Walks a parse_blocks() tree into the get-blocks shape, with limits.
	 *
	 * Paths are dot-separated index paths of the nodes this function keeps. Whitespace-only freeform nodes, which
	 * parse_blocks() puts between blocks, are dropped before indexing, so "0.1" is the second block inside the first.
	 * A node that is cut for a limit takes no index, but every kept node keeps the index it would have had. The cuts
	 * are the first MAX_BLOCKS nodes in document order, nodes shallower than MAX_DEPTH, and a total of MAX_CONTENT_BYTES
	 * across inner_html and attrs. Names are not counted; they are short identifiers.
	 *
	 * Limits are parameters so that tests can use small values. The tool always passes the constants.
	 *
	 * @param array<int, array<string, mixed>> $parsed     Output of parse_blocks().
	 * @param int                              $max_blocks Most nodes to return.
	 * @param int                              $max_depth  Deepest level to return, with a root block at depth 0.
	 * @param int                              $max_bytes  Budget for inner_html and attrs together.
	 * @return array{blocks: array<int, array<string, mixed>>, block_count: int, truncated: bool}
	 */
	public static function blocks(
		array $parsed,
		int $max_blocks = self::MAX_BLOCKS,
		int $max_depth = self::MAX_DEPTH,
		int $max_bytes = self::MAX_CONTENT_BYTES
	): array {
		$state = array(
			'count'     => 0,
			'bytes'     => 0,
			'truncated' => false,
		);
		$tree  = self::walk( $parsed, '', 0, $state, $max_blocks, $max_depth, $max_bytes );

		return array(
			'blocks'      => $tree,
			'block_count' => $state['count'],
			'truncated'   => $state['truncated'],
		);
	}

	/**
	 * Walks one level of blocks. See blocks() for the rules.
	 *
	 * @param array<int, mixed>    $nodes      Nodes at this level.
	 * @param string               $prefix     Path of the parent, or '' at the root.
	 * @param int                  $depth      Depth of these nodes.
	 * @param array<string, mixed> $state     Running count, byte total and truncation flag, updated in place.
	 * @param int                  $max_blocks Most nodes.
	 * @param int                  $max_depth  Deepest level.
	 * @param int                  $max_bytes  Byte budget.
	 * @return array<int, array<string, mixed>>
	 */
	private static function walk( array $nodes, string $prefix, int $depth, array &$state, int $max_blocks, int $max_depth, int $max_bytes ): array {
		$out   = array();
		$index = 0;

		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) ) {
				continue;
			}

			$name = $node['blockName'] ?? null;
			$html = (string) ( $node['innerHTML'] ?? '' );
			if ( null === $name && '' === trim( $html ) ) {
				continue;
			}

			$path = '' === $prefix ? (string) $index : $prefix . '.' . $index;
			++$index;

			if ( $state['count'] >= $max_blocks || $depth >= $max_depth ) {
				$state['truncated'] = true;
				continue;
			}
			++$state['count'];

			$attrs      = (array) ( $node['attrs'] ?? array() );
			$room       = $max_bytes - $state['bytes'];
			$attrs_size = strlen( (string) wp_json_encode( (object) $attrs ) );
			if ( $attrs_size > $room ) {
				$attrs              = array();
				$attrs_size         = 2;
				$state['truncated'] = true;
			}

			$room = max( 0, $room - $attrs_size );
			if ( strlen( $html ) > $room ) {
				$html               = self::cut_utf8( $html, $room );
				$state['truncated'] = true;
			}
			$state['bytes'] += $attrs_size + strlen( $html );

			$out[] = array(
				'path'         => $path,
				'name'         => null === $name ? null : (string) $name,
				'attrs'        => (object) $attrs,
				'inner_html'   => $html,
				'inner_blocks' => self::walk( (array) ( $node['innerBlocks'] ?? array() ), $path, $depth + 1, $state, $max_blocks, $max_depth, $max_bytes ),
			);
		}

		return $out;
	}

	/**
	 * Schema for one block node, nested to $levels.
	 *
	 * Core's REST validator has no $ref, so recursion cannot be written as a reference. The schema is generated to the
	 * depth the walker can produce, and the deepest level accepts no children. Every object is strict, except attrs,
	 * which is an attribute bag and accepts any keys.
	 *
	 * @param int $levels How many levels this schema describes, counting this one.
	 * @return array<string, mixed>
	 */
	public static function block_schema( int $levels = self::MAX_DEPTH ): array {
		$inner_blocks = $levels > 1
			? array(
				'type'  => 'array',
				'items' => self::block_schema( $levels - 1 ),
			)
			: array(
				'type'     => 'array',
				'maxItems' => 0,
			);

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'path', 'name', 'attrs', 'inner_html', 'inner_blocks' ),
			'properties'           => array(
				'path'         => array(
					'type'    => 'string',
					'pattern' => '^[0-9]+(\.[0-9]+)*$',
				),
				'name'         => array( 'type' => array( 'string', 'null' ) ),
				'attrs'        => array(
					'type'                 => 'object',
					'additionalProperties' => true,
				),
				'inner_html'   => array( 'type' => 'string' ),
				'inner_blocks' => $inner_blocks,
			),
		);
	}
}
