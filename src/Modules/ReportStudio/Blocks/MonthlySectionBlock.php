<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\TeamReportContext;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlockOptions;
use TT\Modules\Analytics\Reports\TeamMonthlyReportDocument;
use TT\Modules\Analytics\Reports\TeamMonthlyReportLayout;
use TT\Modules\Analytics\Reports\TeamSectionRights;
use TT\Modules\ReportStudio\Domain\CarriesSensitiveReads;
use TT\Modules\ReportStudio\Domain\CutsForReader;
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
 *
 * #5023 — attendance, minutes share, matches and the notes area are section
 * blocks too, so the monthly template prints every section with the report's
 * own renderer. The notes area (`monthly_notes`) prints the report's ruled
 * lines; what the meeting decided is a snapshot's note on that block, never
 * a value in the definition.
 */
final class MonthlySectionBlock implements ReportBlock, RendersDocument, CutsForReader, CarriesSensitiveReads {

    /** The Studio key of each section this block covers. */
    public const KEYS = [
        TeamMonthlyReportBlock::COVERAGE    => 'monthly_coverage',
        TeamMonthlyReportBlock::STATUS      => 'monthly_status',
        TeamMonthlyReportBlock::EVALUATIONS => 'monthly_evaluations',
        TeamMonthlyReportBlock::ATTENDANCE  => 'monthly_attendance',
        TeamMonthlyReportBlock::MINUTES     => 'monthly_minutes',
        TeamMonthlyReportBlock::MATCHES     => 'monthly_matches',
        TeamMonthlyReportBlock::ATTENTION   => 'monthly_attention',
        TeamMonthlyReportBlock::CHANGES     => 'monthly_changes',
        TeamMonthlyReportBlock::TESTS       => 'monthly_tests',
        TeamMonthlyReportBlock::ROSTER      => 'monthly_roster',
        TeamMonthlyReportBlock::NOTES       => 'monthly_notes',
        TeamMonthlyReportBlock::QUALITY     => 'monthly_quality',
    ];

    /** The Studio key of a monthly report section, or null for one without a section block. */
    public static function keyFor( string $section ): ?string {
        return self::KEYS[ $section ] ?? null;
    }

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
        if ( $context->scope !== ReportContext::SCOPE_TEAM ) return false;
        if ( $this->section !== TeamMonthlyReportBlock::ATTENDANCE ) return true;
        // The report leaves its attendance section out for a reader without
        // the team's attendance right (#4949), so the block is not offered.
        return $context->teamId > 0
            ? AuthorizationService::canReadTeamAttendance( $context->userId, $context->teamId )
            : AuthorizationService::attendanceReadableTeamIds( $context->userId ) !== [];
    }

    public function externalKeys(): array {
        return [];
    }

    public function render( ReportContext $context, array $options ): array {
        return self::sectionOf( $context, $this->section, $options );
    }

    /**
     * #5023 — one monthly section's payload, composed onto the report's one
     * context for this team, period and reader, so every section block of a
     * report (and the key figures) reads the squad once between them. A
     * section with options of its own is composed onto a context for that
     * option set; options that are the section's defaults are none.
     *
     * @param array<string,mixed> $options the block's validated options
     * @return array<string,mixed>
     */
    public static function sectionOf( ReportContext $context, string $section, array $options = [] ): array {
        $bag = TeamMonthlyReportBlockOptions::normalise( $section, $options );
        $set = $bag === [] ? [] : [ $section => $bag ];
        $key = md5( (string) wp_json_encode( $set ) );

        return SharedReads::remember( 'monthly_section:' . $section . ':' . $key, $context, static function () use ( $context, $section, $set, $key ): array {
            $held = SharedReads::remember( 'monthly_report_context:' . $key, $context, static fn(): array => [
                'context' => new TeamReportContext( $context->teamId, $context->from, $context->to, $context->userId, $set ),
            ] );
            $ctx = $held['context'] ?? null;
            if ( ! $ctx instanceof TeamReportContext ) return [];
            $report = ( new TeamMonthlyReport() )->forContext( $ctx, [ $section ] );
            return is_array( $report['data'][ $section ] ?? null ) ? $report['data'][ $section ] : [];
        } );
    }

    /**
     * #4963 — "what changed": its injury entries per player, and its medical
     * and safeguarding entries per player as the reader's own read of them
     * says (`TeamSectionRights::sensitiveChanges()`, the rule the monthly
     * report's snapshot records by).
     */
    public function sensitiveReads( array $data, array $context, int $reader ): array {
        if ( $this->section !== TeamMonthlyReportBlock::CHANGES ) return [];
        return TeamMonthlyReport::sensitiveReadsOf(
            $data,
            $reader,
            is_numeric( $context['team_id'] ?? null ) ? (int) $context['team_id'] : 0,
            is_string( $context['from'] ?? null ) ? (string) $context['from'] : '',
            is_string( $context['to'] ?? null ) ? (string) $context['to'] : ''
        );
    }

    /** The frozen section as this reader may read it now (`TeamSectionRights::cut()`). */
    public function forReader( array $data, int $reader, array $context ): ?array {
        return self::cutFor( $this->section, $data, $reader, $context );
    }

    /**
     * A monthly section cut for a reader, by the document's team and period.
     *
     * @param array<string,mixed> $data
     * @param array<string,mixed> $context
     * @return array<string,mixed>|null
     */
    public static function cutFor( string $section, array $data, int $reader, array $context ): ?array {
        $team = is_numeric( $context['team_id'] ?? null ) ? (int) $context['team_id'] : 0;
        $from = is_string( $context['from'] ?? null ) ? (string) $context['from'] : '';
        $to   = is_string( $context['to'] ?? null ) ? (string) $context['to'] : '';
        if ( $team <= 0 || $from === '' || $to === '' ) return null;
        return TeamSectionRights::cut( $section, $data, $reader, $team, $from, $to );
    }

    public function document( array $data ): string {
        return '<div class="tt-mr-doc tt-sd-monthly">' . TeamMonthlyReportDocument::sectionHtml( $this->section, $data ) . '</div>';
    }

    public function documentHeight( array $data ): float {
        return TeamMonthlyReportLayout::sectionHeight( $this->section, $data );
    }
}
