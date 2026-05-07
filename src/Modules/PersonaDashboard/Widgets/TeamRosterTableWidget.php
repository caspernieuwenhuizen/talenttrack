<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupPill;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;

/**
 * TeamRosterTableWidget (#0089 A4) — per-team player table for the
 * HoD dashboard. Distinct from `TeamOverviewGridWidget` (#0073) which
 * lists multiple teams as cards. This widget pivots a SINGLE team's
 * roster into a table with First Name / Last Name / Status / PDP
 * status / Average attendance — exactly the shape the pilot user
 * asked for.
 *
 * Configuration via the slot's `data_source`:
 *
 *     "team_id=42,days=30"
 *
 * `team_id` selects which team's roster to show; `days` is the
 * attendance window (default 30, max 365). When `team_id` is unset
 * or the team is out of the operator's club, the widget renders an
 * empty-state card explaining that the operator picks the team in the
 * dashboard editor.
 *
 * Cap-gated: implicit via `CurrentClub` filter + the matrix grant
 * `players r global` that HoD + Academy Admin already hold.
 */
class TeamRosterTableWidget extends AbstractWidget {

    public function id(): string { return 'team_roster_table'; }

    public function label(): string { return __( 'Team roster table', 'talenttrack' ); }

    public function defaultSize(): string { return Size::L; }

    /** @return list<string> */
    public function allowedSizes(): array { return [ Size::M, Size::L, Size::XL ]; }

    public function defaultMobilePriority(): int { return 22; }

