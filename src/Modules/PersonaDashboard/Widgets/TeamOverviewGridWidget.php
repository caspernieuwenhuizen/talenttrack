<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Modules\PersonaDashboard\Repositories\TeamOverviewRepository;

/**
 * TeamOverviewGridWidget (#0073) — HoD landing's team-by-team snapshot.
 *
 * Renders a responsive grid of expandable team cards. Each card carries
 * the team's headline numbers (avg rating + attendance %) over a window
 * configurable via the slot's `data_source` config string:
 *
 *   "days=30,limit=20,sort=rating_desc"
 *
 * Sort modes: alphabetical (default), rating_desc, attendance_desc,
 * concern_first. The expand/collapse state per card is persisted in
 * localStorage keyed by `tt_pd_team_card_{team_id}` — UI preference,
 * not data preference, so cross-device sync isn't worth the cost.
 *
 * Capability gate is implicit: `TeamOverviewRepository` returns rows
 * scoped to the current club, and the matrix grants `team R global`
 * to HoD + Academy Admin. Other personas with this widget configured
 * see whatever their team-R scope allows (typically nothing).
 */
class TeamOverviewGridWidget extends AbstractWidget {

    public function id(): string { return 'team_overview_grid'; }

    public function label(): string { return __( 'Team overview grid', 'talenttrack' ); }

    public function defaultSize(): string { return Size::XL; }

    /** @return list<string> */
    public function allowedSizes(): array { return [ Size::L, Size::XL ]; }

    public function defaultMobilePriority(): int { return 18; }

    public function personaContext(): string { return PersonaContext::ACADEMY; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $config    = $this->parseConfig( $slot->data_source );
        $repo      = new TeamOverviewRepository();
        $summaries = $repo->summariesFor( $ctx->user_id, $config['days'], $config['sort'], $config['limit'] );

        $title = $slot->persona_label !== '' ? $slot->persona_label : __( 'Team overview', 'talenttrack' );

        $head = '<div class="tt-pd-panel-head">'
            . '<span class="tt-pd-panel-title">' . esc_html( $title ) . '</span>'
            . '<span class="tt-pd-panel-meta">' . esc_html( sprintf(
                /* translators: %d: number of days in the window. */
                __( 'Last %d days', 'talenttrack' ),
                $config['days']
            ) ) . '</span>'
            . '</div>';

        if ( $summaries === [] ) {
            $body = '<div class="tt-pd-team-grid-empty">'
                . esc_html__( 'No teams with recent activity.', 'talenttrack' )
                . '</div>';
            return $this->wrap( $slot, $head . $body );
        }

        $cards = '';
        foreach ( $summaries as $s ) {
            $cards .= $this->renderCard( $s, $ctx, $config['days'] );
        }
        $inner = $head . '<div class="tt-pd-team-grid">' . $cards . '</div>';
        return $this->wrap( $slot, $inner );
    }

    /** @return array{days:int,limit:int,sort:string} */
    private function parseConfig( string $config_string ): array {
        $defaults = [ 'days' => 30, 'limit' => 20, 'sort' => 'alphabetical' ];
        if ( $config_string === '' ) return $defaults;
        $out = $defaults;
        foreach ( explode( ',', $config_string ) as $pair ) {
            $pair = trim( $pair );
            if ( $pair === '' || strpos( $pair, '=' ) === false ) continue;
            [ $k, $v ] = array_map( 'trim', explode( '=', $pair, 2 ) );
            switch ( $k ) {
                case 'days':
                    $days = max( 1, min( 365, (int) $v ) );
                    $out['days'] = $days;
                    break;
                case 'limit':
                    $out['limit'] = max( 1, min( 100, (int) $v ) );
                    break;
                case 'sort':
                    if ( in_array( $v, [ 'alphabetical', 'rating_desc', 'attendance_desc', 'concern_first' ], true ) ) {
                        $out['sort'] = $v;
                    }
                    break;
            }
        }
        return $out;
    }

    private function renderCard( \TT\Modules\PersonaDashboard\Repositories\TeamSummary $s, RenderContext $ctx, int $days ): string {
        $rating  = $s->avg_rating !== null ? number_format_i18n( $s->avg_rating, 1 ) : '—';
        $att     = $s->attendance_pct !== null ? number_format_i18n( $s->attendance_pct, 0 ) . '%' : '—';
        $coach   = $s->head_coach_name !== null ? $s->head_coach_name : '—';
        $age     = $s->age_group !== '' ? ' · ' . $s->age_group : '';
        $players = sprintf(
            /* translators: %d: number of players. */
            _n( '%d player', '%d players', $s->player_count, 'talenttrack' ),
            $s->player_count
        );

        $players_url = esc_url( add_query_arg( [
            'tt_view'   => 'players',
            'team_id'   => $s->team_id,
        ], $ctx->base_url ) );

        return '<article class="tt-pd-team-card" data-tt-team-id="' . (int) $s->team_id . '" data-tt-days="' . (int) $days . '">'
            . '<header class="tt-pd-team-card-head">'
            . '<button type="button" class="tt-pd-team-card-toggle" aria-expanded="false" data-tt-team-toggle="' . (int) $s->team_id . '">'
            . '<span class="tt-pd-team-card-name">' . esc_html( $s->name ) . esc_html( $age ) . '</span>'
            . '<span class="tt-pd-team-card-coach">' . esc_html( sprintf(
                /* translators: %s: coach name or em-dash. */
                __( 'Coach: %s', 'talenttrack' ),
                $coach
            ) ) . '</span>'
            . '</button>'
            . '</header>'
            . '<div class="tt-pd-team-card-stats">'
            . '<div class="tt-pd-team-stat"><span class="tt-pd-team-stat-value">' . esc_html( $rating ) . '</span>'
            . '<span class="tt-pd-team-stat-label">' . esc_html__( 'Rating', 'talenttrack' ) . '</span></div>'
            . '<div class="tt-pd-team-stat"><span class="tt-pd-team-stat-value">' . esc_html( $att ) . '</span>'
            . '<span class="tt-pd-team-stat-label">' . esc_html__( 'Attendance', 'talenttrack' ) . '</span></div>'
            . '</div>'
            . '<div class="tt-pd-team-card-meta">'
            . '<a href="' . $players_url . '" class="tt-pd-team-card-roster">' . esc_html( $players ) . '</a>'
            . '</div>'
            . '<div class="tt-pd-team-card-body" hidden data-tt-team-body="' . (int) $s->team_id . '">'
            . '<div class="tt-pd-team-card-loading">' . esc_html__( 'Loading players…', 'talenttrack' ) . '</div>'
            . '</div>'
            . '</article>';
    }
}
