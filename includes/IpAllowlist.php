<?php
/**
 * Per-agent IP allowlist: which client addresses an agent's Application Password is accepted from (SPEC §4.1, D28).
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand;

defined( 'ABSPATH' ) || exit;

/**
 * The allowlist of one dedicated agent. Pure helpers and storage; no hooks. Lockdown enforces the list and the Agents screen edits it.
 *
 * An entry is one IPv4 or IPv6 address, or a CIDR range. Entries are stored in canonical text form. A client address is taken from
 * REMOTE_ADDR only, unless the owner's code filters it with steadhand_client_ip (docs/HOOKS.md).
 */
final class IpAllowlist {

	/** The user meta key that holds the list: one array of canonical entries, or nothing when the list is off. */
	public const META_KEY = 'steadhand_allowed_ips';

	/** The most entries one list may hold. */
	public const MAX_ENTRIES = 20;

	/** The longest entry line, in bytes, as typed (before canonicalisation). */
	public const MAX_ENTRY_LENGTH = 49;

	/** The most error lines parse() reports. */
	private const MAX_ERRORS = 5;

	/** The longest error text parse() reports, in bytes. */
	private const MAX_ERROR_LENGTH = 60;

	/** The narrowest IPv4 range accepted (a /8). A wider range is nearly "everyone". */
	private const MIN_PREFIX_V4 = 8;

	/** The narrowest IPv6 range accepted (a /16). */
	private const MIN_PREFIX_V6 = 16;

	/**
	 * Parses the text of the form: one entry per line. Blank lines are ignored. An entry is refused when it is not exactly one
	 * address or range in the accepted forms. Duplicates are dropped after canonicalisation.
	 *
	 * @param string $text The text, with its line breaks, after wp_unslash().
	 * @return array{entries: string[], errors: string[]} The canonical entries in their order, and the offending lines (at most 5).
	 *                                                    A list of more than MAX_ENTRIES entries is reported as an error too.
	 */
	public static function parse( string $text ): array {
		$entries = array();
		$errors  = array();

		foreach ( explode( "\n", $text ) as $line ) {
			// Copy-paste friendly: spaces, tabs and CRs at the ends of a line are not part of the entry. Whitespace inside one is refused.
			$line = trim( $line, " \t\r" );
			if ( '' === $line ) {
				continue;
			}

			$canonical = self::canonical( $line );
			if ( null === $canonical ) {
				$errors[] = self::printable( $line );
				continue;
			}

			if ( ! in_array( $canonical, $entries, true ) ) {
				$entries[] = $canonical;
			}
		}

		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$errors[] = sprintf(
				/* translators: %d: the most entries an allowlist may hold. */
				__( 'A list may hold at most %d addresses or ranges.', 'steadhand' ),
				self::MAX_ENTRIES
			);
		}

