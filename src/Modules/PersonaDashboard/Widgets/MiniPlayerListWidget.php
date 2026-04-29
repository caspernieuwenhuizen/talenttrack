<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;

/**
 * MiniPlayerListWidget — horizontal rail or short list of players.
 *
 * data_source identifies the preset:
 *   "podium_top3"          — Player template: top 3 in the user's team.
 *   "recent_evaluations"   — Coach template: last 5 players I evaluated.
 *   "top_movers"           — Observer template: rolling-rating climbers.
 *
 * Sprint 1 ships scaffolding + empty-state; sprint 3 wires queries.
 */
class MiniPlayerListWidget extends AbstractWidget {

    public function id(): string { return 'mini_player_list'; }

    public function label(): string { return __( 'Mini player list', 'talenttrack' ); }

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
        $inner = '<div class="tt-pd-panel-head"><span class="tt-pd-panel-title">' . esc_html( $title ) . '</span></div>'
            . '<div class="tt-pd-mini-list-empty">' . esc_html__( 'No players to show yet.', 'talenttrack' ) . '</div>';
        return $this->wrap( $slot, $inner, 'mini-list' );
    }
}
