<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Reports\PlayerReportAccess;
use TT\Modules\Analytics\Reports\TeamReportAccess;
use TT\Modules\Authorization\AllTeamsScope;

/**
 * ReportSubjectAccess — may this reader read a Studio block about this
 * subject? Asked on every call, not once when a report is made (#4568).
 *
 * - a player: `PlayerReportAccess::canRead()`, the player report's rule;
 * - a team: `TeamReportAccess::canRead()`, the team report's rule;
 * - a group: each player through `AuthorizationService::canViewPlayer()`,
 *   and a player who has gone out of scope is dropped rather than failing
 *   the report;
 * - the academy: club-wide analytics read;
 * - me: the reader's own scope, so no subject to check beyond being signed in.
 */
final class ReportSubjectAccess {

    public static function canRead( ReportContext $context ): bool {
        return ! ( self::check( $context ) instanceof StudioError );
    }

    /**
     * The context the reader may use, with a group narrowed to the players
     * they can see, or the refusal.
     */
    public static function check( ReportContext $context ): ReportContext|StudioError {
        $uid = $context->userId;
        if ( $uid <= 0 ) return self::forbidden( 'forbidden_scope' );

        switch ( $context->scope ) {
            case ReportContext::SCOPE_PLAYER:
                return PlayerReportAccess::canRead( $uid, $context->playerId ) ? $context : self::forbidden( 'forbidden_player' );
            case ReportContext::SCOPE_TEAM:
                return TeamReportAccess::canRead( $uid, $context->teamId ) ? $context : self::forbidden( 'forbidden_team' );
            case ReportContext::SCOPE_GROUP:
                $visible = array_values( array_filter(
                    $context->playerIds,
                    static fn( int $pid ): bool => AuthorizationService::canViewPlayer( $uid, $pid )
                ) );
                return $visible === [] ? self::forbidden( 'forbidden_player' ) : $context->withPlayerIds( $visible );
            case ReportContext::SCOPE_ACADEMY:
                return ( user_can( $uid, 'manage_options' ) || AllTeamsScope::canSeeClubWideAnalytics( $uid ) )
                    ? $context
                    : self::forbidden( 'forbidden_scope' );
            case ReportContext::SCOPE_ME:
                return $context;
        }
        return self::forbidden( 'forbidden_scope' );
    }

    /**
     * The fact-query scope for a block in this context: the reader's own
     * scope, narrowed to the team when the subject is a team. Filters on the
     * subject's players narrow further inside it.
     */
    public static function factScope( ReportContext $context ): FactScope {
        $scope = FactScope::forUser( $context->userId );
        return $context->scope === ReportContext::SCOPE_TEAM ? $scope->narrowedToTeams( [ $context->teamId ] ) : $scope;
    }

    private static function forbidden( string $code ): StudioError {
        return new StudioError( $code, __( 'This report subject is not in your scope.', 'talenttrack' ), 403 );
    }
}
