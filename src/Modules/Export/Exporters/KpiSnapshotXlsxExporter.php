<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;
use TT\Modules\Export\ExportValueFormatter;
use TT\Modules\Players\Repositories\PlayerPotentialRepository;

/**
 * KpiSnapshotXlsxExporter (#865) — point-in-time KPI snapshot for board
 * meetings / quarterly reviews.
 *
 * Sheet 1, one row per KPI:
 *   - Active players
 *   - Total players (incl. archived / trial)
 *   - Active teams
 *   - Activities in period
 *   - Evaluations in period
 *   - Attendance: present rows / total
 *   - Goals: active / completed / total
 *   - Potential: how many active players have a band recorded, and how many
 *     do not
 *
 * Sheet 2, one row per active player: their current potential band and when
 * it was recorded (#3414).
 *
 * ## Why potential is here and not on the roster CSV
 *
 * A potential band is a staff judgement about a minor. `PlayersListCsvExporter`
 * is a roster and contact sheet that gets mailed around, and putting the band
 * on it would change who can carry that judgement out of the system; this
 * export is already staff-scoped and analytical, and a KPI snapshot missing
 * the academy's only recorded answer to *where is this player going* is
 * missing its point (#3385).
 *
 * The band is read through `PlayerPotentialRepository::latestFor()` — the
 * accessor `PlayerStatusCalculator` uses — so the sheet and the player's
 * status dot can never disagree about what the current band is. One query
 * per active player, which a periodic export can afford and a disagreement
 * with the traffic light is not.
 *
 * URL:
 *   `POST /wp-json/talenttrack/v1/exports/kpi_snapshot?format=xlsx`
 *   filters:
 *     `date_from` (Y-m-d, default first day of current month)
 *     `date_to`   (Y-m-d, default today)
 *
 * Cap: `tt_view_reports`.
 */
final class KpiSnapshotXlsxExporter implements ExporterInterface {

    public function key(): string { return 'kpi_snapshot'; }

    public function label(): string { return __( 'KPI snapshot', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'xlsx' ]; }

    public function requiredCap(): string { return 'tt_view_reports'; }

    /**
     * Metric × value rows — toggling either column makes no sense,
     * so this exporter opts out of the column picker by returning
     * an empty map (#986).
     */
    public function availableColumns(): array {
        return [];
    }

