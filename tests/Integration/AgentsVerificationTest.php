<?php
/**
 * The Agents screen's checks after each write. A new user must hold nothing but the agent role, under the posted login, and a password
 * or capability change that leaves the target no longer an agent is undone. Also the races on login and label.
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

		$this->assertSame( 'create_failed', $result['code'] );
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

		$this->assertSame( 'create_failed', $result['code'] );
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
		$this->assertSame( 'create_failed', $result['code'] );
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
		$this->assertSame( 'create_failed', $result['code'] );
		$this->assertNull( $result['secret'], 'No plaintext is returned.' );
		$this->assertNull( \WP_Application_Passwords::get_user_application_password( $made[0], $made[1] ), 'The password was revoked.' );
		$this->assert_no_user_and_count( 'role-later', $count );
	}

	public function test_a_login_written_twice_by_a_racing_request_refuses_and_removes_only_its_own_user(): void {
		global $wpdb;
		$inserted = 0;
		$this->on(
			'user_register',
			static function ( $user_id ) use ( &$inserted ): void {
				if ( 0 !== $inserted ) {
					return;
				}
				$inserted = (int) $user_id;
				// Another request's row with the same login, as a race between two requests could leave.
				global $wpdb;
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The race is simulated by a second row, which the API would not allow.
					$wpdb->users,
					array(
						'user_login'      => 'race-login',
						'user_pass'       => 'not-used',
						'user_nicename'   => 'race-login',
						'user_email'      => '',
						'user_registered' => gmdate( 'Y-m-d H:i:s' ),
						'display_name'    => 'race-login',
					)
				);
			}
		);
		$count = count( get_users( array( 'fields' => 'ID' ) ) );

		$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'race-login', 'Desk' );

		$this->assertGreaterThan( 0, $inserted, 'The second row was inserted during the request.' );
		$this->assertSame( 'username_taken', $result['code'] );
		$this->assertNull( $result['secret'] );
		$this->assertFalse( get_userdata( $inserted ), 'The request removed its own user.' );
		$this->assertSame( array(), \WP_Application_Passwords::get_user_application_passwords( $inserted ) );
		// The other row stays, and is the only one with this login.
		$rows = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login = %s", 'race-login' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test: counts the rows of one login; the table name is core's.
		$this->assertSame( 1, $rows );
		$this->assertCount( $count + 1, get_users( array( 'fields' => 'ID' ) ), 'Only the other row was added.' );
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

	public function test_set_capabilities_read_back_refuses_when_the_target_stops_being_an_agent_after_the_writes(): void {
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
		$this->assertSame( 'not_agent', $result['code'] );
		$this->assertSame( 0, $saved, 'The changed action did not fire.' );
	}
}
