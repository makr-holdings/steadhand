<?php
/**
 * The Guardrails screen: what the guardrail engine enforces, the presets an administrator can turn on and off, and the rules other
 * plugins added.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Admin;

use Steadhand\Abilities\PostReader;
use Steadhand\Agents;
use Steadhand\Guardrails\Engine;
use Steadhand\Guardrails\Presets;
use Steadhand\Guardrails\Rule;
use Steadhand\Guardrails\Rules\ContentLimits;
use Steadhand\Guardrails\Rules\DailyUploadLimit;
use Steadhand\Guardrails\Rules\EditLock;
use Steadhand\Guardrails\Rules\ProtectedOptions;
use Steadhand\Guardrails\Rules\RateLimit;
use Steadhand\Pipeline\OptionRules;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the Guardrails screen (SPEC §6, D27). The screen evaluates no rule and changes nothing by itself: its only form posts to
 * GuardrailsHandler. The built-in rules are read from the engine that Plugin builds, so a new built-in rule shows up here. Only an
 * administrator who holds no agent role sees the screen, as on the Agents screen. On a multisite network it shows only a notice.
 */
final class GuardrailsScreen {

	/** The menu slug of the screen. */
	public const SLUG = 'steadhand-guardrails';

	/**
	 * Constructor.
	 *
	 * @param Engine    $engine  The guardrail engine, which lists the built-in rules and the rules other plugins added.
	 * @param bool|null $network Test seam only. Null, which Plugin always passes, means is_multisite().
	 */
	public function __construct( private Engine $engine, private ?bool $network = null ) {}

	/**
	 * The label and the description of each built-in rule, by rule ID. A built-in rule that is not listed here is shown by its ID.
	 *
	 * @return array<string, array{label: string, description: string}>
	 */
	public static function labels(): array {
		return array(
			'steadhand/rate-limit'         => array(
				'label'       => __( 'Write rate limit', 'steadhand' ),
				'description' => __( 'Refuses a write when the agent has already made the allowed number of writes in the last minute. Reads are not counted, and a refused call is not counted either.', 'steadhand' ),
			),
			'steadhand/daily-upload-limit' => array(
				'label'       => __( 'Daily upload cap', 'steadhand' ),
				'description' => __( 'Refuses an upload when the agent has already made the allowed number of uploads in the last 24 hours.', 'steadhand' ),
			),
			'steadhand/edit-lock'          => array(
				'label'       => __( 'Open-editor lock', 'steadhand' ),
				'description' => __( 'Refuses a write to a post that another user has open in the block editor, while WordPress holds a live edit lock on it.', 'steadhand' ),
			),
			'steadhand/protected-options'  => array(
				'label'       => __( 'Option allowlist', 'steadhand' ),
				'description' => __( 'Refuses an update-option call for an option that is not on the allowlist, or that is hard-blocked.', 'steadhand' ),
			),
			'steadhand/content-limits'     => array(
				'label'       => __( 'Content size and nesting', 'steadhand' ),
				'description' => __( 'Refuses post content that is too large, is not valid UTF-8, has too many blocks, or nests blocks too deeply.', 'steadhand' ),
			),
			'steadhand/block-markup'       => array(
				'label'       => __( 'Block markup', 'steadhand' ),
				'description' => __( 'Refuses block comments that are malformed, unbalanced, or hold attributes that are not valid JSON. It does not run the editor\'s own block validation.', 'steadhand' ),
			),
		);
	}

	/**
	 * Renders the page. Refuses everyone but an administrator who holds no agent role. The result code of the last POST, if any, is
	 * shown as one fixed notice.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) || Agents::holds_agent_role( wp_get_current_user() ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'steadhand' ), esc_html__( 'Forbidden', 'steadhand' ), array( 'response' => 403 ) );
		}

		echo '<div class="wrap">';
		printf( '<h1>%s</h1>', esc_html__( 'Steadhand guardrails', 'steadhand' ) );

		$this->notice_for_request();

		if ( $this->is_network() ) {
			$this->notice( 'warning', __( 'Guardrail presets are not available on multisite networks.', 'steadhand' ) );
			echo '</div>';
			return;
		}

		$this->rules_section();
		$this->presets_section();
		$this->other_plugins_section();
		$this->announce();

		echo '</div>';
	}

	/**
	 * Whether this install is a multisite network (SPEC D13).
	 *
	 * @return bool
	 */
	private function is_network(): bool {
		return $this->network ?? is_multisite();
	}

