<?php
namespace TT\Modules\Analytics\Reports\TeamMonthlyComposers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\PlayerStatus\StatusVerdict;

/**
 * The agenda (#4590): every amber or red player, most urgent first, with what
 * the data says about them. The block the meeting is actually for.
 */
final class AttentionComposer {

    /** @return array<string,mixed> */
    public static function compose( TeamReportContext $ctx ): array {
        $attendance = [];
        foreach ( $ctx->attendanceRows() as $r ) {
            $attendance[ $r['player_id'] ] = $r;
        }
        $players = $ctx->players();

        $items = [];
        foreach ( $ctx->verdictsAt( $ctx->to ) as $pid => $v ) {
            if ( $v->color !== StatusVerdict::COLOR_AMBER && $v->color !== StatusVerdict::COLOR_RED ) continue;
            $att     = $attendance[ $pid ] ?? null;
            $items[] = [
                'player_id'      => $pid,
                'name'           => isset( $players[ $pid ] ) ? TeamReportFigures::name( $players[ $pid ] ) : '',
                'color'          => $v->color,
                'score'          => $v->score,
                'reasons'        => $v->reasons,
                'missing_inputs' => $v->missing_inputs,
                'attendance_pct' => $att !== null ? $att['present_pct'] : null,
                'missed'         => $att !== null ? $att['missed'] : null,
            ];
        }
        usort( $items, static function ( array $a, array $b ): int {
            $ra = $a['color'] === StatusVerdict::COLOR_RED ? 0 : 1;
            $rb = $b['color'] === StatusVerdict::COLOR_RED ? 0 : 1;
            if ( $ra !== $rb ) return $ra <=> $rb;
            return ( $a['score'] ?? 0.0 ) <=> ( $b['score'] ?? 0.0 );
        } );

        return [ 'items' => $items ];
    }
}
