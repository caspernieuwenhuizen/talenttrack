<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;

/**
 * AssignedPlayersGridWidget — Scout landing's primary surface.
 *
 * Renders the players the HoD has explicitly assigned to the scout. The
 * Scout template uses an empty tile_subset and lets this hero own the
 * full landing. Sprint 3 wires the actual ScoutAssignmentRepository
 * read; sprint 1 ships the chrome with an empty-state.
 */
class AssignedPlayersGridWidget extends AbstractWidget {

    public function id(): string { return 'assigned_players_grid'; }

    public function label(): string { return __( 'Assigned players grid', 'talenttrack' ); }

    public function defaultSize(): string { return Size::XL; }

    /** @return list<string> */
    public function allowedSizes(): array { return [ Size::XL ]; }

    public function defaultMobilePriority(): int { return 1; }

    public function capRequired(): string { return 'tt_view_scout_assignments'; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $eyebrow   = __( 'Scout · assigned by HoD', 'talenttrack' );
        $title     = __( 'My players', 'talenttrack' );
        $empty_msg = __( 'You have no assigned players yet. Ask your Head of Development to share players with you.', 'talenttrack' );
        $cta_url   = $ctx->viewUrl( 'scout-history' );

        $inner = '<div class="tt-pd-hero-eyebrow">' . esc_html( $eyebrow ) . '</div>'
            . '<div class="tt-pd-hero-title">' . esc_html( $title ) . '</div>'
            . '<div class="tt-pd-assigned-empty">' . esc_html( $empty_msg ) . '</div>'
            . '<div class="tt-pd-hero-cta-row">'
            . '<a class="tt-pd-cta tt-pd-cta-primary" href="' . esc_url( $cta_url ) . '">' . esc_html__( 'My reports', 'talenttrack' ) . '</a>'
            . '</div>';
        return $this->wrap( $slot, $inner, 'hero hero-assigned-players' );
    }
}
