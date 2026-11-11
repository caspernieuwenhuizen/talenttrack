<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TeamReportAccess (#3460) — may this user read a report about this team?
 *
 * Lifted out of `TeamsRestController::canReadTeamReports()` (#2835) when the
 * team monthly report grew a second door: the PDF exporter. A REST route and an
 * export that answer "may I see this team's report" with two copies of the rule
 * are two places for it to drift, and the one that drifts is the one that leaks
 * a squad's data to a coach of another team.
 *
 * A global `reports` read (head of development, academy admin, read-only
 * observer) sees any team; everyone else is confined to the teams their matrix
 * grant names. Deliberately the `reports` entity rather than `team` — reporting
 * on a squad and managing it are different rights.
 */
final class TeamReportAccess {

    public static function canRead( int $user_id, int $team_id ): bool {
        if ( $user_id <= 0 || $team_id <= 0 ) return false;
        if ( ! class_exists( '\\TT\\Modules\\Authorization\\MatrixGate' ) ) {
            return user_can( $user_id, 'tt_view_reports' );
        }

        if ( \TT\Modules\Authorization\MatrixGate::can( $user_id, 'reports', 'read', 'global' ) ) {
            return true;
        }

        return \TT\Modules\Authorization\MatrixGate::can( $user_id, 'reports', 'read', 'team', $team_id );
    }

    /**
     * #5021 — may this user make a record from this team's report: freeze a
     * Report Studio snapshot of it, or write a note on one? Reading the report
     * is required, and on top of it the matrix's `reports` create_delete at
     * global scope or on the team, the rule `PlayerReportAccess::canCreate()`
     * asks for a player.
     */
    public static function canCreate( int $user_id, int $team_id ): bool {
        if ( ! self::canRead( $user_id, $team_id ) ) return false;
        if ( ! class_exists( '\\TT\\Modules\\Authorization\\MatrixGate' ) ) {
            return user_can( $user_id, 'tt_generate_report' );
        }
        return \TT\Modules\Authorization\MatrixGate::can( $user_id, 'reports', 'create_delete', 'global' )
            || \TT\Modules\Authorization\MatrixGate::can( $user_id, 'reports', 'create_delete', 'team', $team_id );
    }

    /**
     * `canRead()` as a list, for a query: null for every team, otherwise the
     * teams on which the matrix grants `reports` read. An empty list means
     * none.
     *
     * @return list<int>|null
     */
    public static function readableTeamIds( int $user_id ): ?array {
        if ( $user_id <= 0 ) return [];
        if ( ! class_exists( '\\TT\\Modules\\Authorization\\MatrixGate' ) ) {
            return user_can( $user_id, 'tt_view_reports' ) ? null : [];
        }

        if ( \TT\Modules\Authorization\MatrixGate::can( $user_id, 'reports', 'read', 'global' ) ) {
            return null;
        }

        // #4684 — the teams `canRead()` admits, functional-role grants
        // included, not only the teams the user coaches.
        return \TT\Modules\Authorization\MatrixGate::teamIdsFor( $user_id, 'reports', 'read' );
    }
}
