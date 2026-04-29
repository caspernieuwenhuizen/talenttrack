<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

/**
 * Depends on #0057 player status traffic light. Renders unavailable
 * until that epic ships — the editor still surfaces this KPI in the
 * picker so customers can pre-place it on their HoD layouts.
 */
class PlayersAtRisk extends AbstractKpiDataSource {
    public function id(): string { return 'players_at_risk'; }
    public function label(): string { return __( 'Players at risk', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }
    public function compute( int $user_id, int $club_id ): KpiValue {
        return KpiValue::unavailable();
    }
}
