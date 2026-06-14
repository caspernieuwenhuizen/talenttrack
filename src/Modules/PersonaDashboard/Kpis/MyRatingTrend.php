<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvaluationsRepository;
use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

class MyRatingTrend extends AbstractKpiDataSource {
    public function id(): string { return 'my_rating_trend'; }
    public function label(): string { return __( 'My rating trend', 'talenttrack' ); }
    public function context(): string { return PersonaContext::PLAYER_PARENT; }
    public function compute( int $user_id, int $club_id ): KpiValue {
        $player_id = PlayerKpiResolver::playerId( $user_id );
        if ( $player_id <= 0 ) return KpiValue::unavailable();

        $trend = ( new EvaluationsRepository() )->personalTrendForPlayer( $player_id );
        if ( empty( $trend['has_data'] ) || $trend['current_avg'] === null ) {
            return KpiValue::unavailable();
        }

        $current = number_format_i18n( (float) $trend['current_avg'], 1 );
        $delta   = $trend['delta'];
        if ( $delta === null ) {
            return KpiValue::of( (string) $current );
        }

        $dir         = $delta > 0 ? 'up' : ( $delta < 0 ? 'down' : 'flat' );
        $delta_label = sprintf(
            /* translators: %s is the signed rating change since last month, e.g. "+0.4". */
            __( '%s since last month', 'talenttrack' ),
            ( $delta > 0 ? '+' : '' ) . number_format_i18n( $delta, 1 )
        );
        return KpiValue::of( (string) $current, $dir, $delta_label );
    }
}
