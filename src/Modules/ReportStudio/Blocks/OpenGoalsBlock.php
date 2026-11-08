<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Goals\GoalsRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\TeamReportContext;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\TeamReportFigures;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * Open goals (#4572), as they stand now — the goal reads are not
 * period-bound, so the block never says "in the period".
 *
 * - For a player: `GoalsRepository::countActiveForPlayer()`, the number on
 *   the player profile's Goals KPI, tab badge and list heading.
 * - For a team: `GoalsRepository::openCountsForTeam()`, the team monthly
 *   report's roster column, for every squad player in shirt order.
 *
 * Each scope follows the route that owns its figure: a player's count the
 * goals read on that player, as `players/{id}/goals` asks; the team's the
 * team's reports read, as the monthly report's squad list. Goals are on the
 * family allowlist and off the scout's, so a player's count may reach the
 * family and never a scout.
 */
final class OpenGoalsBlock implements ReportBlock {

    public function key(): string {
        return 'open_goals';
    }

    public function label(): string {
        return __( 'Open goals now', 'talenttrack' );
    }

    public function scopes(): array {
        return [ ReportContext::SCOPE_PLAYER, ReportContext::SCOPE_TEAM ];
    }

    public function audiences(): array {
        return [ PlayerReportAudience::INTERNAL, PlayerReportAudience::FAMILY ];
    }

    public function capability(): string {
        return 'tt_view_reports';
    }

    public function options( ReportContext $context ): array {
        return [];
    }

    public function available( ReportContext $context ): bool {
        return self::readsGoals( $context );
    }

    public function externalKeys(): array {
        return [ 'value', 'unit', 'measure_label' ];
    }

    public function render( ReportContext $context, array $options ): array {
        $repo = new GoalsRepository();
        if ( $context->scope === ReportContext::SCOPE_PLAYER ) {
            return [
                'value'         => $repo->countActiveForPlayer( $context->playerId ),
                'unit'          => '',
                'measure_label' => __( 'Open goals now', 'talenttrack' ),
            ];
        }

        $counts = SharedReads::remember( 'open_goal_counts', $context, static fn(): array => $repo->openCountsForTeam( $context->teamId ) );
        $squad  = ( new TeamReportContext( $context->teamId, $context->from, $context->to, $context->userId ) )->players();

        // Shirt order, every squad player, as the roster column lists them.
        $rows  = [];
        $total = 0;
        foreach ( $squad as $pid => $player ) {
            $n      = (int) ( $counts[ (int) $pid ] ?? 0 );
            $total += $n;
            $rows[] = [
                'player_id'  => (int) $pid,
                'name'       => TeamReportFigures::name( $player ),
                'open_goals' => $n,
            ];
        }

        return [ 'breakdown' => 'player_id', 'rows' => $rows, 'total' => $total ];
    }

    /**
     * The read this subject's goal figure answers to: the player's goals
     * section for a player (a listing names no player, so it asks for the
     * goals capability); for a team, nothing beyond the subject check.
     */
    public static function readsGoals( ReportContext $context ): bool {
        if ( $context->scope === ReportContext::SCOPE_PLAYER ) {
            // The section pair the goals routes ask: the goals read on the
            // player and, for a linked parent, the child's own switch.
            return $context->playerId > 0
                ? AuthorizationService::mayReadPlayerSection( $context->userId, $context->playerId, 'goals' )
                : user_can( $context->userId, 'tt_view_goals' );
        }
        // The team's count is the team monthly report's squad-list column,
        // which the team's reports read opens (the subject check).
        return $context->scope === ReportContext::SCOPE_TEAM;
    }
}
