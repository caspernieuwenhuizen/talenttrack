<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

class AttendancePctRolling extends AbstractKpiDataSource {
    public function id(): string { return 'attendance_pct_rolling'; }
    public function label(): string { return __( 'Attendance % (4-week)', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }

    public function compute( int $user_id, int $club_id ): KpiValue {
        // Sprint 3 wires the rolling AttendanceRepository query.
        return KpiValue::unavailable();
    }
}
