<?php
namespace TT\Infrastructure\Journey;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Enums\GoalOrigin;
use TT\Domain\Vocabularies\Lookups\JourneyEventType;
use TT\Infrastructure\Evaluations\EvalRatingsRepository;
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
     *   `position_repaired` is the exception: it counts rows actually
     *   rewritten, so a second run reports zero.
     */
    public static function rebuildAll(): array {
        $stats = [
            'evaluation_completed' => 0,
            'pdp_verdict_recorded' => 0,
            'goal_set'             => 0,
            'joined_academy'       => 0,
            'trial_started'        => 0,
            'trial_ended'          => 0,
            'position_repaired'    => 0,
        ];

        $club_id = CurrentClub::id();

        $stats['evaluation_completed'] = self::backfillEvaluations( $club_id );
        $stats['pdp_verdict_recorded'] = self::backfillPdpVerdicts( $club_id );
        $stats['goal_set']             = self::backfillGoals( $club_id );
        $stats['joined_academy']       = self::backfillPlayersJoined( $club_id );
        [ $started, $ended ]           = self::backfillTrials( $club_id );
        $stats['trial_started']        = $started;
        $stats['trial_ended']          = $ended;
        $stats['position_repaired']    = self::repairPositionEvents( $club_id );

        return $stats;
    }

    /**
     * #3767 — the same overall the live hook writes, batched.
     *
     * `EvalRatingsRepository::overallRatingsForEvaluations()` resolves every
     * evaluation in two roundtrips, so the rebuild agrees with
     * `JourneyEventSubscriber` without a query per row. An evaluation with
     * neither a weighted overall nor a legacy `rating` gets no `overall`
     * key at all — "not scored" is not the same claim as "scored zero".
     *
     * Existing rows are rewritten rather than skipped: `EventEmitter::emit()`
     * is insert-only, so the installs carrying `overall: 0` on every
     * evaluation are exactly the ones a rebuild would otherwise leave wrong.
     */
    private static function backfillEvaluations( int $club_id ): int {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, player_id, eval_date, rating
               FROM {$wpdb->prefix}tt_evaluations
              WHERE archived_at IS NULL AND club_id = %d
              ORDER BY id ASC",
            $club_id
        ) );
        $rows = (array) $rows;
        if ( $rows === [] ) return 0;

        $legacy = [];
        foreach ( $rows as $r ) {
            $legacy[ (int) $r->id ] = $r->rating;
        }
        $overalls = ( new EvalRatingsRepository() )->overallRatingsForEvaluations( array_keys( $legacy ) );

        $n = 0;
        foreach ( $rows as $r ) {
            $eval_id   = (int) $r->id;
            $eval_date = self::dateOnly( $r->eval_date ) . ' 00:00:00';

            $payload = [ 'evaluation_id' => $eval_id ];
            $overall = $overalls[ $eval_id ]['value'] ?? null;
            if ( $overall === null && $legacy[ $eval_id ] !== null && $legacy[ $eval_id ] !== '' ) {
                $overall = (float) $legacy[ $eval_id ];
            }
            if ( $overall !== null ) $payload['overall'] = (float) $overall;

            $event_id = EventEmitter::emit(
                (int) $r->player_id,
                JourneyEventType::EVALUATION_COMPLETED,
                $eval_date,
                sprintf( __( 'Evaluation on %s', 'talenttrack' ), substr( $eval_date, 0, 10 ) ),
                $payload,
                'Evaluations',
                'evaluation',
                $eval_id
            );
            if ( $event_id !== null ) {
                EventEmitter::refreshPayload( $event_id, JourneyEventType::EVALUATION_COMPLETED, $payload );
            }
            $n++;
        }
        return $n;
    }

    /**
     * #3767 — scrub the raw `[]` older position-change rows baked in.
     *
     * Clearing a player's preferred positions used to emit "Position:
     * Centre forward → []", and the emit is insert-only so the row stays
     * wrong however often the source is re-saved. The literal is replaced
     * with the same "none" wording a first-time position would read, in
     * the summary and in the `from` / `to` payload keys alike. Idempotent:
     * a row with no `[]` left in it is skipped.
     *
     * Migration 0185 did the same job for the raw-code case; this is the
     * empty-array case it did not reach.
     */
    private static function repairPositionEvents( int $club_id ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_player_events';

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, summary, payload
               FROM {$table}
              WHERE source_entity_type = %s
                AND club_id = %d
                AND ( summary LIKE %s OR payload LIKE %s )",
            'position_change',
            $club_id,
            '%[]%',
            '%[]%'
        ) );

        $none = __( 'none', 'talenttrack' );
        $n    = 0;
        foreach ( (array) $rows as $r ) {
            $summary = str_replace( '[]', $none, (string) $r->summary );
            $payload = (string) $r->payload;
            $decoded = json_decode( $payload, true );
            if ( is_array( $decoded ) ) {
                foreach ( [ 'from', 'to' ] as $k ) {
                    if ( isset( $decoded[ $k ] ) && is_string( $decoded[ $k ] ) && trim( $decoded[ $k ] ) === '[]' ) {
                        $decoded[ $k ] = '';
                    }
                }
                $encoded = wp_json_encode( $decoded );
                if ( $encoded !== false ) $payload = $encoded;
            }

            if ( $summary === (string) $r->summary && $payload === (string) $r->payload ) continue;

            $wpdb->update(
                $table,
                [ 'summary' => mb_substr( $summary, 0, 500 ), 'payload' => $payload ],
                [ 'id' => (int) $r->id, 'club_id' => $club_id ],
                [ '%s', '%s' ],
                [ '%d', '%d' ]
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
                JourneyEventType::PDP_VERDICT_RECORDED,
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
                JourneyEventType::GOAL_SET,
                (string) $r->created_at,
                $title !== '' ? sprintf( __( 'Goal set: %s', 'talenttrack' ), $title ) : __( 'Goal set', 'talenttrack' ),
                // #3131 — a stored goal carries no record of what wrote it,
                // so a backfilled entry reads as somebody having set it.
                // Only goals written from here on know better.
                [ 'goal_id' => (int) $r->id, 'origin' => GoalOrigin::SET ],
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
                JourneyEventType::JOINED_ACADEMY,
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
                JourneyEventType::TRIAL_STARTED,
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
                    JourneyEventType::TRIAL_ENDED,
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
