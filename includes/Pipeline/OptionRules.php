<?php
/**
 * The read-only rules for update-option: which options may be changed, which never may, what a value must look like, and what the
 * stored option must be like before a write (SPEC §5, update-option, and §6.1).
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * Nothing here writes. OptionWriter does the change, and the ability and the guardrail rule call these same functions first.
 *
 * Values are display text. WordPress stores an option through sanitize_option(), which HTML-escapes a title (Joe's becomes
 * Joe&#039;s), so a stored value is decoded before the agent sees it, and a sent value is checked by its stored form: the stored form must
 * decode back to the value that was sent (value_error()).
 *
 * The allowlist is the two built-in options, blogname and blogdescription, which the steadhand_allowed_options filter may change within
 * limits (allowed()). The hard-coded blocks (hard_blocked()) are not configurable: no filter can add a name to them, and none can remove one.
 */
final class OptionRules {

	/** The most entries the allowlist keeps, whatever the filter returns. */
	public const MAX_ENTRIES = 50;

	/** The largest max_length an allowlist entry may give. */
	public const MAX_LENGTH = 2000;

	/** The pattern an option name must match, in the allowlist and in a call. */
	private const NAME_PATTERN = '/^[A-Za-z0-9_\-]{1,64}\z/';

	/**
	 * The built-in allowlist, in the order the names are kept first. min_length is the fewest characters a value may take: an empty site
	 * title is refused, an empty tagline is normal.
	 *
	 * @var array<string, array{max_length: int, min_length: int}>
	 */
	private const DEFAULTS = array(
		'blogname'        => array(
			'max_length' => 200,
			'min_length' => 1,
		),
		'blogdescription' => array(
			'max_length' => 500,
			'min_length' => 0,
		),
	);

	/**
	 * Every option WordPress creates on a new site (populate_options() in wp-admin/includes/schema.php, WordPress 6.9 and later), except the
	 * two that may be changed, plus the options core adds elsewhere. Compared in lower case. Core options are never changed through a tool.
	 *
	 * @var string[]
	 */
	private const CORE_OPTIONS = array(
		'active_plugins',
		'admin_email',
		'admin_email_lifespan',
		'auto_plugin_theme_update_emails',
		'auto_update_core_dev',
		'auto_update_core_major',
		'auto_update_core_minor',
		'auto_update_plugins',
		'auto_update_themes',
		'avatar_default',
		'avatar_rating',
		'blog_charset',
		'blog_public',
		'category_base',
		'category_children',
		'close_comments_days_old',
		'close_comments_for_old_posts',
		'comment_max_links',
		'comment_moderation',
		'comment_order',
		'comment_previously_approved',
		'comment_registration',
		'comments_notify',
		'comments_per_page',
		'date_format',
		'db_upgraded',
		'db_version',
		'default_category',
		'default_comments_page',
		'default_comment_status',
		'default_email_category',
		'default_link_category',
		'default_pingback_flag',
		'default_ping_status',
		'default_post_format',
		'default_role',
		'disallowed_keys',
		'finished_splitting_shared_terms',
		'fileupload_url',
		'fresh_site',
		'gmt_offset',
		'hack_file',
		'home',
		'html_type',
		'image_default_align',
		'image_default_link_type',
		'image_default_size',
		'large_size_h',
		'large_size_w',
		'link_manager_enabled',
		'links_updated_date_format',
		'mailserver_login',
		'mailserver_pass',
		'mailserver_port',
		'mailserver_url',
		'medium_large_size_h',
		'medium_large_size_w',
		'medium_size_h',
		'medium_size_w',
		'moderation_keys',
		'moderation_notify',
		'nav_menu_options',
		'page_comments',
		'page_for_posts',
		'page_on_front',
		'permalink_structure',
		'ping_sites',
		'posts_per_page',
		'posts_per_rss',
		'recently_edited',
		'require_name_email',
		'rewrite_rules',
		'rss_use_excerpt',
		'show_avatars',
		'show_comments_cookies_opt_in',
		'show_on_front',
		'site_icon',
		'site_logo',
		'siteurl',
		'start_of_week',
		'sticky_posts',
		'stylesheet',
		'stylesheet_root',
		'tag_base',
		'template',
		'template_root',
		'thread_comments',
		'thread_comments_depth',
		'thumbnail_crop',
		'thumbnail_size_h',
		'thumbnail_size_w',
		'time_format',
		'timezone_string',
		'uninstall_plugins',
		'upload_path',
		'upload_url_path',
		'uploads_use_yearmonth_folders',
		'use_balancetags',
		'users_can_register',
		'use_smilies',
		'use_trackback',
		'widget_categories',
		'widget_rss',
		'widget_text',
		'wp_attachment_pages_enabled',
		'wp_force_deactivated_plugins',
		'wp_page_for_privacy_policy',
		'active_sitewide_plugins',
		'cron',
		'current_theme',
		'initial_db_version',
		'new_admin_email',
		'recently_activated',
		'sidebars_widgets',
		'widget_block',
		'wplang',
		'auth_key',
		'secure_auth_key',
		'logged_in_key',
		'nonce_key',
		'auth_salt',
		'secure_auth_salt',
		'logged_in_salt',
		'nonce_salt',
		'secret_key',
	);

