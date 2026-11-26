<?php
/**
 * Tests for the title rules of steadhand/update-menu: an empty title on a post or taxonomy link, the refusals an empty title and a
 * stored title that cannot be put back meet, the rule that previous.title always restores the stored title, and the refused characters.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Capabilities;

/**
 * Each test runs the ability through the pipeline as a dedicated agent with steadhand_menus and steadhand_publish.
 */
final class UpdateMenuTitleTest extends MenuWriteTestCase {

	/**
	 * The ability the tests call.
	 */
	protected const ABILITY = 'steadhand/update-menu';

	/**
	 * How many times wp_delete_post() ran.
	 *
	 * @var int
	 */
	private int $deletes = 0;

	/**
	 * Counts permanent deletes, which tearDown() checks.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->on(
			'before_delete_post',
			function (): void {
				++$this->deletes;
			},
			10,
			2
		);
	}

	/**
	 * Checks that no permanent delete happened.
	 */
	protected function tearDown(): void {
		$this->assertSame( 0, $this->deletes, 'update-menu must never call wp_delete_post().' );
		parent::tearDown();
	}

	/**
	 * Makes the current user a menu agent, and returns its ID.
	 */
	private function menu_agent(): int {
		return $this->act_as_agent(
			array(
				Capabilities::MENUS   => true,
				Capabilities::PUBLISH => true,
			)
		);
	}

	/**
	 * A post link to a published post, with the given stored title ('' follows the post's title). Returns the item's ID.
	 *
	 * @param int    $menu  The menu.
	 * @param int    $post  The linked post.
	 * @param string $title The stored title.
	 */
	private function post_link( int $menu, int $post, string $title ): int {
		$item = wp_update_nav_menu_item(
			$menu,
			0,
			array(
				'menu-item-type'      => 'post_type',
				'menu-item-object'    => 'post',
				'menu-item-object-id' => $post,
				'menu-item-title'     => $title,
				'menu-item-status'    => 'publish',
			)
		);
		$this->assertIsInt( $item, 'The post link fixture must be created.' );

		return $item;
	}

	/**
	 * The arguments of an update of an item's title.
	 *
	 * @param int    $menu  The menu.
	 * @param int    $item  The item.
	 * @param string $title The title.
	 * @return array<string, mixed>
	 */
	private function rename_args( int $menu, int $item, string $title ): array {
		return array(
			'kind'  => 'classic',
			'menu'  => $menu,
			'op'    => 'update',
			'item'  => $item,
			'title' => $title,
		);
	}

	/**
	 * The arguments of an add of a custom link with the given title.
	 *
	 * @param int    $menu  The menu.
	 * @param string $title The title.
	 * @return array<string, mixed>
	 */
	private function custom_add( int $menu, string $title ): array {
		return array(
			'kind'  => 'classic',
			'menu'  => $menu,
			'op'    => 'add',
			'type'  => 'custom',
			'url'   => 'https://example.com/added',
			'title' => $title,
		);
	}

	/**
	 * The number of items in a menu, in publish or draft.
	 *
	 * @param int $menu The menu.
	 */
	private function count_items( int $menu ): int {
		return count( \Steadhand\Pipeline\MenuRules::items_raw( $this->menu_term( $menu ) ) );
	}

	/**
	 * An add of a custom link needs a title, and an empty one is refused as invalid input, with nothing created.
	 */
	public function test_an_add_of_a_custom_link_with_an_empty_title_is_refused(): void {
		$menu = $this->classic_menu( 'Empty add ' . wp_generate_password( 6, false ) );
		$this->custom_item( $menu, 'Existing', 'https://example.com/existing' );
		$this->menu_agent();

		$result = $this->execute_ability( self::ABILITY, $this->custom_add( $menu, '' ) );

		$this->assert_refused( $result, 'steadhand_invalid_input' );
		$this->assertSame( 1, $this->count_items( $menu ), 'A refused add must create no item.' );
	}

	/**
	 * An add of a post link with an empty title is allowed: the link follows the post's own title.
	 */
	public function test_an_add_of_a_post_link_with_an_empty_title_follows_the_post(): void {
		$menu = $this->classic_menu( 'Empty post ' . wp_generate_password( 6, false ) );
		$post = $this->admin_post( array( 'post_title' => 'Followed post' ) );
		$this->menu_agent();

		$output = $this->execute_ability(
			self::ABILITY,
			array(
				'kind'    => 'classic',
				'menu'    => $menu,
				'op'      => 'add',
				'type'    => 'post',
				'post_id' => $post,
				'title'   => '',
			)
		);

		$this->assertIsArray( $output );
		$this->assertSame( 'Followed post', $output['item']['title'], 'The item shows the post\'s own title.' );
		$this->assertSame( '', $this->raw_row( (int) $output['item']['id'] )->post_title, 'The stored title stays empty.' );
	}