    public function validateFilters( array $raw ): ?array {
        $date_from = isset( $raw['date_from'] ) ? (string) $raw['date_from'] : '';
        $date_to   = isset( $raw['date_to'] )   ? (string) $raw['date_to']   : '';
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
            $date_from = ( new \DateTime( 'first day of this month', wp_timezone() ) )->format( 'Y-m-d' );
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
            $date_to = ( new \DateTime( 'today', wp_timezone() ) )->format( 'Y-m-d' );
        }
        if ( $date_from > $date_to ) {
            [ $date_from, $date_to ] = [ $date_to, $date_from ];
        }
        return [ 'date_from' => $date_from, 'date_to' => $date_to ];
    }

    public function collect( ExportRequest $request ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $club_id   = (int) $request->clubId;
        $date_from = (string) ( $request->filters['date_from'] ?? '' );
        $date_to   = (string) ( $request->filters['date_to']   ?? '' );

        $active_players = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_players WHERE club_id = %d AND status = 'active'",
            $club_id
        ) );
        $total_players = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_players WHERE club_id = %d",
            $club_id
        ) );
        $active_teams = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_teams WHERE club_id = %d AND ( archived_at IS NULL OR archived_at = '0000-00-00 00:00:00' )",
            $club_id
        ) );
        $activities_in_period = (int) $wpdb->get_var( $wpdb->prepare(
            // v4.20.44 (#1222) — added `archived_at IS NULL` so soft-archived
            // activities stop polluting HoD's KPI snapshot. Audit 7 (#1181).
            "SELECT COUNT(*) FROM {$p}tt_activities WHERE club_id = %d AND archived_at IS NULL AND session_date BETWEEN %s AND %s",
            $club_id, $date_from, $date_to
        ) );
        $evaluations_in_period = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_evaluations e
                INNER JOIN {$p}tt_players pl ON pl.id = e.player_id
                WHERE pl.club_id = %d AND e.archived_at IS NULL
                  AND e.eval_date BETWEEN %s AND %s",
            $club_id, $date_from, $date_to
        ) );
        $attendance_total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_attendance att
                INNER JOIN {$p}tt_activities a ON a.id = att.activity_id AND a.club_id = att.club_id
                WHERE att.club_id = %d
                  AND att.record_type = 'actual'
                  AND a.plan_state = 'completed'
                  AND a.session_date BETWEEN %s AND %s",
            $club_id, $date_from, $date_to
        ) );
        $attendance_present = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_attendance att
                INNER JOIN {$p}tt_activities a ON a.id = att.activity_id AND a.club_id = att.club_id
                WHERE att.club_id = %d
                  AND att.record_type = 'actual'
                  AND a.plan_state = 'completed'
                  AND a.session_date BETWEEN %s AND %s
                  AND att.status = 'present'",
            $club_id, $date_from, $date_to
        ) );
        $attendance_pct = $attendance_total > 0
            ? round( ( $attendance_present / $attendance_total ) * 100, 1 )
            : '';

        $goals_total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_goals WHERE club_id = %d",
            $club_id
        ) );
        $goals_active = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_goals WHERE club_id = %d AND status IN ('pending', 'in_progress')",
            $club_id
        ) );
        $goals_completed = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_goals WHERE club_id = %d AND status = 'completed'",
            $club_id
        ) );

        $potential = $this->potentialRows( $club_id );
        $with_band = 0;
        foreach ( $potential as $player_row ) {
            if ( $player_row[2] !== '' ) $with_band++;
        }

        $headers = [
            __( 'Metric', 'talenttrack' ),
            __( 'Value',  'talenttrack' ),
        ];

        $rows = [
            [ __( 'Snapshot range from', 'talenttrack' ), $date_from ],
            [ __( 'Snapshot range to',   'talenttrack' ), $date_to ],
            [ __( 'Generated at',        'talenttrack' ), gmdate( 'Y-m-d H:i:s' ) . ' UTC' ],
            [ __( 'Active players',      'talenttrack' ), $active_players ],
            [ __( 'Total players',       'talenttrack' ), $total_players ],
            [ __( 'Active teams',        'talenttrack' ), $active_teams ],
            [ __( 'Activities in period','talenttrack' ), $activities_in_period ],
            [ __( 'Evaluations in period','talenttrack' ), $evaluations_in_period ],
            [ __( 'Attendance rows in period', 'talenttrack' ), $attendance_total ],
            [ __( 'Attendance present', 'talenttrack' ), $attendance_present ],
            [ __( 'Attendance present %', 'talenttrack' ), $attendance_pct ],
            [ __( 'Goals — total',      'talenttrack' ), $goals_total ],
            [ __( 'Goals — active',     'talenttrack' ), $goals_active ],
            [ __( 'Goals — completed',  'talenttrack' ), $goals_completed ],
            [ __( 'Potential — band recorded', 'talenttrack' ), $with_band ],
            [ __( 'Potential — no band recorded', 'talenttrack' ), count( $potential ) - $with_band ],
        ];

        return [
            'sheets' => [
                __( 'KPI snapshot', 'talenttrack' ) => [ $headers, $rows ],
                self::potentialSheetName()          => [
                    [
                        __( 'Player', 'talenttrack' ),
                        __( 'Team', 'talenttrack' ),
                        __( 'Potential band', 'talenttrack' ),
                        __( 'Recorded on', 'talenttrack' ),
                    ],
                    $potential,
                ],
            ],
        ];
    }

    /**
     * Tab name for the per-player sheet.
     *
     * `_x()` rather than `__()`: one word on its own picks up whichever
     * sense a translator met first, and "Potential" has two other uses in
     * the product already.
     */
    public static function potentialSheetName(): string {
        return _x( 'Potential', 'export sheet name — potential band per player', 'talenttrack' );
    }

    /**
     * One row per active player: name, team, current band, date recorded.
     *
     * A player with no potential row gets an empty cell rather than a
     * guessed default. On an academy that has not been through a potential
     * round this is a visibly sparse column, and that is the honest
     * rendering — a default would read as a judgement nobody made.
     *
     * Active players only, matching the "Active players" metric on the
     * first sheet: a released player's band is history, not a snapshot of
     * where the academy is now.
     *
     * @return list<list<string>>
     */
    private function potentialRows( int $club_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $players = $wpdb->get_results( $wpdb->prepare(
            "SELECT pl.id, pl.first_name, pl.last_name, COALESCE( t.name, '' ) AS team_name
               FROM {$p}tt_players pl
          LEFT JOIN {$p}tt_teams t ON t.id = pl.team_id AND t.club_id = pl.club_id
              WHERE pl.club_id = %d AND pl.status = 'active'
              ORDER BY pl.last_name ASC, pl.first_name ASC, pl.id ASC",
            $club_id
        ) );

        $repo = new PlayerPotentialRepository();
        $out  = [];

        foreach ( is_array( $players ) ? $players : [] as $player ) {
            $latest = $repo->latestFor( (int) $player->id );

            $out[] = [
                trim( (string) ( $player->first_name ?? '' ) . ' ' . (string) ( $player->last_name ?? '' ) ),
                (string) ( $player->team_name ?? '' ),
                $latest === null
                    ? ''
                    : ExportValueFormatter::potentialBand( (string) ( $latest->potential_band ?? '' ) ),
                $latest === null
                    ? ''
                    : substr( (string) ( $latest->set_at ?? '' ), 0, 10 ),
            ];
        }

        return $out;
    }
}
