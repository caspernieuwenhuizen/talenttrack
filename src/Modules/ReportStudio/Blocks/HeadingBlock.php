<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\ReportStudio\Domain\BlockOption;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;

/**
 * Heading (#4572) — a section title between blocks. It reads no data.
 */
final class HeadingBlock implements ReportBlock {

    public function key(): string {
        return 'heading';
    }

    public function label(): string {
        return _x( 'Heading', 'report studio block', 'talenttrack' );
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
        return [
            new BlockOption( 'heading', BlockOption::TYPE_TEXT, _x( 'Heading', 'report studio block', 'talenttrack' ), [], false, '', null, null, TextBlock::MAX_HEADING ),
        ];
    }

    public function available( ReportContext $context ): bool {
        return true;
    }

    public function externalKeys(): array {
        return [ 'heading' ];
    }

    public function render( ReportContext $context, array $options ): array {
        return [ 'heading' => is_string( $options['heading'] ?? null ) ? $options['heading'] : '' ];
    }
}
