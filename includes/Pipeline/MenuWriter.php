<?php
/**
 * The only place Steadhand changes a classic menu item (SPEC §5, the update-menu ability).
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * Adds, renames, hides and shows classic menu items, for the update-menu ability. Every function is called only from a write
 * (Pipeline::is_writing()), reads the item again uncached, runs the caller's own permission check on it again, compares the
 * state it finds with the state the caller read, writes, and reads the result back.
 *
 * Writes are narrow. A change to an existing item is one wp_update_post() call, bracketed by PostWriter::keep_text_for(), so the
 * stored title, content and excerpt come back byte for byte (a rename keeps the content and excerpt, and changes the title). An item
 * is never saved through wp_update_nav_menu_item(), because that rewrites eight meta keys from its arguments. That function is used
 * only to create an item. Nothing here deletes a post, a menu, a theme location or a theme mod, and nothing calls wp_delete_post().
 *
 * Hiding is done with the draft status. A hidden item carries three meta keys (MenuRules::HIDDEN_KEYS): the agent's user ID, the
 * item's own ID, and the item's post_modified_gmt as the hide left it. show needs all three, each with one row, and the state the
 * hide left (MenuRules::hide_recorded()). The keys are removed whenever the item is published by any route: Plugin::boot() hooks
 * became_published() to transition_post_status, and every hide starts by removing them.
 *
 * Every function calls do_action( 'wp_update_nav_menu' ) after a change, as wp-admin and REST do, so page caches flush.
 *
 * Refusals (returned, nothing changed) and failures use these codes:
 * - steadhand_forbidden (403): the item is not in the menu, or the caller's check failed on it now.
 * - steadhand_refused_content_changed (409): the stored state differs from the state the caller read.
 * - steadhand_update_failed (500): WordPress reported an error and the item is as it was.
 * - steadhand_update_partial (500): the item was changed, but not as asked, or could not be read back.
 */
final class MenuWriter {

	/**
	 * The text fields a rename keeps. The title is the one field the rename changes.
	 *
	 * @var string[]
	 */
	private const RENAME_KEEPS = array( 'post_content', 'post_excerpt', 'post_content_filtered' );

	/**
	 * The text fields a status change keeps: all four.
	 *
	 * @var string[]
	 */
	private const ALL_TEXT = array( 'post_title', 'post_content', 'post_excerpt', 'post_content_filtered' );

	/**
	 * The keys of a state the caller may pass in $expected, and that same() compares.
	 *
	 * @var string[]
	 */
	private const COMPARED = array( 'menu', 'status', 'title', 'parent', 'order' );