	/**
	 * An update of a custom link to an empty title is refused with 409 and steadhand_refused_title_required, and nothing changes.
	 */
	public function test_an_update_of_a_custom_link_to_an_empty_title_is_refused_with_409(): void {
		$menu = $this->classic_menu( 'Empty custom ' . wp_generate_password( 6, false ) );
		$item = $this->custom_item( $menu, 'Kept', 'https://example.com/kept' );
		$this->menu_agent();

		$result = $this->execute_ability( self::ABILITY, $this->rename_args( $menu, $item, '' ) );

		$this->assert_refused( $result, 'steadhand_refused_title_required' );
		$this->assertSame( 409, $result->get_error_data()['status'] ?? 0 );
		$this->assertSame( 'Kept', $this->raw_row( $item )->post_title, 'A refused rename must leave the title as it was.' );
	}

	/**
	 * An update of a post link to an empty title is allowed, and the link then shows the post's title.
	 */
	public function test_an_update_of_a_post_link_to_an_empty_title_follows_the_post(): void {
		wp_set_current_user( $this->admin_id() );
		$menu = $this->classic_menu( 'Follow ' . wp_generate_password( 6, false ) );
		$post = $this->admin_post( array( 'post_title' => 'Linked page' ) );
		$item = $this->post_link( $menu, $post, 'Own title' );
		$this->menu_agent();

		$output = $this->execute_ability( self::ABILITY, $this->rename_args( $menu, $item, '' ) );

		$this->assertIsArray( $output );
		$this->assertTrue( $output['changed'] );
		$this->assertSame( '', $this->raw_row( $item )->post_title );
		$this->assertSame( 'Linked page', $output['item']['title'] );
		$this->assertSame( 'Own title', $output['previous']['title'], 'previous.title holds the stored title, to put back.' );
	}

	/**
	 * Stored titles that the agent could not put back, markup, a non-&amp; entity, a bare &, and a title over 200 characters, make an
	 * update refused with steadhand_refused_title_not_plain, and nothing changes. The empty title of a custom link is refused the same way.
	 */
	public function test_an_update_is_refused_when_the_stored_title_cannot_be_put_back_and_nothing_changes(): void {
		wp_set_current_user( $this->admin_id() );
		$menu   = $this->classic_menu( 'Stored ' . wp_generate_password( 6, false ) );
		$stored = array(
			'markup' => '<i class="fa-home">Home</i>',
			'entity' => 'Tom &#38; Jerry',
			'bare'   => 'Tom & Jerry',
			'long'   => str_repeat( 'a', 201 ),
			'empty'  => '',
		);

		$items = array();
		foreach ( $stored as $label => $title ) {
			$items[ $label ] = $this->custom_item( $menu, '' === $title ? 'placeholder' : $title, 'https://example.com/' . $label );
			if ( '' === $title ) {
				// The fixture is made with a placeholder, because an empty custom title has to be stored by the fixture itself.
				wp_update_post(
					array(
						'ID'         => $items[ $label ],
						'post_title' => '',
					)
				);
			}
			$this->assertSame( $title, $this->raw_row( $items[ $label ] )->post_title, "The $label fixture must hold its stored title." );
		}

		$this->menu_agent();
		foreach ( $items as $label => $item ) {
			$before = $this->raw_row( $item )->post_title;

			$result = $this->execute_ability( self::ABILITY, $this->rename_args( $menu, $item, 'Renamed' ) );

			$this->assert_refused( $result, 'steadhand_refused_title_not_plain' );
			$this->assertStringContainsString( 'Appearance > Menus', $result->get_error_message(), "The $label refusal must say an administrator must rename it." );
			$this->assertSame( $before, $this->raw_row( $item )->post_title, "A refused rename of the $label title must change nothing." );
		}
	}

