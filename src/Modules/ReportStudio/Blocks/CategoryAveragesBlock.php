<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\PlayerReportComposers\PlayerReportContext;
use TT\Modules\Analytics\Reports\PlayerReportComposers\RatingsComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\EvaluationsComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\TeamReportContext;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\FamilySection;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * Average per main category (#4572), following the club's category tree.
 *
 * - For a team: the team monthly report's evaluations section at its summary
 *   level (`EvaluationsComposer` over `TeamMonthlyEvaluations`): each main
 *   category's squad average, its change against the previous period, and
 *   the spread of the players' averages; with the squad average, which is the
 *   "Squad rating" tile's figure. Staff only.
 * - For a player: the player report's ratings (`RatingsComposer` over the
 *   evidence packet): each main category's average and latest rating in the
 *   period. Scores only, never the coach's written notes, so it is on the
 *   family and the scout lists as the player report's ratings are.
 */
final class CategoryAveragesBlock implements ReportBlock, FamilySection {

    public function key(): string {
        return 'category_averages';
    }

    public function label(): string {
        return __( 'Average per main category', 'talenttrack' );
    }

    public function scopes(): array {
        return [ ReportContext::SCOPE_PLAYER, ReportContext::SCOPE_TEAM ];
    }

    public function audiences(): array {
        return [ PlayerReportAudience::INTERNAL, PlayerReportAudience::FAMILY, PlayerReportAudience::SCOUT ];
    }

    public function familySection(): string {
        return \TT\Modules\Analytics\Reports\PlayerReportBlock::RATINGS;
    }

    public function capability(): string {
        return 'tt_view_reports';
    }

    public function options( ReportContext $context ): array {
        return [];
    }

    /**
     * The gate of the route that owns each figure. For a team, the squad's
     * averages are the team monthly report's evaluations section, which the
     * team's reports read opens (the subject check), so nothing more is
     * asked. For a player, the player report's ratings ask the evaluations
     * read on that player, and so does this block.
     */
    public function available( ReportContext $context ): bool {
        if ( $context->scope === ReportContext::SCOPE_TEAM ) return true;
        return self::readsPlayerEvaluations( $context );
    }

    /**
     * The evaluations read on the player, the gate of
     * `players/{id}/evaluations` and the player report's ratings: the
     * section read and, for a linked parent, the child's own switch. A
     * listing names no player. Shared with the progress block.
     */
    public static function readsPlayerEvaluations( ReportContext $context ): bool {
        if ( $context->scope !== ReportContext::SCOPE_PLAYER ) return false;
        return $context->playerId > 0
            ? AuthorizationService::mayReadPlayerSection( $context->userId, $context->playerId, 'evaluations' )
            : user_can( $context->userId, 'tt_view_evaluations' );
    }

    public function externalKeys(): array {
        return [ 'breakdown', 'rows', 'evaluation_count', 'average' ];
    }

    public function render( ReportContext $context, array $options ): array {
        if ( $context->scope === ReportContext::SCOPE_PLAYER ) {
            return $this->forPlayer( $context );
        }

        $section = SharedReads::remember(
            'team_evaluations_section',
            $context,
            static fn(): array => EvaluationsComposer::compose( new TeamReportContext( $context->teamId, $context->from, $context->to, $context->userId ) )
        );

        $rows = [];
        foreach ( (array) ( $section['categories'] ?? [] ) as $c ) {
            if ( ! is_array( $c ) ) continue;
            $rows[] = [
                'category' => (string) ( $c['label'] ?? '' ),
                'average'  => $c['avg'] ?? null,
                'change'   => $c['delta'] ?? null,
                'lowest'   => $c['min'] ?? null,
                'highest'  => $c['max'] ?? null,
            ];
        }

        return [
            'breakdown'       => 'category',
            'rows'            => $rows,
            'squad_avg'       => $section['squad_avg'] ?? null,
            'squad_avg_delta' => $section['squad_avg_delta'] ?? null,
            'evaluated'       => (int) ( $section['evaluated'] ?? 0 ),
            'squad'           => (int) ( $section['squad'] ?? 0 ),
        ];
    }

    /** @return array<string,mixed> */
    private function forPlayer( ReportContext $context ): array {
        $ratings = SharedReads::remember( 'player_ratings', $context, static function () use ( $context ): array {
            $player = PlayerReportContext::make( $context->playerId, $context->from, $context->to, $context->userId, $context->audience );
            return $player !== null ? RatingsComposer::compose( $player ) : [];
        } );

        $rows = [];
        foreach ( (array) ( $ratings['categories'] ?? [] ) as $c ) {
            if ( ! is_array( $c ) ) continue;
            $rows[] = [
                'category' => (string) ( $c['label'] ?? '' ),
                'average'  => $c['average'] ?? null,
                'latest'   => $c['latest'] ?? null,
                'count'    => (int) ( $c['count'] ?? 0 ),
            ];
        }

        return [
            'breakdown'        => 'category',
            'rows'             => $rows,
            'evaluation_count' => (int) ( $ratings['evaluation_count'] ?? 0 ),
            'average'          => $ratings['average'] ?? null,
        ];
    }
}
