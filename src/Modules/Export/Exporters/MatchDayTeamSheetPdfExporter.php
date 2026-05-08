<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;

/**
 * MatchDayTeamSheetPdfExporter (#0063 use case 4) — pitch-side match-day
 * team sheet PDF.
 *
 * Per user-direction shaping (2026-05-08): filter `tt_activities` to
 * `activity_type_key = 'match'`; surface the match meta (opponent,
 * home_away, kickoff_time, formation) added by migration 0079;
 * partition the squad by `tt_attendance.lineup_role` into Starting XI
 * vs Bench. Position per player comes from the per-match
 * `tt_attendance.position_played` override, falling back to
 * `tt_players.preferred_positions[0]` when the operator hasn't filled
 * it in.
 *
 * URL:
 *   `GET /wp-json/talenttrack/v1/exports/match_day_team_sheet?format=pdf&activity_id=42`
 *
 * Cap: `tt_view_activities`.
 *
 * Form-UI to populate the new columns is a deferred follow-up — for
 * v1 the operator can edit `opponent` / `home_away` / `kickoff_time`
 * / `formation` / `lineup_role` / `position_played` via direct DB
 * write or REST PATCH. The exporter renders gracefully when columns
 * are NULL: empty header fields show as "—", missing `lineup_role`
 * lands in a "Squad" section instead of Starting XI / Bench.
 */
final class MatchDayTeamSheetPdfExporter implements ExporterInterface {

    public function key(): string { return 'match_day_team_sheet'; }

    public function label(): string { return __( 'Match-day team sheet (PDF)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'pdf' ]; }

    public function requiredCap(): string { return 'tt_view_activities'; }

    public function validateFilters( array $raw ): ?array {
        $activity_id = isset( $raw['activity_id'] ) ? (int) $raw['activity_id'] : 0;
        if ( $activity_id <= 0 ) return null;
        return [ 'activity_id' => $activity_id ];
    }

    public function collect( ExportRequest $request ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $activity_id = (int) ( $request->filters['activity_id'] ?? 0 );

        $activity = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.id, a.title, a.session_date, a.location, a.team_id, a.activity_type_key,
                    a.opponent, a.home_away, a.kickoff_time, a.formation,
                    t.name AS team_name
                FROM {$p}tt_activities a
                LEFT JOIN {$p}tt_teams t ON t.id = a.team_id AND t.club_id = a.club_id
                WHERE a.id = %d AND a.club_id = %d
                LIMIT 1",
            $activity_id,
            (int) $request->clubId
        ) );

        if ( ! $activity ) {
            return [
                'html'    => '<p>' . esc_html__( 'Activity not found.', 'talenttrack' ) . '</p>',
                'options' => [ 'paper' => 'A4', 'orientation' => 'portrait' ],
            ];
        }

        // Refuse to render team sheets for non-match activities.
        if ( strtolower( (string) ( $activity->activity_type_key ?? '' ) ) !== 'match' ) {
            return [
                'html'    => '<p>' . esc_html__( 'This activity is not a match — the team-sheet export is only available for activities with type "match".', 'talenttrack' ) . '</p>',
                'options' => [ 'paper' => 'A4', 'orientation' => 'portrait' ],
            ];
        }

        $roster = $wpdb->get_results( $wpdb->prepare(
            "SELECT pl.id AS player_id, pl.first_name, pl.last_name, pl.jersey_number,
                    pl.preferred_positions,
                    att.status, att.lineup_role, att.position_played
                FROM {$p}tt_attendance att
                JOIN {$p}tt_players pl ON pl.id = att.player_id
                WHERE att.activity_id = %d AND pl.club_id = %d
                ORDER BY
                    CASE LOWER(IFNULL(att.lineup_role, ''))
                        WHEN 'start' THEN 1
                        WHEN 'bench' THEN 2
                        ELSE 3
                    END ASC,
                    pl.jersey_number IS NULL,
                    pl.jersey_number ASC,
                    pl.last_name ASC",
            $activity_id,
            (int) $request->clubId
        ) );
        $roster = is_array( $roster ) ? $roster : [];

        // Partition by lineup_role.
        $starting = [];
        $bench    = [];
        $squad    = [];
        foreach ( $roster as $r ) {
            $role = strtolower( (string) ( $r->lineup_role ?? '' ) );
            if ( $role === 'start' ) {
                $starting[] = $r;
            } elseif ( $role === 'bench' ) {
                $bench[] = $r;
            } else {
                $squad[] = $r;
            }
        }

        $html = self::renderHtml( $activity, $starting, $bench, $squad );

        return [
            'html'    => $html,
            'options' => [ 'paper' => 'A4', 'orientation' => 'portrait' ],
        ];
    }

