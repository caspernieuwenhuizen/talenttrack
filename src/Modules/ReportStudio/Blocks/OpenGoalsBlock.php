<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Goals\GoalsRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\TeamReportContext;
use TT\Modules\Authorization\MatrixGate;
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
 * Gated on the goals read, the gate of `players/{id}/goals`, not the reports
 * read. Goals are on the family allowlist and off the scout's, so a player's
 * count may reach the family and never a scout.
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
                'name'       => trim( (string) ( $player->first_name ?? '' ) . ' ' . (string) ( $player->last_name ?? '' ) ),
                'open_goals' => $n,
            ];
        }

        return [ 'breakdown' => 'player_id', 'rows' => $rows, 'total' => $total ];
    }

    /**
     * The goals read for this subject: the player's goals section, or the
     * goals read on the team. A listing names no subject, so it asks for the
     * goals capability.
     */
    public static function readsGoals( ReportContext $context ): bool {
        if ( $context->scope === ReportContext::SCOPE_PLAYER ) {
            return $context->playerId > 0
                ? AuthorizationService::canReadPlayerSection( $context->userId, $context->playerId, 'goals' )
                : user_can( $context->userId, 'tt_view_goals' );
        }
        if ( $context->scope !== ReportContext::SCOPE_TEAM ) return false;
        if ( $context->teamId <= 0 ) return user_can( $context->userId, 'tt_view_goals' );
        return MatrixGate::can( $context->userId, 'goals', MatrixGate::READ, MatrixGate::SCOPE_GLOBAL )
            || MatrixGate::can( $context->userId, 'goals', MatrixGate::READ, MatrixGate::SCOPE_TEAM, $context->teamId );
    }
}
