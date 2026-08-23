<?php
namespace TT\Modules\MatchAnalysis\Print;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisComposer;

/**
 * MatchAnalysisPrintableRenderer (#2709) — the A4 body of a printed match
 * analysis.
 *
 * Text, not an image. Match prep prints through an html2canvas capture
 * because its value is the pitch diagram, which has to come out pixel-
 * faithful; an analysis is prose and a list of names, where selectable,
 * searchable, screen-readable text is worth more than pixel fidelity — and
 * the browser's own print dialog then produces the PDF with no library at
 * all.
 *
 * Players who were not mentioned are omitted. The roster on screen is an
 * input device — it lists everyone so nobody is forgotten — but a printed
 * sheet listing twelve names with nothing beside them says the coach had
 * nothing to say about them, which is not what an empty row means.
 */
final class MatchAnalysisPrintableRenderer {

    /**
     * @param array<string,mixed> $payload
     */
    public static function bodyHtml( array $payload ): string {
        /** @var object $activity */
        $activity = $payload['activity'];
        $result   = (array) $payload['result'];

        $out = '<div class="tt-map-doc">';

        $title = (string) ( $activity->title ?? '' );
        $out  .= '<h1>' . esc_html( $title !== '' ? $title : __( 'Match analysis', 'talenttrack' ) ) . '</h1>';

        $meta = [];
        $date = (string) ( $activity->session_date ?? '' );
        if ( $date !== '' ) {
            $meta[] = date_i18n( (string) get_option( 'date_format' ), strtotime( $date ) );
        }
        if ( (string) ( $result['opponent'] ?? '' ) !== '' ) {
            $meta[] = (string) $result['opponent'];
        }
        if ( ! empty( $result['has_score'] ) ) {
            $meta[] = sprintf( '%d – %d', (int) $result['home_score'], (int) $result['away_score'] );
        }
        if ( $meta ) {
            $out .= '<p class="tt-map-meta">' . esc_html( implode( ' · ', $meta ) ) . '</p>';
        }

        $summary = trim( (string) $payload['summary'] );
        if ( $summary !== '' ) {
            $out .= '<h2>' . esc_html( MatchAnalysisEnums::sectionLabel( MatchAnalysisEnums::SECTION_GENERAL ) ) . '</h2>';
            $out .= '<p class="tt-map-summary">' . nl2br( esc_html( $summary ) ) . '</p>';
        }

        foreach ( (array) $payload['sections'] as $section ) {
            if ( empty( $section['rated'] ) ) continue;

            $notes  = self::lines( (string) $section['notes'] );
            $rating = $section['rating'] ?? null;
            if ( $rating === null && empty( $notes ) ) continue;

            $out .= '<section class="tt-map-section">';
            $out .= '<h2>' . esc_html( (string) $section['label'] );
            if ( $rating !== null ) {
                $out .= ' <span class="tt-map-rating">'
                    . esc_html( MatchAnalysisEnums::ratings()[ $rating ] ?? '' )
                    . '</span>';
            }
            $out .= '</h2>';

            if ( ! empty( (string) $section['planned'] ) ) {
                $out .= '<p class="tt-map-planned">'
                    . esc_html( __( 'Planned', 'talenttrack' ) . ': ' . str_replace( "\n", ' · ', (string) $section['planned'] ) )
                    . '</p>';
            }

            if ( $notes ) {
                $out .= '<ul>';
                foreach ( $notes as $line ) {
                    $out .= '<li>' . esc_html( $line ) . '</li>';
                }
                $out .= '</ul>';
            }
            $out .= '</section>';
        }

        $mentioned = array_values( array_filter(
            (array) $payload['players'],
            static fn( array $p ): bool => (string) $p['marker'] !== '' || trim( (string) $p['note'] ) !== ''
        ) );

        if ( $mentioned ) {
            $out .= '<section class="tt-map-section">';
            $out .= '<h2>' . esc_html__( 'Players', 'talenttrack' ) . '</h2>';
            $out .= '<table class="tt-map-players"><tbody>';
            foreach ( $mentioned as $player ) {
                $out .= '<tr>';
                $out .= '<th scope="row">' . esc_html( (string) $player['name'] ) . '</th>';
                $out .= '<td class="tt-map-marker">'
                    . esc_html( MatchAnalysisEnums::markerLabel( (string) $player['marker'] ) )
                    . '</td>';
                $out .= '<td>' . esc_html( (string) $player['note'] ) . '</td>';
                $out .= '</tr>';
            }
            $out .= '</tbody></table>';
            $out .= '</section>';
        }

        $out .= '</div>';

        return $out;
    }

    /**
     * The stylesheet for the standalone print document. Print CSS is
     * intentionally its own small sheet in points and greys rather than the
     * app's tokens: this page renders outside the dashboard shell, where no
     * token stylesheet is loaded, and colour that reads well on screen
     * prints as mud.
     */
    public static function styleBlock(): string {
        return 'body { font-family: -apple-system, Segoe UI, Roboto, sans-serif; color: #1a1d21; font-size: 11pt; line-height: 1.45; margin: 0; padding: 12px; }'
            . '.tt-map-doc h1 { font-size: 17pt; margin: 0 0 4px; }'
            . '.tt-map-doc h2 { font-size: 12pt; margin: 14px 0 4px; border-bottom: 1px solid #d5d8dc; padding-bottom: 2px; }'
            . '.tt-map-meta { margin: 0 0 10px; color: #55595f; }'
            . '.tt-map-summary { margin: 0; }'
            . '.tt-map-planned { margin: 0 0 4px; color: #55595f; font-style: italic; }'
            . '.tt-map-rating { font-weight: normal; font-size: 10pt; color: #55595f; }'
            . '.tt-map-doc ul { margin: 4px 0 0; padding-left: 18px; }'
            . '.tt-map-section { page-break-inside: avoid; }'
            . '.tt-map-players { width: 100%; border-collapse: collapse; margin-top: 4px; }'
            . '.tt-map-players th, .tt-map-players td { text-align: left; vertical-align: top; padding: 3px 6px 3px 0; border-bottom: 1px solid #e6e8ea; }'
            . '.tt-map-players th { width: 22%; font-weight: 600; }'
            . '.tt-map-marker { width: 18%; color: #55595f; }';
    }

    /**
     * @return list<string>
     */
    private static function lines( string $notes ): array {
        $lines = preg_split( '/\r\n|\r|\n/', $notes ) ?: [];
        $lines = array_map( 'trim', $lines );

        return array_values( array_filter( $lines, static fn( string $l ): bool => $l !== '' ) );
    }

    /**
     * Convenience for the router: compose then render, or '' when the
     * activity carries no analysis worth printing.
     */
    public static function forActivity( int $activity_id ): string {
        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, false );
        if ( $payload === null || (int) $payload['analysis_id'] <= 0 ) return '';

        return self::bodyHtml( $payload );
    }
}
