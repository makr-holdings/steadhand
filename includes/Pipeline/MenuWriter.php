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
 * state it finds with the state the caller read, writes, reads the result back, and checks the menu's tree rule again.
 *
 * Writes are narrow. A change to an existing item is one wp_update_post() call, bracketed by PostWriter::keep_text_for(), so the
 * stored title, content and excerpt come back byte for byte (a rename keeps the content and excerpt, and changes the title). After
 * each save the writer compares the title, content, excerpt and filtered content with what they must be, and reports a partial failure
 * on any difference. An item is never saved through wp_update_nav_menu_item(), because that rewrites eight meta keys from its
 * arguments. That function is used only to create an item. Nothing here deletes a post, a menu, a theme location or a theme mod,
 * nothing calls wp_delete_post(), and nothing writes core's orphan mark: a leftover row is reported, never changed.
 *
 * Tree rule: no published item of a menu has a parent that is a draft item of that menu. The ability checks it before a call, and
 * the writer checks it again after each write, because another request can change the menu in between:
 * - after show and after add's publish, the item's parent must be 0 or a published item of this menu; if not, the item is set back to draft;
 * - after hide, the item must have no published direct child; if one has appeared, the item is set back to published, and then
 *   that item's own parent must be visible, or it is set back to draft once more. There is at most one put-back and one correction.
 * Each put-back is reported as steadhand_update_partial, and says whether it worked.
 *
 * Hiding is done with the draft status. A hidden item carries three meta keys (MenuRules::HIDDEN_KEYS): the agent's user ID, the
 * item's own ID, and the item's post_modified_gmt as the hide left it. show needs all three, each with one row, and the state the
 * hide left (MenuRules::hide_recorded()). The keys are removed whenever an item is published by a route that fires
 * transition_post_status while Steadhand is loaded: Plugin::boot() hooks became_published() to that action, and every hide starts by
 * removing them. A hide that stored its status but failed the text check still keeps its keys, so the item can be shown again.
 *
 * Every function calls do_action( 'wp_update_nav_menu' ) after a change, as wp-admin and REST do, so page caches flush.
 *
 * Refusals (returned, nothing changed) and failures use these codes:
 * - steadhand_forbidden (403): the item is not in the menu, or the caller's check failed on it now.
 * - steadhand_refused_content_changed (409): the stored state differs from the state the caller read.
 * - steadhand_update_failed (500): WordPress reported an error and the item is as it was.
 * - steadhand_update_partial (500): the item was changed, but not as asked, or could not be read back. An add whose item exists says so
 *   in its message, and its error data carries the item's ID as 'item'.
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
	 * a half-built item. Once the item's ID is known, any later failure, including an exception, is a steadhand_update_partial error
	 * whose data carries the item's ID as 'item', and whose message names the item and says what state it was left in (see
	 * added_partial()). A failure before any row exists is steadhand_update_failed, and nothing was created. An exception before any
	 * row exists is not caught.
	 *
	 * $spec: type (post_type or custom), object (the post type, or custom), object_id (the post ID, or 0 for a custom link), url
	 * (a custom link's URL, or empty), title (plain, as validated; for a custom link it is required), parent (0 for the top level),
	 * position (the menu_order to give the item, which the ability works out).
	 *
	 * $expected: count (the number of items in the menu), max_order (the largest menu_order in it), and parent_state: the parent's
	 * state as the ability read it, with the keys menu, status, parent and order, or null for the top level. The writer compares
	 * each key it is given with the fresh state of the parent, so a parent hidden since the read is refused before the insert.
	 *
	 * @param \WP_Term                                                                                                    $menu           Classic menu.
	 * @param array{type: string, object: string, object_id: int, url: string, title: string, parent: int, position: int} $spec           The item to add.
	 * @param array{count: int, max_order: int, parent_state: array<string, mixed>|null}                                  $expected       What the caller read.
	 * @param callable                                                                                                    $still_allowed  Called with null (there is no item yet). Returns true when the caller may still add.
	 * @return array{id: int}|\WP_Error The new item's ID, or the refusal or failure.
	 * @throws \Throwable Rethrown when raised before the new item's ID is known. Also \LogicException when called outside a write.
	 */
	public static function add( \WP_Term $menu, array $spec, array $expected, callable $still_allowed ): array|\WP_Error {
		self::assert_writing();

		if ( ! $still_allowed( null ) ) {
			return self::forbidden();
		}

		$menu_id = (int) $menu->term_id;
		$rows    = MenuRules::items_raw( $menu );
		$max     = 0;
		foreach ( $rows as $row ) {
			$max = max( $max, $row['order'] );
		}

		if ( (int) ( $expected['count'] ?? -1 ) !== count( $rows ) || (int) ( $expected['max_order'] ?? -1 ) !== $max ) {
			return self::changed();
		}

		if ( $spec['parent'] > 0 ) {
			$parent = MenuRules::fresh_state( $spec['parent'] );
			if ( null === $parent || $parent['menu'] !== $menu_id || ! self::same( (array) ( $expected['parent_state'] ?? array() ), $parent ) ) {
				return self::changed();
			}
		}

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
		} catch ( \Throwable $thrown ) {
			// Before the row exists there is nothing to name, so the exception is not caught.
			if ( $captured < 1 ) {
				throw $thrown;
			}

			return self::added_partial( $captured, $menu_id, self::added_failed( $captured ) );
		} finally {
			remove_action( 'wp_add_nav_menu_item', $listener, 10 );
		}

		if ( is_wp_error( $result ) ) {
			return $captured > 0 ? self::added_partial( $captured, $menu_id, self::added_failed( $captured ) ) : self::update_failed();
		}

		$id = (int) $result;
		if ( $id < 1 ) {
			return self::update_failed();
		}

		try {
			return self::finish_add( $id, $menu_id, $spec );
		} catch ( \Throwable ) {
			return self::added_partial( $id, $menu_id, self::added_failed( $id ) );
		}
	}

	/**
	 * The steps of an add after its row exists: the stored row is checked, the hidden keys are stored, the item is published, and the
	 * tree rule is checked again. Any exception from here is caught by add(), which names the item.
	 *
	 * @param int                                                                                                         $id      The new item's ID.
	 * @param int                                                                                                         $menu_id The menu.
	 * @param array{type: string, object: string, object_id: int, url: string, title: string, parent: int, position: int} $spec    The item the call asked for.
	 * @return array{id: int}|\WP_Error
	 */
	private static function finish_add( int $id, int $menu_id, array $spec ): array|\WP_Error {
		if ( ! self::stored_as( $id, $menu_id, $spec ) ) {
			return self::added_partial( $id, $menu_id, self::added_failed( $id ) );
		}

		// The hidden keys are stored first, so a later failure leaves a draft that show can recognise.
		self::remember_hidden( $id );

		$fresh = MenuRules::fresh_state( $id );
		if ( null === $fresh || null !== self::write_status( $fresh, 'publish' ) ) {
			return self::added_partial( $id, $menu_id, self::added_failed( $id ) );
		}

		// The tree rule, checked again after the publish: the parent may have been hidden while the item was being created.
		$now = MenuRules::fresh_state( $id );
		if ( null === $now ) {
			return self::added_partial( $id, $menu_id, self::added_failed( $id ) );
		}

		if ( ! self::parent_is_visible( $now, $menu_id ) ) {
			$back = self::write_status( $now, 'draft' );
			self::announce( $menu_id );

			return self::added_partial(
				$id,
				$menu_id,
				null === $back
					/* translators: %d: the new menu item's ID. */
					? sprintf( __( 'Menu item %d was created and published, but its parent item was hidden at the same time, so Steadhand put the new item back to hidden.', 'steadhand' ), $id )
					/* translators: %d: the new menu item's ID. */
					: sprintf( __( 'Menu item %d was created and published under a parent item that was hidden at the same time, and Steadhand could not put it back to hidden.', 'steadhand' ), $id )
			);
		}

		self::announce( $menu_id );

		return array( 'id' => $id );
	}

	/**
	 * Changes the title of an item. The item keeps its status, its parent and its order. The content, the excerpt and the filtered
	 * content stay as they are stored. A title that is already the stored one changes nothing and returns changed false.
	 *
	 * If the item is one a hide left (its keys are intact), the modified key is refreshed after the rename, so that show still
	 * recognises it. A rename is Steadhand's own change and is not an outside edit. An item whose keys are stale (edited by other code
	 * after the hide) is not refreshed, so show stays refused for it.
	 *
	 * @param int                  $item_id        Item post ID.
	 * @param \WP_Term             $menu           The classic menu the item must be in.
	 * @param string               $title          The new title, plain and validated, not slashed. May be empty where the rules allow it.
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

		if ( ! self::text_kept( $fresh['post'], $after['post'], $title ) ) {
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
	 * This function checks that the item is still the state the caller read, and then the tree rule (see the class comment).
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

		$tree = self::tree_error( $item_id, $menu, $to );
		self::announce( (int) $menu->term_id );
		if ( null !== $tree ) {
			return $tree;
		}

		return array(
			'id'      => $item_id,
			'changed' => true,
		);
	}

	/**
	 * Moves an item to a new parent and a new place among its new siblings, and rewrites the menu_order of the rows that the move changes.
	 * The caller's plan (MenuRules::plan_move()) is passed in $expected, with the state of every row the plan touches. This function reads
	 * the menu again, compares the states and the plan with what the caller read, and then runs the rules on the fresh rows; nothing is
	 * written if any of that differs or refuses.
	 *
	 * Order of the writes: the parent meta first (only when the parent changes), then one wp_update_post() per changed row, in ascending
	 * position, each with the text kept. Stopping midway is safe in the sense that no item is lost or deleted, and no status changes; the
	 * order may be wrong until the rest is written, which is what the partial failure says. The parent goes first so that, if the order
	 * writes stop, the item is already under its new parent, and the tree rule can be checked on it afterwards.
	 *
	 * After the writes the menu is read again. Its order must be the target order, with the new values, the item's parent must be the new
	 * one, and every row that was written must keep its title, content, excerpt and filtered content. If the moved item is visible under a
	 * parent that has become hidden in the meantime, the parent meta goes back to the previous parent. Any of these failing is
	 * steadhand_update_partial. Hidden items whose modified time the order write changes keep their hide record valid, but only where the
	 * record was valid before the call.
	 *
	 * $expected: 'target' (the target flat order), 'orders' (new menu_order by ID), and 'states' (for each row the plan touches, the state the
	 * caller read: menu, status, title, parent and order).
	 *
	 * @param \WP_Term             $menu          The classic menu.
	 * @param int                  $item_id       The item that moves.
	 * @param int                  $parent_id     The new parent, or 0.
	 * @param int                  $after_id      The new predecessor, or 0.
	 * @param array<string, mixed> $expected      The plan and the states the caller read.
	 * @param callable             $still_allowed Receives the fresh post of the item, returns true when the caller may still move it.
	 * @return array{id: int, changed: bool}|\WP_Error
	 * @throws \LogicException When called outside a write.
	 */
	public static function move( \WP_Term $menu, int $item_id, int $parent_id, int $after_id, array $expected, callable $still_allowed ): array|\WP_Error {
		self::assert_writing();

		$fresh = self::current( $item_id, $menu, array(), $still_allowed );
		if ( $fresh instanceof \WP_Error ) {
			return $fresh;
		}

		$menu_id = (int) $menu->term_id;
		foreach ( (array) ( $expected['states'] ?? array() ) as $id => $state ) {
			$now = MenuRules::fresh_state( (int) $id );
			if ( null === $now || ! self::same( (array) $state, $now ) ) {
				return self::changed();
			}
		}

		$rows = MenuRules::items_raw( $menu );
		$plan = MenuRules::plan_move( $rows, $item_id, $parent_id, $after_id );
		if ( null !== $plan['refusal'] ) {
			return self::move_refusal( (string) $plan['refusal'] );
		}

		$expected_target = $expected['target'] ?? array();
		$expected_orders = $expected['orders'] ?? array();
		if ( $plan['target'] !== $expected_target || $plan['orders'] !== $expected_orders ) {
			return self::changed();
		}

		$old_parent = (int) $fresh['parent'];
		if ( $old_parent === $parent_id && array() === $plan['orders'] ) {
			return array(
				'id'      => $item_id,
				'changed' => false,
			);
		}

		$posts    = array();
		$recorded = array();
		foreach ( $rows as $row ) {
			$posts[ $row['id'] ] = $row['post'];
			if ( isset( $plan['orders'][ $row['id'] ] ) && 'draft' === $row['status'] && MenuRules::hide_recorded( $row['id'] ) ) {
				$recorded[ $row['id'] ] = true;
			}
		}

		$wrote = false;
		if ( $old_parent !== $parent_id ) {
			update_post_meta( $item_id, MenuRules::PARENT_META, (string) $parent_id );
			$wrote = true;
		}

		$failed = false;
		foreach ( $plan['orders'] as $id => $order ) {
			$saved = PostWriter::keep_text_for(
				$posts[ $id ],
				static fn(): mixed => wp_update_post(
					wp_slash(
						array(
							'ID'         => $id,
							'menu_order' => $order,
						)
					),
					true
				),
				self::ALL_TEXT
			);
			if ( is_wp_error( $saved ) ) {
				$failed = true;
				break;
			}

			$wrote = true;
			if ( isset( $recorded[ $id ] ) ) {
				update_post_meta( $id, MenuRules::HIDDEN_MODIFIED_META, (string) TrashRules::current_modified_gmt( $id ) );
			}
		}

		self::announce( $menu_id );

		if ( $failed ) {
			return $wrote ? self::move_partial( $item_id ) : self::update_failed();
		}

		$now = MenuRules::fresh_state( $item_id );
		if ( null === $now ) {
			return self::move_partial( $item_id );
		}

		// The tree rule first: a parent hidden in the gap is put back before the order is judged.
		if ( 'publish' === $now['status'] && ! self::parent_is_visible( $now, $menu_id ) ) {
			if ( $old_parent === $parent_id ) {
				// The parent was visible when the plan was made and was hidden in the gap. Nothing here moved it, so the item stays where it is.
				return self::tree_partial( __( 'The menu item is visible under its parent item, which was hidden at the same time, and Steadhand did not move it. Check the menu with steadhand/get-menu, and hide the item with op hide.', 'steadhand' ) );
			}

			update_post_meta( $item_id, MenuRules::PARENT_META, (string) $old_parent );

			$back = MenuRules::fresh_state( $item_id );
			$text = null !== $back && $back['parent'] === $old_parent
				? __( 'The menu item was moved, but its new parent item was hidden at the same time, so Steadhand moved it back under its previous parent. The order of the menu may also have changed. Check the menu with steadhand/get-menu, and fix the order in Appearance > Menus if it is wrong.', 'steadhand' )
				: __( 'The menu item was moved, but its new parent item was hidden at the same time, and Steadhand could not move it back, so it may be visible under a hidden parent. Check the menu with steadhand/get-menu, and hide the item with op hide.', 'steadhand' );

			return self::tree_partial( $text );
		}

		$after_rows = MenuRules::items_raw( $menu );
		if ( ! self::moved_as_planned( $rows, $after_rows, $plan, $item_id, $parent_id ) ) {
			return self::move_partial( $item_id );
		}

		return array(
			'id'      => $item_id,
			'changed' => true,
		);
	}

	/**
	 * Whether a re-read of the menu after the move's writes is the planned menu: the same rows in the target order, every row with the
	 * order and parent the plan gives it, the same status everywhere, and the text of each row the writes touched unchanged.
	 *
	 * @param array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}> $before    Rows before the writes.
	 * @param array<int, array{id: int, parent: int, order: int, status: string, orphaned: bool, post: \WP_Post}> $after     Rows after the writes.
	 * @param array{refusal: string|null, target: int[], orders: array<int, int>}                                 $plan      The plan.
	 * @param int                                                                                                 $item_id   The moved item.
	 * @param int                                                                                                 $parent_id The new parent.
	 */
	private static function moved_as_planned( array $before, array $after, array $plan, int $item_id, int $parent_id ): bool {
		if ( array_column( $after, 'id' ) !== $plan['target'] || count( $after ) !== count( $before ) ) {
			return false;
		}

		$was = array();
		foreach ( $before as $row ) {
			$was[ $row['id'] ] = $row;
		}

		foreach ( $after as $row ) {
			$old = $was[ $row['id'] ] ?? null;
			if ( null === $old || $row['status'] !== $old['status'] ) {
				return false;
			}

			$order  = $plan['orders'][ $row['id'] ] ?? $old['order'];
			$parent = $row['id'] === $item_id ? $parent_id : $old['parent'];
			if ( $row['order'] !== $order || $row['parent'] !== $parent ) {
				return false;
			}

			$touched = $row['id'] === $item_id || isset( $plan['orders'][ $row['id'] ] );
			if ( $touched && ! self::text_kept( $old['post'], $row['post'], (string) $old['post']->post_title ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Removes the hidden keys from an item that was published by a route that fires transition_post_status while Steadhand is loaded.
	 * Hooked to that action (Plugin::boot()), so a Save Menu in wp-admin, a status change by an administrator and a show all clear
	 * them. Nothing happens unless the new status is publish and the post is a nav_menu_item. When the item has none of the keys, it
	 * returns after a metadata check, with no delete query.
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

		$item_id = (int) $post->ID;
		if ( ! self::stores_hidden_keys( $item_id ) ) {
			return;
		}

		self::forget_hidden( $item_id );
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
	 * failure. A hide stores the hidden keys after the save, whatever the text check finds, because the status did change: the item
	 * is then hidden, and show can bring it back. A show removes the keys before its text check, so a show that failed the check leaves
	 * the item visible without them.
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

		$text_kept = self::text_kept( $fresh['post'], $after['post'], (string) $fresh['post']->post_title );

		if ( 'draft' === $to ) {
			self::remember_hidden( $item_id );

			if ( ! $text_kept ) {
				return self::text_partial( true );
			}

			return MenuRules::hide_recorded( $item_id ) ? null : self::partial();
		}

		self::forget_hidden( $item_id );

		if ( ! $text_kept ) {
			return self::text_partial( false );
		}

		return self::has_hidden_keys( $item_id ) ? self::partial() : null;
	}

	/**
	 * Checks the tree rule after a status write, reading the menu fresh. Returns null when the rule holds, or the partial failure after
	 * the put-back. A show whose parent is no longer visible is set back to draft. A hide with a published child is set back to
	 * published; if that item's own parent is then hidden, it is set back to draft once more, and the call stops there.
	 *
	 * @param int      $item_id Item post ID.
	 * @param \WP_Term $menu    The menu.
	 * @param string   $to      The status just written.
	 * @return \WP_Error|null
	 */
	private static function tree_error( int $item_id, \WP_Term $menu, string $to ): ?\WP_Error {
		$now = MenuRules::fresh_state( $item_id );
		if ( null === $now ) {
			return self::partial();
		}

		if ( 'publish' === $to ) {
			if ( self::parent_is_visible( $now, (int) $menu->term_id ) ) {
				return null;
			}

			$back = self::write_status( $now, 'draft' );
			if ( null === $back ) {
				return self::tree_partial( self::show_put_back() );
			}

			$after = MenuRules::fresh_state( $item_id );
			if ( null !== $after && 'draft' === $after['status'] ) {
				// Hidden, but the text check failed, or the hide record was not stored: say which.
				return self::tree_partial( MenuRules::hide_recorded( $item_id ) ? self::show_half_text() : self::show_half_record() );
			}

			return self::tree_partial( self::show_stuck() );
		}

		if ( ! self::has_visible_child( $item_id, $menu ) ) {
			return null;
		}

		$back = self::write_status( $now, 'publish' );
		if ( null !== $back ) {
			$after = MenuRules::fresh_state( $item_id );

			return self::tree_partial( null !== $after && 'publish' === $after['status'] ? self::hide_back_text() : self::hide_stuck() );
		}

		// The item is visible again. Its own parent must be visible too, or the item is hidden once more: one correction, no loop.
		$shown = MenuRules::fresh_state( $item_id );
		if ( null === $shown ) {
			return self::partial();
		}

		if ( self::parent_is_visible( $shown, (int) $menu->term_id ) ) {
			return self::tree_partial( self::hide_put_back() );
		}

		$again = self::write_status( $shown, 'draft' );
		if ( null === $again ) {
			return self::tree_partial( self::hide_parent_hidden() );
		}

		$last = MenuRules::fresh_state( $item_id );

		return self::tree_partial( null !== $last && 'publish' === $last['status'] ? self::hide_parent_stuck() : self::hide_parent_hidden() );
	}

	/**
	 * Whether an item's parent is 0 (the top level), or a published item of this menu. A parent that is missing, malformed or in
	 * another menu is not visible.
	 *
	 * @param array<string, mixed> $state   The item's fresh state.
	 * @param int                  $menu_id The menu.
	 */
	private static function parent_is_visible( array $state, int $menu_id ): bool {
		if ( 0 === (int) $state['parent'] ) {
			return true;
		}

		$parent = MenuRules::fresh_state( (int) $state['parent'] );

		return null !== $parent && $parent['menu'] === $menu_id && 'publish' === $parent['status'];
	}

	/**
	 * Whether an item has a published direct child in its menu, read fresh.
	 *
	 * @param int      $item_id Item post ID.
	 * @param \WP_Term $menu    The menu.
	 */
	private static function has_visible_child( int $item_id, \WP_Term $menu ): bool {
		foreach ( MenuRules::children( MenuRules::items_raw( $menu ), $item_id ) as $child ) {
			if ( 'publish' === $child['status'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the title, content, excerpt and filtered content of a saved item are the ones they must be: the title the save was meant
	 * to store, and the other three as they were before it.
	 *
	 * @param \WP_Post $before The item before the save.
	 * @param \WP_Post $after  The item after the save, read fresh.
	 * @param string   $title  The title the save must leave.
	 */
	private static function text_kept( \WP_Post $before, \WP_Post $after, string $title ): bool {
		return $after->post_title === $title
			&& $after->post_content === $before->post_content
			&& $after->post_excerpt === $before->post_excerpt
			&& $after->post_content_filtered === $before->post_content_filtered;
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
	 * Whether any hidden key has a row. Checks the post meta cache first, through metadata_exists(), which reads the database only
	 * when the cache holds nothing for the item. Used on every publish, so it does no delete and no more than one read.
	 *
	 * @param int $item_id Item post ID.
	 */
	private static function stores_hidden_keys( int $item_id ): bool {
		foreach ( MenuRules::HIDDEN_KEYS as $key ) {
			if ( metadata_exists( 'post', $item_id, $key ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether any hidden key has a row, read uncached.
	 *
	 * @param int $item_id Item post ID.
	 */
	private static function has_hidden_keys( int $item_id ): bool {
		wp_cache_delete( $item_id, 'post_meta' );

		return self::stores_hidden_keys( $item_id );
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
	 * The failure of a status change whose text check failed. A hide has stored its hidden keys, so the item is hidden and can be shown
	 * again. A show has removed them, so the item is visible without them.
	 *
	 * @param bool $hidden Whether the change was a hide.
	 */
	private static function text_partial( bool $hidden ): \WP_Error {
		return new \WP_Error(
			'steadhand_update_partial',
			$hidden
				? __( 'The menu item is hidden, but another change altered its text while it was saved, so the hide did not complete cleanly. The item IS hidden, and it can be shown again with op show. Check it with steadhand/get-menu.', 'steadhand' )
				: __( 'The menu item is visible, but another change altered its text while it was saved, so the show did not complete cleanly. Steadhand removed its hidden record. Check it with steadhand/get-menu.', 'steadhand' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * The failure of a status change whose tree-rule put-back ran: the message says what was done and why.
	 *
	 * @param string $message The text, which says what the put-back did.
	 */
	private static function tree_partial( string $message ): \WP_Error {
		return new \WP_Error( 'steadhand_update_partial', $message, array( 'status' => 500 ) );
	}

	/**
	 * The refusal for a move whose rules say no, with the code MenuRules::plan_move() gave. Nothing was changed.
	 *
	 * @param string $code One of the MenuRules::MOVE_ codes.
	 */
	public static function move_refusal( string $code ): \WP_Error {
		switch ( $code ) {
			case MenuRules::MOVE_INVALID:
				return new \WP_Error(
					$code,
					__( 'The move is not possible. The parent must be 0 or an item of this menu. The after item must be 0 or an item of this menu that is a child of the parent. An item cannot move under itself or under one of its own children. Nothing was changed.', 'steadhand' ),
					array( 'status' => 400 )
				);

			case MenuRules::MOVE_HIDDEN:
				return new \WP_Error( $code, __( 'The new parent item is hidden, so the item would show at the top level of the menu. Show the parent first, or choose a visible parent. Nothing was changed.', 'steadhand' ), array( 'status' => 409 ) );

			case MenuRules::MOVE_DEPTH:
				return new \WP_Error(
					$code,
					/* translators: %d: the most levels a menu may have. */
					sprintf( __( 'The item and its own children would be deeper than the %d levels a menu may have at the new place. Nothing was changed.', 'steadhand' ), MenuRules::MAX_DEPTH ),
					array( 'status' => 409 )
				);

			case MenuRules::MOVE_ORDER:
				return new \WP_Error(
					$code,
					__( 'The menu order is not consistent with its tree, or the order values this move would rewrite are not distinct positive integers, so the result would be ambiguous. Nothing was changed. An administrator can fix the order by saving the menu in Appearance > Menus.', 'steadhand' ),
					array( 'status' => 409 )
				);

			case MenuRules::MOVE_TOO_LARGE:
				return new \WP_Error(
					$code,
					/* translators: %d: the most menu items one move may change. */
					sprintf( __( 'This move would change the order of more than %d menu items, so Steadhand will not make it. Nothing was changed. Move the item in smaller steps, or reorder the menu in Appearance > Menus.', 'steadhand' ), MenuRules::MAX_MOVE_ROWS ),
					array( 'status' => 409 )
				);

			default:
				return new \WP_Error( MenuRules::MOVE_UNSUPPORTED, __( 'This item, its new parent or its predecessor is not in a state Steadhand moves items from or to. Nothing was changed.', 'steadhand' ), array( 'status' => 409 ) );
		}
	}

	/**
	 * The failure of a move that wrote some rows, or whose result could not be confirmed. It names the moved item, says the order may be wrong,
	 * and tells the agent and the administrator what to do. No item is lost: the move writes no delete.
	 *
	 * @param int $item_id The moved item.
	 */
	private static function move_partial( int $item_id ): \WP_Error {
		return new \WP_Error(
			'steadhand_update_partial',
			/* translators: %d: the moved menu item's ID. */
			sprintf( __( 'Menu item %d was moved, but the change was not completed cleanly, so the menu order may be wrong. No item was deleted. Call steadhand/get-menu to read the menu before you continue. An administrator can fix the order by saving the menu in Appearance > Menus.', 'steadhand' ), $item_id ),
			array(
				'status' => 500,
				'item'   => $item_id,
			)
		);
	}

	/**
	 * The cause of an add that failed after its row existed, for the item with this ID.
	 *
	 * @param int $item_id The new item's ID.
	 */
	public static function added_failed( int $item_id ): string {
		/* translators: %d: the new menu item's ID. */
		return sprintf( __( 'Menu item %d was created, but Steadhand could not finish adding it.', 'steadhand' ), $item_id );
	}

	/**
	 * The failure for an add whose item exists. The message names the item, then says in what state it was left, from a fresh read. The
	 * error data carries the item's ID as 'item', so the change log names it too. This function writes nothing: it does not mark, move or
	 * delete the row, and a read that throws is replaced by a plain text.
	 *
	 * @param int    $item_id The new item's ID.
	 * @param int    $menu_id The menu the item was added to.
	 * @param string $cause   The sentence that says what failed, with the item's ID in it.
	 */
	public static function added_partial( int $item_id, int $menu_id, string $cause ): \WP_Error {
		try {
			$text = self::added_state_text( $item_id, MenuRules::fresh_state( $item_id ), $menu_id );
		} catch ( \Throwable ) {
			$text = __( 'Steadhand could not read it back, so check the menu with steadhand/get-menu before you continue.', 'steadhand' );
		}

		return new \WP_Error(
			'steadhand_update_partial',
			trim( $cause . ' ' . $text ),
			array(
				'status' => 500,
				'item'   => $item_id,
			)
		);
	}

	/**
	 * Says where an added item is now, from a fresh read and the terms the row holds: live or a hidden draft in this menu; in another
	 * menu or in more than one; in no menu (unfinished, or published and unlisted); or unknown when the terms cannot be read. Writes nothing.
	 *
	 * @param int                       $item_id The item's ID.
	 * @param array<string, mixed>|null $state   The item's fresh state, or null.
	 * @param int                       $menu_id The menu.
	 */
	private static function added_state_text( int $item_id, ?array $state, int $menu_id ): string {
		if ( null === $state ) {
			return __( 'Steadhand could not read it back, so check the menu with steadhand/get-menu before you continue.', 'steadhand' );
		}

		$draft = 'draft' === $state['status'];

		$terms = wp_get_object_terms( $item_id, 'nav_menu', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) ) {
			return $draft
				/* translators: %d: the new menu item's ID. */
				? sprintf( __( 'The row %d is a draft, so it is not on the public site. Steadhand could not check which menu it is in. An administrator can remove it by its ID.', 'steadhand' ), $item_id )
				/* translators: %d: the new menu item's ID. */
				: sprintf( __( 'The row %d is published. Steadhand could not check which menu it is in, so check it with steadhand/get-menu.', 'steadhand' ), $item_id );
		}

		$ids = array_map( 'intval', $terms );

		if ( array() === $ids ) {
			return $draft
				/* translators: %d: the new menu item's ID. */
				? sprintf( __( 'The row %d is unfinished, is in no menu and is not on the public site. No Steadhand tool or admin screen lists it; an administrator can remove it by its ID.', 'steadhand' ), $item_id )
				/* translators: %d: the new menu item's ID. */
				: sprintf( __( 'The row %d is published but in no menu, so no menu shows it. No Steadhand tool or admin screen lists it; an administrator can remove it by its ID.', 'steadhand' ), $item_id );
		}

		if ( count( $ids ) > 1 ) {
			return in_array( $menu_id, $ids, true )
				? __( 'It is in more than one menu, including this one, so it may show in more than one place. Check it with steadhand/get-menu.', 'steadhand' )
				: __( 'It is in more than one menu, and not in this one. Check it with steadhand/get-menu.', 'steadhand' );
		}

		if ( $ids[0] !== $menu_id ) {
			return __( 'It is in another menu, not this one, so this menu does not show it. Check it with steadhand/get-menu.', 'steadhand' );
		}

		if ( ! $draft ) {
			return __( 'It is live on the public site and may be incomplete. Check it with steadhand/get-menu, and hide it with op hide if it is wrong.', 'steadhand' );
		}

		return __( 'It was left as a hidden draft, so it is not on the public site. Check it with steadhand/get-menu, and check it in Appearance > Menus.', 'steadhand' );
	}

	/**
	 * The text for a show put-back that did not work, where the item is still visible under its hidden parent.
	 */
	private static function show_stuck(): string {
		return __( 'The item was shown, but its parent item was hidden at the same time, and Steadhand could not hide it again, so the item may be visible under a hidden parent. Check the menu with steadhand/get-menu, and hide the item with op hide.', 'steadhand' );
	}

	/**
	 * The text for a show put-back that worked: the item is hidden again.
	 */
	private static function show_put_back(): string {
		return __( 'The item was shown, but its parent item was hidden at the same time, so Steadhand hid it again. Show the parent first, then show this item. Nothing else was changed.', 'steadhand' );
	}

	/**
	 * The text for a show put-back that hid the item, but whose text check failed. The hide record is stored, so show can bring it back.
	 */
	private static function show_half_text(): string {
		return __( 'The item was shown, but its parent item was hidden at the same time, and Steadhand hid it again. Another change altered its text during that save, so the hide did not complete cleanly. The item IS hidden, and it can be shown again with op show once its parent is visible. Check it with steadhand/get-menu.', 'steadhand' );
	}

	/**
	 * The text for a show put-back that hid the item, but did not store the record that lets show bring it back.
	 */
	private static function show_half_record(): string {
		return __( 'The item was shown, but its parent item was hidden at the same time, and Steadhand hid it again without storing the record it needs to show the item later, so op show will refuse it. The item IS hidden. An administrator can publish it in Appearance > Menus.', 'steadhand' );
	}

	/**
	 * The text for a hide put-back that worked: a hide set back to visible.
	 */
	private static function hide_put_back(): string {
		return __( 'The item was hidden, but a visible child item was found under it at the same time, so Steadhand showed it again. Hide the child first, then this item. Nothing else was changed.', 'steadhand' );
	}

	/**
	 * The text for a hide put-back that did not work: the item is still hidden, and its child is visible.
	 */
	private static function hide_stuck(): string {
		return __( 'The item was hidden, but a visible child item was found under it at the same time, and Steadhand could not show it again, so the child may show at the top level. Check the menu with steadhand/get-menu, and publish the item in Appearance > Menus.', 'steadhand' );
	}

	/**
	 * The text for a hide put-back that showed the item, whose text check then failed: the item is visible.
	 */
	private static function hide_back_text(): string {
		return __( 'The item was hidden, but a visible child item was found under it at the same time, so Steadhand showed it again. Another change altered its text during that save. Check it with steadhand/get-menu.', 'steadhand' );
	}

	/**
	 * The text for a hide put-back whose item stayed hidden because its own parent is hidden.
	 */
	private static function hide_parent_hidden(): string {
		return __( 'The item was hidden, but a visible child item was found under it at the same time. Steadhand tried to show it again, but its parent item is hidden, so the item stayed hidden. Show the parent first. The child item may show at the top level until then. Check the menu with steadhand/get-menu.', 'steadhand' );
	}

	/**
	 * The text for a hide put-back that showed the item under a hidden parent, and could not hide it again.
	 */
	private static function hide_parent_stuck(): string {
		return __( 'The item was hidden, but a visible child item was found under it at the same time. Steadhand showed it again, but its parent item is hidden, and it could not hide it again, so the item is visible under a hidden parent. Check the menu with steadhand/get-menu, and hide the item in Appearance > Menus.', 'steadhand' );
	}
}
