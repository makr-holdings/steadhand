<?php
/**
 * Maps WordPress's post capabilities to Steadhand's for dedicated agents, during Steadhand calls only.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand;

use Steadhand\Abilities\MediaReader;
use Steadhand\Pipeline\Pipeline;
use Steadhand\Pipeline\TrashRules;

defined( 'ABSPATH' ) || exit;

/**
 * The map_meta_cap mapping (SPEC §4.2, §14.1, D6). It covers read_post, edit_post, publish_post and delete_post.
 *
 * The mapping applies only when all of these hold: a Steadhand call is authorizing or executing (Pipeline::is_running()),
 * the capability is one of those four, the user is a dedicated agent (Agents::is_agent()), and a post ID is given.
 * Anything else returns the capabilities core chose. The agent role never holds WordPress's general editing capabilities,
 * so outside a Steadhand call the answers are core's own.
 *
 * edit_post and publish_post are mapped only inside a write call (Pipeline::is_write_call()). A read tool, such as
 * get-rendered, runs shortcodes and other code that may ask these questions, so inside a read they keep core's answer.
 * read_post is mapped in every Steadhand call.
 *
 * For a post that is missing, every mapped capability is refused. For a post in the trash, every mapped capability is refused
 * except delete_post inside steadhand/restore-post (see delete_post below). A post whose type is not allowed is refused too. An attachment is handled by the media rule for read_post and refused for the other three.
 *
 * A post type registered with map_meta_cap off is never allowed (PostTypes::allowed()), whatever the owner's filter says.
 * Core makes none of its per-post checks for such a type (author, status, privacy page), and for some of them core
 * renames the capability before this filter runs, so this layer would not see it. The allowlist is the real refusal.
 * The refusal in unmapped_refusal() is a second layer and is not reached through is_allowed() (SPEC §14.1).
 *
 * - read_post: steadhand_read.
 * - edit_post: steadhand_write_drafts for a draft, pending or auto-draft; steadhand_publish for a published, scheduled
 *   or private post; refused for any other status (see edit_capability()).
 * - publish_post: steadhand_publish, whatever the status.
 * - delete_post: mapped only inside a write call of steadhand/trash-post or steadhand/restore-post (SPEC §5, §14.1). Everywhere
 *   else core's answer stands, and the agent role holds nothing that grants it, so it is refused.
 *   - trash-post, for a post that is not missing, not an attachment, of an allowed type, uses meta caps, is not in the trash
 *     and is not auto-draft: steadhand_trash AND the edit capability for its status (edit_capability()), both required. For
 *     a post core asks more of (a protected page, for one), or an auto-draft, or a status with no rule: refused. A protected
 *     page (the front page, the posts page, or the privacy policy page, TrashRules::protected_page()) is refused too, so the
 *     mapping refuses it even where core would not. A post in the trash is refused (do_not_allow), as for every other mapped
 *     capability.
 *   - restore-post, for a post in the trash that is not an attachment, is of an allowed type and uses meta caps, and that the
 *     calling user trashed through steadhand/trash-post while that trash is still the current one (TrashRules::own_trash()):
 *     steadhand_trash only, and only when core asks nothing more. Any other trashed post, whoever trashed it, is refused.
 *     This is the one exception to the rule that a trashed post is refused for every mapped capability. read_post, edit_post
 *     and publish_post stay refused for a trashed post in every call. For a post that is not in the trash, restore-post is
 *     refused.
 *   Anything else asked during either call is refused as well; the mapping never grants more than those capabilities.
 *
 * Core's extra requirements survive. The mapping replaces the post type's own capabilities (every value in
 * get_post_type_object()->cap), and never anything stricter. If core asks for anything else as well, for example
 * manage_privacy_options for the privacy policy page, or a capability another plugin added, edit_post and publish_post
 * are refused.
 */
final class MetaCaps {

