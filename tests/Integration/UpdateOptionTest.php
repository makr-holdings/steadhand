<?php
/**
 * Tests for steadhand/update-option through the pipeline: the three capabilities, the names that are never changed, the value and the
 * stored-value rules as the tool applies them, the expected value, the change log, the snapshot, undo, and the registration. Most tests are
 * negative. The rules on their own are in OptionRulesTest, the writer in OptionWriterTest and the guardrail rule in ProtectedOptionsRuleTest.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Tests\Integration;

use Steadhand\Admin\UndoEntry;
use Steadhand\Guardrails\Result;
use Steadhand\Capabilities;
use Steadhand\Log\DbLogger;
use Steadhand\Log\Entry;
use Steadhand\Log\SnapshotStore;
use Steadhand\Pipeline\OptionRules;
use Steadhand\Pipeline\Pipeline;

/**
 * Covers the ability from the permission callback down. Every call goes through execute_ability(), which runs the plugin's own callbacks.
 */
final class UpdateOptionTest extends OptionTestCase {

	/**
	 * The text of the publish refusal.
	 */
	private const PUBLISH_NEEDED = 'Changing a site option needs the steadhand_publish capability as well as steadhand_settings, because the site title and tagline appear on every public page at once. This agent does not hold steadhand_publish. Nothing was changed.';

