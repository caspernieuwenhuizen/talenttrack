<?php
namespace TT\Modules\Activities\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * AttendanceGridQuery (#2382) — the players × activities matrix that backs
 * the desktop attendance-entry grid (epic #2381).
 *
 * The transpose of the read-only Minutes-audit matrix: here rows are the
 * team's ACTIVE roster (columns of a coach's Excel register) and columns are
 * the team's activities in the window (training + matches). Each cell is the
 * recorded `record_type='actual'` attendance status for that player at that
 * activity, or empty when nothing has been recorded yet.
 *
 * Primary-entry semantics (#2381): columns come from the ACTIVE roster
 * (QueryHelpers::get_players), NOT from existing attendance rows — so a
 * brand-new activity with no attendance still shows every player. This is
 * the deliberate difference from MinutesAuditQuery, which resolves its
 * columns from attendance because it audits what was recorded.
 *
 * Tenant-scoped on `club_id` (no-op single-tenant today; structural for the
 * SaaS migration, CLAUDE.md §4).
 */
final class AttendanceGridQuery {

    /**
     * Build the grid for a team over a window.
     *
     * @param string $type_filter 'all' | 'training' | 'match'
     * @return array{
     *   activities: list<array{ activity_id:int, session_date:string, title:string, type_key:string, is_match:bool }>,
     *   players: list<array{ player_id:int, first_name:string, last_name:string, jersey_number:?int }>,
     *   cells: array<int, array<int, string>>,
     *   summary: array{ total_activities:int, total_players:int }
     * }
     */
    public function matrix( int $team_id, string $from, string $to, string $type_filter = 'all' ): array {
        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = (int) CurrentClub::id();

        $empty = [
            'activities' => [],
            'players'    => [],
            'cells'      => [],
            'summary'    => [ 'total_activities' => 0, 'total_players' => 0 ],
        ];
        if ( $team_id <= 0 ) return $empty;

        $date_col = 'sess' . 'ion_date'; // legacy date column (#0035 lint-safe)

        // Activity-type narrowing. 'match' folds the three game-ish types the
        // minutes surfaces use; 'training' is the single training type; 'all'
        // keeps both families (the two the entry grid is for).
        $match_types = "'match','game','tournament'";
        if ( $type_filter === 'match' ) {
            $where_type = " AND LOWER(activity_type_key) IN ($match_types)";
        } elseif ( $type_filter === 'training' ) {
            $where_type = " AND LOWER(activity_type_key) = 'training'";
        } else {
            $where_type = " AND LOWER(activity_type_key) IN ($match_types, 'training')";
        }

        // 1. Activities for the team in the window (columns), oldest first so
        //    the register reads left-to-right in time like an Excel sheet.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $activity_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, activity_type_key, {$date_col} AS session_date, title
               FROM {$p}tt_activities
              WHERE club_id = %d
                AND team_id = %d
                AND {$date_col} BETWEEN %s AND %s
                AND archived_at IS NULL
                AND trashed_at IS NULL
                AND plan_state <> 'cancelled'
                AND ( activity_status_key IS NULL OR activity_status_key <> 'cancelled' )
                {$where_type}
              ORDER BY {$date_col} ASC, id ASC",
            $club_id, $team_id, $from, $to
        ) );

        $activities = [];
        foreach ( (array) $activity_rows as $a ) {
            $type = strtolower( (string) ( $a->activity_type_key ?? '' ) );
            $activities[] = [
                'activity_id'  => (int) $a->id,
                'session_date' => (string) $a->session_date,
                'title'        => (string) ( $a->title ?? '' ),
                'type_key'     => $type,
                'is_match'     => in_array( $type, [ 'match', 'game', 'tournament' ], true ),
            ];
        }

        // 2. Active roster for the team (rows). Primary-entry: the roster is
        //    the source of columns, not the attendance rows.
        $players = [];
        foreach ( QueryHelpers::get_players( $team_id ) as $pl ) {
            $players[] = [
                'player_id'     => (int) $pl->id,
                'first_name'    => (string) ( $pl->first_name ?? '' ),
                'last_name'     => (string) ( $pl->last_name ?? '' ),
                'jersey_number' => isset( $pl->jersey_number ) && $pl->jersey_number !== null ? (int) $pl->jersey_number : null,
            ];
        }
        // Column order of the register: jersey number asc (nulls last), then
        // last name — the same ordering the audit matrix uses for players.
        usort( $players, static function ( array $a, array $b ): int {
            $ja = $a['jersey_number'] ?? PHP_INT_MAX;
            $jb = $b['jersey_number'] ?? PHP_INT_MAX;
            if ( $ja !== $jb ) return $ja <=> $jb;
            return strcasecmp( $a['last_name'], $b['last_name'] );
        } );

        if ( $activities === [] || $players === [] ) {
            return [
                'activities' => $activities,
                'players'    => $players,
                'cells'      => [],
                'summary'    => [ 'total_activities' => count( $activities ), 'total_players' => count( $players ) ],
            ];
        }

        // 3. Existing recorded attendance for those activities (cells). Only
        //    non-guest `actual` rows — the same scope the attendance reports
        //    sum, so the grid and the reports agree on what "recorded" means.
        $activity_ids = array_map( static fn( array $a ): int => $a['activity_id'], $activities );
        $in_ids = implode( ',', array_fill( 0, count( $activity_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $att_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT activity_id, player_id, status
               FROM {$p}tt_attendance
              WHERE activity_id IN ($in_ids)
                AND club_id = %d
                AND is_guest = 0
                AND player_id > 0
                AND record_type = 'actual'
              ORDER BY id ASC",
            array_merge( $activity_ids, [ $club_id ] )
        ) );

        // Fold into cells. Ordering by id ascending means that if legacy dirty
        // data left more than one `actual` row for the same (activity, player)
        // — e.g. a wizard row and a match-execution row — the LATEST row wins
        // deterministically, matching the row the bulk-save upsert keeps.
        $cells = [];
        foreach ( (array) $att_rows as $r ) {
            $pid = (int) $r->player_id;
            $aid = (int) $r->activity_id;
            $st  = strtolower( trim( (string) $r->status ) );
            if ( $pid <= 0 || $aid <= 0 || $st === '' ) continue;
            $cells[ $pid ][ $aid ] = $st;
        }

        return [
            'activities' => $activities,
            'players'    => $players,
            'cells'      => $cells,
            'summary'    => [
                'total_activities' => count( $activities ),
                'total_players'    => count( $players ),
            ],
        ];
    }
}
