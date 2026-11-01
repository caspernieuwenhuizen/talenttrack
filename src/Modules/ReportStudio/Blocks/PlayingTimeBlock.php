<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\MinutesQuery;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * Playing time per player (#4568) — minutes, matches and starts for each
 * player of one team over the period, read through `MinutesQuery::forTeam()`,
 * the read the team monthly report's minutes section uses. Staff only: a
 * per-player table is another child's minutes to a family or a scout.
 */
final class PlayingTimeBlock implements ReportBlock {

    public function key(): string {
        return 'playing_time';
    }

    public function label(): string {
        return __( 'Playing time per player', 'talenttrack' );
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
        return $context->scope === ReportContext::SCOPE_TEAM;
    }

    public function externalKeys(): array {
        return [];
    }

    public function render( ReportContext $context, array $options ): array {
        $source = SharedReads::remember(
            'minutes_for_team',
            $context,
            static fn(): array => ( new MinutesQuery() )->forTeam( $context->teamId, $context->from, $context->to )
        );

        $rows  = [];
        $total = 0;
        foreach ( $source as $r ) {
            if ( ! is_array( $r ) ) continue;
            $minutes = (int) ( $r['total_minutes'] ?? 0 );
            $total  += $minutes;
            $rows[]  = [
                'player_id'         => (int) ( $r['player_id'] ?? 0 ),
                'name'              => trim( (string) ( $r['first_name'] ?? '' ) . ' ' . (string) ( $r['last_name'] ?? '' ) ),
                'total_minutes'     => $minutes,
                'matches'           => (int) ( $r['matches'] ?? 0 ),
                'starts'            => (int) ( $r['starts'] ?? 0 ),
                'available_minutes' => (int) ( $r['available_minutes'] ?? 0 ),
            ];
        }

        return [
            'breakdown'     => 'player_id',
            'rows'          => $rows,
            'total_minutes' => $total,
        ];
    }
}
