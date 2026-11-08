<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\GoalContributionQuery;
use TT\Modules\Analytics\Reports\PlayerOrder;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\TeamReportContext;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * Goals and assists (#4572), read through `GoalContributionQuery`.
 *
 * - For a team: every player who scored or assisted in the period, as the
 *   team monthly report's scorers table lists them, named from the squad and
 *   in shirt order (`PlayerOrder`). Staff only.
 * - For a player: their own goals and assists, the figure the player's
 *   goal-contributions route answers. On the family and the scout lists.
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
        return [ PlayerReportAudience::INTERNAL, PlayerReportAudience::FAMILY, PlayerReportAudience::SCOUT ];
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

        $contributions = SharedReads::remember(
            'goal_contributions_for_team',
            $context,
            static fn(): array => ( new GoalContributionQuery() )->forTeam( $context->teamId, $window )
        );
        $squad   = ( new TeamReportContext( $context->teamId, $context->from, $context->to, $context->userId ) )->players();
        $jerseys = PlayerOrder::jerseys( $squad );

        $rows = [];
        foreach ( $contributions as $player_id => $c ) {
            if ( ! is_array( $c ) ) continue;
            $goals   = (int) ( $c['goals'] ?? 0 );
            $assists = (int) ( $c['assists'] ?? 0 );
            if ( $goals === 0 && $assists === 0 ) continue;
            $player = $squad[ (int) $player_id ] ?? null;
            $rows[] = [
                'player_id'     => (int) $player_id,
                'name'          => $player !== null ? trim( (string) ( $player->first_name ?? '' ) . ' ' . (string) ( $player->last_name ?? '' ) ) : '',
                'goals'         => $goals,
                'assists'       => $assists,
                'contributions' => $goals + $assists,
            ];
        }

        return [
            'breakdown' => 'player_id',
            'rows'      => PlayerOrder::sort( $rows, $jerseys ),
            'goals'     => array_sum( array_column( $rows, 'goals' ) ),
            'assists'   => array_sum( array_column( $rows, 'assists' ) ),
        ];
    }
}