    /**
     * @param object   $activity
     * @param object[] $starting
     * @param object[] $bench
     * @param object[] $squad
     */
    private static function renderHtml( object $activity, array $starting, array $bench, array $squad ): string {
        $css = '@page { size: A4 portrait; margin: 14mm; }'
             . 'body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11pt; color: #1a1d21; line-height: 1.4; margin: 0; }'
             . 'h1 { font-size: 22pt; margin: 0 0 1mm; }'
             . 'h2 { font-size: 13pt; margin: 6mm 0 2mm; border-bottom: 1px solid #c5c8cc; padding-bottom: 2mm; }'
             . '.header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 4mm; }'
             . '.header .vs { font-size: 14pt; color: #5b6e75; }'
             . 'table.meta { width: 100%; border-collapse: collapse; margin-bottom: 3mm; }'
             . 'table.meta th { width: 32mm; text-align: left; font-weight: 600; color: #5b6e75; padding: 1.5mm 4mm 1.5mm 0; }'
             . 'table.meta td { padding: 1.5mm 0; }'
             . 'table.lineup { width: 100%; border-collapse: collapse; }'
             . 'table.lineup th { background: #f4f4f4; text-align: left; padding: 2mm 3mm; font-weight: 600; font-size: 10pt; border-bottom: 1px solid #c5c8cc; }'
             . 'table.lineup td { padding: 2mm 3mm; border-bottom: 1px solid #f0f2f4; }'
             . 'table.lineup tr:nth-child(even) td { background: #fafbfc; }'
             . '.jersey { width: 14mm; font-weight: 700; }'
             . '.position { width: 22mm; color: #5b6e75; font-size: 10pt; }'
             . '.signature { margin-top: 8mm; display: flex; gap: 12mm; }'
             . '.signature .sig-line { flex: 1; border-top: 1px solid #1a1d21; padding-top: 2mm; font-size: 9pt; color: #5b6e75; }';

        $home_away = strtolower( (string) ( $activity->home_away ?? '' ) );
        $vs_text   = ( $activity->opponent ?? '' ) !== ''
            ? sprintf(
                /* translators: 1: opponent name, 2: home or away label */
                __( 'vs %1$s (%2$s)', 'talenttrack' ),
                (string) $activity->opponent,
                self::homeAwayLabel( $home_away )
            )
            : '';

        $title       = (string) $activity->title;
        $team_name   = (string) ( $activity->team_name ?? '' );
        $date        = (string) $activity->session_date;
        $kickoff     = (string) ( $activity->kickoff_time ?? '' );
        $location    = (string) ( $activity->location ?? '' );
        $formation   = (string) ( $activity->formation ?? '' );

        $meta_rows = [
            [ __( 'Team',         'talenttrack' ), $team_name ],
            [ __( 'Date',         'talenttrack' ), $date ],
            [ __( 'Kickoff',      'talenttrack' ), $kickoff ],
            [ __( 'Location',     'talenttrack' ), $location ],
            [ __( 'Formation',    'talenttrack' ), $formation ],
        ];

        $meta_html = '<table class="meta"><tbody>';
        foreach ( $meta_rows as [ $label, $value ] ) {
            $value_text = trim( (string) $value );
            $value_html = $value_text !== '' ? esc_html( $value_text ) : '<span style="color:#9aa3a8;">—</span>';
            $meta_html .= '<tr><th>' . esc_html( (string) $label ) . '</th><td>' . $value_html . '</td></tr>';
        }
        $meta_html .= '</tbody></table>';

        $sections_html = '';
        if ( $starting !== [] ) {
            $sections_html .= self::lineupTable( __( 'Starting XI', 'talenttrack' ), $starting );
        }
        if ( $bench !== [] ) {
            $sections_html .= self::lineupTable( __( 'Bench', 'talenttrack' ), $bench );
        }
        // Squad falls through when `lineup_role` hasn't been set yet —
        // operator hasn't picked a starting XI / bench split. Render
        // it instead of Starting XI / Bench so the team sheet still
        // carries useful information.
        if ( $squad !== [] && $starting === [] && $bench === [] ) {
            $sections_html .= self::lineupTable( __( 'Squad', 'talenttrack' ), $squad );
        }
        if ( $sections_html === '' ) {
            $sections_html = '<p><em>' . esc_html__( 'No squad recorded for this match.', 'talenttrack' ) . '</em></p>';
        }

        $signature_html = '<div class="signature">'
            . '<div class="sig-line">' . esc_html__( 'Coach signature', 'talenttrack' ) . '</div>'
            . '<div class="sig-line">' . esc_html__( 'Referee signature', 'talenttrack' ) . '</div>'
            . '</div>';

        return '<!doctype html><html><head><meta charset="UTF-8">'
            . '<title>' . esc_html( $title ) . '</title>'
            . '<style>' . $css . '</style></head><body>'
            . '<div class="header">'
            . '<h1>' . esc_html( $title ) . '</h1>'
            . ( $vs_text !== '' ? '<div class="vs">' . esc_html( $vs_text ) . '</div>' : '' )
            . '</div>'
            . $meta_html
            . $sections_html
            . $signature_html
            . '</body></html>';
    }

