<?php
/**
 * Migration 0210 — backfill the #2411 team-archive cascade.
 *
 * Teams archived BEFORE #2411 shipped left their activities fully active, so
 * a retired age group's sessions keep surfacing on planners, dashboards and
 * reports. This sweeps them once: every still-active activity whose team is
 * archived (or trashed) is stamped archived, attributed to user 0 (the
 * system) so it is distinguishable from a coach's own archive.
 *
 * Deliberately NOT reversed by a later team restore: the cascade-reversal in
 * ArchiveRepository::restore() only un-archives what an audited cascade
 * stamped, and this backfill writes no audit payload. Restoring one of these
 * historical teams therefore brings back the team alone — its activities were
 * already meant to be out of the way. Forward-only.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0210_backfill_archived_team_activities';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        // Both tables must exist and carry the lifecycle columns; on a fresh
        // install this runs before any data exists and simply matches nothing.
        $activities = "{$p}tt_activities";
        $teams      = "{$p}tt_teams";
        foreach ( [ $activities, $teams ] as $table ) {
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;
        }

        $wpdb->query(
            "UPDATE {$activities} a
               INNER JOIN {$teams} t
                  ON t.id = a.team_id AND t.club_id = a.club_id
                SET a.archived_at = NOW(), a.archived_by = 0
              WHERE a.archived_at IS NULL
                AND ( t.archived_at IS NOT NULL OR t.trashed_at IS NOT NULL )"
        );
    }

    public function down(): void {
        // Forward-only: we cannot tell these rows apart from activities a
        // coach archived by hand afterwards.
    }
};
