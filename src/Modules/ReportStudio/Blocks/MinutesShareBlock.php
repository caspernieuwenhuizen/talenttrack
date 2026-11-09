<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Analytics\Reports\MinutesShareQuery;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\MinutesComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\TeamReportContext;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * Share of the available minutes (#4572, #4966): the share the team monthly
 * report's minutes section computes (`MinutesComposer`), the same figure as
 * the key-figures tile and `playing_time`. A player's minutes are divided by
 * the minutes available in the matches whose minutes were recorded
 * (`MinutesQuery::forTeam()`, #2252), not by every played match: a match
 * nobody recorded minutes for adds to neither side of the share. Against the
 * academy's one target.
 *
 * - For a team: every squad player's share, lowest first, because who is not
 *   getting on is the finding, with the section's median. Staff only.
 * - For a player: their own row in their current team's section, against the
 *   target. On the family and the scout lists without any team or
 *   position-group average (#3991): in a small group an average is another
 *   child's minutes.
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

        $section = self::section( $context, $context->teamId );

        $rows = [];
        foreach ( (array) ( $section['rows'] ?? [] ) as $r ) {
            if ( ! is_array( $r ) ) continue;
            $rows[] = [
                'player_id'         => (int) ( $r['player_id'] ?? 0 ),
                'name'              => (string) ( $r['name'] ?? '' ),
                'minutes'           => (int) ( $r['total_minutes'] ?? 0 ),
                'available_minutes' => (int) ( $r['available_minutes'] ?? 0 ),
                'share_pct'         => isset( $r['share_pct'] ) ? (float) $r['share_pct'] : null,
            ];
        }
        // Lowest share first, because who is not getting on is the finding;
        // a player with no minutes available to share has no figure and goes
        // last. PHP's sort is stable, so equal shares keep the section's order.
        usort( $rows, static function ( array $a, array $b ): int {
            if ( $a['share_pct'] === null || $b['share_pct'] === null ) {
                return ( $a['share_pct'] === null ? 1 : 0 ) <=> ( $b['share_pct'] === null ? 1 : 0 );
            }
            return $a['share_pct'] <=> $b['share_pct'];
        } );

        return [
            'breakdown'        => 'player_id',
            'rows'             => $rows,
            'matches'          => (int) ( $section['matches_played'] ?? 0 ),
            'target_pct'       => (int) ( $section['target_pct'] ?? MinutesShareQuery::targetPct() ),
            'median_share_pct' => $section['median_share_pct'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private function forPlayer( ReportContext $context ): array {
        $player  = QueryHelpers::get_player( $context->playerId );
        $team_id = $player ? (int) ( $player->team_id ?? 0 ) : 0;

        $mine    = null;
        $section = $team_id > 0 ? self::section( $context, $team_id ) : [];
        foreach ( (array) ( $section['rows'] ?? [] ) as $r ) {
            if ( is_array( $r ) && (int) ( $r['player_id'] ?? 0 ) === $context->playerId ) {
                $mine = $r;
                break;
            }
        }

        $pct = is_array( $mine ) && isset( $mine['share_pct'] ) ? (float) $mine['share_pct'] : null;
        return [
            'value'             => $pct,
            'unit'              => 'percent',
            'measure_label'     => __( 'Share of available minutes', 'talenttrack' ),
            'minutes'           => is_array( $mine ) ? (int) ( $mine['total_minutes'] ?? 0 ) : 0,
            'available_minutes' => is_array( $mine ) ? (int) ( $mine['available_minutes'] ?? 0 ) : 0,
            'matches'           => is_array( $mine ) ? (int) ( $mine['matches'] ?? 0 ) : 0,
            'target_pct'        => (int) ( $section['target_pct'] ?? MinutesShareQuery::targetPct() ),
        ];
    }

    /**
     * The team monthly report's minutes section for this team and period,
     * the one read `playing_time` and the key-figures tile answer from, so
     * the share has one owner (#4966). For a player it is their current
     * team's section; the memo key carries the subject, so that never
     * answers for a team report.
     *
     * @return array<string,mixed>
     */
    private static function section( ReportContext $context, int $team_id ): array {
        return SharedReads::remember(
            'team_minutes_section',
            $context,
            static fn(): array => MinutesComposer::compose( new TeamReportContext( $team_id, $context->from, $context->to, $context->userId ) )
        );
    }
}
