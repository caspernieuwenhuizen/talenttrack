<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\MinutesComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\TeamReportContext;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * Playing time per player against the target (#4568, #4572) — the team
 * monthly report's minutes section, read through its composer: every squad
 * player, the ones who never got on at 0, each with the minutes available to
 * them and their share of it, against the academy's one target
 * (`MinutesShareQuery::targetPct()`). The minutes available come from each
 * match's resolved length (`MatchLengthResolver`), never a fixed 11×70.
 *
 * Staff only: a per-player table is another child's minutes to a family or a
 * scout.
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
        $section = SharedReads::remember(
            'team_minutes_section',
            $context,
            static fn(): array => MinutesComposer::compose( new TeamReportContext( $context->teamId, $context->from, $context->to, $context->userId ) )
        );

        $rows  = [];
        $total = 0;
        foreach ( (array) ( $section['rows'] ?? [] ) as $r ) {
            if ( ! is_array( $r ) ) continue;
            $minutes = (int) ( $r['total_minutes'] ?? 0 );
            $total  += $minutes;
            $rows[]  = [
                'player_id'         => (int) ( $r['player_id'] ?? 0 ),
                'name'              => (string) ( $r['name'] ?? '' ),
                'total_minutes'     => $minutes,
                'matches'           => (int) ( $r['matches'] ?? 0 ),
                'starts'            => (int) ( $r['starts'] ?? 0 ),
                'available_minutes' => (int) ( $r['available_minutes'] ?? 0 ),
                'share_pct'         => isset( $r['share_pct'] ) ? (float) $r['share_pct'] : null,
            ];
        }

        return [
            'breakdown'        => 'player_id',
            'rows'             => $rows,
            'total_minutes'    => $total,
            'target_pct'       => (int) ( $section['target_pct'] ?? 0 ),
            'median_share_pct' => $section['median_share_pct'] ?? null,
            'matches_played'   => (int) ( $section['matches_played'] ?? 0 ),
        ];
    }
}
