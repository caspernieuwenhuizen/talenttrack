<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\GoalContributionQuery;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\MatchesComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\TeamReportContext;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * Goals and assists (#4572), read through `GoalContributionQuery`.
 *
 * - For a team: the team monthly report's scorers table, through
 *   `MatchesComposer::scorers()`: every player who scored or assisted,
 *   ranked on goals, then assists, then shirt order, with the shared rank.
 *   Staff only.
 * - For a player: their own goals and assists, the figure the player's
 *   goal-contributions route answers. On the scout list. Not on the
 *   family's (#5021): the player report gives a family no goals and assists.
 */
final class GoalsAssistsBlock implements ReportBlock {

    public function key(): string {
        return 'goals_assists';
    }

    public function label(): string {
        return __( 'Goals and assists', 'talenttrack' );
    }

    public function scopes(): array {
        return [ ReportContext::SCOPE_PLAYER, ReportContext::SCOPE_TEAM ];
    }

    public function audiences(): array {
        return [ PlayerReportAudience::INTERNAL, PlayerReportAudience::SCOUT ];
    }

    public function capability(): string {
        return 'tt_view_reports';
    }

    public function options( ReportContext $context ): array {
        return [];
    }

    public function available( ReportContext $context ): bool {
        return in_array( $context->scope, $this->scopes(), true );
    }

    public function externalKeys(): array {
        return [ 'breakdown', 'rows' ];
    }

    public function render( ReportContext $context, array $options ): array {
        $window = [ 'from' => $context->from, 'to' => $context->to ];

        if ( $context->scope === ReportContext::SCOPE_PLAYER ) {
            $mine = SharedReads::remember(
                'goal_contributions_for_player',
                $context,
                static fn(): array => ( new GoalContributionQuery() )->forPlayer( $context->playerId, $window )
            );
            return [
                'breakdown' => null,
                'rows'      => [ [
                    'goals'         => (int) ( $mine['goals'] ?? 0 ),
                    'assists'       => (int) ( $mine['assists'] ?? 0 ),
                    'contributions' => (int) ( $mine['contributions'] ?? 0 ),
                ] ],
            ];
        }

        $scorers = SharedReads::remember(
            'team_scorers',
            $context,
            static fn(): array => MatchesComposer::scorers( new TeamReportContext( $context->teamId, $context->from, $context->to, $context->userId ) )
        );

        $rows = [];
        foreach ( (array) ( $scorers['rows'] ?? [] ) as $s ) {
            if ( ! is_array( $s ) ) continue;
            $rows[] = [
                'player_id' => (int) ( $s['player_id'] ?? 0 ),
                'rank'      => isset( $s['rank'] ) ? (int) $s['rank'] : null,
                'name'      => (string) ( $s['name'] ?? '' ),
                'goals'     => (int) ( $s['goals'] ?? 0 ),
                'assists'   => (int) ( $s['assists'] ?? 0 ),
            ];
        }

        return [
            'breakdown' => 'player_id',
            'rows'      => $rows,
            'goals'     => (int) ( $scorers['goals'] ?? 0 ),
            'assists'   => (int) ( $scorers['assists'] ?? 0 ),
        ];
    }
}