	/**
	 * The always-on section: every built-in rule the engine runs, its mode and its current limit.
	 *
	 * @return void
	 */
	private function rules_section(): void {
		echo '<h2>' . esc_html__( 'Always on', 'steadhand' ) . '</h2>';
		printf( '<p>%s</p>', esc_html__( 'These rules run on every call that writes, and no preset or setting here turns them off or changes their limits.', 'steadhand' ) );
		printf( '<p>%s</p>', esc_html__( 'The hard-coded option blocks cannot be changed here or by any filter, and no preset can switch them off.', 'steadhand' ) );

		echo '<table class="widefat striped"><thead><tr>';
		printf(
			'<th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th><th scope="col">%s</th>',
			esc_html__( 'Rule', 'steadhand' ),
			esc_html__( 'What it does', 'steadhand' ),
			esc_html__( 'Mode', 'steadhand' ),
			esc_html__( 'Current limit', 'steadhand' )
		);
		echo '</tr></thead><tbody>';

		foreach ( $this->engine->built_in_rules() as $rule ) {
			$this->rule_row( $rule );
		}

		echo '</tbody></table>';
	}

	/**
	 * One row of the built-in rules table.
	 *
	 * @param Rule $rule A built-in rule.
	 * @return void
	 */
	private function rule_row( Rule $rule ): void {
		$id    = $this->id_of( $rule );
		$texts = self::labels();

		if ( isset( $texts[ $id ] ) ) {
			$label       = $texts[ $id ]['label'];
			$description = $texts[ $id ]['description'];
		} else {
			$label       = $id;
			$description = __( 'A built-in rule that this screen does not describe yet.', 'steadhand' );
		}

		printf(
			'<tr><th scope="row"><strong>%s</strong><br><code>%s</code></th><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( $label ),
			esc_html( $id ),
			esc_html( $description ),
			esc_html__( 'Blocks', 'steadhand' ),
			esc_html( $this->limit_text( $rule ) )
		);
	}

	/**
	 * The current limit of a built-in rule, as text, read from the same source the rule uses. A rule without a number says so.
	 *
	 * @param Rule $rule The built-in rule.
	 * @return string
	 */
	private function limit_text( Rule $rule ): string {
		if ( $rule instanceof RateLimit ) {
			return sprintf(
				/* translators: %d: writes per minute allowed for each agent. */
				__( '%d writes per minute for each agent. This is the value the steadhand_rate_limit_per_minute filter gives for this administrator\'s user ID; a filter that varies by agent may give other values.', 'steadhand' ),
				$rule->limit( get_current_user_id() )
			);
		}

		if ( $rule instanceof DailyUploadLimit ) {
			return sprintf(
				/* translators: %d: uploads allowed for each agent in any rolling 24 hours. */
				__( '%d uploads for each agent in any rolling 24 hours.', 'steadhand' ),
				DailyUploadLimit::LIMIT
			);
		}

		if ( $rule instanceof ContentLimits ) {
			return sprintf(
				/* translators: 1: content size in kilobytes, 2: blocks, 3: levels of nesting. */
				__( 'Content up to %1$d KB, %2$s blocks and %3$d levels of nesting.', 'steadhand' ),
				intdiv( PostReader::MAX_CONTENT_BYTES, 1024 ),
				number_format_i18n( PostReader::MAX_BLOCKS ),
				PostReader::MAX_DEPTH
			);
		}

		if ( $rule instanceof EditLock ) {
			return sprintf(
				/* translators: %d: seconds. */
				__( 'A lock held by another user is live for %d seconds after its holder last checked in. The wp_check_post_lock_window filter sets this.', 'steadhand' ),
				EditLock::window()
			);
		}

		if ( $rule instanceof ProtectedOptions ) {
			$names = array_keys( OptionRules::allowed() );
			if ( array() === $names ) {
				return __( 'No option is on the allowlist, so no option can be changed.', 'steadhand' );
			}

			return sprintf(
				/* translators: %s: comma-separated option names. */
				__( 'Options that may be changed: %s.', 'steadhand' ),
				implode( ', ', $names )
			);
		}

		return __( 'No numeric limit.', 'steadhand' );
	}

