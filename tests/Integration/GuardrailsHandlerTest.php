<?php
/**
 * The Guardrails screen's save handler: its refusals, what it reads from the request, what it stores, the hook it fires and the
 * redirect codes.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Admin\GuardrailsHandler;
use Steadhand\Admin\GuardrailsScreen;
use Steadhand\Guardrails\Presets;
use Steadhand\Roles;

/**
 * Negative tests first: a refused request saves nothing, and a request can only ever set the known presets.
 */
final class GuardrailsHandlerTest extends TestCase {

	/**
	 * Filters and actions this test added, removed in tearDown.
	 *
	 * @var array<int, array{0: string, 1: callable, 2: int}>
	 */
	private array $added = array();

	/**
	 * Hook calls the spy listener recorded, as its arguments.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $changed_calls = array();

	protected function setUp(): void {
		parent::setUp();

		// A test double: a refusal throws instead of printing a page, and the test catches it.
		$thrower = static function ( $message ): void {
			throw new \WPDieException( (string) $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double; the message is never printed.
		};
		$this->on( 'wp_die_handler', static fn() => $thrower, PHP_INT_MAX );

		delete_option( Presets::OPTION );
		wp_cache_flush();

		$_POST                     = array();
		$_GET                      = array();
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	protected function tearDown(): void {
		foreach ( $this->added as $added ) {
			remove_filter( $added[0], $added[1], $added[2] );
		}
		$this->added = array();

		$_POST                     = array();
		$_GET                      = array();
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
	 * @param int      $args     Number of arguments.
	 */
	private function on( string $hook, callable $callback, int $priority = 10, int $args = 1 ): void {
		add_filter( $hook, $callback, $priority, $args );
		$this->added[] = array( $hook, $callback, $priority );
	}

	private function admin(): int {
		return self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Sends a POST of the form to the handler and returns the redirect URL, or 'died' when the request is refused with wp_die().
	 *
	 * @param array<string, mixed> $fields The form fields. The nonce is added unless $nonce is given.
	 * @param string|null          $nonce  A nonce to send instead of the right one.
	 * @return string
	 */
	private function post( array $fields, ?string $nonce = null ): string {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array_merge(
			array( '_wpnonce' => $nonce ?? wp_create_nonce( GuardrailsHandler::NONCE_ACTION ) ),
			$fields
		);

		try {
			return ( new GuardrailsHandler() )->process();
		} catch ( \WPDieException ) {
			return 'died';
		}
	}

	/**
	 * The result code in a redirect URL, or '' when there is none.
	 *
	 * @param string $url The redirect URL.
	 */
	private function code_of( string $url ): string {
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );

		return is_string( $query[ GuardrailsHandler::QUERY_KEY ] ?? null ) ? $query[ GuardrailsHandler::QUERY_KEY ] : '';
	}

	/**
	 * The form fields of a save with the marker and the given presets checked.
	 *
	 * @param string[] $checked The presets checked.
	 * @return array<string, mixed>
	 */
	private function form( array $checked ): array {
		return array(
			GuardrailsHandler::MARKER => '1',
			GuardrailsHandler::FIELD  => $checked,
		);
	}

	public function test_a_get_is_refused_and_nothing_is_saved(): void {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_POST                     = $this->form( array() );
		$_POST['_wpnonce']         = wp_create_nonce( GuardrailsHandler::NONCE_ACTION );
		wp_set_current_user( $this->admin() );

		try {
			( new GuardrailsHandler() )->process();
			$this->fail( 'A GET must be refused.' );
		} catch ( \WPDieException ) {
			$this->assertFalse( get_option( Presets::OPTION ), 'A GET must not save anything.' );
		}
	}

	public function test_a_missing_or_wrong_nonce_is_refused_and_nothing_is_saved(): void {
		wp_set_current_user( $this->admin() );

		$this->assertSame( 'died', $this->post( $this->form( array() ), '' ) );
		$this->assertSame( 'died', $this->post( $this->form( array() ), 'not-a-nonce' ) );

		// A nonce made for another action is not this form's.
		$this->assertSame( 'died', $this->post( $this->form( array() ), wp_create_nonce( 'steadhand_undo_entry_1' ) ) );

		$this->assertFalse( get_option( Presets::OPTION ), 'A refused request must save nothing.' );
	}

	public function test_an_editor_or_a_subscriber_is_refused_and_nothing_is_saved(): void {
		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );

