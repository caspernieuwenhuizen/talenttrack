<?php
namespace TT\Modules\ReportStudio\Document;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\ReportStudio\Domain\ReportGrid;

/**
 * StudioGrid (#4962, epic #4564) — a Report Studio report laid out as a grid,
 * for a screen: each block at its stored place on 12 columns from the desktop
 * up, one column in reading order below that.
 *
 * The blocks print through `StudioDocument::blockMarkup()`, so a block reads
 * the same in a grid and in rows. A grid is for staff on screen only: it has
 * no pages and no PDF (`ReportGrid::printable()`).
 *
 * The markup is in reading order, so a phone, a screen reader and the Tab key
 * all meet the blocks top row first, left to right. The place is a custom
 * property per cell, read by `frontend-report-grid.css`.
 */
final class StudioGrid {

    /**
     * @param array<string,mixed> $doc a `ReportData::compose()` answer for a grid report
     */
    public static function body( array $doc, int $user_id ): string {
        $context = [];
        foreach ( is_array( $doc['context'] ?? null ) ? $doc['context'] : [] as $k => $v ) $context[ (string) $k ] = $v;
        $words = new DocumentWords( $context, $user_id );

        $placed = [];
        foreach ( is_array( $doc['blocks'] ?? null ) ? $doc['blocks'] : [] as $block ) {
            if ( ! is_array( $block ) || ! is_string( $block['key'] ?? null ) ) continue;
            $place = ReportGrid::place( $block['grid'] ?? null );
            if ( ! is_array( $place ) ) continue;
            $keyed = [];
            foreach ( $block as $k => $v ) $keyed[ (string) $k ] = $v;
            $placed[] = [ 'place' => $place, 'block' => $keyed ];
        }

        if ( $placed === [] ) {
            $out = '<p class="tt-sd-msg">' . esc_html__( 'This dashboard has no blocks yet.', 'talenttrack' ) . '</p>';
        } else {
            $out = '<div class="tt-sg-grid">';
            foreach ( ReportGrid::readingOrder( $placed ) as $entry ) {
                $p    = $entry['place'];
                $vars = sprintf( '--tt-sg-x:%d;--tt-sg-y:%d;--tt-sg-w:%d;--tt-sg-h:%d', $p['x'] + 1, $p['y'] + 1, $p['w'], $p['h'] );
                $out .= '<div class="tt-sg-cell" style="' . esc_attr( $vars ) . '">' /* tt-inline-ok */
                    . StudioDocument::blockMarkup( $entry['block'], $words )
                    . '</div>';
            }
            $out .= '</div>';
        }

        return '<div class="tt-sd tt-sg">' . $out . '<p class="tt-sd-foot">' . esc_html( PlayerReportAudience::footerText( false ) ) . '</p></div>';
    }
}
