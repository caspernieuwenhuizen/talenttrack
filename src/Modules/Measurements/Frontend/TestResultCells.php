<?php
namespace TT\Modules\Measurements\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Measurements\Levels\MeasurementLevelPalette;
use TT\Modules\Measurements\Repositories\MeasurementTargetsRepository;
use TT\Shared\Frontend\Components\CrossViewLink;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\Components\TrendGlyph;

/**
 * TestResultCells (#4194) — the cell markup of one Test results row.
 *
 * The list is drawn by the shared list table from `GET
 * /measurement-results`, which sends these fragments next to the raw
 * fields (`render: html`). They used to be private methods on
 * `FrontendTestResultsView`. Every value is escaped here.
 */
final class TestResultCells {

    /**
     * The player's name, linked to their profile when the caller may open
     * the players view; plain text otherwise.
     *
     * @param array<string, mixed> $row a MeasurementResultsBrowse row
     */
    public static function player( array $row ): string {
        $name = (string) ( $row['name'] ?? '' );
        $id   = (int) ( $row['player_id'] ?? 0 );
        $url  = CrossViewLink::allows( 'players' ) ? RecordLink::detailUrlForWithBack( 'players', $id ) : '';
        if ( $url === '' ) return esc_html( $name );
        return '<a class="tt-record-link" href="' . esc_url( $url ) . '">' . esc_html( $name ) . '</a>';
    }

    /**
     * Status tests: the level's colour swatch and label. Numeric and scale
     * tests: the value plus its target flag.
     *
     * @param array<string, mixed> $row
     */
    public static function result( array $row ): string {
        if ( (string) ( $row['value_type'] ?? '' ) === 'status' ) {
            $label = (string) ( $row['level_label'] ?? '' );
            if ( $label === '' ) {
                return '<span class="tt-tr-empty">—</span>';
            }
            return '<span class="tt-tr-level">'
                . '<span class="tt-mlvl-swatch ' . esc_attr( MeasurementLevelPalette::cssClass( (string) ( $row['level_token'] ?? '' ) ) ) . '" aria-hidden="true"></span>'
                . '<span class="tt-tr-level__label">' . esc_html( $label ) . '</span></span>';
        }

        $value = (string) ( $row['value'] ?? '' );
        if ( $value === '' ) {
            return '<span class="tt-tr-empty">—</span>';
        }
        $flag = (string) ( $row['flag'] ?? '' );
        $out  = '<span class="tt-tr-value">' . esc_html( $value ) . '</span>';
        if ( $flag !== '' ) {
            $label = MeasurementTargetsRepository::flagLabel( $flag );
            $out  .= ' <span class="tt-tr-flag tt-tr-flag--' . esc_attr( sanitize_html_class( $flag ) ) . '" title="' . esc_attr( $label ) . '">'
                . '<span class="tt-tr-sr">' . esc_html( $label ) . '</span></span>';
        }
        return $out;
    }

    /**
     * #2586 — the arrow says whether it got better; the delta says by how
     * much. A row with no previous value keeps the bare dash.
     *
     * @param array<string, mixed> $row
     */
    public static function trend( array $row ): string {
        $delta = isset( $row['delta'] ) ? (float) $row['delta'] : null;
        return TrendGlyph::render( (string) ( $row['trend'] ?? '' ), $delta, (string) ( $row['unit'] ?? '' ) );
    }
}
