<?php
namespace TT\Modules\Prospects\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\TrialCaseDecision;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ProspectOutcome (#3604) — where a logged prospect ended up, in one word.
 *
 * This is the short answer the scout's visit surfaces and the prospects
 * list give next to a name: archived, joined, not admitted, in trial, or
 * still active. It is NOT the onboarding funnel's stage —
 * {@see ProspectStageClassifier} answers that, and can drop a prospect off
 * the funnel altogether.
 *
 * #4251 — the one derivation. The prospects list, its `status` filter, the
 * visit surfaces and the new-prospect wizard each had their own, in two
 * different orders, and none read the trial case's decision: a prospect
 * admitted ten days ago still listed as "In trial", and every undecided
 * trialist showed "Joined" on the visit page (admit-to-trial sets the
 * player and the trial case together). The trial case decides now, as
 * D-8 maps it:
 *
 *   admit, offered_team_position                              → joined
 *   deny_final, deny_encouragement, declined_offered_position → not admitted
 *   continue_in_trial_group, or no decision yet               → in trial
 *
 * `forRow()` is the PHP rule; `sqlCase()` is the same rule for a query, so
 * the list and its filter cannot disagree.
 */
final class ProspectOutcome {

    public const ARCHIVED     = 'archived';
    public const JOINED       = 'joined';
    public const NOT_ADMITTED = 'not_admitted';
    public const IN_TRIAL     = 'in_trial';
    public const ACTIVE       = 'active';

    /** Trial decisions after which the prospect counts as joined. */
    public const JOINED_DECISIONS = [
        TrialCaseDecision::ADMIT,
        TrialCaseDecision::OFFERED_TEAM_POSITION,
    ];

    /** Trial decisions after which the prospect counts as not admitted. */
    public const NOT_ADMITTED_DECISIONS = [
        TrialCaseDecision::DENY_FINAL,
        TrialCaseDecision::DENY_ENCOURAGEMENT,
        TrialCaseDecision::DECLINED_OFFERED_POSITION,
    ];

    /** @var array<int, ?string> trial case id => decision, per request */
    private static array $decisions = [];

    /**
     * @param array<string,mixed> $row a `tt_prospects` row, cast to array.
     *        When it carries `trial_decision` (as `search()` rows do) that
     *        is used; otherwise the trial case is looked up.
     */
    public static function forRow( array $row ): string {
        if ( ! empty( $row['archived_at'] ) ) return self::ARCHIVED;

        $trial_case_id = (int) ( $row['promoted_to_trial_case_id'] ?? 0 );
        if ( $trial_case_id > 0 ) {
            $decision = array_key_exists( 'trial_decision', $row )
                ? ( $row['trial_decision'] === null ? null : (string) $row['trial_decision'] )
                : self::decisionFor( $trial_case_id );
            return self::forDecision( $decision );
        }

        if ( ! empty( $row['promoted_to_player_id'] ) ) return self::JOINED;

        return self::ACTIVE;
    }

    /** The outcome of a prospect whose trial case carries this decision. */
    public static function forDecision( ?string $decision ): string {
        if ( in_array( $decision, self::JOINED_DECISIONS, true ) )       return self::JOINED;
        if ( in_array( $decision, self::NOT_ADMITTED_DECISIONS, true ) ) return self::NOT_ADMITTED;
        return self::IN_TRIAL;
    }

    /**
     * The trial decision a prospect row's trial case carries, as a scalar
     * subquery on its primary key. `$table` is the full prospects table
     * name as it appears in the outer query.
     */
    public static function sqlTrialDecision( string $table ): string {
        global $wpdb;
        return "( SELECT tc.decision FROM {$wpdb->prefix}tt_trial_cases tc
                   WHERE tc.id = {$table}.promoted_to_trial_case_id
                     AND tc.club_id = {$table}.club_id )";
    }

    /**
     * `forRow()` as a SQL expression over a `tt_prospects` row. Every value
     * in it is a class constant, so it carries no caller input.
     */
    public static function sqlCase( string $table ): string {
        $joined       = self::sqlList( self::JOINED_DECISIONS );
        $not_admitted = self::sqlList( self::NOT_ADMITTED_DECISIONS );
        $decision     = self::sqlTrialDecision( $table );

        return "( CASE
                    WHEN {$table}.archived_at IS NOT NULL THEN '" . self::ARCHIVED . "'
                    WHEN {$table}.promoted_to_trial_case_id IS NOT NULL THEN
                        CASE
                            WHEN {$decision} IN ( {$joined} ) THEN '" . self::JOINED . "'
                            WHEN {$decision} IN ( {$not_admitted} ) THEN '" . self::NOT_ADMITTED . "'
                            ELSE '" . self::IN_TRIAL . "'
                        END
                    WHEN {$table}.promoted_to_player_id IS NOT NULL THEN '" . self::JOINED . "'
                    ELSE '" . self::ACTIVE . "'
                  END )";
    }

    public static function label( string $outcome ): string {
        switch ( $outcome ) {
            case self::ARCHIVED:     return __( 'Archived', 'talenttrack' );
            case self::JOINED:       return __( 'Joined', 'talenttrack' );
            case self::NOT_ADMITTED: return __( 'Not admitted', 'talenttrack' );
            case self::IN_TRIAL:     return __( 'In trial', 'talenttrack' );
        }

        return __( 'Active', 'talenttrack' );
    }

    /** @param list<string> $values class constants only */
    private static function sqlList( array $values ): string {
        return "'" . implode( "', '", $values ) . "'";
    }

    private static function decisionFor( int $trial_case_id ): ?string {
        if ( array_key_exists( $trial_case_id, self::$decisions ) ) {
            return self::$decisions[ $trial_case_id ];
        }
        global $wpdb;
        $value = $wpdb->get_var( $wpdb->prepare(
            "SELECT decision FROM {$wpdb->prefix}tt_trial_cases WHERE id = %d AND club_id = %d",
            $trial_case_id,
            CurrentClub::id()
        ) );
        self::$decisions[ $trial_case_id ] = ( $value === null || $value === '' ) ? null : (string) $value;
        return self::$decisions[ $trial_case_id ];
    }

    /** For tests: forget the per-request decision cache. */
    public static function flushCache(): void {
        self::$decisions = [];
    }
}
