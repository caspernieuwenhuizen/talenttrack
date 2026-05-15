<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * MiniPlayerListWidget — horizontal rail or short list of players.
 *
 * data_source identifies the preset:
 *   "podium_top3"          — Player template: top 3 in the user's team.
 *   "recent_evaluations"   — Coach template: last 5 players I evaluated.
 *   "top_movers"           — Observer template: rolling-rating climbers.
 *
 * v3.110.108 — `recent_evaluations` gained an actual fetch. Sprint 1
 * shipped scaffolding + empty-state and the wiring never landed; the
 * widget rendered "No players to show yet" forever on the coach
 * dashboard even when evaluations existed. Coach-scoping: evals for
 * any player on a team the coach owns (matches `tt_teams.head_coach_id`
 * and `tt_team_people` membership — the same shape
 * `QueryHelpers::get_teams_for_coach()` resolves).
 */
class MiniPlayerListWidget extends AbstractWidget {

    public function id(): string { return 'mini_player_list'; }

    public function label(): string { return __( 'Mini player list', 'talenttrack' ); }

    /** @return array<string,string> */
    public function dataSourceCatalogue(): array {
        return [
            'podium_top3'        => __( 'Podium · my team', 'talenttrack' ),
            'recent_evaluations' => __( 'Recent evaluations', 'talenttrack' ),
            'top_movers'         => __( 'Top movers · this month', 'talenttrack' ),
        ];
    }

    public function defaultSize(): string { return Size::M; }

    /** @return list<string> */
    public function allowedSizes(): array { return [ Size::M, Size::L ]; }

    public function defaultMobilePriority(): int { return 45; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $preset_titles = [
            'podium_top3'        => __( 'Podium · my team', 'talenttrack' ),
            'recent_evaluations' => __( 'Recent evaluations', 'talenttrack' ),
            'top_movers'         => __( 'Top movers · this month', 'talenttrack' ),
        ];
        if ( ! isset( $preset_titles[ $slot->data_source ] ) ) return '';

        $title = $slot->persona_label !== '' ? $slot->persona_label : (string) $preset_titles[ $slot->data_source ];
        $head  = '<div class="tt-pd-panel-head"><span class="tt-pd-panel-title">' . esc_html( $title ) . '</span>';
        $head_close = '</div>';

        if ( $slot->data_source === 'recent_evaluations' ) {
            $rows = $this->fetchRecentEvaluations( $ctx->user_id, $ctx->club_id );
            if ( empty( $rows ) ) {
                $body = '<div class="tt-pd-mini-list-empty">' . esc_html__( 'No evaluations yet.', 'talenttrack' ) . '</div>';
            } else {
                $see_all_url = $ctx->viewUrl( 'evaluations' );
                $head .= '<a class="tt-pd-panel-more" href="' . esc_url( $see_all_url ) . '">'
                       . esc_html__( 'Show all', 'talenttrack' ) . '</a>';
                $items = '';
                foreach ( $rows as $row ) {
                    $eval_url   = BackLink::appendTo( add_query_arg(
                        [ 'tt_view' => 'evaluations', 'id' => (int) $row['eval_id'] ],
                        RecordLink::dashboardUrl()
                    ) );
                    $avg_text   = $row['avg'] === null
                        ? '—'
                        : number_format_i18n( (float) $row['avg'], 1 );
                    $items .= '<li class="tt-pd-mini-list-row">'
                        . '<a href="' . esc_url( $eval_url ) . '">'
                        . '<span class="tt-pd-mini-list-name">' . esc_html( (string) $row['player_name'] ) . '</span>'
                        . '<span class="tt-pd-mini-list-meta">'
                        . esc_html( (string) $row['eval_date'] )
                        . ' · ' . esc_html( $avg_text )
                        . '</span>'
                        . '</a>'
                        . '</li>';
                }
                $body = '<ul class="tt-pd-mini-list">' . $items . '</ul>';
            }
        } else {
            // podium_top3 / top_movers — scaffolding only, no fetch yet.
            $body = '<div class="tt-pd-mini-list-empty">' . esc_html__( 'No players to show yet.', 'talenttrack' ) . '</div>';
        }

        return $this->wrap( $slot, $head . $head_close . $body, 'mini-list' );
    }

    /**
     * v3.110.108 — five most recent non-archived evaluations for any
     * player on a team the current coach owns. Mirrors the coach-scope
     * shape used by `FrontendEvaluationsView` / `EvaluationsRestController`
     * so the same evals surface here as on the evaluations list.
     *
     * @return list<array{eval_id:int, player_name:string, eval_date:string, avg:?float}>
     */
    private function fetchRecentEvaluations( int $user_id, int $club_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $teams = QueryHelpers::get_teams_for_coach( $user_id );
        if ( empty( $teams ) ) return [];
        $team_ids = array_map( static fn( $t ): int => (int) $t->id, $teams );
        if ( empty( $team_ids ) ) return [];

        $placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
        $sql = "SELECT e.id, e.eval_date,
                       pl.first_name, pl.last_name,
                       (SELECT AVG(r.rating) FROM {$p}tt_eval_ratings r
                         WHERE r.evaluation_id = e.id AND r.club_id = e.club_id) AS avg_rating
                  FROM {$p}tt_evaluations e
                  LEFT JOIN {$p}tt_players pl ON pl.id = e.player_id
                 WHERE e.club_id = %d
                   AND e.archived_at IS NULL
                   AND pl.team_id IN ($placeholders)
                 ORDER BY e.eval_date DESC, e.id DESC
                 LIMIT 5";

        $params = array_merge( [ $club_id > 0 ? $club_id : CurrentClub::id() ], $team_ids );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — placeholders built from int array.
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
        $out  = [];
        foreach ( (array) $rows as $r ) {
            $name = trim( ( $r->first_name ?? '' ) . ' ' . ( $r->last_name ?? '' ) );
            if ( $name === '' ) $name = '#' . (int) $r->id;
            $out[] = [
                'eval_id'     => (int) $r->id,
                'player_name' => $name,
                'eval_date'   => (string) $r->eval_date,
                'avg'         => $r->avg_rating !== null ? round( (float) $r->avg_rating, 1 ) : null,
            ];
        }
        return $out;
    }
}
