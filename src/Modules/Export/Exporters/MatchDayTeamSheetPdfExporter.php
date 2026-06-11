<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;
use TT\Modules\MatchPrep\Frontend\FrontendMatchPrepView;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * MatchDayTeamSheetPdfExporter (#0063 use case 4) — pitch-side match-day
 * team sheet PDF.
 *
 * #1194 — source of truth is the match-prep view. When a match-prep
 * row exists for the activity, the exporter partitions players via
 * `tt_match_prep_lineup` (half 1 = Starting XI) and
 * `tt_match_prep_availability` (Present-but-not-in-lineup = Bench;
 * Absent = Squad). Position per player resolves from the formation
 * template's slot label. When no match-prep row exists (legacy
 * activities pre-#838), the exporter falls back to the original
 * `tt_attendance.lineup_role` / `position_played` path so the
 * existing PDF surface keeps working without regression.
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

    /** Non-tabular exporter — opts out of the column picker (#986). */
    public function availableColumns(): array { return []; }

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

        // #1194 — prefer match-prep as source of truth. Legacy path
        // (read tt_attendance.lineup_role) remains as fallback for
        // activities created before match-prep shipped.
        $prep_repo = new MatchPrepRepository();
        $prep      = $prep_repo->findByActivity( $activity_id );

        if ( $prep ) {
            [ $starting, $bench, $squad ] = self::partitionFromMatchPrep(
                $prep_repo,
                (int) $prep->id,
                (int) ( $prep->formation_template_id ?? 0 ),
                $activity_id,
                (int) $request->clubId
            );
        } else {
            [ $starting, $bench, $squad ] = self::partitionFromAttendance( $activity_id, (int) $request->clubId );
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

    /**
     * #1194 — partition from match-prep tables. Half 1 lineup = Starting
     * XI; Present-but-not-in-lineup = Bench; Absent (or no row) =
     * Squad.
     *
     * @return array{0:list<object>,1:list<object>,2:list<object>}
     */
    private static function partitionFromMatchPrep(
        MatchPrepRepository $repo,
        int $prep_id,
        int $formation_template_id,
        int $activity_id,
        int $club_id
    ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        // Slot-to-position-label map from the formation template.
        $shape = '';
        if ( $formation_template_id > 0 ) {
            $shape = (string) $wpdb->get_var( $wpdb->prepare(
                "SELECT formation_shape FROM {$p}tt_formation_templates WHERE id = %d LIMIT 1",
                $formation_template_id
            ) );
        }
        $slot_position = self::buildSlotPositionMap( $shape );

        // Half-1 lineup → slot_number per player_id.
        $starting_slot = [];
        foreach ( $repo->listLineup( $prep_id ) as $row ) {
            if ( (int) $row->half !== 1 ) continue;
            $starting_slot[ (int) $row->player_id ] = (int) $row->slot_number;
        }

        // Availability + reason per player.
        $availability = [];
        foreach ( $repo->listAvailability( $prep_id ) as $row ) {
            $availability[ (int) $row->player_id ] = (string) $row->status;
        }

        // Pull every roster player on the team in one query so Bench
        // can also include players who haven't been ticked in
        // availability yet (defensive — usually availability covers
        // the full roster).
        $player_ids = array_keys( $starting_slot + $availability );
        if ( empty( $player_ids ) ) {
            return [ [], [], [] ];
        }
        $placeholders = implode( ',', array_fill( 0, count( $player_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $players = $wpdb->get_results( $wpdb->prepare(
            "SELECT pl.id AS player_id, pl.first_name, pl.last_name, pl.jersey_number, pl.preferred_positions
               FROM {$p}tt_players pl
              WHERE pl.id IN ({$placeholders}) AND pl.club_id = %d",
            array_merge( $player_ids, [ $club_id ] )
        ) );
        $players = is_array( $players ) ? $players : [];

        $starting = [];
        $bench    = [];
        $squad    = [];

        foreach ( $players as $pl ) {
            $pid    = (int) $pl->player_id;
            $status = strcasecmp( $availability[ $pid ] ?? '', 'Present' ) === 0 ? 'Present' : ( $availability[ $pid ] ?? '' );

            if ( isset( $starting_slot[ $pid ] ) ) {
                $slot                  = $starting_slot[ $pid ];
                $pl->status            = 'Present';
                $pl->lineup_role       = 'start';
                $pl->position_played   = $slot_position[ $slot ] ?? '';
                $starting[]            = $pl;
                continue;
            }
            if ( $status === 'Present' ) {
                $pl->status          = 'Present';
                $pl->lineup_role     = 'bench';
                $pl->position_played = '';
                $bench[]             = $pl;
                continue;
            }
            $pl->status          = $availability[ $pid ] ?? '';
            $pl->lineup_role     = '';
            $pl->position_played = '';
            $squad[]             = $pl;
        }

        // Stable sort within each section: jersey ASC nulls last,
        // last_name ASC. Matches the legacy ORDER BY.
        $sorter = static function ( object $a, object $b ): int {
            $ja = $a->jersey_number !== null ? (int) $a->jersey_number : PHP_INT_MAX;
            $jb = $b->jersey_number !== null ? (int) $b->jersey_number : PHP_INT_MAX;
            if ( $ja !== $jb ) return $ja <=> $jb;
            return strcasecmp( (string) $a->last_name, (string) $b->last_name );
        };
        usort( $starting, $sorter );
        usort( $bench, $sorter );
        usort( $squad, $sorter );

        return [ $starting, $bench, $squad ];
    }

    /**
     * Legacy fallback: read partition from `tt_attendance.lineup_role`
     * / `position_played`. Used when no match-prep row exists for the
     * activity (legacy installs, or activities created before #838).
     *
     * @return array{0:list<object>,1:list<object>,2:list<object>}
     */
    private static function partitionFromAttendance( int $activity_id, int $club_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;

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
            $club_id
        ) );
        $roster = is_array( $roster ) ? $roster : [];

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
        return [ $starting, $bench, $squad ];
    }

    /**
     * Slot_number → position label map (e.g. 1 => 'GK', 9 => 'ST')
     * derived from the formation shape's default layout. Returns an
     * empty map when shape is unknown — callers fall back to blank
     * position labels.
     *
     * @return array<int,string>
     */
    private static function buildSlotPositionMap( string $shape ): array {
        if ( $shape === '' ) return [];
        $layouts = FrontendMatchPrepView::defaultSlotLayouts();
        if ( ! isset( $layouts[ $shape ] ) ) return [];
        $map = [];
        foreach ( $layouts[ $shape ] as $entry ) {
            if ( isset( $entry['num'], $entry['label'] ) ) {
                $map[ (int) $entry['num'] ] = (string) $entry['label'];
            }
        }
        return $map;
    }
}
