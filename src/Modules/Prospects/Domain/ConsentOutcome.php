<?php
namespace TT\Modules\Prospects\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ConsentOutcome (#3812) — what came back from asking a child's own club
 * to pass a consent request on to the family.
 *
 * Four states, and they are a closed set because the log they describe is
 * the academy's proof that it asked properly. "Something else happened"
 * belongs in the entry's notes, not in a fifth value nobody can query.
 *
 *   - `awaiting`  — asked, nothing back yet. This is the one that holds
 *                   the retention clock: an academy genuinely waiting for
 *                   an answer should not have the child purged out from
 *                   under it.
 *   - `agreed`    — the family said yes. This, or `consent_given_at` on
 *                   the prospect, is what lets an invitation go out.
 *   - `declined`  — the family said no.
 *   - `no_reply`  — asked, chased, nothing. Distinct from `awaiting`
 *                   because it is a conclusion rather than a wait.
 */
final class ConsentOutcome {

    public const AWAITING = 'awaiting';
    public const AGREED   = 'agreed';
    public const DECLINED = 'declined';
    public const NO_REPLY = 'no_reply';

    /** @return list<string> */
    public static function all(): array {
        return [ self::AWAITING, self::AGREED, self::DECLINED, self::NO_REPLY ];
    }

    public static function isValid( string $value ): bool {
        return in_array( $value, self::all(), true );
    }

    /**
     * Reader-facing labels, in the order they are offered.
     *
     * @return array<string,string>
     */
    public static function labels(): array {
        return [
            self::AWAITING => __( 'Waiting for an answer', 'talenttrack' ),
            self::AGREED   => __( 'The family agreed', 'talenttrack' ),
            self::DECLINED => __( 'The family declined', 'talenttrack' ),
            self::NO_REPLY => __( 'No reply', 'talenttrack' ),
        ];
    }

    public static function label( string $value ): string {
        return self::labels()[ $value ] ?? $value;
    }
}
