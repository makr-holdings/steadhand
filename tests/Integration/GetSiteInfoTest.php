<?php
/**
 * The steadhand/get-site-info ability, end to end over MCP.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;

/**
 * Covers SPEC §13.1 items 3 and 5.
 */
final class GetSiteInfoTest extends TestCase {

	public function test_server_lists_exactly_the_steadhand_tools(): void {
		wp_set_current_user( $this->make_agent() );

		$body  = $this->body( $this->mcp( 'tools/list', array(), $this->mcp_session() ) );
		$names = wp_list_pluck( $body['result']['tools'], 'name' );

		$this->assertSame( array( self::TOOL ), $names );
	}

	public function test_agent_gets_site_info(): void {
		wp_set_current_user( $this->make_agent() );

		$result = $this->call_tool( self::TOOL )['result'];
		$info   = $result['structuredContent'];

		$this->assertFalse( $result['isError'] );
		$this->assertSame( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $info['name'] );
		$this->assertSame( home_url(), $info['url'] );
		$this->assertSame( get_bloginfo( 'version' ), $info['wordpress_version'] );
		$this->assertSame( PHP_VERSION, $info['php_version'] );
		$this->assertSame( wp_get_theme()->get( 'Name' ), $info['theme']['name'] );
		$this->assertSame( STEADHAND_VERSION, $info['steadhand_version'] );
	}

	public function test_site_name_is_returned_as_plain_text(): void {
		update_option( 'blogname', 'Tom & "Jerry" Co' );
		wp_set_current_user( $this->make_agent() );

		$info = $this->call_tool( self::TOOL )['result']['structuredContent'];

		$this->assertSame( 'Tom & "Jerry" Co', $info['name'] );
	}

	public function test_site_info_reports_what_this_agent_may_do(): void {
		wp_set_current_user( $this->make_agent( array( Capabilities::MEDIA => true ) ) );

		$granted = $this->call_tool( self::TOOL )['result']['structuredContent']['capabilities'];

		$this->assertSame(
			array(
				'steadhand_connect'      => true,
				'steadhand_read'         => true,
				'steadhand_write_drafts' => true,
				'steadhand_publish'      => false,
				'steadhand_media'        => true,
				'steadhand_menus'        => false,
				'steadhand_trash'        => false,
				'steadhand_settings'     => false,
			),
			$granted
		);
	}

	public function test_agent_without_read_is_refused(): void {
		wp_set_current_user( $this->make_agent( array( Capabilities::READ => false ) ) );

		$result = $this->call_tool( self::TOOL )['result'];

		$this->assertTrue( $result['isError'] );
		$this->assertArrayNotHasKey( 'structuredContent', $result );
		$this->assertStringContainsString( 'steadhand_read', $result['content'][0]['text'] );
	}

	public function test_unexpected_arguments_are_refused(): void {
		wp_set_current_user( $this->make_agent() );

		$body   = $this->call_tool( self::TOOL, array( 'extra' => 'x' ) );
		$result = $body['result'];

		// Refused by the input schema as a tool error: a result with isError, not a JSON-RPC error.
		$this->assertArrayNotHasKey( 'error', $body );
		$this->assertTrue( $result['isError'] );
		$this->assertStringContainsString( 'invalid input', $result['content'][0]['text'] );
		// No site info comes back with the refusal.
		$this->assertArrayNotHasKey( 'structuredContent', $result );
		$this->assertStringNotContainsString( home_url(), $result['content'][0]['text'] );
	}

	public function test_ability_refuses_an_administrator_called_directly(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		// Core reports a refused permission check on execute() with _doing_it_wrong(); that is expected here.
		$this->setExpectedIncorrectUsage( 'WP_Ability::execute' );

		$result = wp_get_ability( 'steadhand/get-site-info' )->execute( array() );

		$this->assertWPError( $result );
	}
}
