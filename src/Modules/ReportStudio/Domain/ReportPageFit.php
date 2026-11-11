<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportLayout;
use TT\Modules\ReportStudio\Blocks\LetterheadBlock;
use TT\Modules\ReportStudio\Blocks\PageBreakBlock;

/**
 * ReportPageFit (#4571) — how a Studio report in rows falls over A4 pages:
 * how many pages, how full each is, and which block starts each new page.
 *
 * The heights are the player report's (`PlayerReportLayout`), measured on its
 * PDF, so the canvas's page meter and a printed copy read one estimate. There
 * is no estimator in the browser. Two half blocks side by side are one row,
 * as tall as the taller of the two, and a row that does not fit moves to the
 * next page whole, as the PDF moves it.
 *
 * #5021 — this is the one estimate for a Studio report. A block that prints
 * with another report's renderer (`RendersDocument`, a monthly section) brings
 * that report's height for itself in `height`, so it is measured as that
 * report measures it, on the same A4 page.
 */
final class ReportPageFit {

    /**
     * A page break (`break: true`, #4572) takes no height and moves the row
     * after it to a new page. The letterhead is a block, not page chrome
     * (`letterhead: true`): it takes the letterhead's height where it is
     * placed, and a report without one reserves none.
     *
     * @param list<array{id:string, width:string, rows:int, break?:bool, letterhead?:bool, height?:float}> $blocks in canvas order
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
     * One read block as the estimate measures it (#5021): its place, its
     * rows, whether it is a page break or the letterhead, and the height of a
     * block that prints with another report's renderer.
     *
     * @param array<string,mixed> $block a block of `ReportData::compose()`
     * @return array{id:string, width:string, rows:int, break:bool, letterhead:bool, height?:float}
     */
    public static function entryOf( array $block ): array {
        $key   = (string) ( $block['key'] ?? '' );
        $entry = [
            'id'         => (string) ( $block['id'] ?? '' ),
            'width'      => ( $block['width'] ?? '' ) === ReportDefinition::WIDTH_HALF ? ReportDefinition::WIDTH_HALF : ReportDefinition::WIDTH_FULL,
            'rows'       => self::rowsOf( $block ),
            'break'      => $key === PageBreakBlock::KEY,
            'letterhead' => $key === LetterheadBlock::KEY,
        ];
        $renderer = BlockRegistry::find( $key );
        if ( $renderer instanceof RendersDocument && ! isset( $block['error'] ) && is_array( $block['data'] ?? null ) ) {
            $data = [];
            foreach ( $block['data'] as $k => $v ) $data[ (string) $k ] = $v;
            $entry['height'] = $renderer->documentHeight( $data );
        }
        return $entry;
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
     * One block's printed height: its own when it brings one, the
     * letterhead's for a letterhead block, a section of its rows for any other.
     *
     * @param array{rows:int, letterhead?:bool, height?:float} $block
     */
    private static function heightOf( array $block ): float {
        if ( isset( $block['height'] ) ) return (float) $block['height'];
        return ! empty( $block['letterhead'] )
            ? PlayerReportLayout::letterheadHeight()
            : PlayerReportLayout::sectionHeight( $block['rows'] );
    }

    private static function percent( float $used, float $page ): int {
        return (int) round( min( $used, $page ) / $page * 100 );
    }
}
