<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Pdp\Repositories\SeasonsRepository;
use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Shared\Frontend\Components\BackLink;

class MyPlayersEvaluatedSeason extends AbstractKpiDataSource {
    public function id(): string { return 'my_players_evaluated_season'; }
    public function label(): string { return __( 'Players I evaluated this season', 'talenttrack' ); }
    public function context(): string { return PersonaContext::COACH; }
    public function compute( int $user_id, int $club_id ): KpiValue {
        return KpiValue::unavailable();
    }

    /**
     * #5048 — the evaluations list, filtered to the evaluations this coach
     * wrote in the current season. Without a current season the list opens
     * on all of the coach's evaluations.
     */
    public function linkUrl( RenderContext $ctx ): string {
        $view = $this->linkView();
        if ( $view === '' ) return '';
        $filter = [ 'coach_id' => $ctx->user_id ];
        $season = ( new SeasonsRepository() )->current();
        if ( $season && ! empty( $season->start_date ) && ! empty( $season->end_date ) ) {
            $filter['date_from'] = (string) $season->start_date;
            $filter['date_to']   = (string) $season->end_date;
        }
        return BackLink::appendTo( add_query_arg( [ 'filter' => $filter ], $ctx->viewUrl( $view ) ) );
    }
}