	/**
	 * Without steadhand_settings the pipeline refuses the call, and nothing changes.
	 */
	public function test_refused_without_settings_and_nothing_changes(): void {
		$before = $this->stored_value( 'blogname' );
		$this->act_as_agent( array( Capabilities::PUBLISH => true ) );

		$result = $this->execute_ability( self::ABILITY, $this->args( 'blogname', 'Changed' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
		$this->assertSame( $before, $this->stored_value( 'blogname' ) );
	}

	/**
	 * Without steadhand_publish the call is refused with the publish text, and nothing changes.
	 */
	public function test_refused_without_publish_and_nothing_changes(): void {
		$before = $this->stored_value( 'blogname' );
		$this->option_agent( array( Capabilities::PUBLISH => false ) );

		$result = $this->execute_ability( self::ABILITY, $this->args( 'blogname', 'Changed' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_forbidden', $result->get_error_code() );
		$this->assertSame( self::PUBLISH_NEEDED, $result->get_error_message() );
		$this->assertSame( $before, $this->stored_value( 'blogname' ) );
	}

	/**
	 * Without steadhand_read the call is refused with the generic text, and nothing changes.
	 */
	public function test_refused_without_read_and_nothing_changes(): void {
		$before = $this->stored_value( 'blogname' );
		$this->option_agent( array( Capabilities::READ => false ) );

		$result = $this->execute_ability( self::ABILITY, $this->args( 'blogname', 'Changed' ) );

		$this->assertWPError( $result );
		$this->assertSame( self::NOT_FOUND, $this->strip_code( $result->get_error_message() ) );
		$this->assertSame( $before, $this->stored_value( 'blogname' ) );
	}

	/**
	 * An agent with all three capabilities never holds manage_options, during the write or after it.
	 */
	public function test_the_agent_never_gains_manage_options_during_or_after_the_call(): void {
		$during = null;
		$this->on(
			'update_option_blogname',
			static function () use ( &$during ): void {
				$during = current_user_can( 'manage_options' );
			},
			10,
			2
		);
		$this->option_agent();

		$result = $this->execute_ability( self::ABILITY, $this->args( 'blogname', 'Agent title' ) );

		$this->assertIsArray( $result, 'The call must succeed for this probe to mean anything.' );
		$this->assertFalse( $during, 'manage_options must be false inside the write.' );
		$this->assertFalse( current_user_can( 'manage_options' ), 'manage_options must be false after the call.' );
	}

	/**
	 * Every name that must never change is refused through the tool, and its stored value does not change. The list covers exact names,
	 * the prefixes, the roles option with the real table prefix, and names in other letter cases.
	 *
	 * @dataProvider hard_blocked_names
	 *
	 * @param string $name The option name, with the real table prefix where the name depends on it.
	 */
	public function test_a_hard_blocked_name_is_refused_through_the_tool( string $name ): void {
		$name = $this->with_prefix( $name );
		$this->option_agent();
		$before = $this->seed_option( $name, 'plain value' );

		$result = $this->execute_ability( self::ABILITY, $this->args( $name, 'changed' ) );

		$this->assertWPError( $result, "$name must be refused." );
		// The schema's enum refuses a name it does not list (steadhand_invalid_input); the ability refuses the rest (steadhand_forbidden).
		$this->assertContains( $result->get_error_code(), array( 'steadhand_forbidden', 'steadhand_invalid_input' ) );
		$this->assertSame( $before, $this->stored_value( $name ) );
	}

	/**
	 * The same names are refused by OptionWriter::set() when it is called directly inside a write, and by the guardrail rule.
	 *
	 * @dataProvider hard_blocked_names
	 *
	 * @param string $name The option name.
	 */
	public function test_a_hard_blocked_name_is_refused_by_the_writer_and_the_rule( string $name ): void {
		$name = $this->with_prefix( $name );
		$this->option_agent();
		$before = $this->seed_option( $name, 'plain value' );

		$writer = $this->in_write(
			fn(): mixed => \Steadhand\Pipeline\OptionWriter::set( $name, 'changed', null, static fn(): bool => true ),
			Capabilities::SETTINGS
		);

		$this->assertWPError( $writer, "The writer must refuse $name." );
		$this->assertSame( $before, $this->stored_value( $name ) );

		$rule = ( new \Steadhand\Guardrails\Rules\ProtectedOptions() )->evaluate(
			new \Steadhand\Guardrails\Context( self::ABILITY, Capabilities::SETTINGS, get_current_user_id(), array( 'option' => $name ), 'option', 0 )
		);
		$this->assertSame( Result::MODE_BLOCK, $rule->mode(), "The rule must block $name." );
	}

	/**
	 * The names that are never changed, with the table prefix spliced in where the name depends on it.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function hard_blocked_names(): array {
		return array(
			'siteurl'                 => array( 'siteurl' ),
			'home'                    => array( 'home' ),
			'active_plugins'          => array( 'active_plugins' ),
			'the roles option'        => array( '{prefix}user_roles' ),
			'sidebars_widgets'        => array( 'sidebars_widgets' ),
			'widget_block'            => array( 'widget_block' ),
			'widget_text'             => array( 'widget_text' ),
			'a theme mod'             => array( 'theme_mods_twentytwentyfive' ),
			'admin_email'             => array( 'admin_email' ),
			'users_can_register'      => array( 'users_can_register' ),
			'default_role'            => array( 'default_role' ),
			'template'                => array( 'template' ),
			'stylesheet'              => array( 'stylesheet' ),
			'a steadhand option'      => array( 'steadhand_db_version' ),
			'a transient'             => array( '_transient_x' ),
			'upper case siteurl'      => array( 'SITEURL' ),
			'mixed case home'         => array( 'HoMe' ),
			'upper case roles option' => array( '{PREFIX}USER_ROLES' ),
			'a capabilities option'   => array( 'wp_capabilities' ),
		);
	}

	/**
	 * Replaces {prefix} with the table prefix, and {PREFIX} with the same prefix in upper case.
	 *
	 * @param string $name The name, possibly with a placeholder.
	 */
	private function with_prefix( string $name ): string {
		global $wpdb;

		return str_replace( array( '{prefix}', '{PREFIX}' ), array( $wpdb->prefix, strtoupper( $wpdb->prefix ) ), $name );
	}

	/**
	 * Makes sure an option exists for a test and returns what the database holds for it. An option that already exists (the roles option,
	 * for one) is left as it is, so the test compares with its real stored value. A new one is created with autoload off.
	 *
	 * @param string $name  The option name.
	 * @param string $value The value of a new option.
	 * @return string The stored value.
	 */
	private function seed_option( string $name, string $value ): string {
		if ( null === OptionRules::stored_raw( $name ) ) {
			add_option( $name, $value, '', 'no' );
			wp_cache_flush();
		}

		return $this->stored_value( $name );
	}

	/**
	 * The schema lists exactly the allowlist, is strict, and requires option and value.
	 */
	public function test_the_schema_is_strict_and_lists_the_allowlist(): void {
		$ability = wp_get_ability( self::ABILITY );
		$this->assertNotNull( $ability );

		$input = $ability->get_input_schema();
		$this->assertFalse( $input['additionalProperties'] );
		$this->assertSame( array( 'option', 'value' ), $input['required'] );
		$this->assertSame( array( 'blogname', 'blogdescription' ), $input['properties']['option']['enum'] );
		$this->assertSame( OptionRules::MAX_LENGTH, $input['properties']['value']['maxLength'] );
		$this->assertSame( OptionRules::MAX_LENGTH, $input['properties']['expected']['maxLength'] );
	}

	/**
	 * A name that is not on the enum is refused by the schema path, and the ability refuses a name that the enum allowed but the allowlist
	 * no longer does, with the generic text and nothing changed.
	 */
	public function test_a_name_removed_from_the_allowlist_is_refused_by_the_ability_itself(): void {
		$before = $this->stored_value( 'blogname' );
		$this->option_agent();
		$this->on(
			'steadhand_allowed_options',
			static function ( array $options ): array {
				unset( $options['blogname'] );

				return $options;
			}
		);

		// Through the tool, the schema built now no longer lists blogname, so the call is refused before the ability runs.
		$result = $this->execute_ability( self::ABILITY, $this->args( 'blogname', 'Changed' ) );
		$this->assertWPError( $result );
		$this->assertSame( $before, $this->stored_value( 'blogname' ) );

		// The ability's own check does not rely on that schema: a schema built before the change would still list the name.
		$ability = new \Steadhand\Abilities\UpdateOption();
		$this->assertFalse( $ability->authorize_object( array( 'option' => 'blogname' ) ) );
		$this->assertSame(
			self::NOT_FOUND,
			$this->strip_code(
				$ability->execute(
					array(
						'option' => 'blogname',
						'value'  => 'Changed',
					)
				)->get_error_message()
			)
		);
		$this->assertSame( $before, $this->stored_value( 'blogname' ) );
	}

	/**
	 * Each value rule is applied through the tool: HTML, an entity, a control character, a leading space, and a value that is too long
	 * are refused, and nothing changes.
	 *
	 * @dataProvider refused_values
	 *
	 * @param mixed $value The value sent.
	 */
	public function test_a_bad_value_is_refused_through_the_tool_and_nothing_changes( mixed $value ): void {
		$before = $this->stored_value( 'blogname' );
		$this->option_agent();

		$result = $this->execute_ability( self::ABILITY, $this->args( 'blogname', $value ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_invalid_input', $result->get_error_code() );
		$this->assertSame( $before, $this->stored_value( 'blogname' ) );
	}

	/**
	 * Values the value rule refuses.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function refused_values(): array {
		return array(
			'markup'         => array( '<b>Bold</b>' ),
			'an entity'      => array( 'Tom &copy; Jerry' ),
			'a control char' => array( "Line\x01one" ),
			'leading space'  => array( ' Leading' ),
			'too long'       => array( str_repeat( 'a', 201 ) ),
			'invalid utf-8'  => array( "bad\xC3(" ),
		);
	}

	/**
	 * An empty tagline is accepted, and is stored as an empty string.
	 */
	public function test_an_empty_tagline_is_accepted_and_stored(): void {
		// The test install ships an empty tagline, so the test first gives it one, to make the change an emptying.
		update_option( 'blogdescription', 'Fixture tagline' );
		$this->option_agent();
		$this->assertNotSame( '', $this->stored_value( 'blogdescription' ), 'Precondition: the tagline is not empty.' );

		$result = $this->execute_ability( self::ABILITY, $this->args( 'blogdescription', '' ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( '', $this->stored_value( 'blogdescription' ) );
	}

	/**
	 * The expected value: a mismatch is refused with 409 and nothing is written; a match is written.
	 */
	public function test_expected_mismatch_is_refused_and_a_match_is_written(): void {
		$before = $this->stored_value( 'blogname' );
		$this->option_agent();

		$refused = $this->execute_ability( self::ABILITY, $this->args( 'blogname', 'Changed', 'Something else' ) );
		$this->assert_refused_with( $refused, 'steadhand_refused_content_changed', 409 );
		$this->assertSame( $before, $this->stored_value( 'blogname' ) );

		$written = $this->execute_ability( self::ABILITY, $this->args( 'blogname', 'Changed', $before ) );
		$this->assertIsArray( $written );
		$this->assertSame( 'Changed', $this->stored_value( 'blogname' ) );
	}

	/**
	 * A value the option already holds writes nothing: changed is false and update_option() is not called.
	 */
	public function test_the_same_value_writes_nothing(): void {
		$current = $this->stored_value( 'blogname' );
		$calls   = 0;
		$this->on(
			'pre_update_option_blogname',
			static function ( mixed $value ) use ( &$calls ): mixed {
				++$calls;

				return $value;
			},
			10,
			1
		);
		$this->option_agent();

		$result = $this->execute_ability( self::ABILITY, $this->args( 'blogname', $current ) );

		$this->assertIsArray( $result );
		$this->assertFalse( $result['changed'] );
		$this->assertSame( 0, $calls, 'update_option() must not be called for the same value.' );
		$this->assertSame( $current, $result['previous'] );
	}

	/**
	 * A successful change stores the value, reports the previous value, keeps the autoload setting, logs one ok row against the option with
	 * undoable 0, and saves a snapshot of kind option with the previous value.
	 */
	public function test_a_successful_change_is_stored_reported_logged_and_snapshotted(): void {
		$previous = $this->stored_value( 'blogname' );
		$autoload = $this->autoload_of( 'blogname' );
		$agent    = $this->option_agent();

		$result = $this->execute_ability( self::ABILITY, $this->args( 'blogname', 'Agent title' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'Agent title', $result['value'] );
		$this->assertSame( $previous, $result['previous'] );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( 'Agent title', $this->stored_value( 'blogname' ) );
		$this->assertSame( $autoload, $this->autoload_of( 'blogname' ), 'The autoload setting must not change.' );

		$row = $this->latest_row( $agent );
		$this->assertSame( self::ABILITY, $row['ability'] );
		$this->assertSame( Entry::RESULT_OK, $row['result'] );
		$this->assertSame( 'option', $row['object_type'] );
		$this->assertSame( 0, (int) $row['object_id'] );
		$this->assertSame( 0, (int) $row['undoable'] );

		$snapshot = SnapshotStore::find( (int) $row['snapshot_id'] );
		$this->assertIsArray( $snapshot, 'The snapshot must exist.' );
		$this->assertSame( SnapshotStore::KIND_OPTION, $snapshot['kind'] );
		$this->assertSame( 'blogname', $snapshot['state']['option'] );
		$this->assertSame( $previous, $snapshot['state']['value'] );
	}

	/**
	 * A pre_update_option filter that changes the value leaves a value that is neither: the call is partial.
	 */
	public function test_a_filter_that_changes_the_value_during_the_save_is_partial(): void {
		$this->on(
			'pre_update_option_blogname',
			static fn(): string => 'Something else entirely',
			10,
			1
		);
		$this->option_agent();

		$result = $this->execute_ability( self::ABILITY, $this->args( 'blogname', 'Agent title' ) );

		$this->assert_refused_with( $result, 'steadhand_update_partial', 500 );
		$this->assertSame( 'Something else entirely', $this->stored_value( 'blogname' ) );
	}

	/**
	 * A pre_update_option filter that keeps the old value means nothing was written: the call is a failed update.
	 */
	public function test_a_filter_that_keeps_the_old_value_is_a_failed_update(): void {
		$before = $this->stored_value( 'blogname' );
		$this->on(
			'pre_update_option_blogname',
			static function () use ( $before ): string {
				return $before;
			},
			10,
			1
		);
		$this->option_agent();

		$result = $this->execute_ability( self::ABILITY, $this->args( 'blogname', 'Agent title' ) );

		$this->assert_refused_with( $result, 'steadhand_update_failed', 500 );
		$this->assertSame( $before, $this->stored_value( 'blogname' ) );
	}

	/**
	 * Undo refuses an update-option entry: the agent's undo-change, and the administrator's undo on the log screen.
	 */
	public function test_undo_refuses_an_update_option_entry_for_the_agent_and_the_administrator(): void {
		$agent = $this->option_agent();
		$this->execute_ability( self::ABILITY, $this->args( 'blogname', 'Agent title' ) );
		$entry = (int) $this->latest_row( $agent )['id'];

		$agent_undo = $this->execute_ability( 'steadhand/undo-change', array( 'id' => $entry ) );
		$this->assertWPError( $agent_undo, 'undo-change must refuse an update-option entry.' );

		wp_set_current_user( $this->admin_id() );
		$admin_undo = ( new Pipeline( new DbLogger() ) )->run_admin( new UndoEntry( new DbLogger() ), array( 'id' => $entry ) );
		$this->assertWPError( $admin_undo, 'The administrator\'s undo must refuse an update-option entry.' );

		$this->assertSame( 'Agent title', $this->stored_value( 'blogname' ), 'The change must stay.' );
	}

	/**
	 * Successful writes count toward the rate limit: the thirty-first change within a minute is refused, and nothing changes.
	 */
	public function test_the_rate_limit_refuses_the_thirty_first_change_in_a_minute(): void {
		$this->option_agent();

		for ( $i = 1; $i <= 30; ++$i ) {
			$this->assertIsArray( $this->execute_ability( self::ABILITY, $this->args( 'blogname', 'Title ' . $i ) ), "Write $i must pass." );
		}

		$blocked = $this->execute_ability( self::ABILITY, $this->args( 'blogname', 'Title 31' ) );

		$this->assertWPError( $blocked );
		$this->assertStringContainsString( 'too many changes', $blocked->get_error_message() );
		$this->assertSame( 'Title 30', $this->stored_value( 'blogname' ) );
	}

	/**
	 * Each call counts as a write, so the rate limit applies to it.
	 */
	public function test_the_rate_limit_counts_the_ability_as_a_write(): void {
		$names = ( new \Steadhand\Plugin() )->registry()->write_names();

		$this->assertContains( self::ABILITY, $names );
	}

	/**
	 * The ability is registered as an ability of the Steadhand server only: not public, with the annotations it should have.
	 */
	public function test_is_registered_as_non_public_with_its_annotations(): void {
		$ability = wp_get_ability( self::ABILITY );
		$this->assertNotNull( $ability );

		$meta = $ability->get_meta();
		$this->assertFalse( $meta['public'] ?? false, 'The ability must not be public.' );
		$this->assertFalse( $meta['mcp']['public'] ?? false, 'The MCP entry must not be public.' );
		$this->assertFalse( $meta['annotations']['readonly'] );
		$this->assertFalse( $meta['annotations']['destructive'] );
		$this->assertTrue( $meta['annotations']['idempotent'] );
	}

	/**
	 * The caller check needs steadhand_settings, and the object check then needs steadhand_read and steadhand_publish as well.
	 */
	public function test_the_first_check_is_steadhand_settings_alone(): void {
		$ability = ( new \Steadhand\Abilities\UpdateOption() );

		$this->assertSame( Capabilities::SETTINGS, $ability->capability() );
		$this->assertSame( array( Capabilities::SETTINGS ), $ability->capabilities_any() );
	}

	/**
	 * Removes "(steadhand_code)" from the end of a refusal text, so that a test compares the bare text.
	 *
	 * @param string $message The message.
	 */
	private function strip_code( string $message ): string {
		return (string) preg_replace( '/ \(steadhand_[a-z0-9_]+\)$/', '', $message );
	}
}
