<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Frontend\Components\CrossViewLink;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * AttendanceLeaderboardCells (#4194) — one leaderboard row as the shared
 * list table reads it: the raw figures from `AttendanceRankingQuery` plus
 * the pre-rendered cells (`render: html`). `GET
 * /reports/attendance-leaderboard?board=…` returns these; they used to be
 * built inline by `FrontendAttendanceLeaderboardView`. Every value is
 * escaped here.
 */
final class AttendanceLeaderboardCells {

    /**
     * @param array<string, mixed> $r    a row from AttendanceRankingQuery::leaderboard()
     * @param int                  $rank the place on the whole board, from 1
     * @param bool                 $is_bottom the "needs attention" board, which
     *                                        carries the at-risk badge
     * @return array<string, mixed>
     */
    public static function row( array $r, int $rank, bool $is_bottom ): array {
        $player_id = (int) ( $r['player_id'] ?? 0 );
        $name      = trim( (string) ( $r['first_name'] ?? '' ) . ' ' . (string) ( $r['last_name'] ?? '' ) );
        if ( $name === '' ) $name = '#' . $player_id;
        $team    = (string) ( $r['team_name'] ?? '' );
        $pct     = isset( $r['present_pct'] ) ? (float) $r['present_pct'] : null;
        $flagged = $is_bottom && ! empty( $r['flagged'] );
        $missed  = (int) ( $r['missed'] ?? 0 );

        $player_url  = CrossViewLink::allows( 'players' ) ? RecordLink::detailUrlForWithBack( 'players', $player_id ) : '';
        $player_html = $player_url !== ''
            ? '<a class="tt-record-link" href="' . esc_url( $player_url ) . '">' . esc_html( $name ) . '</a>'
            : esc_html( $name );
        // #2350 — the at-risk badge on flagged rows of the bottom board,
        // the same chip the player report shows.
        if ( $flagged ) {
            $player_html .= ' <span class="tt-flag-badge" title="'
                . esc_attr( sprintf( /* translators: %d missed activities */ __( '%d missed', 'talenttrack' ), $missed ) )
                . '">⚠ ' . $missed . '</span>';
        }

        return [
            'rank'         => $rank,
            'player_id'    => $player_id,
            'player_name'  => $name,
            'team_name'    => $team,
            'activities'   => (int) ( $r['activities'] ?? 0 ),
            'present_pct'  => $pct,
            'missed'       => $missed,
            'flagged'      => $flagged,
            'detail_url'   => $player_url,
            'player_html'  => $player_html,
            'team_html'    => $team !== '' ? esc_html( $team ) : '<span class="tt-muted">&mdash;</span>',
            'present_html' => self::bar( $pct ),
        ];
    }

    /**
     * Inline present-% bar — value + a proportional track, red below 70%.
     * Shares the .tt-att-bar vocabulary with the team and player
     * attendance reports (#1688 / #1695).
     */
    public static function bar( ?float $pct ): string {
        if ( $pct === null ) {
            return '<span class="tt-att-bar"><span class="v">—</span></span>';
        }
        $w = max( 0, min( 100, (int) round( $pct ) ) );
        return '<span class="tt-att-bar' . ( $pct < 70 ? ' is-low' : '' ) . '">'
            . '<span class="v">' . esc_html( number_format_i18n( $pct, 1 ) . '%' ) . '</span>'
            . '<span class="track"><i style="width:' . $w . '%;"></i></span>' /* tt-inline-ok */
            . '</span>';
    }
}