	/**
	 * Creates a menu item. It is created as a draft with the hidden keys stored, and then published, so the public site never shows
	 * a half-built item. If the item cannot be read back after it is created, the leftover draft is reported as partial, with
	 * its ID and no change to its status: a draft is never public.
	 *
	 * $spec: type (post_type or custom), object (the post type, or custom), object_id (the post ID, or 0 for a custom link), url
	 * (a custom link's URL, or empty), title (plain, as validated; for a custom link it is required), parent (0 for the top level),
	 * position (the menu_order to give the item, which the ability works out).
	 *
	 * $expected: count (the number of items in the menu), max_order (the largest menu_order in it), and parent_state (the state of
	 * the parent item, from MenuRules::fresh_state(), or null for the top level). The writer compares each with what it finds.
	 *
	 * @param \WP_Term                                                                                                    $menu           Classic menu.
	 * @param array{type: string, object: string, object_id: int, url: string, title: string, parent: int, position: int} $spec           The item to add.
	 * @param array{count: int, max_order: int, parent_state: array<string, mixed>|null}                                  $expected       What the caller read.
	 * @param callable                                                                                                    $still_allowed  Called with null (there is no item yet). Returns true when the caller may still add.
	 * @return array{id: int}|\WP_Error The new item's ID, or the refusal or failure.
	 * @throws \LogicException When called outside a write.
	 */
	public static function add( \WP_Term $menu, array $spec, array $expected, callable $still_allowed ): array|\WP_Error {
		self::assert_writing();

		if ( ! $still_allowed( null ) ) {
			return self::forbidden();
		}

		$rows = MenuRules::items_raw( $menu );
		$max  = 0;
		foreach ( $rows as $row ) {
			$max = max( $max, $row['order'] );
		}

		if ( (int) ( $expected['count'] ?? -1 ) !== count( $rows ) || (int) ( $expected['max_order'] ?? -1 ) !== $max ) {
			return self::changed();
		}

		if ( $spec['parent'] > 0 ) {
			$parent = MenuRules::fresh_state( $spec['parent'] );
			if ( null === $parent || $parent['menu'] !== (int) $menu->term_id || ! self::same( (array) ( $expected['parent_state'] ?? array() ), $parent ) ) {
				return self::changed();
			}
		}

		$menu_id  = (int) $menu->term_id;
		$captured = 0;
		$listener = static function ( mixed $listened_menu, mixed $item_id ) use ( &$captured ): void {
			unset( $listened_menu );

			if ( is_numeric( $item_id ) ) {
				$captured = (int) $item_id;
			}
		};

		// Core fires this after the row is inserted and before its terms are set, so a failure after the insert still names the row.
		add_action( 'wp_add_nav_menu_item', $listener, 10, 2 );
		try {
			$result = wp_update_nav_menu_item(
				$menu_id,
				0,
				array(
					'menu-item-type'      => $spec['type'],
					'menu-item-object'    => $spec['object'],
					'menu-item-object-id' => $spec['object_id'],
					'menu-item-parent-id' => $spec['parent'],
					'menu-item-position'  => $spec['position'],
					'menu-item-title'     => wp_slash( $spec['title'] ),
					'menu-item-url'       => $spec['url'],
					'menu-item-status'    => 'draft',
				)
			);
		} finally {
			remove_action( 'wp_add_nav_menu_item', $listener, 10 );
		}

		if ( is_wp_error( $result ) ) {
			return $captured > 0 ? self::added_hidden_partial() : self::update_failed();
		}

		$id = (int) $result;
		if ( $id < 1 || ! self::stored_as( $id, $menu_id, $spec ) ) {
			return $id > 0 ? self::added_hidden_partial() : self::update_failed();
		}

		// The hidden keys are stored first, so a later failure leaves a draft that show can recognise.
		self::remember_hidden( $id );

		$fresh = MenuRules::fresh_state( $id );
		if ( null === $fresh || null !== self::write_status( $fresh, 'publish' ) ) {
			return self::added_hidden_partial();
		}

		self::announce( $menu_id );

		return array( 'id' => $id );
	}

	/**
	 * Changes the title of an item. The item keeps its status, its parent and its order. The content, the excerpt and the filtered
	 * content stay as they are stored. A title that is already the stored one changes nothing and returns changed false.
	 *
	 * If the item is one a hide left (its keys are intact), the modified key is refreshed after the rename, so that show still
	 * recognises it. A rename is Steadhand's own change and is not an outside edit.
	 *
	 * @param int                  $item_id        Item post ID.
	 * @param \WP_Term             $menu           The classic menu the item must be in.
	 * @param string               $title          The new title, plain and validated, not slashed.
	 * @param array<string, mixed> $expected       The state the caller read: any of menu, status, title, parent and order.
	 * @param callable             $still_allowed  Receives the fresh post, returns true when the caller may still rename it.
	 * @return array{id: int, changed: bool}|\WP_Error
	 * @throws \LogicException When called outside a write.
	 */
	public static function rename( int $item_id, \WP_Term $menu, string $title, array $expected, callable $still_allowed ): array|\WP_Error {
		self::assert_writing();

		$fresh = self::current( $item_id, $menu, $expected, $still_allowed );
		if ( $fresh instanceof \WP_Error ) {
			return $fresh;
		}

		if ( $fresh['title'] === $title ) {
			return array(
				'id'      => $item_id,
				'changed' => false,
			);
		}

		$recorded = 'draft' === $fresh['status'] && MenuRules::hide_recorded( $item_id );

		$saved = PostWriter::keep_text_for(
			$fresh['post'],
			static fn(): mixed => wp_update_post(
				wp_slash(
					array(
						'ID'         => $item_id,
						'post_title' => $title,
					)
				),
				true
			),
			self::RENAME_KEEPS
		);
		if ( is_wp_error( $saved ) ) {
			return self::update_failed();
		}

		$after = MenuRules::fresh_state( $item_id );
		if ( null === $after || $after['title'] !== $title || $after['status'] !== $fresh['status'] || $after['parent'] !== $fresh['parent'] || $after['order'] !== $fresh['order'] ) {
			return self::partial();
		}

		if ( $recorded ) {
			update_post_meta( $item_id, MenuRules::HIDDEN_MODIFIED_META, (string) TrashRules::current_modified_gmt( $item_id ) );
			if ( ! MenuRules::hide_recorded( $item_id ) ) {
				return self::partial();
			}
		}

		self::announce( (int) $menu->term_id );

		return array(
			'id'      => $item_id,
			'changed' => true,
		);
	}

