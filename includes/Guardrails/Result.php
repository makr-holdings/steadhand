<?php
/**
 * What a guardrail rule decided about one call.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Guardrails;

defined( 'ABSPATH' ) || exit;

/**
 * A rule's decision. Build one with the named constructors: allow(), block(), warn() or fix().
 */
final class Result {

	/** The call may proceed unchanged. */
	public const MODE_ALLOW = 'allow';

	/** The call is refused. */
	public const MODE_BLOCK = 'block';

	/** The call may proceed, and the note is recorded. */
	public const MODE_WARN = 'warn';

	/** The call proceeds with the input this rule rewrote, and the note is recorded. */
	public const MODE_FIX = 'fix';

	/**
	 * Constructor. Use the named constructors.
	 *
	 * @param string               $mode    One of the MODE_* constants.
	 * @param string               $message The refusal text, for block.
	 * @param string               $note    The note, for warn and fix.
	 * @param array<string, mixed> $input   The rewritten input, for fix.
	 */
	private function __construct(
		private string $mode,
		private string $message = '',
		private string $note = '',
		private array $input = array()
	) {}

	/**
	 * The call may proceed.
	 *
	 * @return self
	 */
	public static function allow(): self {
		return new self( self::MODE_ALLOW );
	}

	/**
	 * The call is refused. The message is shown to the agent, so it should say what to change.
	 *
	 * @param string $message Refusal text.
	 * @return self
	 */
	public static function block( string $message ): self {
		return new self( self::MODE_BLOCK, $message );
	}

	/**
	 * The call may proceed, and the note is recorded in the change log and the response.
	 *
	 * @param string $note What was noticed.
	 * @return self
	 */
	public static function warn( string $note ): self {
		return new self( self::MODE_WARN, '', $note );
	}

	/**
	 * The call proceeds with a rewritten input. The engine checks the rewrite before it is used.
	 *
	 * @param array<string, mixed> $input Rewritten input.
	 * @param string               $note  What was changed.
	 * @return self
	 */
	public static function fix( array $input, string $note ): self {
		return new self( self::MODE_FIX, '', $note, $input );
	}

	/**
	 * Which decision this is.
	 *
	 * @return string One of the MODE_* constants.
	 */
	public function mode(): string {
		return $this->mode;
	}

	/**
	 * The refusal text. Empty unless this is a block.
	 *
	 * @return string
	 */
	public function message(): string {
		return $this->message;
	}

	/**
	 * The note. Empty unless this is a warn or a fix.
	 *
	 * @return string
	 */
	public function note(): string {
		return $this->note;
	}

	/**
	 * The rewritten input. Empty unless this is a fix.
	 *
	 * @return array<string, mixed>
	 */
	public function input(): array {
		return $this->input;
	}
}
