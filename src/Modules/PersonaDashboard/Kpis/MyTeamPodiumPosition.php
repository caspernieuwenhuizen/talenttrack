<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\TeamStatsService;
use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

class MyTeamPodiumPosition extends AbstractKpiDataSource {
    public function id(): string { return 'my_team_podium_position'; }
    public function label(): string { return __( 'My podium position', 'talenttrack' ); }
    public function context(): string { return PersonaContext::PLAYER_PARENT; }
    public function compute( int $user_id, int $club_id ): KpiValue {
        // #1384 — the numeric team rank is only shown to the player when
        // the academy has opted in. Off (default) → hide this KPI; the
        // player's growth trend carries the motivational signal instead.
        if ( QueryHelpers::get_config( 'tt_player_visible_rank', '0' ) !== '1' ) {
            return KpiValue::unavailable();
        }

        $player_id = PlayerKpiResolver::playerId( $user_id );
        if ( $player_id <= 0 ) return KpiValue::unavailable();

        $rank = ( new TeamStatsService() )->getRankInTeam( $player_id, 5 );
        if ( $rank === null ) return KpiValue::unavailable();

        $delta = sprintf(
            /* translators: %d is the number of rated players on the team. */
            __( 'of %d on the team', 'talenttrack' ),
            (int) $rank['total']
        );
        return KpiValue::of( '#' . number_format_i18n( (int) $rank['rank'] ), null, $delta );
    }
}
