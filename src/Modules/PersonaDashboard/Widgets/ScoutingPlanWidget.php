<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Modules\Prospects\Repositories\ScoutingVisitsRepository;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * ScoutingPlanWidget (v3.110.119) — scout dashboard.
 *
 * Renders the next 5 *planned* visits for the current scout, with
 * date / location / event and a count of prospects logged so far.
 * The empty state nudges the scout to plan their next visit.
 *
 * Data: `ScoutingVisitsRepository::upcomingForScout()` — scoped to
 * `scout_user_id = $user_id`, `status = planned`,
 * `visit_date >= today`, ordered ascending.
 *
 * Cap: `tt_view_prospects` (every scout holds it). The widget is
 * read-only — the "Plan visit" action lives on the scouting-visits
 * list view itself.
 */
class ScoutingPlanWidget extends AbstractWidget {

    public function id(): string { return 'scouting_plan'; }

    public function label(): string {
        return __( 'My scouting plan', 'talenttrack' );
    }

    public function description(): string {
        return __( 'Upcoming planned scouting visits for the current scout, with location, event, and prospects-logged count.', 'talenttrack' );
    }

    /** @return list<string> */
    public function intendedPersonas(): array {
        return [ 'scout' ];
    }

    public function defaultSize(): string { return Size::M; }

    /** @return list<string> */
    public function allowedSizes(): array {
        return [ Size::M, Size::L, Size::XL ];
    }

    public function personaContext(): string { return PersonaContext::ACADEMY; }

    public function capRequired(): string { return 'tt_view_prospects'; }

    public function defaultMobilePriority(): int { return 35; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $repo = new ScoutingVisitsRepository();
        $rows = $repo->upcomingForScout( $ctx->user_id, 5 );

        $base_url    = $ctx->viewUrl( 'scouting-visits' );
        $all_link    = '<a class="tt-pd-link" href="' . esc_url( $base_url ) . '">'
            . esc_html__( 'Show all', 'talenttrack' )
            . '</a>';

        $title = '<div class="tt-pd-widget-head">'
            . '<div class="tt-pd-widget-title">' . esc_html__( 'My scouting plan', 'talenttrack' ) . '</div>'
            . $all_link
            . '</div>';

        if ( empty( $rows ) ) {
            $body = $title
                . '<p class="tt-pd-empty">'
                . esc_html__( 'No upcoming visits planned. Plan your next scouting visit to keep your portfolio fresh.', 'talenttrack' )
                . '</p>'
                . '<a class="tt-pd-cta tt-pd-cta-secondary" href="' . esc_url( add_query_arg( [ 'action' => 'new' ], $base_url ) ) . '">'
                . esc_html__( '+ Plan visit', 'talenttrack' )
                . '</a>';
            return $this->wrap( $slot, $body );
        }

        $items = '';
        foreach ( $rows as $row ) {
            $visit_id = (int) $row->id;
            $date_iso = (string) ( $row->visit_date ?? '' );
            $time_part = (string) ( $row->visit_time ?? '' );
            $date_label = $date_iso !== '' ? mysql2date( 'D j M', $date_iso, true ) : '';
            if ( $time_part !== '' && $time_part !== '00:00:00' ) {
                $date_label .= ' · ' . substr( $time_part, 0, 5 );
            }
            $detail_url = BackLink::appendTo( RecordLink::detailUrlFor( 'scouting-visit', $visit_id ) );
            $location   = (string) ( $row->location ?? '' );
            $event      = (string) ( $row->event_description ?? '' );
            $count      = $repo->prospectCount( $visit_id );
            $count_html = $count > 0
                ? '<span class="tt-pd-scouting-count" aria-label="' . esc_attr(
                    sprintf( /* translators: %d: prospects logged so far from this visit. */
                        _n( '%d prospect logged', '%d prospects logged', $count, 'talenttrack' ),
                        $count
                    )
                ) . '">' . esc_html( (string) $count ) . '</span>'
                : '';

            $items .= '<li class="tt-pd-scouting-item">'
                . '<a class="tt-pd-scouting-link" href="' . esc_url( $detail_url ) . '">'
                . '<span class="tt-pd-scouting-date">' . esc_html( $date_label ) . '</span>'
                . '<span class="tt-pd-scouting-loc">' . esc_html( $location ) . '</span>'
                . ( $event !== '' ? '<span class="tt-pd-scouting-event">' . esc_html( $event ) . '</span>' : '' )
                . $count_html
                . '</a>'
                . '</li>';
        }

        $body = $title . '<ul class="tt-pd-scouting-list">' . $items . '</ul>';
        return $this->wrap( $slot, $body );
    }
}
