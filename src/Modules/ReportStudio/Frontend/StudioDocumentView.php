<?php
namespace TT\Modules\ReportStudio\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\TeamMonthlyReportDocument;
use TT\Modules\Analytics\Reports\TeamMonthlyReportLayout;
use TT\Modules\ReportStudio\Document\StudioDocument;

/**
 * StudioDocumentView (#5021) — the styles a page needs to show a Report
 * Studio document, and the document itself, for every page that shows one:
 * the reader's view, the canvas's A4 view and a frozen copy.
 *
 * The paper's stylesheet is the PDF's own (`StudioDocument::screenCss()`),
 * attached to the enqueued sheet, the way the monthly report's printable is;
 * a block that is a monthly section brings that report's sheets with it.
 */
final class StudioDocumentView {

    public static function enqueue(): void {
        wp_enqueue_style( 'tt-stack-table', TT_PLUGIN_URL . 'assets/css/components/stack-table.css', [], TT_VERSION );
        wp_enqueue_style( 'tt-report-document', TT_PLUGIN_URL . 'assets/css/frontend-report-document.css', [ 'tt-stack-table' ], TT_VERSION );
        wp_add_inline_style( 'tt-report-document', StudioDocument::screenCss() );
        // #4962 — a dashboard laid out as a grid places the same blocks.
        wp_enqueue_style( 'tt-report-grid', TT_PLUGIN_URL . 'assets/css/frontend-report-grid.css', [ 'tt-report-document' ], TT_VERSION );

        // A monthly section prints as on the monthly report's page: its phone
        // cards and, from the desktop up, its paper.
        if ( ! wp_style_is( 'tt-frontend-team-monthly-report', 'enqueued' ) ) {
            wp_enqueue_style( 'tt-frontend-team-monthly-report', TT_PLUGIN_URL . 'assets/css/frontend-team-monthly-report.css', [], TT_VERSION );
            wp_add_inline_style( 'tt-frontend-team-monthly-report', TeamMonthlyReportDocument::screenCss( TeamMonthlyReportLayout::PACK ) );
        }
    }

    /**
     * @param array<string,mixed> $document
     * @param array<string,array{body:string, author:int, updated_at:string}> $notes
     */
    public static function render( array $document, int $user_id, array $notes = [] ): void {
        self::enqueue();
        echo StudioDocument::body( $document, $user_id, $notes ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- StudioDocument escapes every value it prints
    }
}
