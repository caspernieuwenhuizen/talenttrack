<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Goals\GoalsRepository;
use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

class MyNextMilestone extends AbstractKpiDataSource {
    public function id(): string { return 'my_next_milestone'; }
    public function label(): string { return __( 'My next milestone', 'talenttrack' ); }
    public function context(): string { return PersonaContext::PLAYER_PARENT; }
    public function compute( int $user_id, int $club_id ): KpiValue {
        $player_id = PlayerKpiResolver::playerId( $user_id );
        if ( $player_id <= 0 ) return KpiValue::unavailable();

        $goal = ( new GoalsRepository() )->nextDueActiveGoalForPlayer( $player_id );
        if ( $goal === null ) return KpiValue::unavailable();

        // Headline = the due date (when); secondary = the goal title (what).
        $due_ts  = strtotime( (string) ( $goal->due_date ?? '' ) );
        $current = $due_ts ? date_i18n( (string) get_option( 'date_format', 'Y-m-d' ), $due_ts ) : '—';

        $title = (string) \TT\Modules\Translations\TranslationLayer::render( (string) ( $goal->title ?? '' ) );
        if ( function_exists( 'mb_strlen' ) && mb_strlen( $title ) > 48 ) {
            $title = mb_substr( $title, 0, 47 ) . '…';
        }

        return KpiValue::of( $current, null, $title !== '' ? $title : null );
    }
}
