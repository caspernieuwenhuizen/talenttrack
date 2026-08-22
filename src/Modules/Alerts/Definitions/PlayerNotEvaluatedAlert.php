<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;

/**
 * PlayerNotEvaluatedAlert (#2636, epic #2629) — nobody has evaluated this
 * player in a long time.
 *
 * Which player question does this answer? *Where is this player now?* An
 * academy that has not written anything down about a player for two months
 * cannot answer that question at all — not for a selection meeting, not for
 * a parent, not for the player. The gap is invisible on every screen
 * precisely because the absence of a record renders as nothing.
 *
 * The threshold is `alerts_eval_stale_weeks` in `tt_config`, defaulting to
 * eight weeks. It is configuration rather than a constant because academies
 * genuinely differ: one evaluates every block, another twice a season, and
 * whichever of them gets the wrong threshold stops trusting the alert.
 *
 * A player who has never been evaluated is measured from the day they
 * joined, not from the beginning of time. Telling a coach that the trialist
 * who arrived on Tuesday is overdue an evaluation is the kind of wrongness
 * that teaches people to mute the whole feature.
 */
final class PlayerNotEvaluatedAlert extends AbstractPlayerAlert {

    /** tt_config key holding the staleness threshold, in weeks. */
    public const CONFIG_KEY_WEEKS = 'alerts_eval_stale_weeks';

    private const DEFAULT_WEEKS = 8;

    public function key(): string {
        return 'evaluations.player_not_evaluated';
    }

    public function module(): string {
        return 'evaluations';
    }

    public function label(): string {
        return __( 'Player not evaluated recently', 'talenttrack' );
    }

    public function description(): string {
        return __( 'No evaluation has been recorded for a player for longer than the academy\'s threshold. Nothing on any screen shows the gap, because a missing record renders as nothing.', 'talenttrack' );
    }

    /**
     * Recording an evaluation is what clears this, so that is the
     * capability that gates receipt.
     */
    public function capRequired(): string {
        return 'tt_evaluate_players';
    }

    /** Twice the threshold is no longer a slip; it is a player nobody is watching. */
    protected function severityFor( object $row ): string {
        $weeks = $this->threshold( self::CONFIG_KEY_WEEKS, self::DEFAULT_WEEKS );
        $since = (string) ( $row->reference_date ?? '' );
        return $this->daysSince( $since ) >= ( $weeks * 7 * 2 ) ? Severity::URGENT : Severity::ATTENTION;
    }

    protected function titleFor( object $row ): string {
        $name = $this->playerName( $row );

        if ( (string) ( $row->last_eval_date ?? '' ) === '' ) {
            return sprintf(
                /* translators: %s: player name */
                __( '%s has never been evaluated.', 'talenttrack' ),
                $name
            );
        }

        $weeks = (int) floor( $this->daysSince( (string) $row->last_eval_date ) / 7 );
        return sprintf(
            /* translators: 1: player name, 2: number of weeks since the last evaluation */
            _n(
                '%1$s has not been evaluated for %2$d week.',
                '%1$s has not been evaluated for %2$d weeks.',
                $weeks,
                'talenttrack'
            ),
            $name,
            $weeks
        );
    }

    /** @return array<string,mixed> */
    protected function payloadFor( object $row ): array {
        return [ 'last_eval_date' => (string) ( $row->last_eval_date ?? '' ) ];
    }

    /** @return list<object> */
    protected function rows( AlertContext $context ): array {
        global $wpdb;
        $p     = $wpdb->prefix;
        $days  = $this->threshold( self::CONFIG_KEY_WEEKS, self::DEFAULT_WEEKS ) * 7;

        // One query, one row per player. The evaluation side is aggregated
        // in a derived table rather than correlated per player: the sweep
        // runs hourly for every club, and MAX() over a grouped scan is the
        // difference between one index pass and one pass per squad member.
        //
        // `club_id IS NULL` is tolerated on the evaluations side because
        // rows predating the #0052 tenancy backfill can still carry it, and
        // treating those as another tenant's would silently mark long-
        // evaluated players as never evaluated.
        //
        // `reference_date` is what the staleness is measured from: the last
        // evaluation when there is one, otherwise the day the player joined.
        // Without that fallback every new arrival is overdue on day one.
        $sql = $wpdb->prepare(
            "SELECT p.id AS player_id, p.first_name, p.last_name, p.team_id,
                    le.last_eval_date,
                    COALESCE( le.last_eval_date, p.date_joined, DATE(p.created_at) ) AS reference_date
               FROM {$p}tt_players p
          LEFT JOIN (
                    SELECT e.player_id, MAX(e.eval_date) AS last_eval_date
                      FROM {$p}tt_evaluations e
                     WHERE e.archived_at IS NULL
                       AND e.trashed_at IS NULL
                       AND ( e.club_id = %d OR e.club_id IS NULL )
                  GROUP BY e.player_id
                 ) le ON le.player_id = p.id
              WHERE " . $this->activePlayerWhere( 'p' ) . "
                AND p.team_id > 0
                AND COALESCE( le.last_eval_date, p.date_joined, DATE(p.created_at) )
                    < DATE_SUB( CURDATE(), INTERVAL %d DAY )"
            . $context->applyScope( self::SUBJECT_TYPE, 'p.id' ) . "
              ORDER BY reference_date ASC, p.id ASC",
            CurrentClub::id(),
            $days
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : [];
    }
}
