<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Analytics\Reports\MinutesQuery;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\FamilySection;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * Minutes per match for one player (#4572) — read through
 * `MinutesQuery::playingTimeForPlayer()` on the player's current team, the
 * read behind the minutes report's drill-down, the player's own minutes
 * route and the development home. Their total and this block's cannot
 * differ.
 *
 * It is the player's own playing time, so it is on the family and the scout
 * lists, like the player report's minutes section.
 */
final class MinutesPerMatchBlock implements ReportBlock, FamilySection {

    public function key(): string {
        return 'minutes_per_match';
    }

    public function label(): string {
        return __( 'Minutes per match', 'talenttrack' );
    }

    public function scopes(): array {
        return [ ReportContext::SCOPE_PLAYER ];
    }

    public function audiences(): array {
        return [ PlayerReportAudience::INTERNAL, PlayerReportAudience::FAMILY, PlayerReportAudience::SCOUT ];
    }

    public function familySection(): string {
        return \TT\Modules\Analytics\Reports\PlayerReportBlock::MINUTES;
    }

    public function capability(): string {
        return 'tt_view_reports';
    }

    public function options( ReportContext $context ): array {
        return [];
    }

    public function available( ReportContext $context ): bool {
        return $context->scope === ReportContext::SCOPE_PLAYER;
    }

    public function externalKeys(): array {
        return [ 'breakdown', 'rows', 'total_minutes' ];
    }

    public function render( ReportContext $context, array $options ): array {
        $player  = QueryHelpers::get_player( $context->playerId );
        $team_id = $player ? (int) ( $player->team_id ?? 0 ) : 0;
        if ( $team_id <= 0 ) {
            // A player between teams has played no minutes for one.
            return [ 'breakdown' => 'match', 'rows' => [], 'total_minutes' => 0 ];
        }

        $time = SharedReads::remember(
            'playing_time_for_player',
            $context,
            static fn(): array => ( new MinutesQuery() )->playingTimeForPlayer( $team_id, $context->playerId, $context->from, $context->to )
        );

        $rows = [];
        foreach ( (array) ( $time['matches'] ?? [] ) as $m ) {
            if ( ! is_array( $m ) ) continue;
            $type   = (string) ( $m['type_key'] ?? '' );
            $rows[] = [
                'session_date' => (string) ( $m['session_date'] ?? '' ),
                'title'        => (string) ( $m['title'] ?? '' ),
                'type'         => $type === '' || $type === 'unknown'
                    ? _x( 'No type', 'match type', 'talenttrack' )
                    : LookupTranslator::byTypeAndName( 'game_subtype', $type ),
                'minutes'      => (int) ( $m['minutes'] ?? 0 ),
            ];
        }

        return [
            'breakdown'     => 'match',
            'rows'          => $rows,
            'total_minutes' => (int) ( $time['total_minutes'] ?? 0 ),
        ];
    }
}