	/**
	 * The property that makes a rename reversible: for each kind of stored title, a rename and then a rename back with previous.title leave
	 * the stored title byte for byte as it was.
	 */
	public function test_previous_title_always_restores_the_stored_title_byte_for_byte(): void {
		wp_set_current_user( $this->admin_id() );
		$menu = $this->classic_menu( 'Round ' . wp_generate_password( 6, false ) );
		$post = $this->admin_post( array( 'post_title' => 'Linked page' ) );

		$items = array(
			'post link with an empty title' => $this->post_link( $menu, $post, '' ),
			'post link with its own title'  => $this->post_link( $menu, $post, 'Home' ),
			'custom link, plain'            => $this->custom_item( $menu, 'Plain', 'https://example.com/plain' ),
			'custom link, &amp;'            => $this->custom_item( $menu, 'Tom &amp; Jerry', 'https://example.com/tj' ),
		);

		$this->menu_agent();
		foreach ( $items as $label => $item ) {
			$original = $this->raw_row( $item )->post_title;

			$renamed = $this->execute_ability( self::ABILITY, $this->rename_args( $menu, $item, 'Renamed' ) );
			$this->assertIsArray( $renamed, "The $label rename must succeed." );
			$this->assertTrue( $renamed['changed'], "The $label rename must change the title." );

			$restored = $this->execute_ability( self::ABILITY, $this->rename_args( $menu, $item, (string) $renamed['previous']['title'] ) );
			$this->assertIsArray( $restored, "Putting back the $label title with previous.title must succeed." );
			$this->assertSame( $original, $this->raw_row( $item )->post_title, "The $label title must come back byte for byte." );
		}
	}

	/**
	 * Each refused character or form, in a title of an add, is refused as invalid input, with nothing created. The message names what
	 * is refused.
	 */
	public function test_each_refused_title_form_is_refused_and_named(): void {
		$menu = $this->classic_menu( 'Forms ' . wp_generate_password( 6, false ) );
		$this->custom_item( $menu, 'Existing', 'https://example.com/existing' );
		$this->menu_agent();

		$refused = array(
			'markup'                => '<b>Bold</b>',
			'entity &lt;'           => 'Tom &lt;b&gt;',
			'entity &#60;'          => 'Tom &#60;',
			'entity &#x3c;'         => 'Tom &#x3c;',
			'entity &nbsp;'         => 'a&nbsp;b',
			'bare ampersand'        => 'Tom & Jerry',
			'zero-width space'      => "Zero\u{200B}width",
			'bidi override'         => "Bidi\u{202E}x",
			'bidi isolate'          => "Iso\u{2067}x",
			'byte order mark'       => "\u{FEFF}bom",
			'soft hyphen'           => "soft\u{00AD}hyphen",
			'tab'                   => "tab\there",
			'newline'               => "two\nlines",
			'C1 control'            => "C1\u{0085}x",
			'percent-encoded octet' => 'Save 100%25',
			'percent-encoded space' => 'a %20 b',
			'double space'          => 'a  b',
			'leading space'         => ' lead',
			'trailing space'        => 'trail ',
		);

		foreach ( $refused as $label => $title ) {
			$result = $this->execute_ability( self::ABILITY, $this->custom_add( $menu, $title ) );

			$this->assert_refused( $result, 'steadhand_invalid_input' );
			$this->assertStringContainsString( 'markup', $result->get_error_message(), "The message must list what is refused ($label)." );
			$this->assertStringContainsString( '&amp;', $result->get_error_message(), "The message must name the entity rule ($label)." );
		}

		// The schema's maxLength refuses a title of 201 characters before the title rules run, so its own text is what the agent sees.
		$this->assert_refused( $this->execute_ability( self::ABILITY, $this->custom_add( $menu, str_repeat( 'a', 201 ) ) ), 'steadhand_invalid_input' );

		$this->assertSame( 1, $this->count_items( $menu ), 'No refused title may create an item.' );
	}

	/**
	 * The plain forms the rules keep: a 200-character title, and an &amp; written as such.
	 */
	public function test_the_plain_forms_are_accepted(): void {
		$menu = $this->classic_menu( 'Plain forms ' . wp_generate_password( 6, false ) );
		$this->menu_agent();

		$amp = $this->execute_ability( self::ABILITY, $this->custom_add( $menu, 'Tom &amp; Jerry' ) );
		$this->assertIsArray( $amp );
		$this->assertSame( 'Tom &amp; Jerry', $this->raw_row( (int) $amp['item']['id'] )->post_title );

		$long = $this->execute_ability( self::ABILITY, $this->custom_add( $menu, str_repeat( 'b', 200 ) ) );
		$this->assertIsArray( $long );
	}
}
