<?php
namespace TT\Modules\Analytics\Reports\TeamMonthlyComposers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Goals\GoalsRepository;
use TT\Infrastructure\Journey\InjuryRepository;
use TT\Infrastructure\PlayerStatus\StatusVerdict;

/**
 * One row per player, every measure (#4590). The page the discussion moves
 * along.
 */
final class RosterComposer {

    /** @return array<string,mixed> */
    public static function compose( TeamReportContext $ctx ): array {
        $attendance = [];
        foreach ( $ctx->attendanceRows() as $r ) {
            $attendance[ $r['player_id'] ] = $r;
        }

        $minutes = [];
        foreach ( $ctx->minutesIn( $ctx->from, $ctx->to ) as $r ) {
            $minutes[ $r['player_id'] ] = $r;
        }

        $goals    = ( new GoalsRepository() )->openCountsForTeam( $ctx->team_id );
        $verdicts = $ctx->verdictsAt( $ctx->to );

        // #4991 — the injury flag is injury information: only when every
        // reader may read the squad's injury records. Otherwise the key is
        // left out, so the renderer draws no injury column.
        $reads_injuries = $ctx->readsInjuries();
        $injured        = [];
        if ( $reads_injuries ) {
            foreach ( ( new InjuryRepository() )->listForTeams( [ $ctx->team_id ], [ 'status' => 'open' ] ) as $i ) {
                $injured[ (int) ( $i->player_id ?? 0 ) ] = true;
            }
        }

        $rows = [];
        foreach ( $ctx->players() as $pid => $p ) {
            $att    = $attendance[ $pid ] ?? null;
            $min    = $minutes[ $pid ] ?? null;
            $jersey = $p->jersey_number ?? null;
            $row = [
                'player_id'      => $pid,
                'name'           => TeamReportFigures::name( $p ),
                'jersey_number'  => $jersey !== null ? (int) $jersey : null,
                'status'         => isset( $verdicts[ $pid ] ) ? $verdicts[ $pid ]->color : StatusVerdict::COLOR_UNKNOWN,
                'attendance_pct' => $att !== null ? $att['present_pct'] : null,
                'minutes'        => $min !== null ? $min['total_minutes'] : null,
                'share_pct'      => $min !== null ? TeamReportFigures::share( $min['total_minutes'], $min['available_minutes'] ) : null,
                'open_goals'     => $goals[ $pid ] ?? 0,
                'injured'        => isset( $injured[ $pid ] ),
                // #4114 — activities the player missed suspended this window
                // (#4102), so a suspension is not a gap nobody can explain.
                'suspended'      => $att !== null ? $att['suspended'] : 0,
            ];
            if ( ! $reads_injuries ) unset( $row['injured'] );
            $rows[] = $row;
        }
        return [ 'rows' => $rows ];
    }
}