	/**
	 * Hides an item (to the draft status) or shows one (to the publish status). A hide stores the three hidden keys after the status
	 * change, so they carry the modified time the hide left. A show needs the keys the hide left, and removes them.
	 *
	 * The caller's checks (has children, is hidden by Steadhand, parent visible, link target published, and so on) are the ability's.
	 * This function checks only that the item is still the state the caller read.
	 *
	 * @param int                  $item_id        Item post ID.
	 * @param \WP_Term             $menu           The classic menu the item must be in.
	 * @param string               $to             'draft' (hide) or 'publish' (show).
	 * @param array<string, mixed> $expected       The state the caller read.
	 * @param callable             $still_allowed  Receives the fresh post, returns true when the caller may still change it.
	 * @return array{id: int, changed: bool}|\WP_Error
	 * @throws \LogicException When called outside a write, or with a $to that is neither draft nor publish.
	 */
	public static function set_status( int $item_id, \WP_Term $menu, string $to, array $expected, callable $still_allowed ): array|\WP_Error {
		self::assert_writing();
		if ( ! in_array( $to, array( 'draft', 'publish' ), true ) ) {
			throw new \LogicException( 'MenuWriter::set_status takes only draft or publish.' );
		}

		$fresh = self::current( $item_id, $menu, $expected, $still_allowed );
		if ( $fresh instanceof \WP_Error ) {
			return $fresh;
		}

		if ( $fresh['status'] === $to ) {
			return array(
				'id'      => $item_id,
				'changed' => false,
			);
		}

		$error = self::write_status( $fresh, $to );
		if ( null !== $error ) {
			return $error;
		}

		self::announce( (int) $menu->term_id );

		return array(
			'id'      => $item_id,
			'changed' => true,
		);
	}

	/**
	 * Removes the hidden keys from an item that was published by any route. Hooked to core's transition_post_status action
	 * (Plugin::boot()), so a Save Menu in wp-admin, a status change by an administrator and a show all clear them. Nothing happens
	 * unless the new status is publish and the post is a nav_menu_item.
	 *
	 * @param mixed $new_status The status the post now has.
	 * @param mixed $old_status The status the post had.
	 * @param mixed $post       The post.
	 */
	public static function became_published( mixed $new_status, mixed $old_status, mixed $post ): void {
		unset( $old_status );

		if ( 'publish' !== $new_status || ! $post instanceof \WP_Post || 'nav_menu_item' !== $post->post_type ) {
			return;
		}

		self::forget_hidden( (int) $post->ID );
	}

