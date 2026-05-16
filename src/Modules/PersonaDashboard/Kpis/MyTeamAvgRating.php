<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

class MyTeamAvgRating extends AbstractKpiDataSource {
    public function id(): string { return 'my_team_avg_rating'; }
    public function label(): string { return __( 'My team average rating', 'talenttrack' ); }
    public function context(): string { return PersonaContext::COACH; }
    public function compute( int $user_id, int $club_id ): KpiValue {
        // Implementation deferred. Returns `unavailable()` so the
        // KPI card renders the `—` empty-state placeholder.
        return KpiValue::unavailable();
    }
    /**
     * v3.110.126 — empty linkView. The default mapping routed this
     * KPI to `my-team`, a player-only view that rejects coaches
     * (see MyTeamAttendancePct docblock for the full reason).
     */
    public function linkView(): string { return ''; }
}
