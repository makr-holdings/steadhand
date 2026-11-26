<?php
/**
 * Choosing an agent's Steadhand capabilities when it is created. Null keeps the role's defaults. An array sets exactly the checked offered
 * capabilities, and a name that is not offered is never stored. A capability write that does not stick, a listener that changes a
 * capability after the password, or a listener that throws refuses the create: no user remains, no secret is returned, and no password
 * was made when the write failed.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Abilities\GetSiteInfo;
use Steadhand\Admin\AgentsHandler;
use Steadhand\Admin\AgentsScreen;
use Steadhand\Agents;
use Steadhand\Capabilities;
use Steadhand\Pipeline\Pipeline;
use Steadhand\Plugin;
use Steadhand\Roles;

/**
 * The negative tests come first in spirit: every refusal must leave no user behind, and every name that is not offered must be absent.
 */
final class AgentsCreateCapabilitiesTest extends TestCase {

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

	/**
	 * The screen under test, for the POST path.
	 *
	 * @var AgentsScreen
	 */
	private AgentsScreen $screen;

	protected function setUp(): void {
		parent::setUp();

		$this->handler = new AgentsHandler( ( new Plugin() )->registry() );
		$this->screen  = new AgentsScreen( $this->handler );

		// A test double: a refusal throws instead of printing a page, and the test catches it.
		$thrower = static function ( $message ): void {
			throw new \WPDieException( (string) $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double; the message is never printed.
		};
		$this->on( 'wp_die_handler', static fn() => $thrower, PHP_INT_MAX );
		$this->on( 'wp_is_application_passwords_available', '__return_true' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	protected function tearDown(): void {
		foreach ( $this->added as $added ) {
			remove_filter( $added[0], $added[1], $added[2] );
		}
		$this->added = array();

		$_POST                     = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Adds a filter or action this test removes again in tearDown.
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
	 * The nonce for the create action.
	 *
	 * @return string
	 */
	private function nonce(): string {
		return wp_create_nonce( AgentsHandler::nonce_action( AgentsHandler::ACTION_CREATE ) );
	}

	/**
	 * Creates an agent through the handler.
	 *
	 * @param string     $login        The login.
	 * @param array|null $capabilities Null for the defaults, or the checked names.
	 * @return array
	 */
	/**
	 * A handler whose screen offers every capability except steadhand_settings, so a test can cover a capability the screen does not offer.
	 */
	private function handler_without_settings(): AgentsHandler {
		$offered = array_values( array_diff( Capabilities::all(), array( Capabilities::SETTINGS ) ) );

		return new AgentsHandler( ( new Plugin() )->registry(), null, $offered );
	}

	private function create( string $login, ?array $capabilities ): array {
		return $this->handler->create( $this->nonce(), $login, 'Desk', $capabilities );
	}

	/**
	 * Asserts that the user stores exactly the role and every Steadhand capability: each offered one with the value the checked list
	 * gives it, and each other one as false. Also asserts that Capabilities::user_has() agrees.
	 *
	 * @param int   $user_id The user.
	 * @param array $checked The names that are checked.
	 */
	private function assert_stored( int $user_id, array $checked ): void {
		$expected = array( Roles::ROLE => true );
		foreach ( Capabilities::all() as $capability ) {
			$expected[ $capability ] = in_array( $capability, $checked, true );
		}

		$user = get_userdata( $user_id );
		$this->assertInstanceOf( \WP_User::class, $user );
		$caps = $user->caps;
		ksort( $expected );
		ksort( $caps );
		$this->assertSame( $expected, $caps, 'The stored map is the role and every Steadhand capability, and nothing else.' );
		$this->assertTrue( Agents::is_agent( $user ) );

		foreach ( Capabilities::all() as $capability ) {
			$this->assertSame( in_array( $capability, $checked, true ), Capabilities::user_has( $user, $capability ), $capability );
		}
	}

	/**
	 * Stores one value in a user's stored capability map, as another plugin's write would, and clears the user's cache. The value is
	 * stored as given, so a non-bool stays non-bool.
	 *
	 * @param int    $user_id    The user.
	 * @param string $capability The capability.
	 * @param mixed  $value      The stored value.
	 */
	private static function store_raw( int $user_id, string $capability, mixed $value ): void {
		$user                = get_userdata( $user_id );
		$caps                = $user->caps;
		$caps[ $capability ] = $value;
		update_user_meta( $user_id, $user->cap_key, $caps );
		clean_user_cache( $user_id );
	}

	/**
	 * Asserts a refusal: the code, no secret, no user with the login, and no password was made for it.
	 *
	 * @param array  $result The result of the create.
	 * @param string $code   The expected code.
	 * @param string $login  The login that must not remain.
	 */
	private function assert_refused( array $result, string $code, string $login ): void {
		$this->assertSame( $code, $result['code'] );
		$this->assertFalse( $result['ok'] );
		$this->assertNull( $result['secret'], 'No secret is returned.' );
		$this->assertFalse( username_exists( $login ), 'The user is rolled back.' );
	}

	/**
	 * Sends a create POST to the screen, with the given fields on top of the required ones.
	 *
	 * @param string $login  The login.
	 * @param array  $fields The other form fields.
	 */
	private function post_create( string $login, array $fields ): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array_merge(
			array(
				AgentsHandler::FIELD => AgentsHandler::ACTION_CREATE,
				'username'           => $login,
				'label'              => 'Desk',
				'_wpnonce'           => $this->nonce(),
			),
			$fields
		);

		$this->screen->process_post();
	}

	/**
	 * Whether a write of the stored capability map grants Steadhand's publish capability. Used by the filters that stop the write.
	 *
	 * @param mixed $meta_key   The meta key.
	 * @param mixed $meta_value The value that would be stored.
	 * @return bool
	 */
	private static function writes_publish( $meta_key, $meta_value ): bool {
		return is_string( $meta_key )
			&& str_ends_with( $meta_key, 'capabilities' )
			&& is_array( $meta_value )
			&& true === ( $meta_value[ Capabilities::PUBLISH ] ?? null );
	}

	public function test_the_checked_capabilities_are_granted_and_the_rest_are_not(): void {
		$result = $this->create( 'chosen-agent', array( Capabilities::CONNECT, Capabilities::READ ) );

		$this->assertSame( 'created', $result['code'] );
		$this->assertTrue( $result['ok'] );
		$this->assertIsArray( $result['secret'] );
		$this->assertSame( 'chosen-agent', $result['secret']['username'] );
		$this->assertTrue( Capabilities::user_has( get_userdata( $result['user_id'] ), Capabilities::CONNECT ) );
		$this->assertTrue( Capabilities::user_has( get_userdata( $result['user_id'] ), Capabilities::READ ) );
		$this->assertFalse( Capabilities::user_has( get_userdata( $result['user_id'] ), Capabilities::WRITE_DRAFTS ), 'Role default, not checked, so written false.' );
		$this->assert_stored( $result['user_id'], array( Capabilities::CONNECT, Capabilities::READ ) );
	}

	public function test_an_empty_list_is_refused_with_connect_required_and_makes_no_user_or_password(): void {
		$made = false;
		$this->on(
			'wp_create_application_password',
			static function () use ( &$made ): void {
				$made = true;
			}
		);
		$this->on(
			'user_register',
			static function () use ( &$made ): void {
				$made = true;
			}
		);

		$result = $this->create( 'empty-agent', array() );

		$this->assert_refused( $result, 'connect_required', 'empty-agent' );
		$this->assertFalse( $made, 'No user and no password were made.' );
	}

	public function test_connect_unticked_is_refused_before_any_user_or_password_is_made(): void {
		$made = false;
		$this->on(
			'wp_create_application_password',
			static function () use ( &$made ): void {
				$made = true;
			}
		);
		$this->on(
			'user_register',
			static function () use ( &$made ): void {
				$made = true;
			}
		);

		$result = $this->create( 'no-connect-create', array( Capabilities::READ, Capabilities::WRITE_DRAFTS ) );

		$this->assert_refused( $result, 'connect_required', 'no-connect-create' );
		$this->assertFalse( $made, 'Refused before the user row and the password.' );
	}

	public function test_a_connect_refusal_redraws_the_form_with_the_posted_values(): void {
		$this->post_create(
			'redraw-login',
			array(
				'capabilities_present' => '1',
				'capabilities'         => array( Capabilities::READ ),
			)
		);

		$html = $this->render();

		$this->assertFalse( username_exists( 'redraw-login' ), 'No user with that login exists.' );
		$this->assertStringContainsString( 'A new agent needs Connect, because creating it makes its first connection. To make an agent that cannot connect, create it and then disable it.', $html );
		$this->assertStringContainsString( 'value="redraw-login"', $html, 'The login is redrawn as posted.' );
		$this->assertStringContainsString( 'value="Desk"', $html, 'The connection name is redrawn as posted.' );
		$this->assertStringNotContainsString( 'Application password:', $html, 'No secret is shown.' );
		preg_match_all( '/<input type="checkbox" name="capabilities\[\]" value="([^"]+)"([^>]*)>/', $html, $matches, PREG_SET_ORDER );
		$boxes = array();
		foreach ( $matches as $match ) {
			$boxes[ $match[1] ] = str_contains( $match[2], 'checked' );
		}
		$this->assertTrue( $boxes[ Capabilities::READ ], 'The box that was ticked stays ticked.' );
		$this->assertFalse( $boxes[ Capabilities::CONNECT ], 'The box that was unticked stays unticked.' );
	}

	/**
	 * The rendered Agents screen, for the current user and the current $_GET.
	 *
	 * @return string
	 */
	private function render(): string {
		ob_start();
		try {
			$this->screen->render();
		} finally {
			$html = (string) ob_get_clean();
		}

		return $html;
	}

	public function test_null_keeps_the_role_defaults_and_stores_no_user_level_capability(): void {
		$result = $this->create( 'null-agent', null );

		$this->assertSame( 'created', $result['code'] );
		$user = get_userdata( $result['user_id'] );
		$this->assertSame( array( Roles::ROLE => true ), $user->caps );
		foreach ( Capabilities::agent_defaults() as $capability => $grant ) {
			$this->assertSame( $grant, Capabilities::user_has( $user, $capability ), $capability );
		}
	}

	public function test_a_listener_that_sets_a_non_offered_steadhand_capability_true_refuses_and_rolls_back(): void {
		$this->handler = $this->handler_without_settings();

		$this->assertNotContains( Capabilities::SETTINGS, $this->handler->offered_capabilities(), 'Precondition: settings is not offered.' );
		$this->on(
			'steadhand_agent_created',
			static function ( $user_id ): void {
				get_userdata( $user_id )->add_cap( Capabilities::SETTINGS, true );
			}
		);

		$result = $this->create( 'menus-listener', array( Capabilities::CONNECT, Capabilities::READ ) );

		$this->assert_refused( $result, 'create_failed', 'menus-listener' );
	}

	public function test_non_offered_non_steadhand_names_are_never_stored_and_non_offered_steadhand_names_are_stored_false(): void {
		$this->handler = $this->handler_without_settings();

		$this->assertNotContains( Capabilities::SETTINGS, $this->handler->offered_capabilities(), 'Precondition: settings is not offered.' );

		$posted = array( Capabilities::CONNECT, Capabilities::READ, 'manage_options', 'administrator', 'edit_posts', Capabilities::SETTINGS, Capabilities::NO_GRANT, Roles::ROLE );
		$result = $this->create( 'refused-names', $posted );

		$this->assertSame( 'created', $result['code'] );
		$user = get_userdata( $result['user_id'] );
		$this->assert_stored( $result['user_id'], array( Capabilities::CONNECT, Capabilities::READ ) );
		foreach ( array( 'manage_options', 'administrator', 'edit_posts', Capabilities::NO_GRANT ) as $name ) {
			$this->assertArrayNotHasKey( $name, $user->caps, $name );
		}
		// Steadhand names that are not offered are stored as an explicit false, never as a grant.
		$this->assertFalse( $user->caps[ Capabilities::SETTINGS ] );
		$this->assertSame( array( Roles::ROLE ), array_values( $user->roles ) );
		$this->assertFalse( user_can( $result['user_id'], 'manage_options' ) );
		$this->assertFalse( user_can( $result['user_id'], 'edit_posts' ) );
	}

	public function test_non_string_entries_are_ignored_without_an_error(): void {
		$posted = array( Capabilities::CONNECT, Capabilities::READ, 5, null, true, 1.5, array( Capabilities::PUBLISH ) );
		$result = $this->create( 'typed-agent', $posted );

		$this->assertSame( 'created', $result['code'] );
		$this->assert_stored( $result['user_id'], array( Capabilities::CONNECT, Capabilities::READ ) );
		$this->assertFalse( Capabilities::user_has( get_userdata( $result['user_id'] ), Capabilities::PUBLISH ) );
	}

	public function test_a_capability_write_that_does_not_stick_refuses_and_no_password_is_made(): void {
		$made = false;
		$this->on(
			'update_user_metadata',
			static fn( $check, $object_id, $meta_key, $meta_value ) => self::writes_publish( $meta_key, $meta_value ) ? false : $check,
			10
		);
		$this->on(
			'wp_create_application_password',
			static function () use ( &$made ): void {
				$made = true;
			}
		);

		$result = $this->create( 'no-grant', array( Capabilities::CONNECT, Capabilities::PUBLISH ) );

		$this->assert_refused( $result, 'create_failed', 'no-grant' );
		$this->assertFalse( $made, 'The password step was never reached.' );
	}

	/**
	 * Proves the outcome (refused, rolled back, no password made), not which of the two catches handled the throw.
	 */
	public function test_a_capability_write_that_throws_refuses_and_leaves_no_user(): void {
		$made = false;
		$this->on(
			'update_user_metadata',
			static function ( $check, $object_id, $meta_key, $meta_value ) {
				if ( self::writes_publish( $meta_key, $meta_value ) ) {
					throw new \RuntimeException( 'Stopped by a test listener.' );
				}

				return $check;
			},
			10
		);
		$this->on(
			'wp_create_application_password',
			static function () use ( &$made ): void {
				$made = true;
			}
		);

		$result = $this->create( 'throws-grant', array( Capabilities::CONNECT, Capabilities::PUBLISH ) );

		$this->assert_refused( $result, 'create_failed', 'throws-grant' );
		$this->assertFalse( $made, 'The password step was never reached, so no password was made.' );
	}

	public function test_a_listener_that_stores_an_int_one_for_a_granted_capability_refuses_and_rolls_back(): void {
		$this->on(
			'steadhand_agent_created',
			static function ( $user_id ): void {
				self::store_raw( $user_id, Capabilities::READ, 1 );
			}
		);

		$result = $this->create( 'int-one', array( Capabilities::CONNECT, Capabilities::READ ) );

		$this->assert_refused( $result, 'create_failed', 'int-one' );
	}

	public function test_a_listener_that_stores_the_string_one_for_a_granted_capability_refuses_and_rolls_back(): void {
		$this->on(
			'steadhand_agent_created',
			static function ( $user_id ): void {
				self::store_raw( $user_id, Capabilities::READ, '1' );
			}
		);

		$result = $this->create( 'string-one', array( Capabilities::CONNECT, Capabilities::READ ) );

		$this->assert_refused( $result, 'create_failed', 'string-one' );
	}

	public function test_a_listener_that_removes_an_explicit_false_refuses_and_rolls_back(): void {
		$revived = null;
		$this->on(
			'steadhand_agent_created',
			static function ( $user_id ) use ( &$revived ): void {
				get_userdata( $user_id )->remove_cap( Capabilities::WRITE_DRAFTS );
				// Without the final check, the role's default would show again.
				$revived = Capabilities::user_has( get_userdata( $user_id ), Capabilities::WRITE_DRAFTS );
			}
		);

		$result = $this->create( 'revive-drafts', array( Capabilities::READ, Capabilities::CONNECT ) );

		$this->assertTrue( $revived, 'Precondition: removing the stored false gives the role default back.' );
		$this->assert_refused( $result, 'create_failed', 'revive-drafts' );
	}

	public function test_a_steadhand_capability_granted_to_the_role_is_not_held_by_a_chosen_agent(): void {
		$role = get_role( Roles::ROLE );
		$this->assertNotNull( $role );
		$role->add_cap( Capabilities::SETTINGS, true );

		try {
			$result = $this->create( 'menus-role', array( Capabilities::CONNECT, Capabilities::READ ) );

			$this->assertSame( 'created', $result['code'] );
			$this->assertFalse( Capabilities::user_has( get_userdata( $result['user_id'] ), Capabilities::SETTINGS ), 'The role grant is overridden by the stored false.' );
			$this->assert_stored( $result['user_id'], array( Capabilities::CONNECT, Capabilities::READ ) );
		} finally {
			$role->remove_cap( Capabilities::SETTINGS );
		}
	}

	public function test_null_stores_only_the_role_key_even_when_the_role_grants_more(): void {
		$role = get_role( Roles::ROLE );
		$this->assertNotNull( $role );
		$role->add_cap( Capabilities::SETTINGS, true );

		try {
			$result = $this->create( 'null-role', null );

			$this->assertSame( 'created', $result['code'] );
			$user = get_userdata( $result['user_id'] );
			$this->assertSame( array( Roles::ROLE => true ), $user->caps, 'Null writes no user-level capability.' );
			$this->assertTrue( Capabilities::user_has( $user, Capabilities::SETTINGS ), 'With null, the role grant applies, as the role defaults say.' );
		} finally {
			$role->remove_cap( Capabilities::SETTINGS );
		}
	}

	public function test_an_agent_whose_connect_is_unticked_later_is_refused_by_the_gate(): void {
		// A new agent cannot be created without Connect (connect_required), so the agent is created connected and then disabled by unticking.
		$result = $this->create( 'no-connect', array( Capabilities::CONNECT, Capabilities::READ, Capabilities::WRITE_DRAFTS ) );
		$this->assertSame( 'created', $result['code'] );
		$user_id = $result['user_id'];
		$this->handler->set_capabilities(
			wp_create_nonce( AgentsHandler::nonce_action( AgentsHandler::ACTION_CAPABILITIES, $user_id ) ),
			$user_id,
			array( Capabilities::READ, Capabilities::WRITE_DRAFTS )
		);

		$this->assertFalse( user_can( $user_id, Capabilities::CONNECT ) );
		$this->assertTrue( user_can( $user_id, Capabilities::READ ), 'Read was checked.' );

		wp_set_current_user( $user_id );
		$this->assertFalse( ( new Plugin() )->server()->can_connect(), 'The transport gate refuses the agent.' );
		$authorized = ( new Pipeline( new SpyLogger() ) )->authorize( new GetSiteInfo(), array() );
		$this->assertInstanceOf( \WP_Error::class, $authorized );
		$this->assertSame( 'steadhand_forbidden', $authorized->get_error_code() );
	}

	public function test_an_agent_created_without_read_cannot_read_through_steadhand(): void {
		$result = $this->create( 'no-read', array( Capabilities::CONNECT ) );
		$this->assertSame( 'created', $result['code'] );
		$user_id = $result['user_id'];

		$this->assertFalse( user_can( $user_id, Capabilities::READ ) );

		wp_set_current_user( $user_id );
		$this->assertTrue( ( new Plugin() )->server()->can_connect(), 'Connect was checked, so the transport gate passes; read is refused per ability.' );

		// GetSiteInfo needs steadhand_read. The agent holds connect, so the refusal here can only come from read.
		$authorized = ( new Pipeline( new SpyLogger() ) )->authorize( new GetSiteInfo(), array() );
		$this->assertInstanceOf( \WP_Error::class, $authorized );
		$this->assertSame( 'steadhand_forbidden', $authorized->get_error_code() );
	}

	public function test_a_listener_that_adds_a_capability_after_the_password_refuses_and_rolls_back(): void {
		$this->on(
			'steadhand_agent_created',
			static function ( $user_id ): void {
				get_userdata( $user_id )->add_cap( 'edit_posts', true );
			}
		);

		$result = $this->create( 'extra-cap', array( Capabilities::CONNECT, Capabilities::READ ) );

		$this->assert_refused( $result, 'extra_role', 'extra-cap' );
	}

	public function test_a_listener_that_grants_an_unchecked_offered_capability_refuses_and_rolls_back(): void {
		$this->on(
			'steadhand_agent_created',
			static function ( $user_id ): void {
				get_userdata( $user_id )->add_cap( Capabilities::PUBLISH, true );
			}
		);

		$result = $this->create( 'flipped-publish', array( Capabilities::CONNECT, Capabilities::READ ) );

		$this->assert_refused( $result, 'create_failed', 'flipped-publish' );
	}

	public function test_the_capabilities_changed_action_does_not_fire_from_create(): void {
		$fired = false;
		$this->on(
			'steadhand_agent_capabilities_changed',
			static function () use ( &$fired ): void {
				$fired = true;
			}
		);

		$result = $this->create( 'quiet-agent', array( Capabilities::CONNECT, Capabilities::READ ) );

		$this->assertSame( 'created', $result['code'] );
		$this->assertFalse( $fired );
	}

	public function test_a_post_with_the_marker_and_no_boxes_is_refused_with_connect_required(): void {
		$made = false;
		$this->on(
			'wp_create_application_password',
			static function () use ( &$made ): void {
				$made = true;
			}
		);

		$this->post_create( 'post-none', array( 'capabilities_present' => '1' ) );

		$this->assertFalse( username_exists( 'post-none' ), 'No user with that login exists.' );
		$this->assertFalse( $made, 'No password was made.' );
		$this->assertStringContainsString( 'A new agent needs Connect', $this->render() );
	}

	public function test_a_post_with_the_marker_and_boxes_grants_exactly_those_boxes(): void {
		$this->post_create(
			'post-some',
			array(
				'capabilities_present' => '1',
				'capabilities'         => array( Capabilities::CONNECT, Capabilities::READ, 'manage_options' ),
			)
		);

		$user = get_user_by( 'login', 'post-some' );
		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assert_stored( $user->ID, array( Capabilities::CONNECT, Capabilities::READ ) );
	}

	public function test_a_post_without_the_marker_gets_the_role_defaults_whatever_is_posted(): void {
		$this->post_create( 'post-old', array( 'capabilities' => array( Capabilities::PUBLISH ) ) );

		$user = get_user_by( 'login', 'post-old' );
		$this->assertInstanceOf( \WP_User::class, $user );
		$this->assertSame( array( Roles::ROLE => true ), $user->caps );
		$this->assertTrue( Capabilities::user_has( $user, Capabilities::CONNECT ) );
		$this->assertFalse( Capabilities::user_has( $user, Capabilities::PUBLISH ) );
	}
}