	/**
	 * The capabilities this class maps.
	 */
	private const MAPPED = array( 'read_post', 'edit_post', 'publish_post', 'delete_post' );

	/**
	 * What edit, publish and delete are refused with where the refusal is not a plain do_not_allow. Core's read_post check
	 * asks for edit_post when a post is neither public nor private and is not the agent's own, and it hands that answer
	 * back to read_post. A do_not_allow there would stop read_post as well, so a post that may be read would be refused.
	 * This name is held by no user, so the check fails exactly as it does for do_not_allow, and read_post still gets
	 * the answer its own rule gives.
	 */
	private const NO_GRANT = Capabilities::NO_GRANT;

	/**
	 * Statuses that edit_post maps to steadhand_write_drafts.
	 */
	private const DRAFT_STATUSES = array( 'draft', 'pending', 'auto-draft' );

	/**
	 * Statuses that edit_post maps to steadhand_publish.
	 */
	private const LIVE_STATUSES = array( 'publish', 'future', 'private' );

	/**
	 * The capabilities that only a write call may map (see the class comment).
	 */
	private const WRITE_ONLY = array( 'edit_post', 'publish_post' );

	/**
	 * The two abilities that may be granted delete_post, and only inside their write call (see the class comment).
	 */
	private const TRASH_ABILITY   = 'steadhand/trash-post';
	private const RESTORE_ABILITY = 'steadhand/restore-post';

	/**
	 * The Steadhand capability edit_post needs for a post in this status, or null when no status rule covers it. This is the
	 * one place where the status decision is made. UpdatePost uses it too, so the object check and the mapping cannot drift.
	 *
	 * @param string $status The post's status.
	 * @return string|null One of the Capabilities constants, or null.
	 */
	public static function edit_capability( string $status ): ?string {
		if ( in_array( $status, self::DRAFT_STATUSES, true ) ) {
			return Capabilities::WRITE_DRAFTS;
		}

		if ( in_array( $status, self::LIVE_STATUSES, true ) ) {
			return Capabilities::PUBLISH;
		}

		return null;
	}

	/**
	 * Attaches the mapping to map_meta_cap.
	 */
	public function register(): void {
		add_filter( 'map_meta_cap', array( $this, 'map' ), 10, 4 );
	}

	/**
	 * Replaces the primitive capabilities for a post capability when a dedicated agent asks inside a Steadhand call.
	 *
	 * A post that does not exist, whose type is not allowed, or that is in the trash is refused. An attachment is
	 * readable only within the media rule (see the comment in map()). Any other post is
	 * readable, whatever its author or status, through steadhand_read, which the agent must hold at user level
	 * as well as through its role. Because the array returned here is what WP_User::has_cap() checks against
	 * the user's allcaps, a user-level denial of steadhand_read still refuses the call.
	 *
	 * @param array  $caps    Primitive capabilities core has worked out so far.
	 * @param string $cap     The meta capability being checked.
	 * @param int    $user_id The user being checked.
	 * @param array  $args    Extra arguments; the first is the post ID for the post capabilities.
	 * @return array The capabilities the check must satisfy.
	 */
	public function map( array $caps, string $cap, int $user_id, array $args ): array {
		// Another component's denial is final. Steadhand only ever narrows or replaces core's answer for an agent; it
		// must never turn a do_not_allow from core or from an earlier map_meta_cap filter into a grant.
		if ( in_array( 'do_not_allow', $caps, true ) ) {
			return $caps;
		}

		if ( ! Pipeline::is_running() || ! in_array( $cap, self::MAPPED, true ) ) {
			return $caps;
		}

		// Outside a write call, edit and publish keep core's answer (S1).
		if ( in_array( $cap, self::WRITE_ONLY, true ) && ! Pipeline::is_write_call() ) {
			return $caps;
		}

		// delete_post is mapped only inside a write call of the two abilities that trash and restore a post. Everywhere else
		// it keeps core's answer, which the agent role never satisfies.
		if ( 'delete_post' === $cap && ! self::is_trash_call() ) {
			return $caps;
		}

		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User || ! Agents::is_agent( $user ) ) {
			return $caps;
		}

