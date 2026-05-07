<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;

/**
 * PlayerOnePagerPdfExporter (#0063 use case 13) — single-player A5 PDF.
 *
 * The compact "trial card" / "scout visit handout" deliverable from
 * the spec: photo, name, age, position, status, jersey, team. Built
 * standalone (not via `PlayerReportRenderer`) because the spec calls
 * for an A5 single-page artifact where the multi-section eval-report
 * shell would overflow and crowd. The four-PDF family (use cases 1 /
 * 2 / 13 / 14) shares the v3.110.0 `PdfRenderer` pipeline; only this
 * one ships a bespoke compact layout.
 *
 * URL:
 *   `GET /wp-json/talenttrack/v1/exports/player_onepager_pdf?format=pdf&player_id=42`
 *
 * Cap: `tt_view_players` — same gate as the squad-list export. The
 * one-pager carries less data than the eval report and goes to
 * trials / scout visits where a broader read group needs it.
 *
 * Fields surfaced (per spec): photo, name, date-of-birth + computed
 * age, primary position (first comma-separated value of
 * `preferred_positions`), preferred foot, jersey number, status,
 * team name. No ratings, no goals, no contact details — those live
 * in the eval report (use case 1) and the scouting report (use
 * case 14) respectively.
 *
 * Brand-kit letterhead lands with the deferred-from-v3.110.0 follow-
 * up; consumers can hook the `tt_pdf_render_html` filter today.
 */
final class PlayerOnePagerPdfExporter implements ExporterInterface {

    public function key(): string { return 'player_onepager_pdf'; }

