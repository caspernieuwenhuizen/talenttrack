<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\ReportStudio\Domain\LayoutBound;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\ReportStartRules;

/**
 * Page break (#4572) — the block after it starts a new A4 page. Rows only:
 * rows print, a grid is for screens. It reads no data and takes no height;
 * `ReportPageFit` moves the next block to a new page.
 */
final class PageBreakBlock implements ReportBlock, LayoutBound {

    public const KEY = 'page_break';

    public function key(): string {
        return self::KEY;
    }

    public function label(): string {
        return _x( 'Page break', 'report studio block', 'talenttrack' );
    }

    public function scopes(): array {
        return ReportContext::SCOPES;
    }

    public function audiences(): array {
        return [ PlayerReportAudience::INTERNAL, PlayerReportAudience::FAMILY, PlayerReportAudience::SCOUT ];
    }

    public function capability(): string {
        return 'tt_view_reports';
    }

    public function options( ReportContext $context ): array {
        return [];
    }

    public function available( ReportContext $context ): bool {
        return true;
    }

    public function externalKeys(): array {
        return [];
    }

    public function render( ReportContext $context, array $options ): array {
        return [];
    }

    public function layouts(): array {
        return [ ReportStartRules::LAYOUT_ROWS ];
    }

    public function layoutReason(): string {
        return __( 'A page break is for a report laid out in rows.', 'talenttrack' );
    }
}