		$post_id = (int) ( $args[0] ?? 0 );
		if ( $post_id < 1 ) {
			return $caps;
		}

		$post = get_post( $post_id );

		if ( 'delete_post' === $cap ) {
			return self::map_delete( $post instanceof \WP_Post ? $post : null, $caps, Pipeline::current_ability_name(), $user_id );
		}

		if ( ! $post instanceof \WP_Post || 'trash' === $post->post_status ) {
			return array( 'do_not_allow' );
		}

		// Attachments are excluded from the post tools (PostTypes::ALWAYS_EXCLUDED), so the post rule below would refuse
		// them. The media tools read them instead. For them this layer requires steadhand_read only and the media rule
		// (MediaReader::within_rule(), SPEC §14.1): own status inherit, and a parent that is none, missing, or of an
		// allowed type and not trashed. Core's answer for the parent is discarded here on purpose: core resolves an
		// attachment to its parent's status, so a draft parent would otherwise refuse the file. The shared rule is
		// also checked by readable_attachment(), so each layer refuses what the other refuses. Edit, publish and delete
		// are never granted on an attachment.
		if ( 'attachment' === $post->post_type ) {
			if ( 'read_post' === $cap && MediaReader::within_rule( $post ) ) {
				return array( Capabilities::READ );
			}

			return array( 'read_post' === $cap ? 'do_not_allow' : self::NO_GRANT );
		}

		// The allowlist refuses every type registered with map_meta_cap off, so the branch below is the second layer.
		if ( ! PostTypes::is_allowed( $post->post_type ) ) {
			return array( 'do_not_allow' );
		}

		if ( ! self::uses_meta_caps( $post ) ) {
			return self::unmapped_refusal( $cap, $caps );
		}

