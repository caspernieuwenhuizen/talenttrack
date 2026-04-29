<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

class CohortDistribution extends AbstractKpiDataSource {
    public function id(): string { return 'cohort_distribution'; }
    public function label(): string { return __( 'Cohort distribution', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }
    public function compute( int $user_id, int $club_id ): KpiValue {
        // Sprint 3 returns the per-age-group counts as a sparkline payload.
        return KpiValue::unavailable();
    }
}
