<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

class PlayersTopQuartile extends AbstractKpiDataSource {
    public function id(): string { return 'players_top_quartile'; }
    public function label(): string { return __( 'Players in top quartile', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }
    public function compute( int $user_id, int $club_id ): KpiValue {
        return KpiValue::unavailable();
    }
}
