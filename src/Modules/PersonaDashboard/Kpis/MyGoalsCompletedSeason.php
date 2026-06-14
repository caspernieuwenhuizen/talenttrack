<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Goals\GoalsRepository;
use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

class MyGoalsCompletedSeason extends AbstractKpiDataSource {
    public function id(): string { return 'my_goals_completed_season'; }
    public function label(): string { return __( 'My goals completed (season)', 'talenttrack' ); }
    public function context(): string { return PersonaContext::PLAYER_PARENT; }
    public function compute( int $user_id, int $club_id ): KpiValue {
        $player_id = PlayerKpiResolver::playerId( $user_id );
        if ( $player_id <= 0 ) return KpiValue::unavailable();

        $count = ( new GoalsRepository() )->countCompletedForPlayer( $player_id );
        return KpiValue::of( number_format_i18n( $count ) );
    }
}
