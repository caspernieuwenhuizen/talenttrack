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
        // Implementation deferred. Returns `unavailable()` so the
        // KPI card renders the `—` empty-state placeholder.
        return KpiValue::unavailable();
    }
    /**
     * v3.110.126 — empty linkView. The default mapping routed this
     * KPI to `my-activities`, which is a player-only view that
     * rejects coaches with "not authorized" (`dispatchMeView` only
     * runs for users with a player record). Until the KPI has a
     * real compute() implementation AND a coach-appropriate
     * destination view, the card stays inert (no click target).
     * Pilot symptom: "clicking the presence of my team widget gives
     * a not authorized message."
     */
    public function linkView(): string { return ''; }
}
