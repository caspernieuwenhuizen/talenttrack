<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PlayerReportAccess (#3872, epic #3871) — may this user read a report about
 * this player?
 *
 * Two conditions, both required:
 *
 *   - `reports` read, at global scope or at team scope on the player's team —
 *     the team report's rule (`TeamReportAccess`) applied to the player's
 *     squad. Players and parents hold no `reports` grant, so they never pass:
 *     the player report is coach-facing in v1.
 *   - `AuthorizationService::canViewPlayer()`. A scout reads reports
 *     academy-wide but players only where linked (#3807), so a report on a
 *     player they have no link to stays closed.
 *
 * The blocks then gate themselves — thread notes on `ThreadAccess`, injuries
 * on the medical rung, journey and tests on the reader's visibility levels,
 * the PDP file on `PdpAccess` — so passing here opens the report, not every
 * record behind it.
 *
 * One class so the REST route, the online view and the PDF cannot answer the
 * question three ways.
 */
final class PlayerReportAccess {

    public static function canRead( int $user_id, int $player_id ): bool {
        if ( $user_id <= 0 || $player_id <= 0 ) return false;

        $team_id = self::teamOf( $player_id );
        if ( $team_id === null ) return false;

        if ( ! self::readsReports( $user_id, $team_id ) ) return false;

        return AuthorizationService::canViewPlayer( $user_id, $player_id );
    }

    private static function readsReports( int $user_id, int $team_id ): bool {
        if ( ! class_exists( '\\TT\\Modules\\Authorization\\MatrixGate' ) ) {
            return user_can( $user_id, 'tt_view_reports' );
        }

        if ( \TT\Modules\Authorization\MatrixGate::can( $user_id, 'reports', 'read', 'global' ) ) {
            return true;
        }

        return $team_id > 0
            && \TT\Modules\Authorization\MatrixGate::can( $user_id, 'reports', 'read', 'team', $team_id );
    }

    /**
     * The player's team id, 0 for a player with no team, null for a player
     * who is not in this club at all.
     */
    private static function teamOf( int $player_id ): ?int {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, team_id FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d",
            $player_id,
            (int) CurrentClub::id()
        ) );

        return $row ? (int) ( $row->team_id ?? 0 ) : null;
    }
}
