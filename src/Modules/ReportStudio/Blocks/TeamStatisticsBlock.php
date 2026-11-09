<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\TeamMatchRecord;
use TT\Modules\Analytics\Reports\TeamMatchStatsQuery;
use TT\Modules\Authorization\AllTeamsScope;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * Team statistics (#4572) — the team statistics tab's record over the
 * period, read through `TeamMatchStatsQuery::forTeam()`, the read of that tab
 * and `GET /teams/{id}/stats`: played, won, drawn, lost, goals, the goal
 * difference, clean sheets and the played matches without a score.
 *
 * Gated as that route is: the team read (`tt_view_teams` and
 * `AllTeamsScope::canReadTeam()`), not the reports read. Staff only.
 */
final class TeamStatisticsBlock implements ReportBlock {

    public function key(): string {
        return 'team_statistics';
    }

    public function label(): string {
        return __( 'Team statistics', 'talenttrack' );
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
        return [];
    }

    public function available( ReportContext $context ): bool {
        if ( $context->scope !== ReportContext::SCOPE_TEAM ) return false;
        $views = AuthorizationService::userCanOrMatrix( $context->userId, 'tt_view_teams' )
            || AuthorizationService::userCanOrMatrix( $context->userId, 'tt_edit_teams' );
        if ( ! $views ) return false;
        return $context->teamId <= 0 || AllTeamsScope::canReadTeam( $context->userId, $context->teamId );
    }

    public function externalKeys(): array {
        return [];
    }

    public function render( ReportContext $context, array $options ): array {
        $stats = SharedReads::remember(
            'team_match_stats',
            $context,
            static fn(): array => ( new TeamMatchStatsQuery() )->forTeam( $context->teamId, [ 'from' => $context->from, 'to' => $context->to ] )
        );
        $r = is_array( $stats['record'] ?? null ) ? $stats['record'] : [];

        return [
            'breakdown' => null,
            'rows'      => [ [
                'played'          => (int) ( $r['played'] ?? 0 ),
                'won'             => (int) ( $r['won'] ?? 0 ),
                'drawn'           => (int) ( $r['drawn'] ?? 0 ),
                'lost'            => (int) ( $r['lost'] ?? 0 ),
                'goals_for'       => (int) ( $r['goals_for'] ?? 0 ),
                'goals_against'   => (int) ( $r['goals_against'] ?? 0 ),
                'goal_difference' => (int) ( $r['goal_difference'] ?? 0 ),
                'clean_sheets'    => (int) ( $r['clean_sheets'] ?? 0 ),
                'without_a_score' => (int) ( $r['without_a_score'] ?? 0 ),
            ] ],
            'tournaments_excluded' => (int) ( $r['tournaments_excluded'] ?? 0 ),
            'note'                 => TeamMatchRecord::tournamentsNote( (int) ( $r['tournaments_excluded'] ?? 0 ) ),
        ];
    }
}
