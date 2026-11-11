<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlockOptions;
use TT\Modules\Analytics\Reports\TeamMonthlyReportDocument;
use TT\Modules\Analytics\Reports\TeamMonthlyReportLayout;
use TT\Modules\ReportStudio\Domain\RendersDocument;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SectionOptions;
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * One section of the team monthly report as a Studio block (#4961): the
 * section's composer payload, as `TeamMonthlyReport::forTeam()` composes it
 * for this reader, so the attendance right cuts it as it cuts the report.
 * It prints with the report's own section renderer, is measured with the
 * report's heights, and offers the options the section's option class owns.
 *
 * Team scope only, and staff only: every one of these sections names
 * players, or judges them.
 */
final class MonthlySectionBlock implements ReportBlock, RendersDocument {

    /** The Studio key of each section this block covers. */
    public const KEYS = [
        TeamMonthlyReportBlock::COVERAGE    => 'monthly_coverage',
        TeamMonthlyReportBlock::STATUS      => 'monthly_status',
        TeamMonthlyReportBlock::EVALUATIONS => 'monthly_evaluations',
        TeamMonthlyReportBlock::ATTENTION   => 'monthly_attention',
        TeamMonthlyReportBlock::CHANGES     => 'monthly_changes',
        TeamMonthlyReportBlock::TESTS       => 'monthly_tests',
        TeamMonthlyReportBlock::ROSTER      => 'monthly_roster',
        TeamMonthlyReportBlock::QUALITY     => 'monthly_quality',
    ];

    public function __construct( private readonly string $section ) {
        if ( ! isset( self::KEYS[ $section ] ) ) {
            throw new \InvalidArgumentException( 'Not a monthly section block: ' . $section );
        }
    }

    /** The monthly report section this block is. */
    public function section(): string {
        return $this->section;
    }

    public function key(): string {
        return self::KEYS[ $this->section ];
    }

    public function label(): string {
        return TeamMonthlyReportBlock::title( $this->section );
    }

    public function scopes(): array {
        return [ ReportContext::SCOPE_TEAM ];
    }

    public function audiences(): array {
        return [ PlayerReportAudience::INTERNAL ];
    }

    public function capability(): string {
        return 'tt_view_reports';
    }

    public function options( ReportContext $context ): array {
        return SectionOptions::of( TeamMonthlyReportBlockOptions::ownerOf( $this->section ) );
    }

    public function available( ReportContext $context ): bool {
        return $context->scope === ReportContext::SCOPE_TEAM;
    }

    public function externalKeys(): array {
        return [];
    }

    public function render( ReportContext $context, array $options ): array {
        $section = $this->section;
        $read    = 'monthly_section:' . $section . ':' . md5( (string) wp_json_encode( $options ) );
        return SharedReads::remember( $read, $context, static function () use ( $context, $section, $options ): array {
            $report = ( new TeamMonthlyReport() )->forTeam( $context->teamId, $context->from, $context->to, [ $section ], $context->userId, [ $section => $options ] );
            return is_array( $report['data'][ $section ] ?? null ) ? $report['data'][ $section ] : [];
        } );
    }

    public function document( array $data ): string {
        return '<div class="tt-mr-doc tt-sd-monthly">' . TeamMonthlyReportDocument::sectionHtml( $this->section, $data ) . '</div>';
    }

    public function documentHeight( array $data ): float {
        return TeamMonthlyReportLayout::sectionHeight( $this->section, $data );
    }
}
