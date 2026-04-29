<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

class PdpVerdictsPending extends AbstractKpiDataSource {
    public function id(): string { return 'pdp_verdicts_pending'; }
    public function label(): string { return __( 'PDP verdicts pending', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }
    public function compute( int $user_id, int $club_id ): KpiValue {
        return KpiValue::unavailable();
    }
}
