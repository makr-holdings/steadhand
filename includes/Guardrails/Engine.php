<?php
/**
 * Runs the guardrail rules over one call, in order, and decides whether it may proceed.
 *
 * @package Steadhand
 */

declare( strict_types=1 );

namespace Steadhand\Guardrails;

use Steadhand\Abilities\PostReader;

defined( 'ABSPATH' ) || exit;

/**
 * Order: the built-in rules in $first, then the third-party rules, then the built-in rules in $last. Each rule sees the
 * input as the rules before it left it. A rule that throws, or returns something other than a Result, blocks the call
 * (fails closed).
 */
final class Engine {

	/** Longest refusal text kept, in bytes. */
	public const MAX_MESSAGE_BYTES = 500;

	/** Longest note kept, in bytes. */
	public const MAX_NOTE_BYTES = 300;

	/** Most notes kept for one call. */
	public const MAX_NOTES = 20;

	/**
	 * Third-party rules, collected once. Null until the first evaluation.
	 *
	 * @var Rule[]|null
	 */
	private ?array $registered = null;

	/**
	 * Constructor.
	 *
	 * @param Rule[] $first Built-in rules that run before the third-party rules.
	 * @param Rule[] $last  Built-in rules that run after the third-party rules, always on the final input.
	 */
	public function __construct( private array $first, private array $last ) {}

	/**
	 * Runs every rule over the call.
	 *
	 * @param Context  $context  The call.
	 * @param callable $revalidate Called with a fixed input. Returns a new Context for that input, or a WP_Error when the
	 *                             input is not allowed. The pipeline supplies it.
	 * @return Verdict
	 */
	public function evaluate( Context $context, callable $revalidate ): Verdict {
		$rules = array_merge( $this->first, $this->third_party(), $this->last );

		$notes = array();
		$fixed = false;

		foreach ( $rules as $rule ) {
			$id = $this->id_of( $rule );

			try {
				$result = $rule->evaluate( $context );
			} catch ( \Throwable ) {
				$result = null;
			}

			if ( ! $result instanceof Result ) {
				return $this->blocked(
					/* translators: Refusal text when a guardrail could not check a call. */
					__( 'A guardrail could not check this call, so it was refused.', 'steadhand' ),
					$this->note_for( $id, 'blocked' ),
					$context->input,
					$notes,
					$fixed
				);
			}

			switch ( $result->mode() ) {
				case Result::MODE_BLOCK:
					return $this->blocked(
						$this->message_for( $result->message() ),
						$this->note_for( $id, 'blocked' ),
						$context->input,
						$notes,
						$fixed
					);

				case Result::MODE_WARN:
					$this->add_note( $notes, $this->note_for( $id, $result->note() ) );
					break;

				case Result::MODE_FIX:
					$revised = $this->revalidate( $revalidate, $result->input() );
					if ( ! $revised instanceof Context ) {
						return $this->blocked(
							/* translators: Refusal text when a guardrail's rewrite of a call is not allowed. */
							__( 'A guardrail changed this call into one that is not allowed, so it was refused.', 'steadhand' ),
							$this->note_for( $id, 'blocked' ),
							$context->input,
							$notes,
							$fixed
						);
					}

					// A rewrite may change the input, but not the object it is about. A rule that moved the call to another
					// post would skip the object checks that ran before it (EditLock, for one), so the call is refused.
					if ( $revised->object_type !== $context->object_type || $revised->object_id !== $context->object_id ) {
						return $this->blocked(
							/* translators: Refusal text when a guardrail's rewrite changes the object a call is about. */
							__( 'A guardrail changed which object this call is about, so it was refused.', 'steadhand' ),
							$this->note_for( $id, 'blocked' ),
							$context->input,
							$notes,
							$fixed
						);
					}

					$context = $revised;
					$fixed   = true;
					$this->add_note( $notes, $this->note_for( $id, $result->note() ) );
					break;

				default:
					break;
			}
		}

		return new Verdict( false, '', $context->input, $notes, $fixed );
	}

	/**
	 * Collects the third-party rules, once. A listener that throws keeps the rules added before it.
	 *
	 * @return Rule[]
	 */
	private function third_party(): array {
		if ( null !== $this->registered ) {
			return $this->registered;
		}

		$this->registered = array();
		$registry         = new Registry();

		try {
			do_action( 'steadhand_register_guardrails', $registry );
		} catch ( \Throwable $error ) {
			// Deliberately ignored: the rules added so far still apply, and a listener cannot disable the built-ins.
			unset( $error );
		}

		$this->registered = $registry->rules();

		return $this->registered;
	}

	/**
	 * Asks for a revalidated context for a rewritten input. A throw or a WP_Error means the rewrite is not allowed.
	 *
	 * @param callable             $revalidate Revalidation callable.
	 * @param array<string, mixed> $input      Rewritten input.
	 * @return Context|null The new context, or null if the rewrite is refused.
	 */
	private function revalidate( callable $revalidate, array $input ): ?Context {
		try {
			$revised = $revalidate( $input );
		} catch ( \Throwable ) {
			return null;
		}

		return $revised instanceof Context ? $revised : null;
	}

	/**
	 * Builds a blocked verdict. The block note is always kept, even when the notes are full.
	 *
	 * @param string               $message Refusal text.
	 * @param string               $note    The note that says which rule blocked.
	 * @param array<string, mixed> $input   The input at the point of refusal.
	 * @param string[]             $notes   Notes so far.
	 * @param bool                 $fixed   Whether a rule rewrote the input before.
	 * @return Verdict
	 */
	private function blocked( string $message, string $note, array $input, array $notes, bool $fixed ): Verdict {
		if ( count( $notes ) >= self::MAX_NOTES ) {
			$notes = array_slice( $notes, 0, self::MAX_NOTES - 1 );
		}
		$notes[] = $note;

		return new Verdict( true, $message, $input, $notes, $fixed );
	}

	/**
	 * Adds a note, unless the list already holds MAX_NOTES.
	 *
	 * @param string[] $notes Notes so far, by reference.
	 * @param string   $note  Note to add.
	 */
	private function add_note( array &$notes, string $note ): void {
		if ( count( $notes ) < self::MAX_NOTES ) {
			$notes[] = $note;
		}
	}

	/**
	 * Formats a note for one rule.
	 *
	 * @param string $id   Rule ID.
	 * @param string $text Note text, or the word blocked.
	 * @return string
	 */
	private function note_for( string $id, string $text ): string {
		return PostReader::cut_utf8( $id . ': ' . $text, self::MAX_NOTE_BYTES );
	}

	/**
	 * The refusal text for a block: cut to the byte limit, or a default when empty.
	 *
	 * @param string $message The rule's message.
	 * @return string
	 */
	private function message_for( string $message ): string {
		$message = PostReader::cut_utf8( $message, self::MAX_MESSAGE_BYTES );

		return '' === $message ? __( 'Refused by a guardrail.', 'steadhand' ) : $message;
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
