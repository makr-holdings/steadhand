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
 * The allowlist is the two built-in options, blogname and blogdescription, which the steadhand_allowed_options filter may change
 * within limits (allowed()). The hard-coded blocks (hard_blocked()) are not configurable: no filter can add a name to them, and
 * none can remove one. A name is refused when it is hard-blocked, whatever the allowlist says.
 */
final class OptionRules {

	/** The most entries the allowlist keeps, whatever the filter returns. */
	public const MAX_ENTRIES = 50;

	/** The largest max_length an allowlist entry may give. */
	public const MAX_LENGTH = 2000;

	/** The pattern an option name must match, in the allowlist and in a call. */
	private const NAME_PATTERN = '/^[A-Za-z0-9_\-]{1,64}\z/';

	/**
	 * The built-in allowlist, in the order the names are kept first.
	 *
	 * @var array<string, array{max_length: int}>
	 */
	private const DEFAULTS = array(
		'blogname'        => array( 'max_length' => 200 ),
		'blogdescription' => array( 'max_length' => 500 ),
	);

	/**
	 * Option names no Steadhand call may change, compared in lower case. Each is a setting that breaks the site, or lets an agent take it
	 * over, or is stored in a form a plain value would corrupt (SPEC §6.1).
	 *
	 * @var string[]
	 */
	private const HARD_BLOCKED = array(
		'siteurl',
		'home',
		'active_plugins',
		'active_sitewide_plugins',
		'recently_activated',
		'uninstall_plugins',
		'sidebars_widgets',
		'widget_block',
		'template',
		'stylesheet',
		'current_theme',
		'users_can_register',
		'default_role',
		'admin_email',
		'new_admin_email',
		'cron',
		'rewrite_rules',
		'permalink_structure',
		'upload_path',
		'upload_url_path',
		'db_version',
		'initial_db_version',
		'wplang',
		'blog_public',
		'mailserver_url',
		'mailserver_login',
		'mailserver_pass',
		'mailserver_port',
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
	 * The options Steadhand may change, with the most characters each may take. Each name is a key, and each entry is
	 * array( 'max_length' => int ). The steadhand_allowed_options filter may change this list, and the result is checked:
	 * - an entry is dropped unless its name is a string that matches NAME_PATTERN, its definition is an array with an integer
	 *   max_length from 1 to MAX_LENGTH, and its name is not hard-blocked;
	 * - the built-in names come first, then the others in the order the filter gave them, and at most MAX_ENTRIES are kept;
	 * - the filter cannot add a hard-blocked name; it can remove a built-in one.
	 * A filter that throws, or returns something that is not an array, leaves the built-in list. Computed on each call: nothing is cached.
	 *
	 * @return array<string, array{max_length: int}>
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
		foreach ( $names as $name ) {
			if ( count( $allowed ) >= self::MAX_ENTRIES ) {
				break;
			}

			$definition = $filtered[ $name ];
			if ( ! is_string( $name ) || 1 !== preg_match( self::NAME_PATTERN, $name ) || self::hard_blocked( $name ) ) {
				continue;
			}

			if ( ! is_array( $definition ) || ! isset( $definition['max_length'] ) || ! is_int( $definition['max_length'] ) ) {
				continue;
			}

			if ( $definition['max_length'] < 1 || $definition['max_length'] > self::MAX_LENGTH ) {
				continue;
			}

			$allowed[ $name ] = array( 'max_length' => $definition['max_length'] );
		}

		return $allowed;
	}

	/**
	 * Whether an option name is one no Steadhand call may change, whatever the allowlist says. The check is case-insensitive.
	 *
	 * @param string $name The option name.
	 */
	public static function hard_blocked( string $name ): bool {
		$lower = strtolower( $name );

		if ( in_array( $lower, self::HARD_BLOCKED, true ) ) {
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

		foreach ( self::HARD_BLOCKED_PARTS as $part ) {
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
	 * The value rule. Reads no site data: the allowlist and WordPress's own sanitizer only. A value must be a string, and when it is not
	 * empty it must be plain text of at most the option's max_length characters (PlainText::valid()). An empty value is allowed, because
	 * an empty tagline is normal. Every value must also be one WordPress would store unchanged: sanitize_option() must return it as sent.
	 * A throw from the sanitizer, or a result that is not a string, refuses the value.
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
		if ( '' !== $value && ! PlainText::valid( $value, $max ) ) {
			return self::invalid(
				/* translators: %d: the most characters the option may take. */
				sprintf( __( 'The value must be plain text on one line, at most %d characters: no markup (< or >), no entity other than &amp;, no bare &, no control, invisible or non-standard space characters, no leading, trailing or repeated spaces, and no percent-encoded octet. Send the value again.', 'steadhand' ), $max )
			);
		}

		try {
			$sanitized = sanitize_option( $name, $value );
		} catch ( \Throwable ) {
			$sanitized = null;
		}

		if ( ! is_string( $sanitized ) || $sanitized !== $value ) {
			return self::invalid( __( 'WordPress would store this value differently from the value sent, so it was refused. Send plain text that WordPress stores unchanged. Nothing was changed.', 'steadhand' ) );
		}

		return null;
	}

	/**
	 * The stored value of an option, read straight from the options table. It runs no filter and uses no cache, so it is what the
	 * database holds. Null when there is no row.
	 *
	 * @param string $name The option name.
	 */
	public static function stored_raw( string $name ): ?string {
		global $wpdb;

		// get_results() with ARRAY_N, not get_var() or get_col(): both return null for an empty string, which is a real stored value
		// (an empty tagline). The table name is $wpdb->options, which WordPress sets, not input, and the name goes through prepare().
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A live read of one option row, which must not come from the cache or run filters.
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- See the comment above.
			ARRAY_N
		);

		if ( ! is_array( $rows ) || ! isset( $rows[0][0] ) || ! is_string( $rows[0][0] ) ) {
			return null;
		}

		return $rows[0][0];
	}

	/**
	 * The stored-value rule, read uncached (stored_raw()). Steadhand never creates an option, so a missing row is refused. An option that
	 * holds structured data (a serialized array or object, or a value that get_option() reads as something other than a string) is refused,
	 * because a plain value would corrupt it. An option the site overrides with a pre_option_ filter is refused, because a write would
	 * not take effect.
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

		if ( has_filter( 'pre_option_' . $name ) ) {
			return new \WP_Error(
				'steadhand_refused_option_filtered',
				__( 'This site overrides this option in code with a pre_option filter, so a change stored in the database would not take effect. Nothing was changed.', 'steadhand' ),
				array( 'status' => 409 )
			);
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