    public function personaContext(): string { return PersonaContext::ACADEMY; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $config  = $this->parseConfig( $slot->data_source );
        $club_id = CurrentClub::id();
        $title   = $slot->persona_label !== ''
            ? $slot->persona_label
            : __( 'Team roster', 'talenttrack' );

        if ( $config['team_id'] <= 0 ) {
            $head = '<div class="tt-pd-panel-head">'
                . '<span class="tt-pd-panel-title">' . esc_html( $title ) . '</span>'
                . '</div>';
            $body = '<div class="tt-pd-team-roster-empty">'
                . esc_html__( 'Pick a team in the dashboard editor (data source: `team_id=N,days=30`).', 'talenttrack' )
                . '</div>';
            return $this->wrap( $slot, $head . $body );
        }

        global $wpdb;
        $p = $wpdb->prefix;

        $team = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, name, age_group FROM {$p}tt_teams WHERE id = %d AND club_id = %d",
            $config['team_id'], $club_id
        ) );
        if ( ! $team ) {
            $head = '<div class="tt-pd-panel-head">'
                . '<span class="tt-pd-panel-title">' . esc_html( $title ) . '</span>'
                . '</div>';
            $body = '<div class="tt-pd-team-roster-empty">'
                . esc_html__( 'That team is no longer available, or it does not belong to your club.', 'talenttrack' )
                . '</div>';
            return $this->wrap( $slot, $head . $body );
        }

        $rows = $this->fetchRoster( $config['team_id'], $club_id, $config['days'] );
        $title = $slot->persona_label !== ''
            ? $slot->persona_label
            : sprintf(
                /* translators: 1: team name, 2: age group */
                __( '%1$s (%2$s) roster', 'talenttrack' ),
                $team->name,
                $team->age_group ?: '—'
            );

        $head = '<div class="tt-pd-panel-head">'
            . '<span class="tt-pd-panel-title">' . esc_html( $title ) . '</span>'
            . '<span class="tt-pd-panel-meta">' . esc_html( sprintf(
                /* translators: %d: window in days */
                __( 'Attendance over last %d days', 'talenttrack' ),
                $config['days']
            ) ) . '</span>'
            . '</div>';

        if ( empty( $rows ) ) {
            $body = '<div class="tt-pd-team-roster-empty">'
                . esc_html__( 'This team has no active players yet.', 'talenttrack' )
                . '</div>';
            return $this->wrap( $slot, $head . $body );
        }

        $body  = '<table class="tt-pd-team-roster">';
        $body .= '<thead><tr>';
        $body .= '<th>' . esc_html__( 'First name', 'talenttrack' ) . '</th>';
        $body .= '<th>' . esc_html__( 'Last name', 'talenttrack' ) . '</th>';
        $body .= '<th>' . esc_html__( 'Status', 'talenttrack' ) . '</th>';
        $body .= '<th>' . esc_html__( 'PDP', 'talenttrack' ) . '</th>';
        $body .= '<th>' . esc_html__( 'Attendance', 'talenttrack' ) . '</th>';
        $body .= '</tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $body .= '<tr>';
            $body .= '<td>' . esc_html( (string) $r['first_name'] ) . '</td>';
            $body .= '<td>' . esc_html( (string) $r['last_name'] ) . '</td>';
            // The pill returns escaped HTML.
            $body .= '<td>' . LookupPill::render( 'player_status', (string) $r['status'] ) . '</td>';
            $body .= '<td>' . esc_html( $this->formatPdpStatus( (string) $r['pdp_status'] ) ) . '</td>';
            $body .= '<td>' . esc_html( $r['attendance_pct'] === null ? '—' : ( $r['attendance_pct'] . '%' ) ) . '</td>';
            $body .= '</tr>';
        }
        $body .= '</tbody></table>';
        return $this->wrap( $slot, $head . $body );
    }

    /**
     * `team_id=42,days=30` → `[ 'team_id' => 42, 'days' => 30 ]` with
     * defaults filled in. Days is clamped 1–365.
     *
     * @return array{team_id:int,days:int}
     */
    private function parseConfig( string $config_string ): array {
        $defaults = [ 'team_id' => 0, 'days' => 30 ];
        if ( $config_string === '' ) return $defaults;
        $parts = explode( ',', $config_string );
        foreach ( $parts as $part ) {
            $kv = explode( '=', trim( $part ), 2 );
            if ( count( $kv ) !== 2 ) continue;
            [ $k, $v ] = $kv;
            $k = trim( $k );
            $v = trim( $v );
            if ( $k === 'team_id' ) $defaults['team_id'] = max( 0, (int) $v );
            if ( $k === 'days' )    $defaults['days']    = max( 1, min( 365, (int) $v ) );
        }
        return $defaults;
    }

    /**
     * Per-player rows: (first_name, last_name, status, pdp_status,
     * attendance_pct over the window). PDP status is the file's
     * latest signed-off verdict if any, else "in_progress" if a file
     * exists, else "none". Attendance % is `present / (present +
     * absent + late)` over activities in the window.
     *
     * @return list<array{first_name:string,last_name:string,status:string,pdp_status:string,attendance_pct:?int}>
     */
    private function fetchRoster( int $team_id, int $club_id, int $days ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $start = gmdate( 'Y-m-d', strtotime( '-' . $days . ' days' ) );

        $players = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name, status
               FROM {$p}tt_players
              WHERE club_id = %d AND team_id = %d AND archived_at IS NULL
              ORDER BY last_name, first_name",
            $club_id, $team_id
        ) );
        if ( empty( $players ) ) return [];

        $ids = array_map( static fn( $r ) => (int) $r->id, $players );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // PDP status — latest verdict per file, or 'in_progress' for
        // files without a signed-off verdict yet, or 'none' for
        // players without a file. Single GROUP BY query.
        $pdp_files = $wpdb->prefix . 'tt_pdp_files';
        $pdp_verds = $wpdb->prefix . 'tt_pdp_verdicts';
        $pdp_by_player = [];
        $pdp_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pdp_files ) ) === $pdp_files;
        if ( $pdp_table_exists ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $pdp_rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT f.player_id,
                        CASE
                          WHEN MAX(v.signed_off_at) IS NOT NULL THEN 'signed_off'
                          WHEN COUNT(f.id) > 0                 THEN 'in_progress'
                          ELSE 'none'
                        END AS pdp_status
                   FROM {$pdp_files} f
              LEFT JOIN {$pdp_verds} v ON v.pdp_file_id = f.id
                  WHERE f.club_id = %d AND f.player_id IN ( {$placeholders} )
                    AND f.archived_at IS NULL
                  GROUP BY f.player_id",
                array_merge( [ $club_id ], $ids )
            ) );
            foreach ( (array) $pdp_rows as $r ) {
                $pdp_by_player[ (int) $r->player_id ] = (string) $r->pdp_status;
            }
        }

        // Attendance % per player over the window. `present + late`
        // counts as present.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $att_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT att.player_id,
                    COUNT(*) AS total,
                    SUM(CASE WHEN att.status IN ( 'present', 'late' ) THEN 1 ELSE 0 END) AS present_count
               FROM {$p}tt_attendance att
               JOIN {$p}tt_activities a ON a.id = att.activity_id AND a.club_id = att.club_id
              WHERE att.club_id = %d
                AND att.player_id IN ( {$placeholders} )
                AND a.session_date >= %s
              GROUP BY att.player_id",
            array_merge( [ $club_id ], $ids, [ $start ] )
        ) );
        $att_by_player = [];
        foreach ( (array) $att_rows as $r ) {
            $total = (int) $r->total;
            $att_by_player[ (int) $r->player_id ] = $total > 0
                ? (int) round( ( (int) $r->present_count / $total ) * 100, 0 )
                : null;
        }

        $out = [];
        foreach ( $players as $pl ) {
            $pid = (int) $pl->id;
            $out[] = [
                'first_name'     => (string) $pl->first_name,
                'last_name'      => (string) $pl->last_name,
                'status'         => (string) ( $pl->status ?? 'active' ),
                'pdp_status'     => $pdp_by_player[ $pid ] ?? 'none',
                'attendance_pct' => $att_by_player[ $pid ] ?? null,
            ];
        }
        return $out;
    }

    private function formatPdpStatus( string $key ): string {
        switch ( $key ) {
            case 'signed_off':  return __( 'Signed off',  'talenttrack' );
            case 'in_progress': return __( 'In progress', 'talenttrack' );
            case 'none':
            default:            return __( '—',           'talenttrack' );
        }
    }
}
