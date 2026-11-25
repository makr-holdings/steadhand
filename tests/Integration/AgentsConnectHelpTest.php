<?php
/**
 * The connection help of the Agents screen: the "Connect a client" section of the detail view, and the finished command and header in the
 * one-time panel. The detail view never shows a password. The panel shows the finished values for one response only.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Admin\AgentsHandler;
use Steadhand\Admin\AgentsScreen;
use Steadhand\Plugin;
use Steadhand\Roles;

/**
 * Negative tests first: the detail view must show no password, no real header value and no command for an unsafe address, and a GET must
 * never show the one-time values again.
 */
final class AgentsConnectHelpTest extends TestCase {

	/**
	 * Filters and actions this test added, removed in tearDown.
	 *
	 * @var array<int, array{0: string, 1: callable, 2: int}>
	 */
	private array $added = array();

	/**
	 * The screen under test.
	 *
	 * @var AgentsScreen
	 */
	private AgentsScreen $screen;

	/**
	 * The handler the screen uses.
	 *
	 * @var AgentsHandler
	 */
	private AgentsHandler $handler;

	/**
	 * The endpoint the rest_url filter returns for the Steadhand route, or null for WordPress's own URL.
	 *
	 * @var string|null
	 */
	private ?string $endpoint_override = null;

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

		// A plain endpoint, so the command is not quoted, unless a test sets its own address.
		$this->endpoint_override = 'https://example.org/wp-json/steadhand/mcp';
		$this->on(
			'rest_url',
			fn( $url ) => null !== $this->endpoint_override && str_contains( (string) $url, 'steadhand/mcp' ) ? $this->endpoint_override : $url,
			10
		);

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
	 */
	private function on( string $hook, callable $callback, int $priority = 10 ): void {
		add_filter( $hook, $callback, $priority, 10 );
		$this->added[] = array( $hook, $callback, $priority );
	}

