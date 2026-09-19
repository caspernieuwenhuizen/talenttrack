<?php
namespace TT\Modules\Prospects\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ProspectOutcome (#3604) — where a logged prospect ended up, in one word.
 *
 * This is the short answer the scout's visit surfaces give next to a name:
 * archived, joined, in trial, or still active. It is NOT the onboarding
 * funnel's stage — {@see ProspectStageClassifier} answers that, needs the
 * player's status joined in, and can drop a prospect off the funnel
 * altogether. This one always answers, from the prospect row alone.
 *
 * It lives here rather than in the view because the visit detail view and
 * `GET /scouting-visits/{id}` both show it, and a coach reading the page
 * and a client reading the API must not be told two different things.
 */
final class ProspectOutcome {

    public const ARCHIVED = 'archived';
    public const JOINED   = 'joined';
    public const IN_TRIAL = 'in_trial';
    public const ACTIVE   = 'active';

    /**
     * @param array<string,mixed> $row a `tt_prospects` row, cast to array.
     */
    public static function forRow( array $row ): string {
        if ( ! empty( $row['archived_at'] ) )               return self::ARCHIVED;
        if ( ! empty( $row['promoted_to_player_id'] ) )     return self::JOINED;
        if ( ! empty( $row['promoted_to_trial_case_id'] ) ) return self::IN_TRIAL;

        return self::ACTIVE;
    }

    public static function label( string $outcome ): string {
        switch ( $outcome ) {
            case self::ARCHIVED: return __( 'Archived', 'talenttrack' );
            case self::JOINED:   return __( 'Joined', 'talenttrack' );
            case self::IN_TRIAL: return __( 'In trial', 'talenttrack' );
        }

        return __( 'Active', 'talenttrack' );
    }
}