	/**
	 * Name prefixes no Steadhand call may change, compared in lower case.
	 *
	 * @var string[]
	 */
	private const HARD_BLOCKED_PREFIXES = array( 'widget_', 'theme_mods_', 'steadhand_', '_transient_', '_site_transient_' );

	/**
	 * Name endings no Steadhand call may change, compared in lower case. The roles option is $wpdb->prefix . 'user_roles', so this covers
	 * every site's prefix.
	 *
	 * @var string[]
	 */
	private const HARD_BLOCKED_ENDINGS = array( 'user_roles' );

	/**
	 * Name fragments no Steadhand call may change, compared in lower case: the capability and user-level options.
	 *
	 * @var string[]
	 */
	private const HARD_BLOCKED_PARTS = array( 'capabilities', 'user_level' );

	/**
	 * Words that mark an option as holding a credential, a key or a session. Any name that contains one (in lower case) is hard-blocked,
	 * because a value sent through update-option goes to the change log and the previous value to the snapshot table.
	 *
	 * @var string[]
	 */
	private const HARD_BLOCKED_WORDS = array( 'key', 'token', 'secret', 'pass', 'auth', 'salt', 'nonce', 'license', 'licence', 'credential', 'cookie', 'session' );

	/**
	 * The options Steadhand may change, with the most characters each may take and the fewest. Each name is a key, and each entry is
	 * array( 'max_length' => int, 'min_length' => int ). The steadhand_allowed_options filter may change this list, and the result is checked:
	 * - an entry is dropped unless its name is a string that matches NAME_PATTERN (a purely numeric name is an integer key, so it is dropped),
	 *   it is not hard-blocked, and no earlier kept entry has the same name in lower case;
	 * - its definition is an array with an integer max_length from 1 to MAX_LENGTH, and an optional integer min_length from 0 to max_length
	 *   (default: the built-in min_length for a built-in name, 0 for any other);
	 * - the built-in names come first, then the others in the order the filter gave them, and at most MAX_ENTRIES are kept;
	 * - the filter cannot add a hard-blocked name; it can remove a built-in one.
	 * A filter that throws, or returns something that is not an array, leaves the built-in list. The list is read on every call and nothing
	 * is cached, so a filter added later is seen; the input schema's enum is built at registration.
	 *
	 * @return array<string, array{max_length: int, min_length: int}>
	 */
	public static function allowed(): array {
		try {
			$filtered = apply_filters( 'steadhand_allowed_options', self::DEFAULTS );
		} catch ( \Throwable ) {
			return self::DEFAULTS;
		}

		if ( ! is_array( $filtered ) ) {
			return self::DEFAULTS;
		}

		$names = array();
		foreach ( array_keys( self::DEFAULTS ) as $builtin ) {
			if ( array_key_exists( $builtin, $filtered ) ) {
				$names[] = $builtin;
			}
		}
		foreach ( array_keys( $filtered ) as $name ) {
			if ( ! in_array( $name, $names, true ) ) {
				$names[] = $name;
			}
		}

		$allowed = array();
		$lower   = array();
		foreach ( $names as $name ) {
			if ( count( $allowed ) >= self::MAX_ENTRIES ) {
				break;
			}

			if ( ! is_string( $name ) || 1 !== preg_match( self::NAME_PATTERN, $name ) || self::hard_blocked( $name ) ) {
				continue;
			}

			if ( isset( $lower[ strtolower( $name ) ] ) ) {
				continue;
			}

			$definition = $filtered[ $name ];
			if ( ! is_array( $definition ) || ! isset( $definition['max_length'] ) || ! is_int( $definition['max_length'] ) ) {
				continue;
			}

			$max = $definition['max_length'];
			if ( $max < 1 || $max > self::MAX_LENGTH ) {
				continue;
			}

			$default_min = self::DEFAULTS[ $name ]['min_length'] ?? 0;
			$min         = array_key_exists( 'min_length', $definition ) ? $definition['min_length'] : $default_min;
			if ( ! is_int( $min ) || $min < 0 || $min > $max ) {
				continue;
			}

			$allowed[ $name ]             = array(
				'max_length' => $max,
				'min_length' => $min,
			);
			$lower[ strtolower( $name ) ] = true;
		}

		return $allowed;
	}