	/**
	 * A dedicated agent with the given number of Application Passwords.
	 *
	 * @param string $login     Login name, or '' for a generated one.
	 * @param int    $passwords Number of Application Passwords to create.
	 * @return int The user ID.
	 */
	private function agent( string $login = '', int $passwords = 0 ): int {
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

	private function admin(): int {
		return self::factory()->user->create( array( 'role' => 'administrator' ) );
	}

	/**
	 * Sends a POST of one action to the screen, as the browser would. Returns what process_post() returns, or 'died' when refused.
	 *
	 * @param string              $action One of the AgentsHandler::ACTION_* values.
	 * @param array<string,mixed> $fields The other form fields.
	 * @return mixed
	 */
	private function post( string $action, array $fields ): mixed {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array_merge( array( AgentsHandler::FIELD => $action ), $fields );

		try {
			return $this->screen->process_post();
		} catch ( \WPDieException ) {
			return 'died';
		}
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
	 * The rendered page as the current user, for the current $_GET.
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

	/**
	 * The password shown in the one-time panel of a page, or '' when there is none.
	 *
	 * @param string $html The page.
	 * @return string
	 */
	private function password_on_screen( string $html ): string {
		return 1 === preg_match( '/Application password: <code>([^<]+)<\/code>/', $html, $match ) ? $match[1] : '';
	}

	/**
	 * Every Base64 value that follows "Authorization: Basic " in a page. The placeholder BASE64_OF_USERNAME_AND_PASSWORD is not matched,
	 * because the underscore after its first six characters is not a Base64 character.
	 *
	 * @param string $html The page.
	 * @return array<int, string>
	 */
	private function real_header_values( string $html ): array {
		preg_match_all( '/Authorization: Basic ([A-Za-z0-9+\/]+={0,2})(?![A-Za-z0-9+\/=_])/', $html, $matches );

		return $matches[1];
	}

	/**
	 * The command as the page shows it, escaped the way the page escapes it, for a Base64 value.
	 *
	 * @param string $value The value, or its placeholder.
	 * @return string
	 */
	private function escaped_command( string $value ): string {
		return esc_html( 'claude mcp add --transport http steadhand ' . rest_url( 'steadhand/mcp' ) . ' --header "Authorization: Basic ' . $value . '"' );
	}

	public function test_the_detail_view_has_the_facts_the_placeholder_command_the_check_sentence_and_the_warning(): void {
		$agent = $this->agent( 'help-facts' );
		$this->as_admin_viewing( $agent );

		$html = $this->render();

		$this->assertStringContainsString( 'Connect a client', $html );
		$this->assertStringContainsString( esc_html( rest_url( 'steadhand/mcp' ) ), $html, 'The endpoint.' );
		$this->assertStringContainsString( 'Streamable HTTP', $html );
		$this->assertStringContainsString( esc_html( 'HTTP Basic: the agent\'s username and one of its Application Passwords' ), $html );
		$this->assertStringContainsString( '<code>help-facts</code>', $html, 'The username.' );
		$this->assertStringContainsString( '<pre><code>' . $this->escaped_command( 'BASE64_OF_USERNAME_AND_PASSWORD' ) . '</code></pre>', $html );
		$this->assertStringContainsString( esc_html( 'Ask the client to call the tool' ), $html );
		$this->assertStringContainsString( '<code>steadhand-get-site-info</code>', $html );
		$this->assertStringContainsString( 'notice notice-warning inline', $html );
		$this->assertStringContainsString( esc_html( 'The command and the header contain the agent\'s password in a form anyone can decode.' ), $html );
		$this->assertStringContainsString( esc_html( 'A connection\'s password is shown once, when the connection is added.' ), $html );
	}

	public function test_the_detail_view_never_shows_a_password_or_a_real_header_value(): void {
		$this->as_user( $this->admin() );
		$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'detail-secret', 'Secret test' );
		$this->assertSame( 'created', $result['code'] );
		$login    = $result['secret']['username'];
		$password = $result['secret']['password'];
		$stripped = preg_replace( '/\s+/', '', $password ) ?? '';
		$created  = (int) $result['user_id'];

		// A later GET of the detail view: a new screen, as a new request would have.
		$this->screen              = new AgentsScreen( $this->handler );
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$this->as_admin_viewing( $created );
		$html = $this->render();

		$this->assertStringContainsString( 'Connect a client', $html );
		$this->assertStringNotContainsString( $password, $html );
		$this->assertStringNotContainsString( $stripped, $html );
		$this->assertStringNotContainsString( base64_encode( $login . ':' . $password ), $html ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test: the value the detail view must not show.
		$this->assertSame( array(), $this->real_header_values( $html ), 'No Authorization header with a real value.' );
	}

	public function test_the_one_time_panel_after_create_shows_the_finished_command_and_header_that_decode_to_the_credentials(): void {
		$this->as_user( $this->admin() );
		$this->assertNull(
			$this->post(
				AgentsHandler::ACTION_CREATE,
				array(
					'_wpnonce' => $this->nonce( AgentsHandler::ACTION_CREATE ),
					'username' => 'panel-agent',
					'label'    => 'Panel test',
				)
			)
		);

		$html     = $this->render();
		$password = $this->password_on_screen( $html );
		$this->assertNotSame( '', $password );

		$expected = base64_encode( 'panel-agent:' . $password ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test: the expected header value.
		$this->assertStringContainsString( 'Connect Claude Code', $html );
		$this->assertStringContainsString( '<pre><code>' . $this->escaped_command( $expected ) . '</code></pre>', $html );
		$this->assertStringContainsString( 'Authorization: Basic ' . $expected, $html, 'The header line under Other MCP clients.' );
		$this->assertStringContainsString( 'This command and this header are shown once, with the password. They cannot be shown again.', $html );

		$values = $this->real_header_values( $html );
		$this->assertCount( 2, $values, 'The command and the header line.' );
		foreach ( $values as $value ) {
			$this->assertSame( $expected, $value );
			$this->assertSame( 'panel-agent:' . $password, base64_decode( $value, true ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Test: decodes the value the panel shows.
		}
	}

	public function test_the_one_time_panel_after_add_password_shows_the_finished_command_and_header(): void {
		$agent = $this->agent( 'panel-second' );
		$this->as_user( $this->admin() );
		$this->assertNull(
			$this->post(
				AgentsHandler::ACTION_ADD_PASSWORD,
				array(
					'user_id'  => (string) $agent,
					'_wpnonce' => $this->nonce( AgentsHandler::ACTION_ADD_PASSWORD, $agent ),
					'label'    => 'Second',
				)
			)
		);

		$html     = $this->render();
		$password = $this->password_on_screen( $html );
		$this->assertNotSame( '', $password );

		$expected = base64_encode( 'panel-second:' . $password ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test: the expected header value.
		$this->assertStringContainsString( '<pre><code>' . $this->escaped_command( $expected ) . '</code></pre>', $html );
		$values = $this->real_header_values( $html );
		$this->assertCount( 2, $values );
		foreach ( $values as $value ) {
			$this->assertSame( $expected, $value );
		}
	}

	public function test_the_list_and_the_add_view_show_no_command_and_no_authorization_text(): void {
		$this->agent( 'help-list' );
		$this->as_user( $this->admin() );

		$list = $this->render();
		$this->assertStringNotContainsString( 'claude mcp add', $list );
		$this->assertStringNotContainsString( 'Authorization:', $list );
		$this->assertStringNotContainsString( 'Connect a client', $list );

		$_GET = array( 'view' => 'new' );
		$add  = $this->render();
		$this->assertStringContainsString( 'Create an agent', $add );
		$this->assertStringNotContainsString( 'claude mcp add', $add );
		$this->assertStringNotContainsString( 'Authorization:', $add );
	}

	public function test_a_disabled_agent_is_told_it_cannot_connect_until_it_is_enabled(): void {
		$agent = $this->agent( 'help-disabled', 1 );
		$this->as_user( $this->admin() );
		$this->handler->disable( $this->nonce( AgentsHandler::ACTION_DISABLE, $agent ), $agent );
		$this->as_admin_viewing( $agent );

		$html = $this->render();

		$this->assertStringContainsString( 'This agent is disabled, so it cannot connect until it is enabled.', $html );
		$this->assertStringContainsString( 'Connect a client', $html );
	}

	public function test_an_enabled_agent_has_no_disabled_sentence_in_the_connect_section(): void {
		$agent = $this->agent( 'help-enabled' );
		$this->as_admin_viewing( $agent );

		$this->assertStringNotContainsString( 'cannot connect until it is enabled', $this->render() );
	}

	public function test_an_unsafe_login_gets_no_command_in_the_detail_view_or_the_one_time_panel(): void {
		// WordPress strips $, double quotes, backticks and backslashes from a login it creates, so the login is written directly, as a login
		// created elsewhere could be.
		global $wpdb;
		$agent = $this->agent( 'unsafe-seed' );
		$login = 'unsafe$agent';
		$wpdb->update( $wpdb->users, array( 'user_login' => $login ), array( 'ID' => $agent ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture: a login WordPress would not write.
		clean_user_cache( $agent );

		$this->as_admin_viewing( $agent );
		$detail = $this->render();
		$this->assertStringContainsString( 'The command cannot be shown safely for this username or address', $detail );
		$this->assertStringNotContainsString( 'claude mcp add', $detail );
		$this->assertSame( array(), $this->real_header_values( $detail ) );

		$this->as_user( $this->admin() );
		$this->assertNull(
			$this->post(
				AgentsHandler::ACTION_ADD_PASSWORD,
				array(
					'user_id'  => (string) $agent,
					'_wpnonce' => $this->nonce( AgentsHandler::ACTION_ADD_PASSWORD, $agent ),
					'label'    => 'Unsafe',
				)
			)
		);
		$panel    = $this->render();
		$password = $this->password_on_screen( $panel );
		$this->assertNotSame( '', $password );
		$this->assertStringContainsString( 'The command cannot be shown safely for this username or address', $panel );
		$this->assertStringNotContainsString( 'claude mcp add', $panel );
		$this->assertSame( array( base64_encode( $login . ':' . $password ) ), $this->real_header_values( $panel ), 'Only the header value.' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test: the expected header value.
	}

	public function test_the_safety_decision_refuses_each_unsafe_character_in_either_value_and_allows_plain_ones(): void {
		$endpoint = 'https://example.org/wp-json/steadhand/mcp';

		$this->assertTrue( AgentsScreen::command_is_safe( 'agent-1', $endpoint ) );
		$this->assertTrue( AgentsScreen::command_is_safe( 'Claude Desktop', $endpoint ), 'A space is not in the unsafe set: the username is only ever encoded.' );

		foreach ( array( '"', '`', '$', '\\', "\n", "\t", "\x00", "\x7F" ) as $bad ) {
			$this->assertFalse( AgentsScreen::command_is_safe( 'agent' . $bad, $endpoint ), 'Username with ' . bin2hex( $bad ) );
			$this->assertFalse( AgentsScreen::command_is_safe( 'agent', $endpoint . $bad ), 'Endpoint with ' . bin2hex( $bad ) );
		}
	}

	public function test_a_plain_endpoint_is_not_quoted_and_one_with_other_characters_is(): void {
		$agent = $this->agent( 'help-quote' );
		$this->as_admin_viewing( $agent );

		$plain = $this->render();
		$this->assertStringContainsString( 'steadhand https://example.org/wp-json/steadhand/mcp --header', $plain );

		$this->endpoint_override = 'https://example.org/wp-json/steadhand/mcp?x=1';
		$quoted                  = $this->render();
		$this->assertStringContainsString( 'steadhand &quot;https://example.org/wp-json/steadhand/mcp?x=1&quot; --header', $quoted );
	}

	public function test_an_endpoint_with_angle_brackets_is_escaped_in_the_page(): void {
		$agent                   = $this->agent( 'help-escape-angle' );
		$this->endpoint_override = 'https://example.org/<b>x</b>/steadhand/mcp';
		$this->as_admin_viewing( $agent );

		$html = $this->render();

		$this->assertStringNotContainsString( '<b>x</b>', $html );
		$this->assertStringContainsString( esc_html( 'https://example.org/<b>x</b>/steadhand/mcp' ), $html );
		// The address has characters outside the plain set, so it is wrapped in double quotes.
		$this->assertStringContainsString( esc_html( 'claude mcp add --transport http steadhand "https://example.org/<b>x</b>/steadhand/mcp" --header "Authorization: Basic BASE64_OF_USERNAME_AND_PASSWORD"' ), $html );
	}

	public function test_an_endpoint_with_a_double_quote_gets_no_command_and_is_escaped_in_the_facts(): void {
		$agent                   = $this->agent( 'help-escape-quote' );
		$this->endpoint_override = 'https://example.org/"<i>/steadhand/mcp';
		$this->as_admin_viewing( $agent );

		$html = $this->render();

		$this->assertStringNotContainsString( '<i>', $html );
		$this->assertStringContainsString( esc_html( 'https://example.org/"<i>/steadhand/mcp' ), $html );
		$this->assertStringNotContainsString( 'claude mcp add', $html );
		$this->assertStringContainsString( 'The command cannot be shown safely for this username or address', $html );
	}

	public function test_a_get_with_the_success_code_in_the_query_string_shows_no_password_command_or_header(): void {
		$this->as_user( $this->admin() );
		$result = $this->handler->create( $this->nonce( AgentsHandler::ACTION_CREATE ), 'reload-agent', 'Reload test' );
		$this->assertSame( 'created', $result['code'] );
		$password = $result['secret']['password'];
		$login    = $result['secret']['username'];
		$created  = (int) $result['user_id'];

		$this->screen = new AgentsScreen( $this->handler );
		$this->as_user( $this->admin() );

		$detail = $this->render_with_query(
			array(
				'agent'  => (string) $created,
				'agents' => 'created',
			)
		);

		$add = $this->render_with_query(
			array(
				'view'   => 'new',
				'agents' => 'password_added',
			)
		);

		foreach ( array( $detail, $add ) as $html ) {
			$this->assertStringNotContainsString( $password, $html );
			$this->assertStringNotContainsString( base64_encode( $login . ':' . $password ), $html ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Test: the value a reload must not show.
			$this->assertSame( array(), $this->real_header_values( $html ) );
			$this->assertStringNotContainsString( 'shown once, with the password', $html );
		}

		// The detail view keeps its placeholder command, which has no value. The add view has no command at all.
		$this->assertStringContainsString( 'BASE64_OF_USERNAME_AND_PASSWORD', $detail );
		$this->assertStringNotContainsString( 'claude mcp add', $add );
	}

	public function test_the_row_action_fires_once_and_after_the_connect_section_and_the_disable_section(): void {
		$agent = $this->agent( 'help-hook', 1 );
		$calls = 0;
		$this->on(
			'steadhand_admin_agent_row',
			static function () use ( &$calls ): void {
				++$calls;
				echo '<!--row-hook-->';
			}
		);
		$this->as_admin_viewing( $agent );

		$html = $this->render();

		$this->assertSame( 1, $calls, 'The action fires once.' );
		$this->assertSame( 1, substr_count( $html, '<!--row-hook-->' ) );
		$connect  = strpos( $html, 'Connect a client' );
		$disable  = strpos( $html, 'Disable or delete' );
		$row_hook = strpos( $html, '<!--row-hook-->' );
		$this->assertIsInt( $connect );
		$this->assertIsInt( $disable );
		$this->assertIsInt( $row_hook );
		$this->assertGreaterThan( $connect, $disable, 'The disable section follows the connect section.' );
		$this->assertGreaterThan( $disable, $row_hook, 'The row action stays last.' );
	}

	/**
	 * Renders the page for a GET with the given query values, as a new request would.
	 *
	 * @param array<string, string> $query The query values, besides the page.
	 * @return string
	 */
	private function render_with_query( array $query ): string {
		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_GET                      = array_merge( array( 'page' => AgentsScreen::SLUG ), $query );

		return $this->render();
	}

	/**
	 * Makes an administrator the current user and points the GET at one agent's detail view.
	 *
	 * @param int $agent The agent.
	 * @return void
	 */
	private function as_admin_viewing( int $agent ): void {
		wp_set_current_user( $this->admin() );
		$_GET                      = array( 'agent' => (string) $agent );
		$_SERVER['REQUEST_METHOD'] = 'GET';
	}

	/**
	 * Makes the given user the current user.
	 *
	 * @param int $id The user.
	 */
	private function as_user( int $id ): void {
		wp_set_current_user( $id );
	}
}
