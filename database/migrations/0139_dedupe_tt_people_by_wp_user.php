<?php
/**
 * Migration 0139 — Deduplicate active `tt_people` rows that share a
 * `wp_user_id` within the same club (#1104).
 *
 * Pilot triage 2026-06-01 surfaced: an Assistant Coach (Jeroen,
 * wp_user_id=10) had **two** active tt_people rows (id=7 and id=30).
 * AuthorizationService::getPersonIdByUserId did a plain `LIMIT 1` with
 * no `ORDER BY`, picked id=7, looked up team assignments on the stale
 * row, found none, returned zero scopes. Meanwhile the admin Persoon
 * edit page used id=30 (the real one with the team assignment) — two
 * sources of truth silently disagreed for the entire pilot.
 *
 * This migration finds every duplicate cluster (an active tt_people row
 * sharing `(wp_user_id, club_id)` with at least one other active row),
 * picks a winner per cluster, and sets the losers to
 * `status='inactive'`. Losers are NOT deleted — operator can recover
 * any inactive row by name lookup if a real merge is needed.
 *
 * Winner tiebreak (data-richer first, then newest):
 *   1. Most `tt_team_people` rows referencing the person id. This is
 *      the canonical "is this Persoon on a real team?" signal and the
 *      direct cause of the pilot symptom (resolver looked at the row
 *      with zero team_people rows). Other person_id-referencing tables
 *      (`tt_staff_development`, `tt_invitations`, `tt_player_parents`)
 *      could be added if the heuristic ever needs sharpening; for the
 *      shapes seen in pilot, team_people alone picks the right winner.
 *   2. On ties, highest `id` (most recently created — matches the
 *      AuthorizationService ORDER BY id DESC tiebreak so resolver and
 *      migration agree).
 *
 * Each loser row gets logged to `tt_audit_log` under
 * `action='person.deduped'` carrying the winner id, the loser id, and
 * the wp_user_id, so an operator can reconcile if they suspect a wrong
 * winner was picked.
 *
 * Idempotent — re-running on an already-clean install is a no-op.
 * Forward-only — flipping inactive rows back to active would re-create
 * the resolver ambiguity. Operators who want to revive an inactivated
 * row can do so explicitly via the People admin.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0139_dedupe_tt_people_by_wp_user';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $people_t = "{$p}tt_people";
        $team_p_t = "{$p}tt_team_people";
        $audit_t  = "{$p}tt_audit_log";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $people_t ) ) !== $people_t ) {
            return;
        }

        // Find every `(wp_user_id, club_id)` pair where more than one
        // active row claims it. NULL wp_user_id is excluded — unlinked
        // person records are not duplicates of each other.
        $clusters = $wpdb->get_results(
            "SELECT wp_user_id, club_id, COUNT(*) AS n
               FROM {$people_t}
              WHERE wp_user_id IS NOT NULL
                AND status     = 'active'
              GROUP BY wp_user_id, club_id
             HAVING n > 1"
        );
        if ( empty( $clusters ) ) return;

        $audit_t_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $audit_t ) ) === $audit_t;

        foreach ( $clusters as $c ) {
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT p.id,
                        ( SELECT COUNT(*) FROM {$team_p_t} tp WHERE tp.person_id = p.id ) AS rel_count
                   FROM {$people_t} p
                  WHERE p.wp_user_id = %d
                    AND p.club_id    = %d
                    AND p.status     = 'active'
                  ORDER BY rel_count DESC, p.id DESC",
                (int) $c->wp_user_id, (int) $c->club_id
            ) );
            if ( count( $rows ) < 2 ) continue;

            $winner = array_shift( $rows );
            foreach ( $rows as $loser ) {
                $wpdb->update(
                    $people_t,
                    [ 'status' => 'inactive' ],
                    [ 'id' => (int) $loser->id, 'club_id' => (int) $c->club_id ]
                );

                if ( $audit_t_exists ) {
                    $wpdb->insert( $audit_t, [
                        'club_id'     => (int) $c->club_id,
                        'user_id'     => 0,
                        'action'      => 'person.deduped',
                        'entity_type' => 'person',
                        'entity_id'   => (int) $loser->id,
                        'payload'     => (string) wp_json_encode( [
                            'reason'         => 'migration_0139',
                            'wp_user_id'     => (int) $c->wp_user_id,
                            'winner_id'      => (int) $winner->id,
                            'winner_rel_cnt' => (int) $winner->rel_count,
                            'loser_id'       => (int) $loser->id,
                            'loser_rel_cnt'  => (int) $loser->rel_count,
                        ] ),
                        'ip_address'  => '',
                        'created_at'  => current_time( 'mysql' ),
                    ] );
                }
            }
        }
    }

    public function down(): void {
        // Forward-only. Reverting would re-introduce the resolver
        // ambiguity this migration is closing. Operators who need a
        // specific inactivated row revived can do so via the People
        // admin — the audit log records the winner/loser mapping.
    }
};