		return array(
			'entries' => $entries,
			'errors'  => array_slice( $errors, 0, self::MAX_ERRORS ),
		);
	}

	/**
	 * The stored list of a user.
	 *
	 * An empty array means the list is off (no list, or an empty one). Null means the stored value is corrupt: it is not a list, it
	 * holds more than MAX_ENTRIES entries, or an entry does not parse back to itself. Null refuses the agent from every address.
	 *
	 * @param int $user_id The agent.
	 * @return string[]|null
	 */
	public static function for_user( int $user_id ): ?array {
		$rows = get_user_meta( $user_id, self::META_KEY, false );
		if ( array() === $rows ) {
			return array();
		}

		if ( 1 !== count( $rows ) ) {
			return null;
		}

		$value = array_values( $rows )[0];
		if ( ! is_array( $value ) ) {
			return null;
		}

		if ( array() === $value ) {
			return array();
		}

		if ( ! array_is_list( $value ) || count( $value ) > self::MAX_ENTRIES ) {
			return null;
		}

		$seen = array();
		foreach ( $value as $entry ) {
			if ( ! is_string( $entry ) ) {
				return null;
			}

			$key = self::entry_key( $entry );
			if ( null === $key || isset( $seen[ $key ] ) ) {
				return null;
			}
			$seen[ $key ] = true;
		}

		return $value;
	}

	/**
	 * Whether a client address is on the list. The comparison is binary, with inet_pton().
	 *
	 * An IPv4-mapped IPv6 client address (::ffff:a.b.c.d) is compared as that IPv4 address. An IPv4 entry never matches an IPv6
	 * client, and the reverse. An address that does not parse matches nothing.
	 *
	 * @param string   $ip      The client address.
	 * @param string[] $entries The entries of the list.
	 * @return bool
	 */
	public static function matches( string $ip, array $entries ): bool {
		$client = inet_pton( $ip );
		if ( false === $client ) {
			return false;
		}

		if ( self::is_mapped( $client ) ) {
			$client = substr( $client, 12 );
		}

		foreach ( $entries as $entry ) {
			if ( ! is_string( $entry ) ) {
				continue;
			}

			$slash   = strpos( $entry, '/' );
			$address = false === $slash ? $entry : substr( $entry, 0, $slash );
			$packed  = inet_pton( $address );
			if ( false === $packed || strlen( $packed ) !== strlen( $client ) ) {
				continue;
			}

			$prefix = false === $slash ? strlen( $packed ) * 8 : (int) substr( $entry, $slash + 1 );
			if ( $prefix < 0 || $prefix > strlen( $packed ) * 8 ) {
				continue;
			}

			if ( self::same_prefix( $client, $packed, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a request from the current client may use the agent's credentials.
	 *
	 * With no list (or an empty one), every address is allowed. A corrupt list allows none. Otherwise the client address must be on
	 * the list.
	 *
	 * @param int $user_id The agent.
	 * @return bool
	 */
	public static function allows( int $user_id ): bool {
		return self::evaluate( $user_id )['allowed'];
	}

	/**
	 * The decision and the address it was made on, so that a caller can report the address without asking for it a second time.
	 *
	 * @param int $user_id The agent.
	 * @return array{allowed: bool, ip: string} The address seen, or '' when none could be determined.
	 */
	public static function evaluate( int $user_id ): array {
		$entries = self::for_user( $user_id );
		if ( array() === $entries ) {
			// No list: every address is allowed, so the address is not looked up.
			return array(
				'allowed' => true,
				'ip'      => '',
			);
		}

		// A corrupt list allows no address, but the address is still reported, so that the refusal can name it.
		$ip = self::client_ip( $user_id );
		if ( null === $ip ) {
			return array(
				'allowed' => false,
				'ip'      => '',
			);
		}

		return array(
			'allowed' => null !== $entries && self::matches( $ip, $entries ),
			'ip'      => $ip,
		);
	}

	/**
	 * The client address: REMOTE_ADDR only, then the steadhand_client_ip filter.
	 *
	 * The filter is the only way a proxy's forwarded address reaches the check. The callback may return a header's value only when the
	 * web server makes that header impossible for a client to set. Any other value must be refused by the callback. A result that
	 * inet_pton() does not accept, or a throwing callback, gives null, which refuses.
	 *
	 * @param int $user_id The agent whose list is checked.
	 * @return string|null
	 */
	public static function client_ip( int $user_id = 0 ): ?string {
		$ip = '';
		// REMOTE_ADDR is the only address the check trusts by itself. Headers such as X-Forwarded-For are never read.
		if ( isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) && false !== inet_pton( $_SERVER['REMOTE_ADDR'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Validated by inet_pton() on this line; it is never printed raw.
			$ip = $_SERVER['REMOTE_ADDR']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- See the line above.
		}

		try {
			/**
			 * Filters the client address that an agent's IP allowlist is checked against.
			 *
			 * @param string $ip      The address from REMOTE_ADDR, or '' when it is missing or invalid.
			 * @param int    $user_id The agent whose list is checked.
			 */
			$filtered = apply_filters( 'steadhand_client_ip', $ip, $user_id );
		} catch ( \Throwable ) {
			return null;
		}

		if ( ! is_string( $filtered ) || false === inet_pton( $filtered ) ) {
			return null;
		}

		return $filtered;
	}

	/**
	 * The canonical form of one entry line, or null when the line is refused.
	 *
	 * @param string $line One line, already trimmed.
	 * @return string|null
	 */
	private static function canonical( string $line ): ?string {
		$decoded = self::decode( $line );
		if ( null === $decoded ) {
			return null;
		}

		$text = inet_ntop( self::masked( $decoded['packed'], $decoded['prefix'] ) );
		if ( false === $text ) {
			return null;
		}

		return $decoded['prefix'] === $decoded['bits'] ? $text : $text . '/' . $decoded['prefix'];
	}

	/**
	 * The binary identity of a stored entry, or null when it is not a valid entry. An entry is valid when it decodes, its address is
	 * already masked to its prefix, and its prefix follows the rules. The text is not compared: an equivalent spelling is valid.
	 *
	 * @param string $entry The stored entry.
	 * @return string|null A key for duplicate detection, or null.
	 */
	private static function entry_key( string $entry ): ?string {
		$decoded = self::decode( $entry );
		if ( null === $decoded || self::masked( $decoded['packed'], $decoded['prefix'] ) !== $decoded['packed'] ) {
			return null;
		}

		return bin2hex( $decoded['packed'] ) . '/' . $decoded['prefix'];
	}

	/**
	 * Decodes one entry: the packed address, its prefix (the full length for a bare address) and the address length in bits. Null when
	 * the entry is refused by the syntax or the range rules.
	 *
	 * @param string $entry One entry, without surrounding whitespace.
	 * @return array{packed: string, prefix: int, bits: int}|null
	 */
	private static function decode( string $entry ): ?array {
		if ( strlen( $entry ) > self::MAX_ENTRY_LENGTH ) {
			return null;
		}

		// Only hex digits, colons, dots and at most one slash. This refuses whitespace, brackets, zone ids, ports, hostnames and the rest.
		if ( 1 !== preg_match( '/^[0-9A-Fa-f:.\/]+\z/', $entry ) ) {
			return null;
		}

		$slash = strpos( $entry, '/' );
		if ( false !== $slash && false !== strpos( $entry, '/', $slash + 1 ) ) {
			return null;
		}

		$address = false === $slash ? $entry : substr( $entry, 0, $slash );
		if ( ! self::is_strict_address( $address ) ) {
			return null;
		}

		$packed = inet_pton( $address );
		if ( false === $packed || self::is_mapped( $packed ) ) {
			// An IPv4-mapped IPv6 address is refused: the client side already folds those to IPv4, so such an entry could never match.
			return null;
		}

		$bits = strlen( $packed ) * 8;
		if ( false === $slash ) {
			return array(
				'packed' => $packed,
				'prefix' => $bits,
				'bits'   => $bits,
			);
		}

		$digits = substr( $entry, $slash + 1 );
		if ( 1 !== preg_match( '/^\d{1,3}\z/', $digits ) ) {
			return null;
		}

		$prefix = (int) $digits;
		$min    = 32 === $bits ? self::MIN_PREFIX_V4 : self::MIN_PREFIX_V6;
		if ( $prefix < $min || $prefix > $bits ) {
			return null;
		}

		return array(
			'packed' => $packed,
			'prefix' => $prefix,
			'bits'   => $bits,
		);
	}

	/**
	 * Whether an address is written in a form inet_pton() is trusted with: a dotted quad with no leading zeros, or an IPv6 address
	 * whose embedded IPv4 part (if any) is written the same way.
	 *
	 * @param string $address The address part of an entry.
	 * @return bool
	 */
	private static function is_strict_address( string $address ): bool {
		if ( false === strpos( $address, '.' ) ) {
			return true;
		}

		$colon = strrpos( $address, ':' );
		$tail  = false === $colon ? $address : substr( $address, $colon + 1 );

		return 1 === preg_match( '/^(0|[1-9][0-9]{0,2})(\.(0|[1-9][0-9]{0,2})){3}\z/', $tail );
	}

	/**
	 * Whether a packed address is an IPv4-mapped IPv6 address (::ffff:a.b.c.d).
	 *
	 * @param string $packed The packed address.
	 * @return bool
	 */
	private static function is_mapped( string $packed ): bool {
		return 16 === strlen( $packed ) && str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 );
	}

	/**
	 * The packed address with every bit after the prefix set to zero.
	 *
	 * @param string $packed The packed address.
	 * @param int    $prefix The prefix length in bits.
	 * @return string
	 */
	private static function masked( string $packed, int $prefix ): string {
		$full = intdiv( $prefix, 8 );
		$rest = $prefix % 8;
		$len  = strlen( $packed );
		$out  = $packed;

		for ( $i = $full + ( 0 === $rest ? 0 : 1 ); $i < $len; $i++ ) {
			$out[ $i ] = "\0";
		}

		if ( 0 !== $rest ) {
			$out[ $full ] = chr( ord( $out[ $full ] ) & ( ( 0xFF << ( 8 - $rest ) ) & 0xFF ) );
		}

		return $out;
	}

	/**
	 * Whether two packed addresses of the same length agree on their first $prefix bits.
	 *
	 * @param string $a      One packed address.
	 * @param string $b      The other, of the same length.
	 * @param int    $prefix The number of leading bits to compare.
	 * @return bool
	 */
	private static function same_prefix( string $a, string $b, int $prefix ): bool {
		$full = intdiv( $prefix, 8 );
		$rest = $prefix % 8;

		if ( substr( $a, 0, $full ) !== substr( $b, 0, $full ) ) {
			return false;
		}

		if ( 0 === $rest ) {
			return true;
		}

		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;

		return ( ord( $a[ $full ] ) & $mask ) === ( ord( $b[ $full ] ) & $mask );
	}

	/**
	 * Removes the list of every user. Called by uninstall.php. Users are never deleted, so the meta is removed from every user, not only
	 * from the agents.
	 */
	public static function uninstall(): void {
		delete_metadata( 'user', 0, self::META_KEY, '', true );
	}

	/**
	 * A line as it may be printed: control and non-ASCII bytes replaced, and cut to MAX_ERROR_LENGTH.
	 *
	 * @param string $line The offending line.
	 * @return string
	 */
	private static function printable( string $line ): string {
		$safe = (string) preg_replace( '/[^\x20-\x7E]/', '?', $line );

		return substr( $safe, 0, self::MAX_ERROR_LENGTH );
	}
}
