<?php
/**
 * The Agents screen's checks after each write. A new user must hold nothing but the agent role, under the posted login, and a password
 * or capability change that leaves the target no longer an agent is undone. Also the races on login and label, the rollback that keeps
 * content, and the listeners that throw.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Admin\AgentsHandler;
use Steadhand\Capabilities;
use Steadhand\Plugin;
use Steadhand\Roles;

/**
 * Each test changes the user or the table during the request, through a hook, and checks that the request refuses and leaves no
 * usable credential behind.
 */
final class AgentsVerificationTest extends TestCase {

	/**
	 * Hooks this test added, removed in tearDown.
	 *
	 * @var array<int, array{0: string, 1: callable, 2: int}>
	 */
	private array $added = array();

	/**
	 * The handler under test.
	 *
	 * @var AgentsHandler
	 */
	private AgentsHandler $handler;

	protected function setUp(): void {
		parent::setUp();

		$this->handler = new AgentsHandler( ( new Plugin() )->registry() );
		$this->on( 'wp_is_application_passwords_available', '__return_true' );
		$this->as_user( $this->admin() );
	}

	protected function tearDown(): void {
		foreach ( $this->added as $added ) {
			remove_filter( $added[0], $added[1], $added[2] );
		}
		$this->added = array();

		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Adds a hook this test removes again in tearDown.
	 *
	 * @param string   $hook     Hook.
	 * @param callable $callback Callback.
	 * @param int      $priority Priority.
	 */
	private function on( string $hook, callable $callback, int $priority = 10 ): void {
		add_filter( $hook, $callback, $priority, 10 );
		$this->added[] = array( $hook, $callback, $priority );
	}

	/**
	 * An administrator, who is the current user.
	 *
	 * @return int
	 */
	private function admin(): int {
		return self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Makes the given user the current user.
	 *
	 * @param int $id The user.
	 */
	private function as_user( int $id ): void {
		wp_set_current_user( $id );
	}

	/**
	 * A dedicated agent with the given number of Application Passwords, named 'Password 1', 'Password 2' and so on.
	 *
	 * @param string $login     Login name, or '' for a generated one.
	 * @param int    $passwords Number of Application Passwords.
	 * @return int The user ID.
	 */
	private function agent( string $login = '', int $passwords = 1 ): int {
		$id = self::factory()->user->create(
			array(
				'role'       => Roles::ROLE,
				'user_login' => '' === $login ? 'agent' . wp_rand( 1000, 999999 ) : $login,
			)
		);
		for ( $i = 1; $i <= $passwords; $i++ ) {
			\WP_Application_Passwords::create_new_application_password( $id, array( 'name' => 'Password ' . $i ) );
		}

		return $id;
	}

	/**
	 * The nonce for an action on a target (or create).
	 *
	 * @param string $action One of the AgentsHandler::ACTION_* values.
	 * @param int    $target The target user, or 0.
	 * @return string
	 */
	private function nonce( string $action, int $target = 0 ): string {
		return wp_create_nonce( AgentsHandler::nonce_action( $action, $target ) );
	}

	/**
	 * Whether no user has this login, and the user count is the given one.
	 *
	 * @param string $login The login.
	 * @param int    $count The number of users there were before the request.
	 */
	private function assert_no_user_and_count( string $login, int $count ): void {
		$this->assertFalse( username_exists( $login ), $login );
		$this->assertCount( $count, get_users( array( 'fields' => 'ID' ) ) );
	}

	/**
	 * A user that is kept after a refused create: no role, no capability of any kind, and no Application Password.
	 *
	 * @param int $id The user ID.
	 */
	private function assert_kept_without_access( int $id ): void {
		$user = get_userdata( $id );

		$this->assertInstanceOf( \WP_User::class, $user, 'The user is kept.' );
		$this->assertEmpty( $user->roles, 'The kept user has no role.' );
		$this->assertEmpty( $user->caps, 'The kept user stores no capability.' );
		$this->assertEmpty( $user->allcaps, 'The kept user has no capability.' );
		$this->assertSame( array(), \WP_Application_Passwords::get_user_application_passwords( $id ), 'The kept user has no password.' );
	}

	/**
	 * Runs a create while $hook gives the new user an extra grant, then checks the refusal: the code, no secret, no user, and, for a
	 * password that was made, no password. The grant is made once, on the first call of the hook.
	 *
	 * The hook is user_register, which runs before the first check, or wp_create_application_password, which runs after the password is
	 * saved and so is caught only by the second check.
	 *
	 * @param string   $login The login to create.
	 * @param string   $hook  The hook that gives the grant.
	 * @param callable $grant Makes the grant. It receives the new user's ID.
	 * @param string   $code  The refusal code the create must return.
	 */
	private function assert_grant_refused( string $login, string $hook, callable $grant, string $code ): void {
		$count   = count( get_users( array( 'fields' => 'ID' ) ) );
		$granted = array();
		$this->on(
			$hook,
			static function ( $user_id, $item = null ) use ( $grant, &$granted ): void {
				if ( array() !== $granted ) {
					return;
				}
				$uuid    = is_array( $item ) ? (string) ( $item['uuid'] ?? '' ) : '';
				$granted = array( (int) $user_id, $uuid );
				$grant( (int) $user_id );
			}
		);

		$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), $login, 'Desk' );

		$this->assertNotSame( array(), $granted, 'The grant was made.' );
		list( $id, $uuid ) = $granted;
		$this->assertSame( $code, $result['code'] );
		$this->assertFalse( $result['ok'] );
		$this->assertNull( $result['secret'], 'No secret is returned.' );
		$this->assertFalse( get_userdata( $id ), 'The user was removed.' );
		if ( '' !== $uuid ) {
			$this->assertNull( \WP_Application_Passwords::get_user_application_password( $id, $uuid ), 'The password was revoked.' );
		}
		$this->assert_no_user_and_count( $login, $count );
	}

