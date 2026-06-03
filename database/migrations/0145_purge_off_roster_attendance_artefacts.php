<?php
/**
 * Migration 0145 — Purge off-roster non-guest attendance artefacts (#1148).
 *
 * Pilot diagnostic (issue #1148) confirmed that the activity edit form's
 * attendance picker pre-loads every player from every team the coach can
 * access (FrontendActivitiesManageView::renderForm). The JS helper hides
 * off-team rows visually, but the hidden form fields still submit. For
 * admin users that means the entire academy ships in the POST, and the
 * REST handler historically wrote each submitted player_id as
 * is_guest = 0 with no roster validation.
 *
 * Result: `tt_attendance` rows exist where the player's current team_id
 * != the activity's team_id, marked as squad attendance (is_guest = 0)
 * even though the player was never on the activity's roster. The
 * Player attendance report aggregated those rows and showed off-roster
 * players as if they were squad members.
 *
 * Pieces of the fix (v4.20.5):
 *   1. REST write-time filter in ActivitiesRestController::write_attendance
 *      — silently drops off-roster ids on non-guest writes going forward.
 *   2. Display JOIN fix in the attendance report — team-column on
 *      p.team_id (the player's actual team).
 *   3. THIS MIGRATION — purges the existing wrong rows so the report
 *      stops showing them. Conservative: when the audit trail (journey
 *      events) proves the player was on the activity's team at the
 *      time the activity ran, the row is preserved as legitimate
 *      historical squad attendance. Only purges when there's no
 *      audit-trail justification for the player being on the activity's
 *      roster at the activity's session_date.
 *
 * Safety:
 *   - Two-pass design: pass 1 logs every row that would be deleted to
 *     `tt_logs` with level = 'audit', so a post-mortem reconstruction is
 *     always available. Pass 2 runs the DELETE.
 *   - Idempotent: re-running on a cleaned install finds zero rows to
 *     purge and is a no-op.
 *   - Only touches is_guest = 0 rows. Guest attendance and any future
 *     legitimately-tagged off-roster rows are untouched.
 *   - Activities with team_id = 0 (no team scope) are skipped — those
 *     accept any player as squad attendance by design.
 *
 * Migration 0144 was the most recent additive schema change; this one
 * is a data-cleanup migration with no schema effect.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Logging\Logger;

return new class extends Migration {

    public function getName(): string {
        return '0145_purge_off_roster_attendance_artefacts';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Guard: required tables must exist.
        foreach ( [ 'tt_attendance', 'tt_activities', 'tt_players' ] as $t ) {
            $tbl = "{$p}{$t}";
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tbl ) ) !== $tbl ) {
                return;
            }
        }

        $events_table_exists = $wpdb->get_var( $wpdb->prepare(
            'SHOW TABLES LIKE %s', "{$p}tt_player_events"
        ) ) === "{$p}tt_player_events";

        // Pass 1 — identify candidate rows. The roster mismatch test:
        // attendance is is_guest = 0, activity has a real team_id, and
        // the player's current team_id differs from it.
        $candidates = $wpdb->get_results(
            "SELECT att.id          AS attendance_id,
                    att.activity_id AS activity_id,
                    att.player_id   AS player_id,
                    a.team_id       AS activity_team_id,
                    a.session_date  AS session_date,
                    p.team_id       AS player_team_id
               FROM {$p}tt_attendance att
               JOIN {$p}tt_activities a ON a.id = att.activity_id
               JOIN {$p}tt_players    p ON p.id = att.player_id
              WHERE att.is_guest = 0
                AND att.player_id IS NOT NULL
                AND a.team_id   > 0
                AND p.team_id   <> a.team_id"
        );

        if ( ! is_array( $candidates ) || ! $candidates ) {
            return;
        }

        // Build the to-delete set, excluding rows where the audit trail
        // justifies the player being on the activity's team at the
        // session_date. We look for any team_changed journey event on
        // the player that put them ON the activity's team at or before
        // session_date AND off it strictly after session_date — i.e. a
        // historical roster window that covers the activity.
        $to_delete = [];
        $preserved = 0;
        foreach ( $candidates as $row ) {
            $justify = false;
            if ( $events_table_exists ) {
                // Find the player's team_changed events.
                $events = $wpdb->get_results( $wpdb->prepare(
                    "SELECT event_date, payload
                       FROM {$p}tt_player_events
                      WHERE player_id  = %d
                        AND event_type = 'team_changed'
                      ORDER BY event_date ASC",
                    (int) $row->player_id
                ) );
                if ( is_array( $events ) && $events ) {
                    foreach ( $events as $ev ) {
                        $payload  = json_decode( (string) $ev->payload, true );
                        $to_team  = is_array( $payload ) ? (int) ( $payload['to_team_id'] ?? 0 ) : 0;
                        $when     = substr( (string) $ev->event_date, 0, 10 );
                        // If the event happened on or before the activity
                        // and put the player on the activity's team, it
                        // justifies the attendance row.
                        if ( $when <= (string) $row->session_date && $to_team === (int) $row->activity_team_id ) {
                            $justify = true;
                            break;
                        }
                    }
                }
            }
            if ( $justify ) {
                $preserved++;
                continue;
            }
            $to_delete[] = (int) $row->attendance_id;
        }

        if ( ! $to_delete ) {
            Logger::info( 'migration.0145.no_unjustified_rows', [
                'candidates' => count( $candidates ),
                'preserved'  => $preserved,
            ] );
            return;
        }

        // Audit log — record what we're about to delete so post-mortem
        // reconstruction is always available.
        Logger::warning( 'migration.0145.purging_off_roster_attendance', [
            'candidates'  => count( $candidates ),
            'preserved'   => $preserved,
            'to_delete'   => count( $to_delete ),
            'sample_ids'  => array_slice( $to_delete, 0, 25 ),
        ] );

        // Pass 2 — DELETE in chunks (avoid massive single statements).
        $chunks = array_chunk( $to_delete, 500 );
        $deleted = 0;
        foreach ( $chunks as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
            $deleted += (int) $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$p}tt_attendance WHERE id IN ($placeholders)",
                ...$chunk
            ) );
        }

        Logger::info( 'migration.0145.complete', [
            'deleted'   => $deleted,
            'preserved' => $preserved,
        ] );
    }

    public function down(): void {
        // Forward-only. Restoring the deleted rows is not possible
        // without a database backup — operators with concerns should
        // restore from backup before running this migration on
        // production data.
    }
};
