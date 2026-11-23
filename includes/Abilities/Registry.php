<?php
/**
 * Registers the Steadhand ability category and abilities with the WordPress Abilities API.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Abilities;

use Steadhand\Log\DbLogger;
use Steadhand\Pipeline\Pipeline;

defined( 'ABSPATH' ) || exit;

/**
 * Holds the list of Steadhand abilities and wires each one to the shared pipeline.
 */
final class Registry {

	/**
	 * Ability category slug.
	 *
	 * @var string
	 */
	public const CATEGORY = 'steadhand';

	/**
	 * The change log that GetChangeLog reads from.
	 *
	 * @var DbLogger
	 */
	private DbLogger $change_log;

	/**
	 * Constructor.
	 *
	 * @param Pipeline      $pipeline   Pipeline every ability call goes through.
	 * @param DbLogger|null $change_log The change log for get-change-log. Defaults to a new DbLogger.
	 */
	public function __construct( private Pipeline $pipeline, ?DbLogger $change_log = null ) {
		$this->change_log = $change_log ?? new DbLogger();
	}

	/**
	 * Returns every Steadhand ability.
	 *
	 * @return Ability[]
	 */
	public function abilities(): array {
		return array(
			new GetSiteInfo(),
			new ListPosts(),
			new GetPost(),
			new GetBlocks(),
			new GetRendered(),
			new ListTerms(),
			new GetPostTerms(),
			new ListMenus(),
			new GetMenu(),
			new ListMedia(),
			new GetMedia(),
			new GetDiagnostics(),
			new GetChangeLog( $this->change_log ),
			new CreatePost(),
			new UpdatePost(),
			new EditBlocks(),
			new SetPostTerms(),
			new UploadMedia(),
			new SetFeaturedImage(),
		);
	}

	/**
	 * Returns the names of every Steadhand ability.
	 *
	 * @return string[]
	 */
	public function names(): array {
		return array_map(
			static fn( Ability $ability ): string => $ability->name(),
			$this->abilities()
		);
	}

	/**
	 * Returns the names of the abilities that can change something: every one that is not a read call.
	 *
	 * @return string[]
	 */
	public function write_names(): array {
		$names = array();
		foreach ( $this->abilities() as $ability ) {
			if ( ! Pipeline::is_read_only_ability( $ability ) ) {
				$names[] = $ability->name();
			}
		}

		return $names;
	}

	/**
	 * Registers the Steadhand ability category.
	 */
	public function register_category(): void {
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Steadhand', 'steadhand' ),
				'description' => __( 'Abilities that let AI agents work on this site safely, under least-privilege capabilities and an audit log.', 'steadhand' ),
			)
		);
	}

	/**
	 * Registers every Steadhand ability.
	 */
	public function register_abilities(): void {
		foreach ( $this->abilities() as $ability ) {
			wp_register_ability( $ability->name(), $this->args_for( $ability ) );
		}
	}

	/**
	 * Adds a Steadhand error code to the end of a refusal's message, for the MCP Adapter.
	 *
	 * The adapter turns a WP_Error into a result that holds only the message text and isError, so the code and status
	 * are lost. Appending " (steadhand_<code>)" lets an agent see which refusal it got. The change log is written before
	 * the pipeline returns, so it still stores the message without the suffix.
	 *
	 * Only a WP_Error whose code matches the Steadhand pattern is changed. Anything else, including a result that is not
	 * an error, is returned unchanged. A message that already ends with the suffix is returned unchanged too. A changed
	 * error is a new WP_Error with the same code and data; the original is not modified. Only the first code, message and
	 * data of a WP_Error are carried over. Steadhand errors have exactly one, so nothing is lost.
	 *
	 * @param mixed $result A value returned by the pipeline.
	 * @return mixed The same value, or a new WP_Error with the code appended to its message.
	 */
	public static function with_code( mixed $result ): mixed {
		if ( ! $result instanceof \WP_Error ) {
			return $result;
		}

		$code = $result->get_error_code();
		if ( ! is_string( $code ) || 1 !== preg_match( '/^steadhand_[a-z0-9_]{1,60}$/', $code ) ) {
			return $result;
		}

		$suffix  = ' (' . $code . ')';
		$message = $result->get_error_message();
		if ( str_ends_with( $message, $suffix ) ) {
			return $result;
		}

		return new \WP_Error( $code, $message . $suffix, $result->get_error_data() );
	}

	/**
	 * Runs one pipeline call and turns any Throwable that escapes it into the generic error, so an exception message
	 * never reaches the MCP Adapter. A throw from a permission check becomes a refusal, never true.
	 *
	 * This is the outer belt. The pipeline catches its own throws first (see Pipeline::check() and Pipeline::run()), so
	 * this only runs if something outside those guards throws. The pipeline could not have logged such a call, so the
	 * belt does not log it either.
	 *
	 * @param callable $call The pipeline call.
	 * @return mixed The call's result, with the code added (see with_code()), or the generic error.
	 */
	private static function contain( callable $call ): mixed {
		try {
			return self::with_code( $call() );
		} catch ( \Throwable ) {
			return self::with_code( Pipeline::generic_error() );
		}
	}

	/**
	 * Builds the registration arguments for one ability.
	 *
	 * Steadhand abilities are exposed only through the Steadhand MCP server, never the
	 * adapter's default server or the core abilities REST API. Hence show_in_rest is false,
	 * mcp.public is false, and the ability is never marked meta.public.
	 *
	 * @param Ability $ability Ability to describe.
	 * @return array<string, mixed> Arguments for wp_register_ability().
	 */
	public function args_for( Ability $ability ): array {
		return array(
			'label'               => $ability->label(),
			'description'         => $ability->description(),
			'category'            => self::CATEGORY,
			'input_schema'        => $ability->input_schema(),
			'output_schema'       => $ability->output_schema(),
			'permission_callback' => fn( $input = null ) => self::contain( fn() => $this->pipeline->check( $ability, is_array( $input ) ? $input : array() ) ),
			'execute_callback'    => fn( $input = null ) => self::contain( fn() => $this->pipeline->run( $ability, $input ) ),
			'meta'                => array(
				'annotations'  => $ability->annotations(),
				'show_in_rest' => false,
				'mcp'          => array( 'public' => false ),
			),
		);
	}
}
