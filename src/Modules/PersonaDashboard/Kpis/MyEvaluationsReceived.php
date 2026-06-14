<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvaluationsRepository;
use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

class MyEvaluationsReceived extends AbstractKpiDataSource {
    public function id(): string { return 'my_evaluations_received'; }
    public function label(): string { return __( 'My evaluations received', 'talenttrack' ); }
    public function context(): string { return PersonaContext::PLAYER_PARENT; }
    public function compute( int $user_id, int $club_id ): KpiValue {
        $player_id = PlayerKpiResolver::playerId( $user_id );
        if ( $player_id <= 0 ) return KpiValue::unavailable();

        $count = ( new EvaluationsRepository() )->countForPlayer( $player_id );
        return KpiValue::of( number_format_i18n( $count ) );
    }
}
