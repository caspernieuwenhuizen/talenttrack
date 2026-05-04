<?php
namespace TT\Infrastructure\Journey;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * JourneyBackfillService — on-demand rebuild of `tt_player_events`
 * from existing tables.
 *
 * Used by:
 *   - Migration 0037 ran a one-shot backfill at install time. Since
 *     that migration is tracked in `tt_migrations`, the runner won't
 *     re-fire it for new data created later (demo runs, manual
 *     bulk imports). This service exposes the same logic as a
 *     callable so admin pages can offer a "Rebuild journey events"
 *     button without re-running the migration.
 *
 *   - `Modules\DemoData\Admin\DemoDataPage` exposes a button that
 *     calls `JourneyBackfillService::rebuildAll()` after a demo run
 *     (only relevant for installs that pre-date the v3.91.7 generator
 *     hook patch — fresh demo runs from v3.91.7 onwards emit events
 *     inline via `do_action`).
 *
 * Idempotent: every emit goes through `EventEmitter::emit()` which
 * uk_natural-checks before insert. Re-running on the same data is a
 * no-op; re-running after new rows land fills only the gap.
 *
 * Walks the same source tables migration 0037's backfill walked:
 *   - tt_evaluations         -> evaluation_completed
 *   - tt_pdp_verdicts        -> pdp_verdict_recorded (signed-off only)
 *   - tt_goals               -> goal_set
 *   - tt_players.date_joined -> joined_academy
 *   - tt_trial_cases         -> trial_started + trial_ended (where decided)
 *
 * Scoped to the active club (`CurrentClub::id()`); a future SaaS
 * tenancy switch picks that up automatically.
 */
final class JourneyBackfillService {

    /**
     * Walk every backfillable source table and emit missing events.
     *
     * @return array<string,int> emitted-or-already-existing counts per
     *   event type (the counter is the row-walk count, not strictly the
     *   number of new inserts — EventEmitter is idempotent so the same
     *   number of `emit` calls fire whether or not the rows existed).
     */
    public static function rebuildAll(): array {
        $stats = [
            'evaluation_completed' => 0,
            'pdp_verdict_recorded' => 0,
            'goal_set'             => 0,
            'joined_academy'       => 0,
            'trial_started'        => 0,
            'trial_ended'          => 0,
        ];

        $club_id = CurrentClub::id();

        $stats['evaluation_completed'] = self::backfillEvaluations( $club_id );
        $stats['pdp_verdict_recorded'] = self::backfillPdpVerdicts( $club_id );
        $stats['goal_set']             = self::backfillGoals( $club_id );
        $stats['joined_academy']       = self::backfillPlayersJoined( $club_id );
        [ $started, $ended ]           = self::backfillTrials( $club_id );
        $stats['trial_started']        = $started;
        $stats['trial_ended']          = $ended;

        return $stats;
    }

