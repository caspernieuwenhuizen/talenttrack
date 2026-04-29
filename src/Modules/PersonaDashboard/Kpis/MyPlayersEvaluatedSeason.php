<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

class MyPlayersEvaluatedSeason extends AbstractKpiDataSource {
    public function id(): string { return 'my_players_evaluated_season'; }
    public function label(): string { return __( 'Players I evaluated this season', 'talenttrack' ); }
    public function context(): string { return PersonaContext::COACH; }
    public function compute( int $user_id, int $club_id ): KpiValue {
        return KpiValue::unavailable();
    }
}