	/**
	 * Reads the item again and checks it against the caller's state. Returns the fresh state, or the refusal.
	 *
	 * @param int                  $item_id       Item post ID.
	 * @param \WP_Term             $menu          The menu the item must be in.
	 * @param array<string, mixed> $expected      The state the caller read.
	 * @param callable             $still_allowed Receives the fresh post.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function current( int $item_id, \WP_Term $menu, array $expected, callable $still_allowed ): array|\WP_Error {
		$fresh = MenuRules::fresh_state( $item_id );
		if ( null === $fresh || $fresh['menu'] !== (int) $menu->term_id ) {
			return self::forbidden();
		}

		if ( ! $still_allowed( $fresh['post'] ) ) {
			return self::forbidden();
		}

		if ( ! self::same( $expected, $fresh ) ) {
			return self::changed();
		}

		return $fresh;
	}

	/**
	 * Whether every key of the caller's state that is in COMPARED matches the fresh state. Keys the caller did not pass are not compared.
	 *
	 * @param array<string, mixed> $expected The caller's state.
	 * @param array<string, mixed> $fresh    The fresh state.
	 */
	private static function same( array $expected, array $fresh ): bool {
		foreach ( self::COMPARED as $key ) {
			if ( array_key_exists( $key, $expected ) && $expected[ $key ] !== $fresh[ $key ] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Sets an item to draft (a hide) or publish (a show), with the text kept, and checks the result. Returns null on success, or the
	 * failure. A hide clears the old hidden keys first and stores new ones after the save, so they carry the modified time it left.
	 *
	 * @param array<string, mixed> $fresh The fresh state, from MenuRules::fresh_state().
	 * @param string               $to    'draft' or 'publish'.
	 * @return \WP_Error|null
	 */
	private static function write_status( array $fresh, string $to ): ?\WP_Error {
		$item_id = (int) $fresh['id'];

		if ( 'draft' === $to ) {
			self::forget_hidden( $item_id );
		}

		$saved = PostWriter::keep_text_for(
			$fresh['post'],
			static fn(): mixed => wp_update_post(
				wp_slash(
					array(
						'ID'          => $item_id,
						'post_status' => $to,
					)
				),
				true
			),
			self::ALL_TEXT
		);

		$after = MenuRules::fresh_state( $item_id );
		if ( null === $after ) {
			return self::partial();
		}

		// Nothing changed when WordPress reported an error and the status is still the one it was.
		if ( $after['status'] !== $to ) {
			return is_wp_error( $saved ) || $after['status'] === $fresh['status'] ? self::update_failed() : self::partial();
		}

		if ( 'draft' === $to ) {
			self::remember_hidden( $item_id );

			return MenuRules::hide_recorded( $item_id ) ? null : self::partial();
		}

		self::forget_hidden( $item_id );

		return self::has_hidden_keys( $item_id ) ? self::partial() : null;
	}

	/**
	 * Whether the item, as stored right after a create, has the link, the parent, the title and the menu the call asked for.
	 *
	 * @param int                                                                                                         $item_id Item post ID.
	 * @param int                                                                                                         $menu_id Menu term ID.
	 * @param array{type: string, object: string, object_id: int, url: string, title: string, parent: int, position: int} $spec    The item the call asked for.
	 */
	private static function stored_as( int $item_id, int $menu_id, array $spec ): bool {
		$fresh = MenuRules::fresh_state( $item_id );
		if ( null === $fresh || $fresh['menu'] !== $menu_id || 'draft' !== $fresh['status'] || $fresh['parent'] !== $spec['parent'] ) {
			return false;
		}

		$object_id = 'custom' === $spec['type'] ? $item_id : $spec['object_id'];

		if ( self::meta( $item_id, '_menu_item_type' ) !== $spec['type']
			|| self::meta( $item_id, '_menu_item_object' ) !== $spec['object']
			|| self::meta( $item_id, '_menu_item_object_id' ) !== (string) $object_id
		) {
			return false;
		}

		if ( 'custom' === $spec['type'] && self::meta( $item_id, '_menu_item_url' ) !== sanitize_url( $spec['url'] ) ) {
			return false;
		}

		return self::stored_title( $spec ) === $fresh['title'];
	}

	/**
	 * The title core stores for an item the call creates. For a post link core stores an empty title when the title equals the linked
	 * post's own title, so the item follows the post. Anything else is stored as sent.
	 *
	 * @param array{type: string, object: string, object_id: int, url: string, title: string, parent: int, position: int} $spec The item.
	 */
	private static function stored_title( array $spec ): string {
		if ( 'custom' === $spec['type'] ) {
			return $spec['title'];
		}

		$original = get_post( $spec['object_id'] );

		return $original instanceof \WP_Post && $original->post_title === $spec['title'] ? '' : $spec['title'];
	}

	/**
	 * Stores the three hidden keys for an item that has just been set to draft (or created as one): the agent's user ID, the item's own
	 * ID, and its current post_modified_gmt. Each is written once, with the value that the read-back in hide_recorded() checks.
	 *
	 * @param int $item_id Item post ID.
	 */
	private static function remember_hidden( int $item_id ): void {
		update_post_meta( $item_id, MenuRules::HIDDEN_META, (string) get_current_user_id() );
		update_post_meta( $item_id, MenuRules::HIDDEN_ITEM_META, (string) $item_id );
		update_post_meta( $item_id, MenuRules::HIDDEN_MODIFIED_META, (string) TrashRules::current_modified_gmt( $item_id ) );
	}

	/**
	 * Deletes every hidden key from an item.
	 *
	 * @param int $item_id Item post ID.
	 */
	private static function forget_hidden( int $item_id ): void {
		foreach ( MenuRules::HIDDEN_KEYS as $key ) {
			delete_post_meta( $item_id, $key );
		}
	}

	/**
	 * Whether any hidden key has a row, read uncached.
	 *
	 * @param int $item_id Item post ID.
	 */
	private static function has_hidden_keys( int $item_id ): bool {
		wp_cache_delete( $item_id, 'post_meta' );
		foreach ( MenuRules::HIDDEN_KEYS as $key ) {
			if ( metadata_exists( 'post', $item_id, $key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * One meta value, read uncached, or an empty string.
	 *
	 * @param int    $item_id Item post ID.
	 * @param string $key     Meta key.
	 */
	private static function meta( int $item_id, string $key ): string {
		wp_cache_delete( $item_id, 'post_meta' );

		return (string) get_post_meta( $item_id, $key, true );
	}

	/**
	 * Runs the hook core and the REST API run after a menu changes, so page caches flush.
	 *
	 * @param int $menu_id Menu term ID.
	 */
	private static function announce( int $menu_id ): void {
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's hook, run as core runs it.
		do_action( 'wp_update_nav_menu', $menu_id, array() );
	}

	/**
	 * Refuses a call made outside a write.
	 *
	 * @throws \LogicException When Pipeline::is_writing() is false.
	 */
	private static function assert_writing(): void {
		if ( ! Pipeline::is_writing() ) {
			throw new \LogicException( 'MenuWriter may only be called from a write ability.' );
		}
	}

	/**
	 * The generic refusal for an item the caller may not change, or that is not in the menu.
	 */
	private static function forbidden(): \WP_Error {
		return new \WP_Error( 'steadhand_forbidden', __( 'Not found, or this agent may not access it.', 'steadhand' ), array( 'status' => 403 ) );
	}

	/**
	 * The refusal for an item that changed since the caller read it.
	 */
	private static function changed(): \WP_Error {
		return new \WP_Error(
			'steadhand_refused_content_changed',
			__( 'The menu changed while this call was running, so nothing was changed. Call steadhand/get-menu again, then send the change again.', 'steadhand' ),
			array( 'status' => 409 )
		);
	}

	/**
	 * The failure for a write WordPress refused, with nothing changed.
	 */
	private static function update_failed(): \WP_Error {
		return new \WP_Error(
			'steadhand_update_failed',
			__( 'WordPress could not save the menu item, so nothing was changed. Try again.', 'steadhand' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * The failure for a write that may have changed the item, or that could not be read back.
	 */
	private static function partial(): \WP_Error {
		return new \WP_Error(
			'steadhand_update_partial',
			__( 'The menu item was changed, but not as asked, so the change was at least partly applied. Call steadhand/get-menu to check the menu before you continue.', 'steadhand' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * The failure for an add that left a hidden draft behind. The draft is not on the public site.
	 */
	private static function added_hidden_partial(): \WP_Error {
		return new \WP_Error(
			'steadhand_update_partial',
			__( 'The menu item was created, but Steadhand could not confirm it or publish it. It was left as a hidden draft, so it is not on the public site. Call steadhand/get-menu to find it, and check it in Appearance > Menus.', 'steadhand' ),
			array( 'status' => 500 )
		);
	}
}