    private static function backfillEvaluations( int $club_id ): int {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, player_id, eval_date, overall_rating
               FROM {$wpdb->prefix}tt_evaluations
              WHERE archived_at IS NULL AND club_id = %d
              ORDER BY id ASC",
            $club_id
        ) );
        $n = 0;
        foreach ( (array) $rows as $r ) {
            $eval_date = self::dateOnly( $r->eval_date ) . ' 00:00:00';
            EventEmitter::emit(
                (int) $r->player_id,
                'evaluation_completed',
                $eval_date,
                sprintf( __( 'Evaluation on %s', 'talenttrack' ), substr( $eval_date, 0, 10 ) ),
                [
                    'evaluation_id' => (int) $r->id,
                    'overall'       => isset( $r->overall_rating ) ? (float) $r->overall_rating : 0.0,
                ],
                'Evaluations',
                'evaluation',
                (int) $r->id
            );
            $n++;
        }
        return $n;
    }

    private static function backfillPdpVerdicts( int $club_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_pdp_verdicts';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return 0;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT v.id AS verdict_id, v.pdp_file_id, v.decision, v.signed_off_at, f.player_id
               FROM {$wpdb->prefix}tt_pdp_verdicts v
               JOIN {$wpdb->prefix}tt_pdp_files f ON f.id = v.pdp_file_id AND f.club_id = v.club_id
              WHERE v.signed_off_at IS NOT NULL AND v.club_id = %d
              ORDER BY v.id ASC",
            $club_id
        ) );
        $n = 0;
        foreach ( (array) $rows as $r ) {
            EventEmitter::emit(
                (int) $r->player_id,
                'pdp_verdict_recorded',
                (string) $r->signed_off_at,
                sprintf( __( 'PDP verdict: %s', 'talenttrack' ), (string) $r->decision ),
                [ 'pdp_file_id' => (int) $r->pdp_file_id, 'decision' => (string) $r->decision ],
                'Pdp',
                'pdp_verdict',
                (int) $r->verdict_id
            );
            $n++;
        }
        return $n;
    }

    private static function backfillGoals( int $club_id ): int {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, player_id, title, created_at
               FROM {$wpdb->prefix}tt_goals
              WHERE archived_at IS NULL AND club_id = %d
              ORDER BY id ASC",
            $club_id
        ) );
        $n = 0;
        foreach ( (array) $rows as $r ) {
            $title = (string) $r->title;
            EventEmitter::emit(
                (int) $r->player_id,
                'goal_set',
                (string) $r->created_at,
                $title !== '' ? sprintf( __( 'Goal set: %s', 'talenttrack' ), $title ) : __( 'Goal set', 'talenttrack' ),
                [ 'goal_id' => (int) $r->id ],
                'Goals',
                'goal',
                (int) $r->id
            );
            $n++;
        }
        return $n;
    }

    private static function backfillPlayersJoined( int $club_id ): int {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, date_joined
               FROM {$wpdb->prefix}tt_players
              WHERE date_joined IS NOT NULL AND date_joined != '0000-00-00' AND club_id = %d
              ORDER BY id ASC",
            $club_id
        ) );
        $n = 0;
        foreach ( (array) $rows as $r ) {
            $event_date = self::dateOnly( $r->date_joined ) . ' 00:00:00';
            EventEmitter::emit(
                (int) $r->id,
                'joined_academy',
                $event_date,
                __( 'Joined the academy', 'talenttrack' ),
                [],
                'Players',
                'player',
                (int) $r->id
            );
            $n++;
        }
        return $n;
    }

    /**
     * @return array{0:int,1:int} [started_count, ended_count]
     */
    private static function backfillTrials( int $club_id ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_trial_cases';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return [ 0, 0 ];

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, player_id, start_date, end_date, decision, decision_made_at, status
               FROM {$table}
              WHERE archived_at IS NULL AND status != 'draft' AND club_id = %d
              ORDER BY id ASC",
            $club_id
        ) );
        $started = 0;
        $ended   = 0;
        foreach ( (array) $rows as $r ) {
            EventEmitter::emit(
                (int) $r->player_id,
                'trial_started',
                self::dateOnly( $r->start_date ) . ' 00:00:00',
                __( 'Trial started', 'talenttrack' ),
                [ 'trial_case_id' => (int) $r->id ],
                'Trials',
                'trial_case',
                (int) $r->id
            );
            $started++;
            if ( ! empty( $r->decision ) && ! empty( $r->decision_made_at ) ) {
                EventEmitter::emit(
                    (int) $r->player_id,
                    'trial_ended',
                    (string) $r->decision_made_at,
                    sprintf( __( 'Trial ended: %s', 'talenttrack' ), (string) $r->decision ),
                    [
                        'trial_case_id' => (int) $r->id,
                        'decision'      => (string) $r->decision,
                        'context'       => 'post_trial',
                    ],
                    'Trials',
                    'trial_case',
                    (int) $r->id
                );
                $ended++;
            }
        }
        return [ $started, $ended ];
    }

    private static function dateOnly( $value ): string {
        $s = (string) $value;
        if ( strlen( $s ) >= 10 ) return substr( $s, 0, 10 );
        return gmdate( 'Y-m-d' );
    }
}
