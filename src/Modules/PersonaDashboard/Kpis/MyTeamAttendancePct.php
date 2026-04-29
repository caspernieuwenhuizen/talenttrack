<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

class MyTeamAttendancePct extends AbstractKpiDataSource {
    public function id(): string { return 'my_team_attendance_pct'; }
    public function label(): string { return __( 'My team attendance %', 'talenttrack' ); }
    public function context(): string { return PersonaContext::COACH; }
    public function compute( int $user_id, int $club_id ): KpiValue {
        return KpiValue::unavailable();
    }
}
