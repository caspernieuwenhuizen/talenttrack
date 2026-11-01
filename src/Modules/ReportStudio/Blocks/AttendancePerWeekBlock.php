<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\FactQuery;
use TT\Modules\Analytics\FactRegistry;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\ReportSubjectAccess;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Modules\ReportStudio\Domain\SubjectFilters;

/**
 * Attendance per week (#4568) — the attendance fact grouped by ISO week, so
 * every week's percentage is counted on the rows and the rule the attendance
 * reports use (#4584). One statement, whatever the squad size.
 */
final class AttendancePerWeekBlock implements ReportBlock {

    private const FACT = 'attendance';

    public function key(): string {
        return 'attendance_per_week';
    }

    public function label(): string {
        return __( 'Attendance per week', 'talenttrack' );
    }

    public function scopes(): array {
        return [ ReportContext::SCOPE_PLAYER, ReportContext::SCOPE_TEAM, ReportContext::SCOPE_GROUP, ReportContext::SCOPE_ACADEMY ];
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
        return FactQuery::canRead( self::FACT, ReportSubjectAccess::factScope( $context ) );
    }

    public function externalKeys(): array {
        return [ 'breakdown', 'rows', 'truncated' ];
    }

    public function render( ReportContext $context, array $options ): array {
        $fact    = FactRegistry::find( self::FACT );
        $filters = $fact !== null ? SubjectFilters::for( $context, $fact ) : null;
        if ( $filters === null ) {
            return [ 'breakdown' => 'week', 'rows' => [], 'truncated' => false ];
        }

        $result = SharedReads::remember( 'attendance_per_week', $context, static function () use ( $filters, $context ): array {
            $r = FactQuery::query(
                self::FACT,
                [ 'week' ],
                [ 'attendance_pct', 'count_present', 'count_absent' ],
                $filters,
                ReportSubjectAccess::factScope( $context )
            );
            return [ 'rows' => $r->rows, 'truncated' => $r->truncated ];
        } );

        $rows = [];
        foreach ( (array) ( $result['rows'] ?? [] ) as $row ) {
            if ( ! is_object( $row ) || ! isset( $row->week ) ) continue;
            $pct    = $row->attendance_pct ?? null;
            $rows[] = [
                'week'           => (string) $row->week,
                'attendance_pct' => $pct === null ? null : round( (float) $pct, 1 ),
                'present'        => (int) ( $row->count_present ?? 0 ),
                'absent'         => (int) ( $row->count_absent ?? 0 ),
            ];
        }
        usort( $rows, static fn( array $a, array $b ): int => strcmp( $a['week'], $b['week'] ) );

        return [
            'breakdown' => 'week',
            'rows'      => $rows,
            'truncated' => (bool) ( $result['truncated'] ?? false ),
        ];
    }
}