    /**
     * @param object[] $rows
     */
    private static function lineupTable( string $heading, array $rows ): string {
        $out = '<h2>' . esc_html( $heading ) . '</h2>';
        $out .= '<table class="lineup"><thead><tr>';
        $out .= '<th class="jersey">' . esc_html__( 'No.',      'talenttrack' ) . '</th>';
        $out .= '<th>'                . esc_html__( 'Player',   'talenttrack' ) . '</th>';
        $out .= '<th class="position">' . esc_html__( 'Position', 'talenttrack' ) . '</th>';
        $out .= '<th>'                . esc_html__( 'Status',   'talenttrack' ) . '</th>';
        $out .= '</tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $jersey   = $r->jersey_number !== null ? (string) (int) $r->jersey_number : '';
            $name     = trim( (string) $r->first_name . ' ' . (string) $r->last_name );
            $position = self::resolvePosition( $r );
            $status   = (string) ( $r->status ?? '' );
            $out .= '<tr>'
                . '<td class="jersey">' . esc_html( $jersey ) . '</td>'
                . '<td>' . esc_html( $name ) . '</td>'
                . '<td class="position">' . esc_html( $position ) . '</td>'
                . '<td>' . esc_html( $status ) . '</td>'
                . '</tr>';
        }
        $out .= '</tbody></table>';
        return $out;
    }

    private static function resolvePosition( object $row ): string {
        $override = trim( (string) ( $row->position_played ?? '' ) );
        if ( $override !== '' ) return $override;
        $preferred = (string) ( $row->preferred_positions ?? '' );
        if ( $preferred === '' ) return '';
        $parts = explode( ',', $preferred );
        return trim( (string) reset( $parts ) );
    }

    private static function homeAwayLabel( string $value ): string {
        switch ( strtolower( $value ) ) {
            case 'home': return __( 'home', 'talenttrack' );
            case 'away': return __( 'away', 'talenttrack' );
            default:     return '—';
        }
    }
}