			$this->assertSame( 'forbidden', $this->code_of( $this->post( $this->form( array() ) ) ), "A $role must be refused." );
		}

		$this->assertFalse( get_option( Presets::OPTION ) );
	}

	public function test_an_agent_is_refused_and_nothing_is_saved(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::ROLE ) ) );

		$this->assertSame( 'forbidden', $this->code_of( $this->post( $this->form( array() ) ) ) );
		$this->assertFalse( get_option( Presets::OPTION ) );
	}

	/**
	 * An administrator who also holds the agent role is refused, as on the Agents screen: an agent is not an administrator for this.
	 */
	public function test_an_administrator_who_also_holds_the_agent_role_is_refused(): void {
		$user = new \WP_User( $this->admin() );
		$user->add_role( Roles::ROLE );
		wp_set_current_user( $user->ID );

		$this->assertSame( 'forbidden', $this->code_of( $this->post( $this->form( array( Presets::RANK_MATH ) ) ) ) );
		$this->assertFalse( get_option( Presets::OPTION ) );
	}

	/**
	 * On a multisite network every save is refused, as the Agents screen refuses every action there (SPEC D13).
	 */
	public function test_on_a_multisite_network_every_save_is_refused(): void {
		wp_set_current_user( $this->admin() );
		$network = new GuardrailsHandler( true );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array_merge( array( '_wpnonce' => wp_create_nonce( GuardrailsHandler::NONCE_ACTION ) ), $this->form( array() ) );
		$url                       = $network->process();

		$this->assertSame( 'forbidden', $this->code_of( $url ) );
		$this->assertFalse( get_option( Presets::OPTION ) );
	}

	/**
	 * A form without the marker is not this screen's form, so nothing is saved, and the result says so.
	 */
	public function test_a_form_without_the_marker_saves_nothing(): void {
		wp_set_current_user( $this->admin() );

		$this->assertSame( 'failed', $this->code_of( $this->post( array( GuardrailsHandler::FIELD => array() ) ) ) );
		$this->assertFalse( get_option( Presets::OPTION ) );
	}

	/**
	 * Only the known preset IDs are read. Unknown IDs, values that are not strings, and nested arrays are ignored, and the presets
	 * are stored as the known IDs that were checked.
	 */
	public function test_unknown_ids_and_non_string_values_are_ignored(): void {
		wp_set_current_user( $this->admin() );

		$url = $this->post(
			$this->form(
				array(
					'no-such-preset',
					array( Presets::RANK_MATH ),
					5,
					true,
					'<script>alert(1)</script>',
					Presets::RANK_MATH,
				)
			)
		);

		$this->assertSame( 'saved', $this->code_of( $url ) );
		$this->assertSame( array( Presets::RANK_MATH => true ), get_option( Presets::OPTION ) );
	}

	public function test_an_unknown_value_in_the_form_turns_nothing_on(): void {
		wp_set_current_user( $this->admin() );

		// A checkbox whose value is not a known ID changes nothing, and the known preset is off because it was not checked.
		$this->post( $this->form( array( 'no-such-preset' ) ) );

		$this->assertSame( array( Presets::RANK_MATH => false ), get_option( Presets::OPTION ) );
	}

	/**
	 * No box checked means every preset is off. The marker is what makes that a choice and not a missing form.
	 */
	public function test_no_box_checked_means_every_preset_is_off(): void {
		wp_set_current_user( $this->admin() );
		Presets::save( array( Presets::RANK_MATH => true ) );

		$url = $this->post( $this->form( array() ) );

		$this->assertSame( 'saved', $this->code_of( $url ) );
		$this->assertFalse( Presets::enabled( Presets::RANK_MATH ) );
		$this->assertSame( array( Presets::RANK_MATH => false ), get_option( Presets::OPTION ) );
	}

	/**
	 * The stored state is exactly a boolean for every known ID, and the redirect carries the saved code.
	 */
	public function test_the_saved_state_holds_exactly_a_boolean_for_every_known_id_and_the_redirect_says_saved(): void {
		wp_set_current_user( $this->admin() );

		$url = $this->post( $this->form( array( Presets::RANK_MATH ) ) );

		$this->assertSame( 'saved', $this->code_of( $url ) );
		$this->assertStringContainsString( 'page=' . GuardrailsScreen::SLUG, $url );

		$stored = get_option( Presets::OPTION );
		$this->assertSame( Presets::ids(), array_keys( $stored ) );
		foreach ( $stored as $value ) {
			$this->assertIsBool( $value );
		}
	}

	public function test_the_changed_hook_fires_once_with_the_states_when_a_value_changes(): void {
		wp_set_current_user( $this->admin() );
		$this->on(
			'steadhand_presets_changed',
			function ( $current, $previous, $user_id ): void {
				$this->changed_calls[] = array( $current, $previous, $user_id );
			},
			10,
			3
		);

		$this->post( $this->form( array() ) );

		$this->assertCount( 1, $this->changed_calls );
		$this->assertSame( array( Presets::RANK_MATH => false ), $this->changed_calls[0][0] );
		$this->assertSame( array( Presets::RANK_MATH => true ), $this->changed_calls[0][1] );
		$this->assertSame( get_current_user_id(), $this->changed_calls[0][2] );
	}

	/**
	 * A save that changes nothing fires nothing, and so does a save that is refused.
	 */
	public function test_the_changed_hook_does_not_fire_when_nothing_changed_or_the_save_was_refused(): void {
		wp_set_current_user( $this->admin() );
		Presets::save( array( Presets::RANK_MATH => true ) );
		$this->on(
			'steadhand_presets_changed',
			function (): void {
				$this->changed_calls[] = array();
			},
			10,
			3
		);

		$this->post( $this->form( array( Presets::RANK_MATH ) ) );
		$this->assertSame( array(), $this->changed_calls, 'An unchanged save must fire nothing.' );

		$this->post( array( 'no-marker' => '1' ) );
		$this->assertSame( array(), $this->changed_calls, 'A refused request must fire nothing.' );

		$this->post( $this->form( array() ), 'not-a-nonce' );
		$this->assertSame( array(), $this->changed_calls, 'A request with the wrong nonce must fire nothing.' );
	}

	/**
	 * A listener that throws does not undo the save or break the redirect.
	 */
	public function test_a_throwing_changed_listener_does_not_undo_the_save_or_break_the_redirect(): void {
		wp_set_current_user( $this->admin() );
		$this->on(
			'steadhand_presets_changed',
			static function (): void {
				throw new \RuntimeException( 'listener failed' );
			},
			10,
			3
		);

		$url = $this->post( $this->form( array() ) );

		$this->assertSame( 'saved', $this->code_of( $url ) );
		$this->assertFalse( Presets::enabled( Presets::RANK_MATH ), 'The save must stand when a listener throws.' );
	}

	/**
	 * A save that does not read back is reported as failed, nothing is announced, and the stored state stays as it was.
	 */
	public function test_a_save_that_does_not_read_back_is_reported_as_failed_and_announces_nothing(): void {
		wp_set_current_user( $this->admin() );
		Presets::save( array( Presets::RANK_MATH => true ) );
		$this->on(
			'pre_update_option_' . Presets::OPTION,
			static fn( $value, $old ): mixed => $old,
			10,
			2
		);
		$this->on(
			'steadhand_presets_changed',
			function (): void {
				$this->changed_calls[] = array();
			},
			10,
			3
		);

		$url = $this->post( $this->form( array() ) );

		$this->assertSame( 'failed', $this->code_of( $url ) );
		$this->assertTrue( Presets::enabled( Presets::RANK_MATH ) );
		$this->assertSame( array(), $this->changed_calls );
	}

	/**
	 * The handler's redirect carries only one fixed code and the screen's page, and it is the same for every outcome that has a code.
	 */
	public function test_every_redirect_carries_one_fixed_code_and_the_screen_page(): void {
		wp_set_current_user( $this->admin() );

		foreach ( array( $this->form( array() ), array( 'x' => 1 ), array() ) as $fields ) {
			$url = $this->post( $fields );

			$this->assertContains( $this->code_of( $url ), GuardrailsHandler::CODES, 'The code must be one of the fixed codes.' );
			parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
			$this->assertSame( GuardrailsScreen::SLUG, $query['page'] );
			$this->assertSame( array( 'page', GuardrailsHandler::QUERY_KEY ), array_keys( $query ) );
		}
	}

	public function test_the_action_and_the_codes_are_the_fixed_ones(): void {
		$this->assertSame( 'steadhand_save_presets', GuardrailsHandler::ACTION );
		$this->assertSame( 'steadhand_save_presets', GuardrailsHandler::NONCE_ACTION );
		$this->assertSame( array( 'saved', 'forbidden', 'failed' ), GuardrailsHandler::CODES );
	}
}
