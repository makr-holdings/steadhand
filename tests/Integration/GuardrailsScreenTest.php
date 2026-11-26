<?php
/**
 * The Guardrails screen: who may see it, the always-on table and its limits, the presets form, the rules other plugins added, the
 * fixed notices, the slot and the menu.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Admin\Admin;
use Steadhand\Admin\AgentsHandler;
use Steadhand\Admin\AgentsScreen;
use Steadhand\Admin\GuardrailsHandler;
use Steadhand\Admin\GuardrailsScreen;
use Steadhand\Admin\LogScreen;
use Steadhand\Guardrails\Engine;
use Steadhand\Guardrails\Presets;
use Steadhand\Guardrails\Registry as GuardrailsRegistry;
use Steadhand\Guardrails\Result;
use Steadhand\Log\DbLogger;
use Steadhand\Plugin;
use Steadhand\Roles;

/**
 * Negative tests first: a viewer who is not an administrator without an agent role sees nothing, and the screen prints nothing from
 * the query string but a fixed notice.
 */
final class GuardrailsScreenTest extends TestCase {

	/**
	 * Filters and actions this test added, removed in tearDown.
	 *
	 * @var array<int, array{0: string, 1: callable, 2: int}>
	 */
	private array $added = array();

	/**
	 * The screen under test, built from the engine the plugin builds.
	 *
	 * @var GuardrailsScreen
	 */
	private GuardrailsScreen $screen;

