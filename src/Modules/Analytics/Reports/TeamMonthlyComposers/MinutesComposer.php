<?php
namespace TT\Modules\Analytics\Reports\TeamMonthlyComposers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\LevelBlockOptions;
use TT\Modules\Analytics\Reports\MinutesQuery;
use TT\Modules\Analytics\Reports\MinutesShareQuery;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;

/**
 * The minutes section (#4590).
 *
 * #4133 — like attendance: the rows at both levels, `level` saying which the
 * section prints, and the summary's figure beside them — who is under the
 * academy's target, lowest first.
 */
final class MinutesComposer {

    /** @return array<string,mixed> */
    public static function compose( TeamReportContext $ctx ): array {
        $level  = LevelBlockOptions::level( $ctx->optionsFor( TeamMonthlyReportBlock::MINUTES ) );
        $counts = ( new MinutesQuery() )->matchCountsForTeam( $ctx->team_id, $ctx->from, $ctx->to );
        $source = $ctx->minutesIn( $ctx->from, $ctx->to );

        $rows = [];
        foreach ( $source as $r ) {
            $rows[] = [
                'player_id'         => $r['player_id'],
                'name'              => trim( $r['first_name'] . ' ' . $r['last_name'] ),
                'total_minutes'     => $r['total_minutes'],
                'available_minutes' => $r['available_minutes'],
                'matches'           => $r['matches'],
                'starts'            => $r['starts'],
                'share_pct'         => TeamReportFigures::share( $r['total_minutes'], $r['available_minutes'] ),
            ];
        }
        usort( $rows, static fn( array $a, array $b ): int => ( $b['share_pct'] ?? -1.0 ) <=> ( $a['share_pct'] ?? -1.0 ) );

        // #3589 — the academy's one target, the one the minutes-share report
        // reads, not a second number of this report's own.
        $target = MinutesShareQuery::targetPct();
        $under  = [];
        foreach ( array_reverse( $rows ) as $r ) {
            if ( $r['share_pct'] !== null && $r['share_pct'] < $target ) {
                $under[] = [ 'player_id' => $r['player_id'], 'name' => $r['name'], 'pct' => $r['share_pct'] ];
            }
        }

        return [
            'level'            => $level,
            'matches_recorded' => $counts['recorded'],
            'matches_played'   => $counts['played'],
            'target_pct'       => $target,
            'median_share_pct' => TeamReportFigures::medianShare( $source ),
            'under'            => $under,
            'rows'             => $rows,
        ];
    }
}
