<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportLayout;

/**
 * ReportPageFit (#4571) — how a Studio report in rows falls over A4 pages:
 * how many pages, how full each is, and which block starts each new page.
 *
 * The heights are the player report's (`PlayerReportLayout`), measured on its
 * PDF, so the canvas's page meter and a printed copy read one estimate. There
 * is no estimator in the browser. Two half blocks side by side are one row,
 * as tall as the taller of the two, and a row that does not fit moves to the
 * next page whole, as the PDF moves it.
 */
final class ReportPageFit {

    /**
     * A page break (`break: true`, #4572) takes no height and moves the row
     * after it to a new page. The letterhead is a block, not page chrome
     * (`letterhead: true`): it takes the letterhead's height where it is
     * placed, and a report without one reserves none.
     *
     * @param list<array{id:string, width:string, rows:int, break?:bool, letterhead?:bool}> $blocks in canvas order
     * @return array{pages:int, fill:list<int>, breaks:list<string>}
     */
    public static function estimate( array $blocks ): array {
        $rows   = [];
        $count  = count( $blocks );
        $forced = false;
        for ( $i = 0; $i < $count; $i++ ) {
            $block = $blocks[ $i ];
            if ( ! empty( $block['break'] ) ) {
                $forced = true;
                continue;
            }
            $height = self::heightOf( $block );
            $next   = $blocks[ $i + 1 ] ?? null;
            if ( $block['width'] === ReportDefinition::WIDTH_HALF && $next !== null && empty( $next['break'] ) && $next['width'] === ReportDefinition::WIDTH_HALF ) {
                $height = max( $height, self::heightOf( $next ) );
                $i++;
            }
            $rows[] = [ 'id' => $block['id'], 'height' => $height, 'forced' => $forced ];
            $forced = false;
        }

        $total = array_sum( array_column( $rows, 'height' ) );
        $page  = PlayerReportLayout::pageHeight( $total > PlayerReportLayout::pageHeight( false ) );

        $fill   = [];
        $breaks = [];
        $used   = 0.0;
        foreach ( $rows as $row ) {
            if ( $used > 0.0 && ( $row['forced'] || $used + $row['height'] > $page ) ) {
                $fill[]   = self::percent( $used, $page );
                $breaks[] = $row['id'];
                $used     = 0.0;
            }
            $used += $row['height'];
            // A table taller than a page runs on over the pages it needs.
            while ( $used > $page ) {
                $fill[] = 100;
                $used  -= $page;
            }
        }
        $fill[] = self::percent( $used, $page );

        return [ 'pages' => count( $fill ), 'fill' => $fill, 'breaks' => $breaks ];
    }

    /**
     * The rows a block prints: its table's rows, or none for a single figure,
     * an empty block or one that could not be read, which print as one line.
     *
     * @param array<string,mixed> $block a block of `ReportData::compose()`
     */
    public static function rowsOf( array $block ): int {
        if ( isset( $block['error'] ) ) return 0;
        $data = is_array( $block['data'] ?? null ) ? $block['data'] : [];
        return is_array( $data['rows'] ?? null ) ? count( $data['rows'] ) : 0;
    }

    /**
     * One block's printed height: the letterhead's for a letterhead block,
     * a section of its rows for any other.
     *
     * @param array{rows:int, letterhead?:bool} $block
     */
    private static function heightOf( array $block ): float {
        return ! empty( $block['letterhead'] )
            ? PlayerReportLayout::letterheadHeight()
            : PlayerReportLayout::sectionHeight( $block['rows'] );
    }

    private static function percent( float $used, float $page ): int {
        return (int) round( min( $used, $page ) / $page * 100 );
    }
}