	protected function setUp(): void {
		parent::setUp();

		$thrower = static function ( $message ): void {
			throw new \WPDieException( (string) $message ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test double; the message is never printed.
		};
		$this->on( 'wp_die_handler', static fn() => $thrower, PHP_INT_MAX );

		$this->screen = new GuardrailsScreen( ( new Plugin() )->engine() );

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

	/**
	 * Renders the screen as the given user, and returns its HTML, or the wp_die message when the viewer is refused.
	 *
	 * @param int $user The user ID, or 0 for a visitor who is not logged in.
	 * @return array{died: ?string, html: string}
	 */
	private function render_as( int $user ): array {
		wp_set_current_user( $user );

		ob_start();
		try {
			$this->screen->render();
		} catch ( \WPDieException $error ) {
			ob_end_clean();

			return array(
				'died' => $error->getMessage(),
				'html' => '',
			);
		}

		return array(
			'died' => null,
			'html' => (string) ob_get_clean(),
		);
	}

	private function admin(): int {
		return self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	public function test_a_visitor_who_is_not_logged_in_is_refused_and_sees_nothing(): void {
		$result = $this->render_as( 0 );

		$this->assertNotNull( $result['died'] );
		$this->assertSame( '', $result['html'] );
	}

	public function test_an_editor_and_a_subscriber_are_refused_and_see_nothing(): void {
		foreach ( array( 'editor', 'subscriber' ) as $role ) {
			$user   = self::factory()->user->create( array( 'role' => $role ) );
			$result = $this->render_as( $user );

			$this->assertNotNull( $result['died'], "A $role must be refused." );
			$this->assertStringNotContainsString( 'Presets', $result['html'] );
		}
	}

	/**
	 * A dedicated agent is refused: it holds no manage_options, and the agent-role rule refuses it in any case.
	 */
	public function test_a_dedicated_agent_is_refused_and_sees_nothing(): void {
		$agent  = self::factory()->user->create( array( 'role' => Roles::ROLE ) );
		$result = $this->render_as( $agent );

		$this->assertNotNull( $result['died'] );
		$this->assertSame( '', $result['html'] );
	}

	/**
	 * An administrator who also holds the agent role is refused, as on the Agents screen.
	 */
	public function test_an_administrator_who_also_holds_the_agent_role_is_refused(): void {
		$user = new \WP_User( $this->admin() );
		$user->add_role( Roles::ROLE );

		$result = $this->render_as( $user->ID );

		$this->assertNotNull( $result['died'] );
		$this->assertSame( '', $result['html'] );
	}

	public function test_an_administrator_sees_the_three_sections_and_the_presets_form(): void {
		$result = $this->render_as( $this->admin() );

		$this->assertNull( $result['died'] );
		$html = $result['html'];
		$this->assertStringContainsString( 'Steadhand guardrails', $html );
		$this->assertStringContainsString( 'Always on', $html );
		$this->assertStringContainsString( 'Presets', $html );
		$this->assertStringContainsString( 'Rules added by other plugins', $html );
		$this->assertStringContainsString( 'name="action" value="steadhand_save_presets"', $html );
		$this->assertStringContainsString( 'name="presets_present"', $html );
		$this->assertStringContainsString( 'name="presets[]" value="rank-math-options"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		$this->assertStringContainsString( 'admin-post.php', $html );
	}

	/**
	 * The hard-coded option blocks line is always shown.
	 */
	public function test_the_always_on_section_says_the_hard_coded_blocks_cannot_be_changed(): void {
		$html = $this->render_as( $this->admin() )['html'];

		$this->assertStringContainsString( 'The hard-coded option blocks cannot be changed here or by any filter', $html );
	}

	/**
	 * Every built-in rule the engine runs has a label on the screen, and no label is left for a rule that does not exist.
	 */
	public function test_every_built_in_rule_id_has_a_label(): void {
		$ids = array_map(
			static fn( $rule ): string => $rule->id(),
			( new Plugin() )->engine()->built_in_rules()
		);

		$this->assertNotEmpty( $ids );
		$labels = array_keys( GuardrailsScreen::labels() );
		sort( $ids );
		sort( $labels );

		$this->assertSame( $ids, $labels, 'Every built-in rule needs a label, and every label needs a built-in rule.' );
	}

	/**
	 * A built-in rule with no label is shown by its ID and a generic text, rather than hidden.
	 */
	public function test_a_built_in_rule_without_a_label_is_shown_by_its_id(): void {
		$engine = new Engine(
			array( new FakeRule( 'steadhand/mystery', static fn(): Result => Result::allow() ) ),
			array()
		);

		wp_set_current_user( $this->admin() );
		ob_start();
		( new GuardrailsScreen( $engine ) )->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'steadhand/mystery', $html );
		$this->assertStringContainsString( 'A built-in rule that this screen does not describe yet.', $html );
	}

	/**
	 * The edit-lock window shown is the one WordPress's filter gives.
	 */
	public function test_the_edit_lock_window_shows_the_value_wordpress_filter_gives(): void {
		$this->on( 'wp_check_post_lock_window', static fn(): int => 60 );

		$this->assertStringContainsString( 'live for 60 seconds', $this->render_as( $this->admin() )['html'] );
	}

	/**
	 * The daily upload cap and the content limits are shown with their constants.
	 */
	public function test_the_upload_cap_and_the_content_limits_are_shown(): void {
		$html = $this->render_as( $this->admin() )['html'];

		$this->assertStringContainsString( '100 uploads for each agent', $html );
		$this->assertStringContainsString( 'Content up to 256 KB', $html );
		$this->assertStringContainsString( '20 levels of nesting', $html );
	}

	/**
	 * The protected-options row lists the names on the allowlist, read through the same function the rule uses.
	 */
	public function test_the_protected_options_row_lists_the_allowlist(): void {
		$html = $this->render_as( $this->admin() )['html'];

		$this->assertStringContainsString( 'Options that may be changed, as the allowlist stands for this request: blogname, blogdescription.', $html );
	}

	/**
	 * Only the fixed result codes are read from the query string. Any other value, and any other parameter, prints nothing.
	 */
	public function test_an_unknown_code_and_an_extra_parameter_print_nothing_from_the_query_string(): void {
		$_GET['guardrails'] = '<script>alert(1)</script>';
		$_GET['extra']      = '<script>alert(2)</script>';
		$_GET['page']       = 'steadhand-guardrails';

		$html = $this->render_as( $this->admin() )['html'];

		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( 'alert(', $html );
		$this->assertStringNotContainsString( 'notice-', $html, 'An unknown code must print no notice.' );
	}

	public function test_each_fixed_code_prints_its_one_notice(): void {
		$_GET['guardrails'] = 'saved';
		$this->assertStringContainsString( 'The presets were saved.', $this->render_as( $this->admin() )['html'] );

		$_GET['guardrails'] = 'forbidden';
		$this->assertStringContainsString( 'The presets were not changed', $this->render_as( $this->admin() )['html'] );

		$_GET['guardrails'] = 'failed';
		$this->assertStringContainsString( 'The presets could not be saved. Nothing was changed.', $this->render_as( $this->admin() )['html'] );
	}

	/**
	 * The checkbox of each preset is checked when the preset is on.
	 */
	public function test_the_checkbox_reflects_the_stored_state(): void {
		Presets::save( array( Presets::RANK_MATH => true ) );
		$this->assertMatchesRegularExpression( '/name="presets\[\]" value="rank-math-options" checked/', $this->render_as( $this->admin() )['html'] );

		Presets::save( array( Presets::RANK_MATH => false ) );
		$this->assertDoesNotMatchRegularExpression( '/value="rank-math-options" checked/', $this->render_as( $this->admin() )['html'] );
	}

	/**
	 * The slot runs once for each render of an administrator's page, and a listener that throws does not break the screen.
	 */
	public function test_the_slot_fires_once_and_a_throwing_listener_does_not_break_the_screen(): void {
		$calls = 0;
		$this->on(
			'steadhand_admin_guardrails',
			static function () use ( &$calls ): void {
				++$calls;
			},
			10,
			0
		);

		$this->render_as( $this->admin() );
		$this->assertSame( 1, $calls, 'The slot must fire once for one render.' );

		remove_all_actions( 'steadhand_admin_guardrails' );
		$this->on(
			'steadhand_admin_guardrails',
			static function (): void {
				throw new \RuntimeException( 'listener failed' );
			},
			10,
			0
		);
		$result = $this->render_as( $this->admin() );

		$this->assertNull( $result['died'] );
		$this->assertStringContainsString( 'Save presets', $result['html'] );
	}

	/**
	 * The slot does not run for a refused viewer.
	 */
	public function test_the_slot_does_not_run_for_a_refused_viewer(): void {
		$calls = 0;
		$this->on(
			'steadhand_admin_guardrails',
			static function () use ( &$calls ): void {
				++$calls;
			},
			10,
			0
		);

		$this->render_as( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 0, $calls );
	}

	/**
	 * On a multisite network the screen shows only the notice: no presets form, no rules table (SPEC D13).
	 */
	public function test_on_a_multisite_network_the_screen_shows_only_the_notice(): void {
		$network = new GuardrailsScreen( ( new Plugin() )->engine(), true );

		wp_set_current_user( $this->admin() );
		ob_start();
		$network->render();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Guardrail presets are not available on multisite networks.', $html );
		$this->assertStringNotContainsString( 'Save presets', $html );
		$this->assertStringNotContainsString( 'Always on', $html );
	}

	/**
	 * Rendering the screen writes nothing: the presets option is not created by a view.
	 */
	public function test_rendering_writes_nothing(): void {
		$this->render_as( $this->admin() );

		$this->assertFalse( get_option( Presets::OPTION ), 'A view must not create the presets option.' );
	}

	/**
	 * The submenu is registered under Steadhand, after Agents, for manage_options; the save handler is hooked to admin-post.
	 */
	public function test_the_menu_puts_guardrails_after_agents_and_the_save_handler_is_hooked(): void {
		wp_set_current_user( $this->admin() );
		$plugin = new Plugin();
		$admin  = new Admin(
			new LogScreen( new DbLogger(), $plugin->registry() ),
			null,
			new AgentsScreen( new AgentsHandler( $plugin->registry() ) ),
			$this->screen,
			new GuardrailsHandler()
		);
		$admin->register();
		$this->assertNotFalse( has_action( 'admin_post_' . GuardrailsHandler::ACTION ) );

		$admin->add_menu();

		$slugs = array();
		foreach ( (array) ( $GLOBALS['submenu'][ LogScreen::SLUG ] ?? array() ) as $item ) {
			if ( isset( $item[2] ) ) {
				$slugs[ $item[2] ] = $item;
			}
		}

		$this->assertArrayHasKey( GuardrailsScreen::SLUG, $slugs );
		$this->assertSame( 'manage_options', $slugs[ GuardrailsScreen::SLUG ][1] );

		$order = array_keys( $slugs );
		$this->assertGreaterThan(
			array_search( AgentsScreen::SLUG, $order, true ),
			array_search( GuardrailsScreen::SLUG, $order, true ),
			'Guardrails must come after Agents under Steadhand.'
		);
	}

	/**
	 * The rate-limit row shows the default and the range from the rule's constants, and the screen does not run the filter for it.
	 */
	public function test_the_rate_limit_row_shows_the_default_and_the_range_and_does_not_run_the_filter(): void {
		$calls = 0;
		$this->on(
			'steadhand_rate_limit_per_minute',
			static function ( int $limit ) use ( &$calls ): int {
				++$calls;

				return $limit;
			},
			10,
			2
		);

		$html = $this->render_as( $this->admin() )['html'];

		$this->assertStringContainsString( 'Default 30 writes per minute for each agent, from 1 to 600.', $html );
		$this->assertSame( 0, $calls, 'The screen must not run steadhand_rate_limit_per_minute.' );
	}

	/**
	 * Rendering the screen runs neither the registration action nor the rate-limit filter: a listener on either is never called.
	 */
	public function test_rendering_runs_neither_the_guardrail_registration_action_nor_the_rate_limit_filter(): void {
		$registrations = 0;
		$rate_limits   = 0;
		$this->on(
			'steadhand_register_guardrails',
			static function () use ( &$registrations ): void {
				++$registrations;
			}
		);
		$this->on(
			'steadhand_rate_limit_per_minute',
			static function ( int $limit ) use ( &$rate_limits ): int {
				++$rate_limits;

				return $limit;
			},
			10,
			2
		);

		$this->render_as( $this->admin() );

		$this->assertSame( 0, $registrations, 'Rendering must not register guardrail rules.' );
		$this->assertSame( 0, $rate_limits, 'Rendering must not run the rate-limit filter.' );
	}

	/**
	 * The section on rules other plugins add is static text: it prints no rule ID, whatever is registered.
	 */
	public function test_the_other_plugins_section_is_static_text_and_prints_no_rule_id(): void {
		$this->on(
			'steadhand_register_guardrails',
			static function ( GuardrailsRegistry $registry ): void {
				$registry->add( new FakeRule( 'acme/safe-rule', static fn(): Result => Result::allow() ) );
			}
		);

		$html = $this->render_as( $this->admin() )['html'];

		$this->assertStringContainsString( 'are not listed here', $html );
		$this->assertStringNotContainsString( 'acme/safe-rule', $html );
	}

	/**
	 * A slot listener that prints and then throws leaves none of its output on the page.
	 */
	public function test_a_slot_listener_that_echoes_then_throws_leaves_none_of_its_output(): void {
		$this->on(
			'steadhand_admin_guardrails',
			static function (): void {
				echo '<p>LEAKED-SLOT-OUTPUT</p>';
				throw new \RuntimeException( 'listener failed' );
			},
			10,
			0
		);

		$result = $this->render_as( $this->admin() );

		$this->assertNull( $result['died'] );
		$this->assertStringNotContainsString( 'LEAKED-SLOT-OUTPUT', $result['html'] );
		$this->assertStringContainsString( 'Save presets', $result['html'] );
	}

	/**
	 * A slot listener that returns normally has its output printed, in place.
	 */
	public function test_a_slot_listener_that_returns_has_its_output_printed(): void {
		$this->on(
			'steadhand_admin_guardrails',
			static function (): void {
				echo '<p>SHOWN-SLOT-OUTPUT</p>';
			},
			10,
			0
		);

		$this->assertStringContainsString( 'SHOWN-SLOT-OUTPUT', $this->render_as( $this->admin() )['html'] );
	}

	/**
	 * A throwing option read on the presets option does not break the screen: the page still renders with the defaults.
	 *
	 * @dataProvider throwing_option_filters
	 *
	 * @param string $hook The option filter that throws.
	 */
	public function test_a_throwing_presets_option_read_does_not_break_the_screen( string $hook ): void {
		$this->on(
			$hook,
			static function (): never {
				throw new \RuntimeException( 'read failed' );
			}
		);

		$result = $this->render_as( $this->admin() );

		$this->assertNull( $result['died'] );
		$this->assertStringContainsString( 'Rank Math options', $result['html'] );
	}

	/**
	 * The option filters that throw on a read of the presets option.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function throwing_option_filters(): array {
		return array(
			'pre_option'    => array( 'pre_option_' . Presets::OPTION ),
			'option_filter' => array( 'option_' . Presets::OPTION ),
		);
	}
}
