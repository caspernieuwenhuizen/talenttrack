<?php
namespace TT\Modules\Trials\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TrialDecisionMotivation (#3786) — the one rule about how long the
 * motivation behind a trial decision has to be.
 *
 * Admitting or releasing a child is the hand-off point of a trial and the
 * one entry a family may ask to see a season later, so a one-word "Yes" is
 * refused on purpose.
 *
 * ## Why this is a class and not two constants
 *
 * There were two rules. `POST /trial-cases/{id}/decision` counted
 * characters (#3654); the decide action on the case screen counted
 * **bytes**, so a Dutch motivation carrying a few accents cleared a floor
 * the message describes in characters several characters early, and the
 * screen and the API disagreed about the same text. The floor is arbitrary
 * — thirty could as easily be forty — which is exactly why it must be
 * stated once: nothing about it is derivable, so a second copy has nothing
 * to check itself against and drifts in silence.
 *
 * `mb_strlen()`, always. A user counting what they typed counts characters.
 */
final class TrialDecisionMotivation {

    /** Shortest motivation either surface accepts, in characters. */
    public const MIN_CHARS = 30;

    /** Length as the user would count it, not as the database stores it. */
    public static function length( string $notes ): int {
        return (int) mb_strlen( $notes );
    }

    public static function isAcceptable( string $notes ): bool {
        return self::length( $notes ) >= self::MIN_CHARS;
    }

    /**
     * The refusal, in the shape both surfaces report it.
     *
     * Null when the motivation is long enough. Otherwise the three facts a
     * caller needs to act: which field, what the floor is, and what they
     * actually sent. The REST route returns them as `details`; the screen
     * renders them under the field. Same numbers, one source.
     *
     * @return array{field:string,min_length:int,length:int}|null
     */
    public static function refusal( string $notes ): ?array {
        $length = self::length( $notes );
        if ( $length >= self::MIN_CHARS ) return null;

        return [
            'field'      => 'notes',
            'min_length' => self::MIN_CHARS,
            'length'     => $length,
        ];
    }

    /**
     * The sentence a human reads, built from a refusal.
     *
     * Translatable and shared, so the screen does not invent its own
     * wording for the rule the API already states. It names both numbers:
     * a message that says only "too short" leaves the user guessing how
     * much of what they wrote counted.
     */
    public static function refusalMessage( int $min_length, int $length ): string {
        return sprintf(
            /* translators: 1: minimum number of characters, 2: number of characters sent. */
            __( 'The motivation must be at least %1$d characters. You wrote %2$d.', 'talenttrack' ),
            $min_length,
            $length
        );
    }
}
