<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Analytics\Reports\MinutesShareQuery;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * Share of the available minutes (#4572), read through `MinutesShareQuery`,
 * the read of the minutes-share report: each played match at its own resolved
 * length, the squad as that report defines it, and the academy's one target.
 *
 * - For a team: every squad player's share, lowest first, the report's own
 *   order, because who is not getting on is the finding. Staff only.
 * - For a player: their own share on their current team, against the target.
 *   On the family and the scout lists without any team or position-group
 *   average (#3991): in a small group an average is another child's minutes.
 */
final class MinutesShareBlock implements ReportBlock {

    public function key(): string {
        return 'minutes_share';
    }

    public function label(): string {
        return __( 'Share of available minutes', 'talenttrack' );
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
        return [ 'value', 'unit', 'measure_label', 'minutes', 'available_minutes', 'matches', 'target_pct' ];
    }

    public function render( ReportContext $context, array $options ): array {
        if ( $context->scope === ReportContext::SCOPE_PLAYER ) {
            return $this->forPlayer( $context );
        }

        $share = SharedReads::remember(
            'minutes_share_for_team',
            $context,
            static fn(): array => ( new MinutesShareQuery() )->forTeam( $context->teamId, $context->from, $context->to )
        );

        $rows   = [];
        $shares = [];
        foreach ( (array) ( $share['players'] ?? [] ) as $p ) {
            if ( ! is_array( $p ) ) continue;
            $pct      = isset( $p['share_pct'] ) ? (float) $p['share_pct'] : null;
            $shares[] = [ 'share_pct' => $pct ];
            $rows[]   = [
                'player_id' => (int) ( $p['player_id'] ?? 0 ),
                'name'      => (string) ( $p['name'] ?? '' ),
                'minutes'   => (int) ( $p['minutes'] ?? 0 ),
                'share_pct' => $pct !== null ? round( $pct, 1 ) : null,
            ];
        }
        // The report's median, over the unrounded shares.
        $median = MinutesShareQuery::medianShare( $shares );

        return [
            'breakdown'         => 'player_id',
            'rows'              => $rows,
            'available_minutes' => (int) ( $share['available_minutes'] ?? 0 ),
            'matches'           => (int) ( $share['matches'] ?? 0 ),
            'target_pct'        => (int) ( $share['target_pct'] ?? MinutesShareQuery::targetPct() ),
            'median_share_pct'  => $median !== null ? round( $median, 1 ) : null,
        ];
    }

    /** @return array<string,mixed> */
    private function forPlayer( ReportContext $context ): array {
        $player  = QueryHelpers::get_player( $context->playerId );
        $team_id = $player ? (int) ( $player->team_id ?? 0 ) : 0;
        $mine    = $team_id > 0
            ? SharedReads::remember(
                'minutes_share_for_player',
                $context,
                static fn(): array => ( new MinutesShareQuery() )->forPlayer( $team_id, $context->playerId, $context->from, $context->to ) ?? []
            )
            : [];

        $pct = $mine['share_pct'] ?? null;
        return [
            'value'             => $pct !== null ? round( (float) $pct, 1 ) : null,
            'unit'              => 'percent',
            'measure_label'     => __( 'Share of available minutes', 'talenttrack' ),
            'minutes'           => (int) ( $mine['minutes'] ?? 0 ),
            'available_minutes' => (int) ( $mine['available_minutes'] ?? 0 ),
            'matches'           => (int) ( $mine['matches'] ?? 0 ),
            'target_pct'        => (int) ( $mine['target_pct'] ?? MinutesShareQuery::targetPct() ),
        ];
    }
}
