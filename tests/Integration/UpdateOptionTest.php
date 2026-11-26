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
use Steadhand\Abilities\UpdateOption;
use Steadhand\Guardrails\Context;
use Steadhand\Guardrails\Result;
use Steadhand\Guardrails\Rules\ProtectedOptions;
use Steadhand\Capabilities;
use Steadhand\Log\DbLogger;
use Steadhand\Log\Entry;
use Steadhand\Log\SnapshotStore;
use Steadhand\Pipeline\OptionRules;
use Steadhand\Pipeline\OptionWriter;
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
	 * A hard-blocked name is refused through the tool even when a filter tries to add it. The schema then lists the name, so the refusal
	 * comes from the call's own checks: OptionRules::allowed() drops every hard-blocked name, so the object check refuses it with the generic
	 * text. The row is unchanged. (The hard-blocked check inside allowed() is the decisive one; see OptionRulesTest for that check on its own.)
	 *
	 * @dataProvider hard_blocked_names
	 *
	 * @param string $name The option name, with the real table prefix where the name depends on it.
	 */
	public function test_a_hard_blocked_name_is_refused_through_the_tool( string $name ): void {
		$name = $this->with_prefix( $name );
		$this->on(
			'steadhand_allowed_options',
			static function ( array $options ) use ( $name ): array {
				$options[ $name ] = array( 'max_length' => 100 );

				return $options;
			}
		);
		$this->option_agent();
		$before = $this->seed_option( $name, 'plain value' );

		$this->assertArrayNotHasKey( $name, OptionRules::allowed(), "The allowlist must drop $name." );
		$result = $this->execute_ability( self::ABILITY, $this->args( $name, 'changed' ) );

		// allowed() drops the name, so the registry's enum does not list it: the schema refuses the call before the object check runs.
		$this->assertWPError( $result, "$name must be refused." );
		$this->assertSame( 'steadhand_invalid_input', $result->get_error_code() );
		$this->assertFalse( ( new UpdateOption() )->authorize_object( array( 'option' => $name ) ), "The object check must refuse $name too." );
		$this->assertSame( $before, $this->stored_value( strtolower( $name ) ) );
	}

	/**
	 * The writer and the guardrail rule refuse a hard-blocked name that a filter tried to add. Both call OptionRules::allowed() and
	 * OptionRules::hard_blocked(); the allowlist drops the name first, so their own hard_blocked() call is defence in depth, not the decisive
	 * check. The row is unchanged.
	 *
	 * @dataProvider hard_blocked_names
	 *
	 * @param string $name The option name.
	 */
	public function test_a_hard_blocked_name_is_refused_by_the_writer_and_the_rule( string $name ): void {
		$name = $this->with_prefix( $name );
		$this->on(
			'steadhand_allowed_options',
			static function ( array $options ) use ( $name ): array {
				$options[ $name ] = array( 'max_length' => 100 );

				return $options;
			}
		);
		$this->option_agent();
		$before = $this->seed_option( $name, 'plain value' );

		$writer = $this->in_write(
			fn(): mixed => OptionWriter::set( $name, 'changed', null, static fn(): bool => true ),
			Capabilities::SETTINGS
		);

		$this->assertWPError( $writer, "The writer must refuse $name." );
		$this->assertSame( 'steadhand_forbidden', $writer->get_error_code() );
		$this->assertSame( $before, $this->stored_value( strtolower( $name ) ) );

		$rule = ( new ProtectedOptions() )->evaluate(
			new Context( self::ABILITY, Capabilities::SETTINGS, get_current_user_id(), array( 'option' => $name ), 'option', 0 )
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
		$row = strtolower( $name );
		if ( null === OptionRules::stored_raw( $row ) ) {
			add_option( $row, $value, '', 'no' );
			wp_cache_flush();
		}

		return $this->stored_value( $row );
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
	 * Display text is accepted, and the row holds the escaped form WordPress stores: an apostrophe, an ampersand and a double quote.
	 */
	public function test_display_text_is_accepted_and_stored_escaped(): void {
		$this->option_agent();
		$cases = array(
			"Joe's Pizza" => 'Joe&#039;s Pizza',
			'A & B'       => 'A &amp; B',
			'Say "hi"'    => 'Say &quot;hi&quot;',
		);

		foreach ( $cases as $sent => $raw ) {
			$result = $this->execute_ability( self::ABILITY, $this->args( 'blogname', $sent ) );

			$this->assertIsArray( $result, "$sent must be accepted." );
			$this->assertSame( $sent, $result['value'], 'The output is display text.' );
			$this->assertSame( $raw, $this->stored_value( 'blogname' ), 'The row holds the escaped form.' );
		}
	}

	/**
	 * An entity sent as text is refused, and nothing changes.
	 *
	 * @dataProvider entities_sent_as_text
	 *
	 * @param string $value The value sent.
	 */
	public function test_an_entity_sent_as_text_is_refused_and_nothing_changes( string $value ): void {
		$this->option_agent();
		$before = $this->stored_value( 'blogname' );

		$result = $this->execute_ability( self::ABILITY, $this->args( 'blogname', $value ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_invalid_input', $result->get_error_code() );
		$this->assertSame( $before, $this->stored_value( 'blogname' ) );
	}

	/**
	 * Entities sent as text, for the tool.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function entities_sent_as_text(): array {
		return array(
			'an amp entity' => array( 'A &amp; B' ),
			'an apostrophe' => array( 'Joe&#039;s' ),
			'a less-than'   => array( 'a &lt; b' ),
		);
	}

	/**
	 * A value that is put back from the previous value restores the same row: set A, set B, send the previous value of B (A) and the stored
	 * row is identical to the row A made.
	 */
	public function test_sending_the_previous_value_back_restores_the_same_row(): void {
		$this->option_agent();
		$this->execute_ability( self::ABILITY, $this->args( 'blogname', 'A & B' ) );
		$original = $this->stored_value( 'blogname' );

		$second = $this->execute_ability( self::ABILITY, $this->args( 'blogname', 'Second title' ) );
		$this->assertIsArray( $second );
		$this->assertSame( 'A & B', $second['previous'] );

		$restored = $this->execute_ability( self::ABILITY, $this->args( 'blogname', $second['previous'] ) );

		$this->assertIsArray( $restored );
		$this->assertTrue( $restored['changed'] );
		$this->assertSame( $original, $this->stored_value( 'blogname' ), 'The raw row must be identical to the original.' );
	}

	/**
	 * The expected value matches the title steadhand/get-site-info returns, for a title with an ampersand and an apostrophe.
	 */
	public function test_expected_matches_the_title_that_site_info_returns(): void {
		$this->option_agent();
		$this->execute_ability( self::ABILITY, $this->args( 'blogname', "Joe's & Co" ) );

		$info = $this->execute_ability( 'steadhand/get-site-info', array() );
		$this->assertIsArray( $info );
		$this->assertSame( "Joe's & Co", $info['name'] );

		$written = $this->execute_ability( self::ABILITY, $this->args( 'blogname', 'Next', $info['name'] ) );

		$this->assertIsArray( $written );
		$this->assertSame( 'Next', $this->stored_value( 'blogname' ) );
	}

	/**
	 * A value that looks serialized is refused through the tool, and the row is unchanged.
	 *
	 * @dataProvider serialized_examples
	 *
	 * @param string $value The value sent.
	 */
	public function test_a_value_that_looks_serialized_is_refused_through_the_tool_and_the_row_is_unchanged( string $value ): void {
		$this->option_agent();
		$before = $this->stored_value( 'blogdescription' );

		$result = $this->execute_ability( self::ABILITY, $this->args( 'blogdescription', $value ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_invalid_input', $result->get_error_code() );
		$this->assertSame( $before, $this->stored_value( 'blogdescription' ) );
	}

	/**
	 * Serialized examples for the tool.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function serialized_examples(): array {
		return array(
			'null'                 => array( 'N;' ),
			'false'                => array( 'b:0;' ),
			'an integer'           => array( 'i:1;' ),
			'an empty array'       => array( 'a:0:{}' ),
			'a float'              => array( 'd:1.5;' ),
			'a string'             => array( 's:1:"a";' ),
			'an integer with text' => array( 'i:5; the top five' ),
			'a boolean with text'  => array( 'b:1;more' ),
			'an array with a name' => array( "a:3: Joe's" ),
		);
	}

	/**
	 * The description states the value rule the way value_error() applies it, and does not keep the phrases that the rule replaced.
	 */
	public function test_the_description_states_the_value_rule_and_drops_the_stale_phrases(): void {
		$description = ( new UpdateOption() )->description();

		foreach ( array( 'no entity other than &amp;', 'no bare &,', 'with a pre_option filter' ) as $stale ) {
			$this->assertStringNotContainsString( $stale, $description, "The stale phrase '$stale' must be gone." );
		}
		foreach ( array( 'a bare &, an apostrophe and a double quote are allowed', 'any HTML entity written as text', 'alloptions' ) as $current ) {
			$this->assertStringContainsString( $current, $description, "The description must say '$current'." );
		}
	}

	/**
	 * A row that is not in WordPress's escaped form (written by another route) is rewritten by a call with the same display text: the call
	 * reports changed true, and the value equals the previous value. This is the documented limit.
	 */
	public function test_a_row_not_in_the_escaped_form_is_rewritten_by_the_same_display_text(): void {
		global $wpdb;
		$agent = $this->option_agent();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Test writes a raw row, as another route would.
		$wpdb->update( $wpdb->options, array( 'option_value' => "Joe's Pizza" ), array( 'option_name' => 'blogname' ) );
		wp_cache_flush();

		$result = $this->execute_ability( self::ABILITY, $this->args( 'blogname', "Joe's Pizza" ) );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['changed'] );
		$this->assertSame( $result['previous'], $result['value'] );
		$this->assertSame( 'Joe&#039;s Pizza', $this->stored_value( 'blogname' ) );
		$this->assertSame( Entry::RESULT_OK, $this->latest_row( $agent )['result'] );
	}

	/**
	 * A filter that adds a case variant of a kept name does not make it a second option: the call names the variant and is refused by the
	 * schema, and the row of the kept name is unchanged.
	 */
	public function test_a_filter_added_case_variant_is_refused_and_the_kept_row_is_unchanged(): void {
		$this->on(
			'steadhand_allowed_options',
			static function ( array $options ): array {
				$options['BlogName'] = array( 'max_length' => 50 );

				return $options;
			}
		);
		$this->option_agent();
		$before = $this->stored_value( 'blogname' );

		$result = $this->execute_ability( self::ABILITY, $this->args( 'BlogName', 'Changed' ) );

		$this->assertWPError( $result );
		$this->assertSame( $before, $this->stored_value( 'blogname' ) );
	}

	/**
	 * An empty site title is refused through the tool, and the title is unchanged.
	 */
	public function test_an_empty_site_title_is_refused_through_the_tool(): void {
		$this->option_agent();
		$before = $this->stored_value( 'blogname' );

		$result = $this->execute_ability( self::ABILITY, $this->args( 'blogname', '' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'steadhand_invalid_input', $result->get_error_code() );
		$this->assertSame( $before, $this->stored_value( 'blogname' ) );
	}

	/**
	 * The change log names the option in the entry's input, which the entry view shows: every option shares object option #0, so the
	 * input is where the name is.
	 */
	public function test_the_change_log_input_names_the_option(): void {
		$agent = $this->option_agent();

		$this->execute_ability( self::ABILITY, $this->args( 'blogname', 'Named in the log' ) );

		$row = $this->latest_row( $agent );
		$this->assertSame( 'option', $row['object_type'] );
		$this->assertSame( 0, (int) $row['object_id'] );
		$this->assertStringContainsString( 'blogname', $row['input'] );
	}

	/**
	 * A third-party guardrail rule receives the value in its input and can block the call, which then writes nothing.
	 */
	public function test_a_third_party_rule_receives_the_value_and_can_block_the_call(): void {
		$this->on(
			'steadhand_register_guardrails',
			static function ( $registry ): void {
				$registry->add(
					new FakeRule(
						'test/no-forbidden-title',
						static fn( Context $context ): Result => ( $context->input['value'] ?? '' ) === 'Forbidden' ? Result::block( 'Not this title.' ) : Result::allow()
					)
				);
			}
		);
		$this->option_agent();
		$before = $this->stored_value( 'blogname' );

		$refused = $this->execute_ability( self::ABILITY, $this->args( 'blogname', 'Forbidden' ) );

		$this->assertWPError( $refused );
		$this->assertSame( $before, $this->stored_value( 'blogname' ) );

		$this->assertIsArray( $this->execute_ability( self::ABILITY, $this->args( 'blogname', 'Allowed title' ) ) );
	}

	/**
	 * A third-party fix rule that moves the call to a hard-blocked name is refused: the rewritten call fails the schema and the object check,
	 * and nothing is written.
	 */
	public function test_a_fix_rule_cannot_move_a_call_to_a_hard_blocked_name(): void {
		$this->on(
			'steadhand_register_guardrails',
			static function ( $registry ): void {
				$registry->add(
					new FakeRule(
						'test/redirect-to-siteurl',
						static function ( Context $context ): Result {
							$input = $context->input;
							if ( 'blogname' === ( $input['option'] ?? '' ) ) {
								$input['option'] = 'siteurl';
							}

							return Result::fix( $input, 'Redirected.' );
						}
					)
				);
			}
		);
		$this->option_agent();
		$title = $this->stored_value( 'blogname' );
		$url   = $this->stored_value( 'siteurl' );

		$result = $this->execute_ability( self::ABILITY, $this->args( 'blogname', 'Changed' ) );

		$this->assertWPError( $result );
		$this->assertSame( $title, $this->stored_value( 'blogname' ) );
		$this->assertSame( $url, $this->stored_value( 'siteurl' ) );
	}

	/**
	 * A third-party fix rule that moves the call to the other allowed option writes THAT option, not the one the agent named, and the change
	 * log records the fix with the option it wrote. This is allowed by the allowlist (the agent may write both options), so it is not refused;
	 * the log and the output show which option changed.
	 */
	public function test_a_fix_rule_that_moves_the_call_to_the_other_allowed_option_writes_that_option(): void {
		$this->on(
			'steadhand_register_guardrails',
			static function ( $registry ): void {
				$registry->add(
					new FakeRule(
						'test/redirect-to-tagline',
						static function ( Context $context ): Result {
							$input = $context->input;
							if ( 'blogname' === ( $input['option'] ?? '' ) ) {
								$input['option'] = 'blogdescription';
							}

							return Result::fix( $input, 'Redirected to the tagline.' );
						}
					)
				);
			}
		);
		$agent = $this->option_agent();
		$title = $this->stored_value( 'blogname' );

		$result = $this->execute_ability( self::ABILITY, $this->args( 'blogname', 'Redirected value' ) );

		$this->assertIsArray( $result );
		$this->assertSame( 'blogdescription', $result['option'] );
		$this->assertSame( $title, $this->stored_value( 'blogname' ), 'The option the agent named is not written.' );
		$this->assertSame( 'Redirected value', $this->stored_value( 'blogdescription' ) );

		$row = $this->latest_row( $agent );
		$this->assertSame( Entry::RESULT_FIXED, $row['result'] );
		$this->assertStringContainsString( 'blogdescription', $row['input'] );
	}

	/**
	 * Revoking steadhand_publish between the object check and the writer's own check writes nothing. The writer receives the real closure of
	 * the ability, which runs the object check again.
	 */
	public function test_publish_revoked_before_the_writer_checks_writes_nothing(): void {
		$this->option_agent();
		$before = $this->stored_value( 'blogname' );

		$result = $this->in_write(
			function () use ( $before ): mixed {
				wp_get_current_user()->allcaps[ Capabilities::PUBLISH ] = false;

				return OptionWriter::set(
					'blogname',
					'Changed',
					null,
					static fn(): bool => ( new UpdateOption() )->authorize_object( array( 'option' => 'blogname' ) )
				);
			},
			Capabilities::SETTINGS
		);

		$this->assert_refused_with( $result, 'steadhand_forbidden', 403 );
		$this->assertSame( $before, $this->stored_value( 'blogname' ) );
	}

	/**
	 * Revoking steadhand_publish in a guardrail rule, after the object check and before the write, is refused by the ability's own check in
	 * execute(), and nothing is written.
	 */
	public function test_publish_revoked_during_the_guardrails_is_refused_before_the_write(): void {
		$this->on(
			'steadhand_register_guardrails',
			static function ( $registry ): void {
				$registry->add(
					new FakeRule(
						'test/revoke-publish',
						static function (): Result {
							wp_get_current_user()->allcaps[ Capabilities::PUBLISH ] = false;

							return Result::allow();
						}
					)
				);
			}
		);
		$this->option_agent();
		$before = $this->stored_value( 'blogname' );

		$result = $this->execute_ability( self::ABILITY, $this->args( 'blogname', 'Changed' ) );

		$this->assertWPError( $result );
		$this->assertSame( self::NOT_FOUND, $this->strip_code( $result->get_error_message() ) );
		$this->assertSame( $before, $this->stored_value( 'blogname' ) );
	}

	/**
	 * An empty allowlist still registers the tool, with an enum that matches nothing, and every call is refused.
	 */
	public function test_an_empty_allowlist_registers_the_tool_and_refuses_every_call(): void {
		$this->on( 'steadhand_allowed_options', static fn(): array => array() );
		$this->option_agent();
		$before = array( $this->stored_value( 'blogname' ), $this->stored_value( 'blogdescription' ) );

		$this->assertContains( self::ABILITY, ( new \Steadhand\Plugin() )->registry()->names() );
		$this->assertSame( array( '' ), ( new UpdateOption() )->input_schema()['properties']['option']['enum'] );

		foreach ( array( 'blogname', 'blogdescription' ) as $name ) {
			$this->assertWPError( $this->execute_ability( self::ABILITY, $this->args( $name, 'Changed' ) ), $name );
		}
		$this->assertSame( $before, array( $this->stored_value( 'blogname' ), $this->stored_value( 'blogdescription' ) ) );
	}

	/**
	 * A filter added after registration that removes blogname refuses the call at call time, and the ability's own check agrees.
	 */
	public function test_a_filter_added_after_registration_that_removes_blogname_refuses_the_call(): void {
		$this->option_agent();
		$before = $this->stored_value( 'blogname' );
		$this->on(
			'steadhand_allowed_options',
			static function ( array $options ): array {
				unset( $options['blogname'] );

				return $options;
			}
		);

		$this->assertWPError( $this->execute_ability( self::ABILITY, $this->args( 'blogname', 'Changed' ) ) );
		$this->assertFalse( ( new UpdateOption() )->authorize_object( array( 'option' => 'blogname' ) ) );
		$this->assertSame( $before, $this->stored_value( 'blogname' ) );
	}

	/**
	 * A filter added after registration that adds a name is refused by the schema the tool was registered with, and nothing is created.
	 */
	public function test_a_name_a_filter_adds_after_registration_is_refused_by_the_registered_schema(): void {
		$ability = wp_get_ability( self::ABILITY );
		$this->assertNotNull( $ability );
		$this->on(
			'steadhand_allowed_options',
			static function ( array $options ): array {
				$options['probe_option'] = array( 'max_length' => 100 );

				return $options;
			}
		);

		$this->assertWPError(
			$ability->validate_input(
				array(
					'option' => 'probe_option',
					'value'  => 'x',
				)
			)
		);
		$this->assertNull( OptionRules::stored_raw( 'probe_option' ), 'The option must not be created.' );
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
