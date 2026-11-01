<?php
namespace TT\Modules\Analytics\Reports\TeamMonthlyComposers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\LevelBlockOptions;
use TT\Modules\Analytics\Reports\PlayerOrder;
use TT\Modules\Analytics\Reports\SectionLevel;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;

/**
 * The attendance section (#4590).
 *
 * #4133 — the rows are there at both levels; `level` says which the section
 * prints, and the summary's figures are carried beside them: who is below the
 * amber line, lowest first, and the absences by kind.
 */
final class AttendanceComposer {

    /** @return array<string,mixed> */
    public static function compose( TeamReportContext $ctx ): array {
        $level = LevelBlockOptions::level( $ctx->optionsFor( TeamMonthlyReportBlock::ATTENDANCE ) );

        $rows = [];
        foreach ( $ctx->attendanceRows() as $r ) {
            $rows[] = [
                'player_id'   => $r['player_id'],
                'name'        => trim( $r['first_name'] . ' ' . $r['last_name'] ),
                'activities'  => $r['activities'],
                'present'     => $r['present'],
                'late'        => $r['late'],
                'absent'      => $r['absent'],
                'excused'     => $r['excused'],
                'injured'     => $r['injured'],
                'suspended'   => $r['suspended'],
                'present_pct' => $r['present_pct'],
                'band'        => TeamReportFigures::attendanceBand( $r['present_pct'] ),
                'flagged'     => $r['flagged'],
            ];
        }
        // #3518 — shirt order. The rows come from the shared attendance
        // ranking query, which other surfaces order for their own reasons, so
        // the report sorts its own copy rather than changing that query.
        $rows = PlayerOrder::sort( $rows, PlayerOrder::jerseys( $ctx->players() ) );

        $below    = [];
        $absences = [ 'absent' => 0, 'excused' => 0, 'injured' => 0, 'suspended' => 0 ];
        foreach ( $rows as $r ) {
            foreach ( array_keys( $absences ) as $kind ) $absences[ $kind ] += (int) $r[ $kind ];
            if ( $r['present_pct'] !== null && $r['present_pct'] < TeamMonthlyReport::ATTENDANCE_AMBER_BELOW ) {
                $below[] = [ 'player_id' => $r['player_id'], 'name' => $r['name'], 'pct' => $r['present_pct'] ];
            }
        }
        usort( $below, static fn( array $a, array $b ): int => $a['pct'] <=> $b['pct'] ?: strcmp( $a['name'], $b['name'] ) );

        return [
            'level'        => $level,
            'team_avg_pct' => TeamReportFigures::squadAttendancePct( $ctx->attendanceRows() ),
            'amber_below'  => TeamMonthlyReport::ATTENDANCE_AMBER_BELOW,
            'red_below'    => TeamMonthlyReport::ATTENDANCE_RED_BELOW,
            'below'        => $below,
            'absences'     => $absences,
            'rows'         => $rows,
        ];
    }
}
