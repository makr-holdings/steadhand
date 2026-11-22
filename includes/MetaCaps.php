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

defined( 'ABSPATH' ) || exit;

/**
 * The map_meta_cap mapping (SPEC §4.2, §14.1, D6). This class implements the read half only: read_post.
 * The edit, publish and delete mappings come in milestone M2.
 *
 * The mapping applies only when all of these hold: a Steadhand call is authorizing or executing (Pipeline::is_running()),
 * the capability is read_post, the user is a dedicated agent (Agents::is_agent()), and a post ID is given. Anything
 * else returns the capabilities core chose. The agent role never holds WordPress's general editing capabilities, so
 * outside a Steadhand call the answers are core's own.
 */
final class MetaCaps {

	/**
	 * Attaches the mapping to map_meta_cap.
	 */
	public function register(): void {
		add_filter( 'map_meta_cap', array( $this, 'map' ), 10, 4 );
	}

	/**
	 * Replaces the primitive capabilities for read_post when a dedicated agent asks inside a Steadhand call.
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
	 * @param array  $args    Extra arguments; the first is the post ID for read_post.
	 * @return array The capabilities the check must satisfy.
	 */
	public function map( array $caps, string $cap, int $user_id, array $args ): array {
		// Another component's denial is final. Steadhand only ever narrows or replaces core's answer for an agent; it
		// must never turn a do_not_allow from core or from an earlier map_meta_cap filter into a grant.
		if ( in_array( 'do_not_allow', $caps, true ) ) {
			return $caps;
		}

		if ( ! Pipeline::is_running() || 'read_post' !== $cap ) {
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

		// M2 adds the edit_post, publish_post and delete_post mappings here.

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'trash' === $post->post_status ) {
			return array( 'do_not_allow' );
		}

		// Attachments are excluded from the post tools (PostTypes::ALWAYS_EXCLUDED), so the post rule below would refuse
		// them. The media tools read them instead. For them this layer requires steadhand_read only, and the media rule
		// (MediaReader::within_rule(), SPEC §14.1): own status inherit, and a parent that is none, missing, or of an
		// allowed type and not trashed. Core's answer for the parent is discarded here on purpose: core resolves an
		// attachment to its parent's status, so a draft parent would otherwise refuse the file. The shared rule is
		// also checked by readable_attachment(), so each layer refuses what the other refuses.
		if ( 'attachment' === $post->post_type ) {
			return MediaReader::within_rule( $post ) ? array( Capabilities::READ ) : array( 'do_not_allow' );
		}

		if ( ! PostTypes::is_allowed( $post->post_type ) ) {
			return array( 'do_not_allow' );
		}

		return array( Capabilities::READ );
	}
}
