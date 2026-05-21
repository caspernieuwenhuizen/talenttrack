<?php
namespace TT\Modules\PersonaDashboard\TableSources;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\PersonaDashboard\Registry\TableRowSource;

/**
 * UpcomingActivitiesSource (#0073) — forward-looking activity rows for the
 * `upcoming_activities` DataTableWidget preset.
 *
 * Rows: [team name, activity-type label, date+time, location/comment].
 * Window default = next 14 days. Scoped to current club; the matrix
 * grants `activities R global` to HoD + Academy Admin so no further
 * row-level gate is needed.
 */
final class UpcomingActivitiesSource implements TableRowSource {

    /**
     * @param array<string, mixed> $config
     * @return list<list<string>>
     */
    public function rowsFor( int $user_id, array $config ): array {
        global $wpdb;
        $p       = $wpdb->prefix;
        // v3.110.126 — default window bumped from 14 → 30 days per
        // pilot ask ("upcoming activities does not show anything"
        // — the actual activities were scheduled 3+ weeks out). The
        // `config['days']` override remains, capped at 90 days.
        $days    = max( 1, min( 90, (int) ( $config['days'] ?? 30 ) ) );
        $limit   = max( 1, min( 50, (int) ( $config['limit'] ?? 15 ) ) );
        $club_id = CurrentClub::id();

        // v4.0.5 (#858) — date-inclusive lower bound. The previous
        // time-inclusive `CONCAT(session_date, start_time) >= NOW()`
        // filter excluded any activity that had already started today,
        // even if it hadn't ended — an HoD opening the dashboard at
        // 09:00 on a day with an 08:00 training saw the widget empty.
        // "Upcoming" here means "today or later", aligned with the
        // coach hero's UpcomingActivityRepository.
        $from = ( new \DateTimeImmutable( 'today' ) )->format( 'Y-m-d' );
        $to   = ( new \DateTimeImmutable( "+{$days} days" ) )->format( 'Y-m-d 23:59:59' );

        // v3.110.182 (#781) — demo-mode scope so the upcoming-activities
        // data table matches what the activities list page shows under
        // the same toggle.
        $scope = QueryHelpers::apply_demo_scope( 's', 'activity' );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT s.id,
                    COALESCE(t.name, '') AS team_name,
                    COALESCE(at.name, '') AS type_name,
                    s.session_date,
                    s.start_time,
                    COALESCE(s.location, '') AS location
               FROM {$p}tt_activities s
               LEFT JOIN {$p}tt_teams t ON t.id = s.team_id AND t.club_id = s.club_id
               LEFT JOIN {$p}tt_lookups at ON at.id = s.activity_type_id
                                           AND at.club_id = s.club_id
                                           AND at.lookup_type = 'activity_type'
              WHERE s.club_id = %d
                AND s.archived_at IS NULL
                AND s.session_date >= %s
                AND s.session_date <= %s
                {$scope}
              ORDER BY s.session_date ASC, s.start_time ASC
              LIMIT %d",
            $club_id, $from, $to, $limit
        ) );

        if ( ! is_array( $rows ) || $rows === [] ) return [];

        return array_map( [ $this, 'formatRow' ], $rows );
    }

    /** @return list<string> */
    private function formatRow( object $r ): array {
        $when = '';
        if ( ! empty( $r->session_date ) ) {
            try {
                $datetime = new \DateTimeImmutable( $r->session_date . ' ' . ( $r->start_time ?? '00:00:00' ) );
                $when     = wp_date( 'D j M, H:i', $datetime->getTimestamp() );
            } catch ( \Exception $e ) {
                $when = (string) $r->session_date;
            }
        }
        return [
            esc_html( (string) $r->team_name !== '' ? (string) $r->team_name : '—' ),
            esc_html( (string) $r->type_name !== '' ? (string) $r->type_name : '—' ),
            esc_html( $when !== '' ? $when : '—' ),
            esc_html( (string) $r->location !== '' ? (string) $r->location : '—' ),
        ];
    }
}