	/**
	 * Whether an option name is one no Steadhand call may change, whatever the allowlist says. The check is case-insensitive: the name is
	 * compared in lower case with every list and pattern here.
	 *
	 * @param string $name The option name.
	 */
	public static function hard_blocked( string $name ): bool {
		$lower = strtolower( $name );

		if ( in_array( $lower, self::CORE_OPTIONS, true ) ) {
			return true;
		}

		foreach ( self::HARD_BLOCKED_PREFIXES as $prefix ) {
			if ( str_starts_with( $lower, $prefix ) ) {
				return true;
			}
		}

		foreach ( self::HARD_BLOCKED_ENDINGS as $ending ) {
			if ( str_ends_with( $lower, $ending ) ) {
				return true;
			}
		}

		foreach ( array_merge( self::HARD_BLOCKED_PARTS, self::HARD_BLOCKED_WORDS ) as $part ) {
			if ( str_contains( $lower, $part ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a string is a well-formed option name (the allowlist's pattern). It says nothing about the allowlist.
	 *
	 * @param string $name The option name.
	 */
	public static function valid_name( string $name ): bool {
		return 1 === preg_match( self::NAME_PATTERN, $name );
	}

	/**
	 * The value rule. Reads no site data: the allowlist and WordPress's own sanitizer only. In order:
	 * 1. a string of at least the option's min_length characters (an empty site title is refused by a min of 1);
	 * 2. when not empty, display text on one line of at most max_length characters (PlainText::valid() with $display): markup is refused,
	 *    and a bare &, an apostrophe and a double quote are allowed;
	 * 3. the value is not a serialized value (is_serialized(), also after trimming), because update_option() would serialize it;
	 * 4. its stored form, sanitize_option(), is a string, and decoding it gives back exactly the value sent (so an entity sent as text, such
	 *    as &amp;, &#039; or &lt;, is refused, because it would not read back as sent).
	 * A throw from the sanitizer refuses the value.
	 *
	 * @param string $name  The option name.
	 * @param mixed  $value The value, as sent.
	 * @return \WP_Error|null A steadhand_invalid_input error that says which rule failed, or null when the value passes.
	 */
	public static function value_error( string $name, mixed $value ): ?\WP_Error {
		$allowed = self::allowed();
		if ( ! array_key_exists( $name, $allowed ) ) {
			return self::invalid( __( 'This option is not one that Steadhand can change. Nothing was changed.', 'steadhand' ) );
		}

		if ( ! is_string( $value ) ) {
			return self::invalid( __( 'The value must be a string of plain text. Send it again.', 'steadhand' ) );
		}

		$max = $allowed[ $name ]['max_length'];
		$min = $allowed[ $name ]['min_length'];
		if ( 1 !== preg_match( '//u', $value ) || mb_strlen( $value, 'UTF-8' ) < $min ) {
			return self::invalid(
				/* translators: %d: the fewest characters the option may take. */
				sprintf( __( 'The value must be at least %d characters long. Send the value again. Nothing was changed.', 'steadhand' ), $min )
			);
		}

		if ( '' !== $value && ! PlainText::valid( $value, $max, true ) ) {
			return self::invalid(
				/* translators: %d: the most characters the option may take. */
				sprintf( __( 'The value must be plain text on one line, at most %d characters: no markup (< or >), no control, invisible or non-standard space characters, no leading, trailing or repeated spaces, and no percent-encoded octet. A bare & and quotes are allowed, and an entity such as &amp; is not: send the characters themselves. Send the value again.', 'steadhand' ), $max )
			);
		}

		if ( is_serialized( $value ) || is_serialized( trim( $value ) ) ) {
			return self::invalid( __( 'The value looks like serialized data, which WordPress would store as a serialized string. Send plain text. Nothing was changed.', 'steadhand' ) );
		}

		$stored = self::stored_form( $name, $value );
		if ( null === $stored || self::display( $stored ) !== $value ) {
			return self::invalid( __( 'WordPress would store this value differently from the value sent, so it was refused. Send plain text that WordPress stores unchanged. Nothing was changed.', 'steadhand' ) );
		}

		return null;
	}

	/**
	 * The stored form of a value: what sanitize_option() returns for it, which update_option() also stores. Null when the sanitizer throws or
	 * returns something that is not a string.
	 *
	 * @param string $name  The option name.
	 * @param string $value The value, as sent.
	 */
	public static function stored_form( string $name, string $value ): ?string {
		try {
			$stored = sanitize_option( $name, $value );
		} catch ( \Throwable ) {
			return null;
		}

		return is_string( $stored ) ? $stored : null;
	}

	/**
	 * The display text of a stored value: the value with its HTML entities decoded, as the agent sees it and sends it.
	 *
	 * @param string $stored A stored value, as the options table holds it.
	 */
	public static function display( string $stored ): string {
		return wp_specialchars_decode( $stored, ENT_QUOTES );
	}

	/**
	 * The stored value of an option, read straight from the options table, uncached and without filters. The row is accepted only when its
	 * name is exactly the name asked for (a case-insensitive collation could match another spelling). Null when there is no such row.
	 *
	 * @param string $name The option name.
	 */
	public static function stored_raw( string $name ): ?string {
		global $wpdb;

		// get_results() with ARRAY_N, not get_var() or get_col(): both return null for an empty string, which is a real stored value
		// (an empty tagline). The table name is $wpdb->options, which WordPress sets, not input, and the name goes through prepare().
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A live read of one option row, which must not come from the cache or run filters.
			$wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See the comment above.
			ARRAY_N
		);

		if ( ! is_array( $rows ) || ! isset( $rows[0] ) || ! is_array( $rows[0] ) || 2 !== count( $rows[0] ) ) {
			return null;
		}

		[ $found, $value ] = $rows[0];
		if ( ! is_string( $found ) || $found !== $name || ! is_string( $value ) ) {
			return null;
		}

		return $value;
	}

	/**
	 * The stored-value rule, read uncached (stored_raw()). Steadhand never creates an option, so a missing row is refused. An option that
	 * holds structured data (a serialized array or object, or a value that get_option() reads as something other than a string) is refused,
	 * because a plain value would corrupt it. An option that any filter reads on the way out or in is refused, because a write would not take
	 * effect or would be changed on the way out: a pre_option_{name}, the generic pre_option, option_{name} or default_option_{name} callback.
	 *
	 * @param string $name The option name.
	 * @return \WP_Error|null A refusal with its own code, or null when the option may be written.
	 */
	public static function stored_error( string $name ): ?\WP_Error {
		$raw = self::stored_raw( $name );
		if ( null === $raw ) {
			return new \WP_Error(
				'steadhand_refused_option_missing',
				__( 'This option does not exist in the database. Steadhand never creates an option, so nothing was changed.', 'steadhand' ),
				array( 'status' => 409 )
			);
		}

		if ( is_serialized( $raw ) ) {
			return self::structured();
		}

		foreach ( array( 'pre_option', 'pre_option_' . $name, 'option_' . $name, 'default_option_' . $name ) as $hook ) {
			if ( has_filter( $hook ) ) {
				return new \WP_Error(
					'steadhand_refused_option_filtered',
					__( 'This site overrides or filters this option in code, so a change stored in the database would not take effect, or would be changed on the way out. Nothing was changed.', 'steadhand' ),
					array( 'status' => 409 )
				);
			}
		}

		try {
			$current = get_option( $name );
		} catch ( \Throwable ) {
			$current = null;
		}

		if ( ! is_string( $current ) ) {
			return self::structured();
		}

		return null;
	}

	/**
	 * The refusal for an option that stores structured data.
	 */
	private static function structured(): \WP_Error {
		return new \WP_Error(
			'steadhand_refused_serialized_option',
			__( 'This option stores structured data, not plain text, so Steadhand will not write a plain value to it. Nothing was changed.', 'steadhand' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * A steadhand_invalid_input refusal.
	 *
	 * @param string $message The text.
	 */
	private static function invalid( string $message ): \WP_Error {
		return new \WP_Error( 'steadhand_invalid_input', $message, array( 'status' => 400 ) );
	}
}
