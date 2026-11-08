<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\ReportStudio\Domain\BlockOption;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;

/**
 * Text (#4572) — a heading and a paragraph about the document: what the
 * report is for, how to read it. It reads no data.
 *
 * It is not a note about a player. Notes about a player live on a snapshot
 * (#3513), never in the report's stored definition, so the settings say so.
 */
final class TextBlock implements ReportBlock {

    /** The longest heading, in characters. */
    public const MAX_HEADING = 120;

    /** The longest paragraph, in characters. */
    public const MAX_TEXT = 2000;

    public function key(): string {
        return 'text';
    }

    public function label(): string {
        return _x( 'Text', 'report studio block', 'talenttrack' );
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
            new BlockOption( 'heading', BlockOption::TYPE_TEXT, _x( 'Heading', 'report studio block', 'talenttrack' ), [], false, '', null, null, self::MAX_HEADING ),
            new BlockOption( 'text', BlockOption::TYPE_TEXT, __( 'Text about this report (not about a player)', 'talenttrack' ), [], false, '', null, null, self::MAX_TEXT ),
        ];
    }

    public function available( ReportContext $context ): bool {
        return true;
    }

    public function externalKeys(): array {
        return [ 'heading', 'text' ];
    }

    public function render( ReportContext $context, array $options ): array {
        return [
            'heading' => is_string( $options['heading'] ?? null ) ? $options['heading'] : '',
            'text'    => is_string( $options['text'] ?? null ) ? $options['text'] : '',
        ];
    }
}
