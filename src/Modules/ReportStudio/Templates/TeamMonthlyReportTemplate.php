<?php
namespace TT\Modules\ReportStudio\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
use TT\Modules\ReportStudio\Blocks\KeyFiguresBlock;
use TT\Modules\ReportStudio\Blocks\LetterheadBlock;
use TT\Modules\ReportStudio\Blocks\MonthlySectionBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\ReportDefinition;
use TT\Modules\ReportStudio\Domain\ReportStartRules;
use TT\Modules\ReportStudio\Domain\ReportTemplate;
use TT\Modules\ReportStudio\Domain\ReportTemplateRegistry;

/**
 * The team monthly report as a Studio template (#5023, epic #4564): every
 * section the report prints when nothing is deselected
 * (`TeamMonthlyReportBlock::normalise( [] )`), in its print order, with the
 * report's default options. Each section is the block that prints with the
 * report's own section renderer: the key figures for the headline numbers,
 * a section block for every other one. For staff, about one team.
 */
final class TeamMonthlyReportTemplate {

    public const KEY = 'team_monthly_report';

    public static function register(): void {
        ReportTemplateRegistry::register( self::KEY, static fn(): ReportTemplate => self::template() );
    }

    public static function template(): ReportTemplate {
        return new ReportTemplate(
            self::KEY,
            __( 'Team monthly report', 'talenttrack' ),
            __( 'The monthly report of one team, the same on screen and on paper: the headline numbers, squad status, evaluations, attendance, minutes, matches, who needs a conversation, what changed, tests, player by player, decisions and actions, and data quality.', 'talenttrack' ),
            [
                'kind'     => ReportStartRules::KIND_REPORT,
                'scope'    => ReportContext::SCOPE_TEAM,
                'audience' => PlayerReportAudience::INTERNAL,
                'layout'   => ReportStartRules::LAYOUT_ROWS,
            ],
            [ 'blocks' => self::blocks() ]
        );
    }

    /**
     * Every section of the report, as blocks, in print order.
     *
     * @return list<array{id:string, key:string, options:array<string,mixed>, width:string}>
     */
    public static function blocks(): array {
        $out = [];
        foreach ( TeamMonthlyReportBlock::normalise( [] ) as $section ) {
            $key = self::keyFor( $section );
            if ( $key === null ) continue;
            $out[] = [ 'id' => 'b' . ( count( $out ) + 1 ), 'key' => $key, 'options' => [], 'width' => ReportDefinition::WIDTH_FULL ];
        }
        return $out;
    }

    /** The block a monthly report section is printed by. */
    public static function keyFor( string $section ): ?string {
        if ( $section === TeamMonthlyReportBlock::LETTERHEAD ) return LetterheadBlock::KEY;
        if ( $section === TeamMonthlyReportBlock::KPI ) return ( new KeyFiguresBlock() )->key();
        return MonthlySectionBlock::keyFor( $section );
    }
}