	/**
	 * The presets section: one checkbox per preset, and one Save button. The form posts to admin-post.php.
	 *
	 * @return void
	 */
	private function presets_section(): void {
		echo '<h2>' . esc_html__( 'Presets', 'steadhand' ) . '</h2>';
		printf( '<p>%s</p>', esc_html__( 'A preset is an optional guardrail. Tick the presets to turn on, and save. A change applies to every agent at once.', 'steadhand' ) );

		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		printf( '<input type="hidden" name="action" value="%s">', esc_attr( GuardrailsHandler::ACTION ) );
		printf( '<input type="hidden" name="%s" value="1">', esc_attr( GuardrailsHandler::MARKER ) );
		wp_nonce_field( GuardrailsHandler::NONCE_ACTION );

		echo '<fieldset>';
		foreach ( Presets::all() as $preset ) {
			printf(
				'<p><label><input type="checkbox" name="%s[]" value="%s"%s> <strong>%s</strong></label><br><span class="description">%s</span></p>',
				esc_attr( GuardrailsHandler::FIELD ),
				esc_attr( $preset['id'] ),
				checked( $preset['enabled'], true, false ),
				esc_html( $preset['label'] ),
				esc_html( $preset['description'] )
			);
		}
		echo '</fieldset>';

		submit_button( __( 'Save presets', 'steadhand' ) );
		echo '</form>';
	}

	/**
	 * The rules that other plugins added through steadhand_register_guardrails, by ID, or "None".
	 *
	 * @return void
	 */
	private function other_plugins_section(): void {
		echo '<h2>' . esc_html__( 'Rules added by other plugins', 'steadhand' ) . '</h2>';

		$ids = $this->engine->third_party_ids();
		if ( array() === $ids ) {
			printf( '<p>%s</p>', esc_html__( 'None', 'steadhand' ) );
			return;
		}

		echo '<ul>';
		foreach ( $ids as $id ) {
			printf( '<li><code>%s</code></li>', esc_html( $id ) );
		}
		echo '</ul>';
	}

	/**
	 * Runs the steadhand_admin_guardrails action (docs/HOOKS.md). A listener that throws cannot break the screen.
	 *
	 * @return void
	 */
	private function announce(): void {
		try {
			do_action( 'steadhand_admin_guardrails' );
		} catch ( \Throwable ) {
			return;
		}
	}

	/**
	 * Shows the notice of the result code the handler redirected with. Only a code from the fixed list is printed. Nothing else from
	 * the query string is read or printed.
	 *
	 * @return void
	 */
	private function notice_for_request(): void {
		$raw  = isset( $_GET[ GuardrailsHandler::QUERY_KEY ] ) ? wp_unslash( $_GET[ GuardrailsHandler::QUERY_KEY ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only notice: the value is compared with a fixed list and never printed.
		$code = is_string( $raw ) ? $raw : '';

		if ( ! in_array( $code, GuardrailsHandler::CODES, true ) ) {
			return;
		}

		if ( 'saved' === $code ) {
			$this->notice( 'success', __( 'The presets were saved.', 'steadhand' ) );
		} elseif ( 'forbidden' === $code ) {
			$this->notice( 'error', __( 'The presets were not changed, because this user may not change them on this site.', 'steadhand' ) );
		} else {
			$this->notice( 'error', __( 'The presets could not be saved. Nothing was changed.', 'steadhand' ) );
		}
	}

	/**
	 * Prints a notice box.
	 *
	 * @param string $type    success, warning or error.
	 * @param string $message The text, already translated. It is escaped here.
	 * @return void
	 */
	private function notice( string $type, string $message ): void {
		printf( '<div class="notice notice-%s"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );
	}

	/**
	 * The ID of a rule, or a placeholder if it cannot be read.
	 *
	 * @param Rule $rule The rule.
	 * @return string
	 */
	private function id_of( Rule $rule ): string {
		try {
			return $rule->id();
		} catch ( \Throwable ) {
			return '(unnamed rule)';
		}
	}
}