		return $this->map_post( $cap, $post, $caps );
	}

	/**
	 * Whether the innermost Steadhand call is a write call of steadhand/trash-post or steadhand/restore-post. Only then may
	 * delete_post be mapped.
	 *
	 * @return bool
	 */
	private static function is_trash_call(): bool {
		return Pipeline::is_write_call() && in_array( Pipeline::current_ability_name(), array( self::TRASH_ABILITY, self::RESTORE_ABILITY ), true );
	}

	/**
	 * Maps delete_post inside a call of steadhand/trash-post or steadhand/restore-post (see the class comment). A missing post is
	 * refused as do_not_allow. Every other refusal is NO_GRANT, so no filter can turn it into a grant.
	 *
	 * Restore grants delete_post only for a trashed post that $user_id trashed, and only while that trash is the current one
	 * (TrashRules::own_trash()). The user is the one map() was asked about, not necessarily the current user. Trash refuses a
	 * protected page (the front page, the posts page and the privacy policy page) at this layer too.
	 *
	 * @param \WP_Post|null $post     The post, or null when it is missing.
	 * @param array         $caps     Core's answer.
	 * @param string        $ability  The innermost ability's name.
	 * @param int           $user_id  The user the check is about.
	 * @return array
	 */
	private static function map_delete( ?\WP_Post $post, array $caps, string $ability, int $user_id ): array {
		if ( null === $post ) {
			return array( 'do_not_allow' );
		}

		if ( 'attachment' === $post->post_type || ! PostTypes::is_allowed( (string) $post->post_type ) || ! self::uses_meta_caps( $post ) ) {
			return array( self::NO_GRANT );
		}

		if ( self::has_extra_requirement( $post, $caps ) ) {
			return array( self::NO_GRANT );
		}

		if ( self::RESTORE_ABILITY === $ability ) {
			$own = 'trash' === $post->post_status && TrashRules::own_trash( (int) $post->ID, $user_id );

			return $own ? array( Capabilities::TRASH ) : array( self::NO_GRANT );
		}

		// steadhand/trash-post: a trashed post is refused as every other mapped capability is, and an auto-draft has no grant.
		if ( 'trash' === $post->post_status ) {
			return array( 'do_not_allow' );
		}

		if ( null !== TrashRules::protected_page( (int) $post->ID ) ) {
			return array( self::NO_GRANT );
		}

		$needed = self::edit_capability( (string) $post->post_status );
		if ( 'auto-draft' === $post->post_status || null === $needed ) {
			return array( self::NO_GRANT );
		}

		return array( Capabilities::TRASH, $needed );
	}

	/**
	 * Whether the post's type is registered with map_meta_cap on, so that core runs its per-post checks for it. The
	 * definition is PostTypes::uses_meta_caps(), shared with the allowlist.
	 *
	 * @param \WP_Post $post The post.
	 * @return bool
	 */
	private static function uses_meta_caps( \WP_Post $post ): bool {
		return PostTypes::uses_meta_caps( (string) $post->post_type );
	}

	/**
	 * The answer for a post type registered with map_meta_cap off. Core skips its own per-post checks for such a type, so
	 * Steadhand refuses read_post, edit_post and publish_post. delete_post is left to core, which the agent role never
	 * satisfies (see map_post()).
	 *
	 * This is a second layer. PostTypes::allowed() never lists such a type, and map() refuses a type that is not allowed
	 * first, so this branch is not reached through is_allowed(). It stays so that a refusal exists here even if the
	 * allowlist ever changed.
	 *
	 * Why not map it: for these types core does not check the author, the status or the privacy page. A mapping to a
	 * Steadhand capability would then grant the post without any of those checks. SPEC §14.1.
	 *
	 * @param string $cap  The meta capability.
	 * @param array  $caps Core's answer, returned unchanged for delete_post.
	 * @return array
	 */
	private static function unmapped_refusal( string $cap, array $caps ): array {
		switch ( $cap ) {
			case 'read_post':
				return array( 'do_not_allow' );

			case 'edit_post':
			case 'publish_post':
				return array( self::NO_GRANT );

			default:
				return $caps;
		}
	}

	/**
	 * Whether core asks for anything beyond the post type's own capabilities. Core's list for edit_post on the privacy
	 * policy page includes manage_privacy_options. The mapping replaces the type's own capabilities, so that extra
	 * requirement must still hold for the agent, and it never does.
	 *
	 * @param \WP_Post $post The post.
	 * @param array    $caps Core's answer for the capability.
	 * @return bool True when core asks for a capability the post type does not own.
	 */
	private static function has_extra_requirement( \WP_Post $post, array $caps ): bool {
		$type = get_post_type_object( (string) $post->post_type );
		if ( ! $type instanceof \WP_Post_Type ) {
			return true;
		}

		$own = array_values( (array) $type->cap );
		foreach ( $caps as $required ) {
			if ( ! in_array( $required, $own, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Maps a capability for a post that is not an attachment, is not in the trash and has an allowed type.
	 *
	 * @param string   $cap   The meta capability.
	 * @param \WP_Post $post  The post.
	 * @param array    $caps  Core's answer, returned unchanged where Steadhand has no mapping.
	 * @return array
	 */
	private function map_post( string $cap, \WP_Post $post, array $caps ): array {
		switch ( $cap ) {
			case 'read_post':
				return array( Capabilities::READ );

			case 'edit_post':
				$needed = self::edit_capability( (string) $post->post_status );

				return null === $needed || self::has_extra_requirement( $post, $caps ) ? array( self::NO_GRANT ) : array( $needed );

			case 'publish_post':
				return self::has_extra_requirement( $post, $caps ) ? array( self::NO_GRANT ) : array( Capabilities::PUBLISH );

			default:
				// delete_post is mapped by map_delete(), never here. Core's answer stands for anything else.
				return $caps;
		}
	}
}
