<?php
/**
 * Tests for the steadhand/protected-options guardrail rule: it blocks an update-option call that names an option Steadhand may not change,
 * and it leaves every other call alone. Most tests are negative.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Guardrails\Context;
use Steadhand\Guardrails\Result;
use Steadhand\Guardrails\Rules\ProtectedOptions;

/**
 * Tests for the steadhand/protected-options guardrail rule.
 */
final class ProtectedOptionsRuleTest extends OptionTestCase {

	/**
	 * The rule's ID is the one the verdict reports.
	 */
	public function test_the_rule_has_its_documented_id(): void {
		$this->assertSame( 'steadhand/protected-options', ( new ProtectedOptions() )->id() );
	}

	/**
	 * The two built-in options pass.
	 *
	 * @dataProvider allowed_names
	 *
	 * @param string $name The option name.
	 */
	public function test_an_allowlisted_option_passes( string $name ): void {
		$this->assertSame( Result::MODE_ALLOW, $this->evaluate( array( 'option' => $name ) )->mode() );
	}

	/**
	 * Names on the allowlist.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function allowed_names(): array {
		return array(
			'blogname'        => array( 'blogname' ),
			'blogdescription' => array( 'blogdescription' ),
		);
	}

	/**
	 * Names the rule blocks: hard-blocked ones in any case, names not on the allowlist, and malformed names.
	 *
	 * @dataProvider blocked_names
	 *
	 * @param string $name The option name.
	 */
	public function test_a_protected_or_unlisted_option_is_blocked( string $name ): void {
		$result = $this->evaluate( array( 'option' => $name ) );

		$this->assertSame( Result::MODE_BLOCK, $result->mode(), "$name must be blocked." );
		$this->assertSame( 'This option is protected and cannot be changed through Steadhand. Nothing was changed.', $result->message() );
	}

	/**
	 * Names the rule refuses.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function blocked_names(): array {
		return array(
			'siteurl'          => array( 'siteurl' ),
			'upper case home'  => array( 'HOME' ),
			'active_plugins'   => array( 'active_plugins' ),
			'widget_text'      => array( 'widget_text' ),
			'a theme mod'      => array( 'theme_mods_twentytwentyfive' ),
			'a transient'      => array( '_transient_x' ),
			'an unlisted name' => array( 'some_other_option' ),
			'a malformed name' => array( 'bad name' ),
		);
	}

	/**
	 * A call without a string option is blocked, because the rule cannot tell what it would change.
	 *
	 * @dataProvider non_string_options
	 *
	 * @param array<string, mixed> $input The input.
	 */
	public function test_a_call_without_a_string_option_is_blocked( array $input ): void {
		$this->assertSame( Result::MODE_BLOCK, $this->evaluate( $input )->mode() );
	}

	/**
	 * Inputs with no string option.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function non_string_options(): array {
		return array(
			'no option'  => array( array( 'value' => 'x' ) ),
			'an integer' => array( array( 'option' => 7 ) ),
			'an array'   => array( array( 'option' => array( 'blogname' ) ) ),
		);
	}

	/**
	 * The rule ignores every other ability, even one with a protected name in its input.
	 */
	public function test_the_rule_ignores_other_abilities(): void {
		$context = new Context( 'steadhand/update-post', 'steadhand_write_drafts', get_current_user_id(), array( 'option' => 'siteurl' ), 'post', 1 );

		$this->assertSame( Result::MODE_ALLOW, ( new ProtectedOptions() )->evaluate( $context )->mode() );
	}

	/**
	 * Evaluates the rule on an update-option call with the given input.
	 *
	 * @param array<string, mixed> $input The input.
	 */
	private function evaluate( array $input ): Result {
		return ( new ProtectedOptions() )->evaluate(
			new Context( 'steadhand/update-option', 'steadhand_settings', get_current_user_id(), $input, 'option', 0 )
		);
	}
}