    public function label(): string { return __( 'Player one-pager (A5 PDF)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'pdf' ]; }

    public function requiredCap(): string { return 'tt_view_players'; }

    public function validateFilters( array $raw ): ?array {
        $player_id = isset( $raw['player_id'] ) ? (int) $raw['player_id'] : 0;
        if ( $player_id <= 0 ) return null;
        return [ 'player_id' => $player_id ];
    }

    public function collect( ExportRequest $request ): array {
        $player_id = (int) ( $request->filters['player_id'] ?? 0 );

        $player = QueryHelpers::get_player( $player_id );
        if ( ! $player ) {
            return [
                'html'    => '<p>' . esc_html__( 'Player not found.', 'talenttrack' ) . '</p>',
                'options' => [ 'paper' => 'A5', 'orientation' => 'portrait' ],
            ];
        }

        $name      = QueryHelpers::player_display_name( $player );
        $photo     = ! empty( $player->photo_url ) ? (string) $player->photo_url : '';
        $dob       = ! empty( $player->date_of_birth ) ? (string) $player->date_of_birth : '';
        $age       = self::computeAge( $dob );
        $position  = self::primaryPosition( (string) ( $player->preferred_positions ?? '' ) );
        $foot      = (string) ( $player->preferred_foot ?? '' );
        $jersey    = isset( $player->jersey_number ) && $player->jersey_number !== null
            ? (string) (int) $player->jersey_number
            : '';
        $status    = (string) ( $player->status ?? '' );
        $team_name = self::teamName( (int) ( $player->team_id ?? 0 ), (int) $request->clubId );

        $html = self::renderHtml( [
            'name'      => $name,
            'photo'     => $photo,
            'dob'       => $dob,
            'age'       => $age,
            'position'  => $position,
            'foot'      => $foot,
            'jersey'    => $jersey,
            'status'    => $status,
            'team_name' => $team_name,
        ] );

        return [
            'html'    => $html,
            'options' => [ 'paper' => 'A5', 'orientation' => 'portrait' ],
        ];
    }

    private static function computeAge( string $dob ): string {
        if ( $dob === '' ) return '';
        $ts = strtotime( $dob );
        if ( $ts === false ) return '';
        $years = (int) floor( ( current_time( 'timestamp' ) - $ts ) / 31557600 ); // 365.25d
        return $years > 0 ? (string) $years : '';
    }

    private static function primaryPosition( string $positions ): string {
        if ( $positions === '' ) return '';
        $parts = explode( ',', $positions );
        return trim( (string) reset( $parts ) );
    }

    private static function teamName( int $team_id, int $club_id ): string {
        if ( $team_id <= 0 ) return '';
        global $wpdb;
        $row = $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}tt_teams WHERE id = %d AND club_id = %d LIMIT 1",
            $team_id, $club_id
        ) );
        return $row !== null ? (string) $row : '';
    }

    /**
     * @param array<string,string> $f  keys: name, photo, dob, age,
     *                                 position, foot, jersey, status, team_name
     */
    private static function renderHtml( array $f ): string {
        $css = '@page { size: A5 portrait; margin: 12mm; }'
             . 'body { font-family: DejaVu Sans, Arial, sans-serif; font-size: 11pt; color: #1a1d21; line-height: 1.4; margin: 0; }'
             . '.header { display: flex; gap: 8mm; align-items: center; margin-bottom: 6mm; }'
             . '.photo { width: 32mm; height: 32mm; object-fit: cover; border-radius: 4mm; background: #fafbfc; flex-shrink: 0; border: 1px solid #e5e7ea; }'
             . '.identity h1 { font-size: 18pt; margin: 0 0 1mm; color: #1a1d21; line-height: 1.15; }'
             . '.identity .team { color: #5b6e75; font-size: 10pt; margin: 0; }'
             . 'table.facts { width: 100%; border-collapse: collapse; margin-top: 4mm; }'
             . 'table.facts th { width: 38mm; text-align: left; font-weight: 600; color: #5b6e75; padding: 2mm 4mm 2mm 0; vertical-align: top; }'
             . 'table.facts td { padding: 2mm 0; vertical-align: top; }'
             . 'table.facts tr + tr th, table.facts tr + tr td { border-top: 1px solid #f0f2f4; }'
             . '.footer { margin-top: 8mm; font-size: 9pt; color: #5b6e75; }';

        $rows = [
            [ __( 'Date of birth', 'talenttrack' ), self::dobLine( $f['dob'], $f['age'] ) ],
            [ __( 'Position',      'talenttrack' ), $f['position'] ],
            [ __( 'Preferred foot','talenttrack' ), $f['foot'] ],
            [ __( 'Jersey',        'talenttrack' ), $f['jersey'] ],
            [ __( 'Status',        'talenttrack' ), self::statusLabel( $f['status'] ) ],
        ];

        $rows_html = '';
        foreach ( $rows as [ $label, $value ] ) {
            $value_text = trim( (string) $value );
            $value_html = $value_text !== '' ? esc_html( $value_text ) : '<span style="color:#9aa3a8;">—</span>';
            $rows_html .= '<tr><th>' . esc_html( (string) $label ) . '</th><td>' . $value_html . '</td></tr>';
        }

        $photo_html = $f['photo'] !== ''
            ? '<img class="photo" src="' . esc_url( $f['photo'] ) . '" alt="" />'
            : '<div class="photo"></div>';

        $team_html = $f['team_name'] !== ''
            ? '<p class="team">' . esc_html( $f['team_name'] ) . '</p>'
            : '';

        $generated_html = esc_html( sprintf(
            /* translators: %s = generation date (Y-m-d) */
            __( 'Generated %s', 'talenttrack' ),
            date_i18n( get_option( 'date_format' ) ?: 'Y-m-d' )
        ) );

        return '<!doctype html><html><head><meta charset="UTF-8">'
            . '<title>' . esc_html( $f['name'] ) . '</title>'
            . '<style>' . $css . '</style>'
            . '</head><body>'
            . '<div class="header">'
            . $photo_html
            . '<div class="identity">'
            . '<h1>' . esc_html( $f['name'] ) . '</h1>'
            . $team_html
            . '</div>'
            . '</div>'
            . '<table class="facts"><tbody>' . $rows_html . '</tbody></table>'
            . '<p class="footer">' . $generated_html . '</p>'
            . '</body></html>';
    }

    private static function dobLine( string $dob, string $age ): string {
        if ( $dob === '' ) return '';
        if ( $age === '' ) return $dob;
        return sprintf(
            /* translators: 1: ISO date, 2: integer age in years */
            __( '%1$s (age %2$s)', 'talenttrack' ),
            $dob,
            $age
        );
    }

    private static function statusLabel( string $status ): string {
        if ( $status === '' ) return '';
        // Reuse the lookup pill's translation surface — the player
        // status lookup already exists in `tt_lookups` under
        // `player_status` and the seed labels carry NL translations.
        $labels = [
            'active'     => __( 'Active',     'talenttrack' ),
            'archived'   => __( 'Archived',   'talenttrack' ),
            'trial'      => __( 'Trial',      'talenttrack' ),
            'released'   => __( 'Released',   'talenttrack' ),
            'contracted' => __( 'Contracted', 'talenttrack' ),
            'inactive'   => __( 'Inactive',   'talenttrack' ),
        ];
        $key = strtolower( $status );
        return $labels[ $key ] ?? ucfirst( $status );
    }
}