	public function test_a_role_that_is_not_registered_refuses_before_anything_is_created(): void {
		$count = count( get_users( array( 'fields' => 'ID' ) ) );

		remove_role( Roles::ROLE );
		try {
			$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'no-role', 'Desk' );
		} finally {
			Roles::install();
		}

		$this->assertSame( 'role_missing', $result['code'] );
		$this->assertFalse( $result['ok'] );
		$this->assertNull( $result['secret'] );
		$this->assert_no_user_and_count( 'no-role', $count );
	}

	public function test_a_listener_that_adds_a_role_at_registration_leaves_no_user(): void {
		$this->on(
			'user_register',
			static function ( $user_id ): void {
				get_userdata( $user_id )->add_role( 'editor' );
			}
		);
		$count = count( get_users( array( 'fields' => 'ID' ) ) );

		$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'added-role', 'Desk' );

		$this->assertSame( 'extra_role', $result['code'] );
		$this->assertNull( $result['secret'] );
		$this->assert_no_user_and_count( 'added-role', $count );
	}

	public function test_a_listener_that_sets_another_role_at_registration_leaves_no_user(): void {
		$this->on(
			'user_register',
			static function ( $user_id ): void {
				get_userdata( $user_id )->set_role( 'subscriber' );
			}
		);
		$count = count( get_users( array( 'fields' => 'ID' ) ) );

		$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'set-role', 'Desk' );

		$this->assertSame( 'extra_role', $result['code'] );
		$this->assertNull( $result['secret'] );
		$this->assert_no_user_and_count( 'set-role', $count );
	}

	public function test_a_listener_that_adds_a_role_when_the_role_is_set_leaves_no_user(): void {
		$done = false;
		$this->on(
			'set_user_role',
			static function ( $user_id ) use ( &$done ): void {
				if ( ! $done ) {
					$done = true;
					get_userdata( $user_id )->add_role( 'editor' );
				}
			}
		);
		$count = count( get_users( array( 'fields' => 'ID' ) ) );

		$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'set-hook', 'Desk' );

		$this->assertTrue( $done, 'The set_user_role listener ran.' );
		$this->assertSame( 'extra_role', $result['code'] );
		$this->assert_no_user_and_count( 'set-hook', $count );
	}

	public function test_a_failed_application_password_write_leaves_no_user(): void {
		// Core's update returns false for this one meta key, so the password is not saved and core returns a WP_Error.
		$this->on(
			'update_user_metadata',
			static fn( $check, $object_id, $meta_key ) => \WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS === $meta_key ? false : $check,
			10
		);
		$count = count( get_users( array( 'fields' => 'ID' ) ) );

		$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'no-password', 'Desk' );

		$this->assertSame( 'password_failed', $result['code'] );
		$this->assertNull( $result['secret'] );
		$this->assert_no_user_and_count( 'no-password', $count );
	}

	public function test_a_role_change_after_the_password_is_made_revokes_it_and_shows_nothing(): void {
		$made = null;
		$this->on(
			'wp_create_application_password',
			static function ( $user_id, $item ) use ( &$made ): void {
				$made = array( $user_id, (string) $item['uuid'] );
				get_userdata( $user_id )->set_role( 'subscriber' );
			},
			10
		);
		$count = count( get_users( array( 'fields' => 'ID' ) ) );

		$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'role-later', 'Desk' );

		$this->assertIsArray( $made, 'The password was made before the check.' );
		$this->assertSame( 'extra_role', $result['code'] );
		$this->assertNull( $result['secret'], 'No plaintext is returned.' );
		$this->assertNull( \WP_Application_Passwords::get_user_application_password( $made[0], $made[1] ), 'The password was revoked.' );
		$this->assert_no_user_and_count( 'role-later', $count );
	}

	/**
	 * M2. The stored-capabilities comparison. Every test above changes a role, and Agents::is_agent() alone catches that. The tests below
	 * change only the stored capabilities, and roles stay exactly [steadhand_agent]: add_cap() adds a key to the caps and not to the roles,
	 * and add_role() of a name that is not registered stores the key but puts no entry in $user->roles, because WP_User only lists the
	 * keys that are registered roles. So each of these passes Agents::is_agent(), and only the caps comparison in holds_only_agent_role()
	 * refuses it. Without that comparison, the create would return 'created' and these tests would fail.
	 */
	public function test_an_extra_capability_granted_at_registration_is_refused(): void {
		$this->assert_grant_refused(
			'grant-cap-reg',
			'user_register',
			static fn( int $id ) => get_userdata( $id )->add_cap( 'manage_options' ),
			'extra_role'
		);
	}

	public function test_a_capability_stored_as_false_at_registration_is_refused(): void {
		$this->assert_grant_refused(
			'grant-false-reg',
			'user_register',
			static fn( int $id ) => get_userdata( $id )->add_cap( 'some_cap', false ),
			'extra_role'
		);
	}

	public function test_an_unregistered_role_name_granted_at_registration_is_refused(): void {
		$this->assert_grant_refused(
			'grant-role-reg',
			'user_register',
			static fn( int $id ) => get_userdata( $id )->add_role( 'not_registered_yet' ),
			'extra_role'
		);
	}

	public function test_an_extra_capability_granted_after_the_password_is_made_is_refused_by_the_second_check(): void {
		$this->assert_grant_refused(
			'grant-cap-late',
			'wp_create_application_password',
			static fn( int $id ) => get_userdata( $id )->add_cap( 'manage_options' ),
			'extra_role'
		);
	}

	public function test_a_capability_stored_as_false_after_the_password_is_made_is_refused_by_the_second_check(): void {
		$this->assert_grant_refused(
			'grant-false-late',
			'wp_create_application_password',
			static fn( int $id ) => get_userdata( $id )->add_cap( 'some_cap', false ),
			'extra_role'
		);
	}

	public function test_an_unregistered_role_name_granted_after_the_password_is_made_is_refused_by_the_second_check(): void {
		$this->assert_grant_refused(
			'grant-role-late',
			'wp_create_application_password',
			static fn( int $id ) => get_userdata( $id )->add_role( 'not_registered_yet' ),
			'extra_role'
		);
	}

	/**
	 * M1. A rollback never deletes content. Core's wp_delete_user() deletes every post, attachment and link whose author is the user,
	 * so a refused create keeps the user when any of them exists.
	 */
	public function test_a_post_attributed_to_the_new_user_is_kept_when_creation_fails(): void {
		$post = 0;
		$this->on(
			'user_register',
			static function ( $user_id ) use ( &$post ): void {
				// Content that someone else made, under an ID that the new user has just taken.
				$post = (int) wp_insert_post(
					array(
						'post_author' => $user_id,
						'post_title'  => 'Written before the agent',
						'post_status' => 'publish',
					)
				);
				get_userdata( $user_id )->add_role( 'editor' );
			}
		);

		$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'owns-post', 'Desk' );

		$this->assertGreaterThan( 0, $post, 'The post was made during the request.' );
		$this->assertSame( 'create_failed_user_kept', $result['code'] );
		$this->assertNull( $result['secret'], 'No secret is returned.' );
		$stored = get_post( $post );
		$this->assertInstanceOf( \WP_Post::class, $stored, 'The post still exists.' );
		$this->assertSame( 'publish', $stored->post_status, 'The post was not trashed.' );
		$this->assert_kept_without_access( $result['user_id'] );
	}

	public function test_an_attachment_attributed_to_the_new_user_is_kept_when_creation_fails(): void {
		$attachment = 0;
		$this->on(
			'user_register',
			static function ( $user_id ) use ( &$attachment ): void {
				$attachment = (int) wp_insert_attachment(
					array(
						'post_author'    => $user_id,
						'post_title'     => 'Photo made before the agent',
						'post_status'    => 'inherit',
						'post_mime_type' => 'image/jpeg',
					),
					false
				);
				get_userdata( $user_id )->add_role( 'editor' );
			}
		);

		$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'owns-media', 'Desk' );

		$this->assertGreaterThan( 0, $attachment, 'The attachment was made during the request.' );
		$this->assertSame( 'create_failed_user_kept', $result['code'] );
		$this->assertNull( $result['secret'] );
		$stored = get_post( $attachment );
		$this->assertInstanceOf( \WP_Post::class, $stored, 'The attachment still exists.' );
		$this->assertSame( 'inherit', $stored->post_status, 'The attachment was not trashed.' );
		$this->assert_kept_without_access( $result['user_id'] );
	}

	public function test_a_link_attributed_to_the_new_user_is_kept_when_creation_fails(): void {
		global $wpdb;
		$link = 0;
		$this->on(
			'user_register',
			static function ( $user_id ) use ( &$link ): void {
				global $wpdb;
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test: a link attributed to the new user, which the link API does not offer.
					$wpdb->links,
					array(
						'link_url'   => 'https://example.com/',
						'link_name'  => 'Made before the agent',
						'link_owner' => $user_id,
					)
				);
				$link = (int) $wpdb->insert_id;
				get_userdata( $user_id )->add_role( 'editor' );
			}
		);

		$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'owns-link', 'Desk' );

		$this->assertGreaterThan( 0, $link, 'The link was made during the request.' );
		$this->assertSame( 'create_failed_user_kept', $result['code'] );
		$rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->links} WHERE link_id = %d", $link ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test: counts one link row; the table name is core's.
		$this->assertSame( 1, $rows, 'The link still exists.' );
		$this->assert_kept_without_access( $result['user_id'] );
	}

	public function test_a_refused_create_with_no_content_still_deletes_the_user(): void {
		$this->on(
			'user_register',
			static function ( $user_id ): void {
				get_userdata( $user_id )->add_role( 'editor' );
			}
		);
		$count = count( get_users( array( 'fields' => 'ID' ) ) );

		$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'no-content', 'Desk' );

		$this->assertSame( 'extra_role', $result['code'] );
		$this->assertFalse( get_userdata( $result['user_id'] ), 'The user with no content is deleted.' );
		$this->assert_no_user_and_count( 'no-content', $count );
	}

	public function test_a_role_change_after_the_password_is_made_keeps_a_user_with_content_and_no_password(): void {
		$post = 0;
		$this->on(
			'wp_create_application_password',
			static function ( $user_id ) use ( &$post ): void {
				$post = (int) wp_insert_post(
					array(
						'post_author' => $user_id,
						'post_title'  => 'Written before the agent',
						'post_status' => 'draft',
					)
				);
				get_userdata( $user_id )->set_role( 'subscriber' );
			},
			10
		);

		$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'role-content', 'Desk' );

		$this->assertGreaterThan( 0, $post );
		$this->assertSame( 'create_failed_user_kept', $result['code'] );
		$this->assertNull( $result['secret'] );
		$this->assertInstanceOf( \WP_Post::class, get_post( $post ) );
		$this->assert_kept_without_access( $result['user_id'] );
	}

	/**
	 * L1. The created action fires before the final check. A listener that changes the user there is caught by the final check.
	 */
	public function test_a_role_added_by_the_created_action_refuses_the_agent_and_shows_no_secret(): void {
		$seen = 0;
		$this->on(
			'steadhand_agent_created',
			static function ( $user_id ) use ( &$seen ): void {
				$seen = (int) $user_id;
				get_userdata( $user_id )->add_role( 'editor' );
			}
		);
		$count = count( get_users( array( 'fields' => 'ID' ) ) );

		$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'created-late', 'Desk' );

		$this->assertGreaterThan( 0, $seen, 'The created action fired before the final check.' );
		$this->assertSame( 'extra_role', $result['code'] );
		$this->assertNull( $result['secret'], 'No secret is returned.' );
		$this->assertFalse( get_userdata( $seen ), 'The agent is rolled back.' );
		$this->assert_no_user_and_count( 'created-late', $count );
	}

	/**
	 * L2. A listener that throws is caught. No exception reaches the screen, and the write it interrupted is rolled back.
	 */
	public function test_a_listener_that_throws_on_registration_leaves_no_user_and_no_exception(): void {
		$this->on(
			'user_register',
			static function (): void {
				throw new \RuntimeException( 'user_register failure' );
			}
		);
		$count = count( get_users( array( 'fields' => 'ID' ) ) );

		$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'throws-register', 'Desk' );

		$this->assertSame( 'create_failed', $result['code'] );
		$this->assertNull( $result['secret'] );
		$this->assert_no_user_and_count( 'throws-register', $count );
	}

	public function test_a_listener_that_throws_on_the_application_password_leaves_no_user_and_no_password(): void {
		$made = array();
		$this->on(
			'wp_create_application_password',
			static function ( $user_id, $item ) use ( &$made ): void {
				$made = array( (int) $user_id, (string) $item['uuid'] );
				throw new \RuntimeException( 'password failure' );
			}
		);
		$count = count( get_users( array( 'fields' => 'ID' ) ) );

		$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'throws-password', 'Desk' );

		$this->assertSame( 'create_failed', $result['code'] );
		$this->assertNull( $result['secret'] );
		$this->assertNotSame( array(), $made, 'The password was saved before the listener threw.' );
		$this->assertNull( \WP_Application_Passwords::get_user_application_password( $made[0], $made[1] ), 'The password was revoked.' );
		$this->assertFalse( get_userdata( $made[0] ), 'The user was removed.' );
		$this->assert_no_user_and_count( 'throws-password', $count );
	}

	public function test_a_delete_that_throws_keeps_the_login_for_the_administrator_to_check(): void {
		$this->on(
			'user_register',
			static function ( $user_id ): void {
				get_userdata( $user_id )->add_role( 'editor' );
			}
		);
		$this->on(
			'delete_user',
			static function (): void {
				throw new \RuntimeException( 'delete failure' );
			}
		);

		$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'stuck-login', 'Desk' );

		$this->assertSame( 'create_failed_cleanup', $result['code'] );
		$this->assertSame( 'stuck-login', $result['login'] );
		$this->assertNull( $result['secret'] );
		$user = get_userdata( $result['user_id'] );
		$this->assertInstanceOf( \WP_User::class, $user, 'The user is still there, so it is named for the administrator.' );
		$this->assertEmpty( $user->roles, 'The rollback still removed the roles.' );
		$this->assertSame( array(), \WP_Application_Passwords::get_user_application_passwords( $result['user_id'] ) );
	}

	/**
	 * L3. A login count that cannot be read is a refusal.
	 */
	public function test_a_login_count_that_cannot_be_read_refuses_and_removes_the_user(): void {
		$this->on(
			'query',
			static function ( $query ) {
				global $wpdb;

				// Replaces the duplicate-login count with a SELECT that returns no value, so the count reads as null.
				return str_contains( $query, "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login" )
					? "SELECT NULL FROM {$wpdb->users} WHERE 1 = 0"
					: $query;
			}
		);
		$count = count( get_users( array( 'fields' => 'ID' ) ) );

		$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'no-count', 'Desk' );

		$this->assertSame( 'create_failed', $result['code'] );
		$this->assertNull( $result['secret'] );
		$this->assert_no_user_and_count( 'no-count', $count );
	}

	public function test_a_username_rewritten_by_a_filter_is_refused_and_the_user_removed(): void {
		$this->on(
			'pre_user_login',
			static fn( $login ) => 'other-' . $login
		);
		$count = count( get_users( array( 'fields' => 'ID' ) ) );

		$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'rewrite-me', 'Desk' );

		$this->assertSame( 'create_failed', $result['code'] );
		$this->assertNull( $result['secret'] );
		$this->assertFalse( username_exists( 'other-rewrite-me' ) );
		$this->assert_no_user_and_count( 'rewrite-me', $count );
	}

	public function test_add_password_revokes_its_password_when_the_target_stops_being_an_agent(): void {
		$agent = $this->agent( '', 1 );
		$this->on(
			'wp_create_application_password',
			static function ( $user_id ) use ( $agent ): void {
				if ( $agent === $user_id ) {
					get_userdata( $user_id )->set_role( 'subscriber' );
				}
			},
			10
		);

		$result = $this->handler->add_password( $this->nonce( AgentsHandler::ACTION_ADD_PASSWORD, $agent ), $agent, 'Late' );

		$this->assertSame( 'not_agent', $result['code'] );
		$this->assertNull( $result['secret'] );
		$this->assertSame( array( 'Password 1' ), array_column( \WP_Application_Passwords::get_user_application_passwords( $agent ), 'name' ) );
	}

	public function test_add_password_revokes_its_password_when_a_racing_request_makes_the_label_a_duplicate(): void {
		$agent     = $this->agent( '', 1 );
		$injected  = false;
		$copy_uuid = '';
		$this->on(
			'wp_create_application_password',
			static function ( $user_id, $item ) use ( $agent, &$injected, &$copy_uuid ): void {
				if ( $agent !== $user_id || $injected ) {
					return;
				}
				$injected = true;
				// A second request saves a password with the same label between the check and this request's re-read.
				$items = \WP_Application_Passwords::get_user_application_passwords( $user_id );

				$copy         = $item;
				$copy['uuid'] = wp_generate_uuid4();
				$copy_uuid    = $copy['uuid'];

				$items[] = $copy;
				update_user_meta( $user_id, \WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, $items );
			},
			10
		);

		$result = $this->handler->add_password( $this->nonce( AgentsHandler::ACTION_ADD_PASSWORD, $agent ), $agent, 'Shared' );

		$this->assertTrue( $injected );
		$this->assertSame( 'label_taken', $result['code'] );
		$this->assertNull( $result['secret'] );
		$remaining = \WP_Application_Passwords::get_user_application_passwords( $agent );
		$this->assertSame( array( $copy_uuid ), array_column( array_filter( $remaining, static fn( array $p ): bool => 'Shared' === $p['name'] ), 'uuid' ), 'Only the racing copy is left.' );
		$this->assertCount( 2, $remaining, 'The request revoked its own password.' );
	}

	public function test_add_password_refuses_an_eleventh_password_made_by_a_race(): void {
		$agent    = $this->agent( '', 9 );
		$injected = false;
		$this->on(
			'wp_create_application_password',
			static function ( $user_id, $item ) use ( $agent, &$injected ): void {
				if ( $agent !== $user_id || $injected ) {
					return;
				}
				$injected     = true;
				$items        = \WP_Application_Passwords::get_user_application_passwords( $user_id );
				$copy         = $item;
				$copy['uuid'] = wp_generate_uuid4();
				$copy['name'] = 'Racing';
				$items[]      = $copy;
				update_user_meta( $user_id, \WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, $items );
			},
			10
		);

		$result = $this->handler->add_password( $this->nonce( AgentsHandler::ACTION_ADD_PASSWORD, $agent ), $agent, 'Mine' );

		$this->assertTrue( $injected );
		$this->assertSame( 'too_many_passwords', $result['code'] );
		$this->assertNull( $result['secret'] );
		$names = array_column( \WP_Application_Passwords::get_user_application_passwords( $agent ), 'name' );
		$this->assertCount( 10, $names, 'The request revoked its own password only.' );
		$this->assertNotContains( 'Mine', $names );
	}

	/**
	 * L4. A revoke that fails says so, and never reports success or "nothing was left behind".
	 */
	public function test_a_revoke_that_fails_says_so_and_keeps_the_password(): void {
		$agent = $this->agent( '', 1 );
		$uuid  = \WP_Application_Passwords::get_user_application_passwords( $agent )[0]['uuid'];
		$this->on(
			'update_user_metadata',
			static fn( $check, $object_id, $meta_key ) => \WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS === $meta_key ? false : $check,
			10
		);

		$result = $this->handler->revoke( $this->nonce( AgentsHandler::ACTION_REVOKE, $agent ), $agent, $uuid );

		$this->assertSame( 'revoke_failed', $result['code'] );
		$this->assertFalse( $result['ok'] );
		$this->assertCount( 1, \WP_Application_Passwords::get_user_application_passwords( $agent ), 'The password is still there.' );
	}

	public function test_a_revoke_all_that_fails_says_so_and_keeps_the_passwords(): void {
		$agent = $this->agent( '', 2 );
		$this->on(
			'update_user_metadata',
			static fn( $check, $object_id, $meta_key ) => \WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS === $meta_key ? false : $check,
			10
		);

		$result = $this->handler->revoke_all( $this->nonce( AgentsHandler::ACTION_REVOKE_ALL, $agent ), $agent );

		$this->assertSame( 'revoke_failed', $result['code'] );
		$this->assertCount( 2, \WP_Application_Passwords::get_user_application_passwords( $agent ), 'The passwords are still there.' );
	}

	public function test_set_capabilities_read_back_reports_unverified_when_the_target_stops_being_an_agent_after_the_writes(): void {
		$agent = $this->agent( '', 1 );
		$calls = 0;
		// The second re-read is the one after the writes, which is the read-back.
		$this->on(
			'clean_user_cache',
			static function ( $user_id ) use ( $agent, &$calls ): void {
				if ( $agent === $user_id ) {
					++$calls;
					if ( 2 === $calls ) {
						get_userdata( $user_id )->set_role( 'subscriber' );
					}
				}
			},
			10
		);
		$saved = 0;
		$this->on(
			'steadhand_agent_capabilities_changed',
			static function () use ( &$saved ): void {
				++$saved;
			}
		);

		$result = $this->handler->set_capabilities( $this->nonce( AgentsHandler::ACTION_CAPABILITIES, $agent ), $agent, array( Capabilities::READ ) );

		$this->assertSame( 2, $calls, 'The user was re-read before the writes and after them.' );
		$this->assertSame( 'capabilities_unverified', $result['code'] );
		$this->assertSame( 0, $saved, 'The changed action did not fire.' );
	}

	public function test_set_capabilities_refuses_when_the_target_stops_being_an_agent_before_the_writes(): void {
		$agent = $this->agent( '', 1 );
		$calls = 0;
		// clean_user_cache() runs first when set_capabilities re-reads the user for its writes.
		$this->on(
			'clean_user_cache',
			static function ( $user_id ) use ( $agent, &$calls ): void {
				if ( $agent === $user_id && 0 === $calls++ ) {
					get_userdata( $user_id )->set_role( 'subscriber' );
				}
			},
			10
		);

		$result = $this->handler->set_capabilities( $this->nonce( AgentsHandler::ACTION_CAPABILITIES, $agent ), $agent, array( Capabilities::PUBLISH ) );

		$this->assertSame( 'not_agent', $result['code'] );
		$this->assertFalse( Capabilities::user_has( get_userdata( $agent ), Capabilities::PUBLISH ), 'Nothing was written.' );
	}
}
