<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

/**
 * Depends on #0054 PDP planning windows. Renders unavailable until that
 * epic ships.
 */
class PdpPlannedVsConductedBlock extends AbstractKpiDataSource {
    public function id(): string { return 'pdp_planned_vs_conducted_block'; }
    public function label(): string { return __( 'PDP planned vs conducted (this block)', 'talenttrack' ); }
    public function context(): string { return PersonaContext::COACH; }
    public function compute( int $user_id, int $club_id ): KpiValue {
        return KpiValue::unavailable();
    }
}
