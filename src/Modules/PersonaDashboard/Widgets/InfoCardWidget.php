<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;

/**
 * InfoCardWidget — persona-specific read-only summary block.
 *
 * data_source identifies the preset:
 *   "coach_nudge"        — most recent comment a coach left for the
 *                          viewing player (Player template).
 *   "pending_pdp_ack"    — PDP conversation awaiting parent ack.
 *   "next_activity"      — date/time/location of upcoming session.
 *   "license_status"     — Pro / count vs cap (Admin template).
 *
 * Sprint 1 ships scaffolding for the four presets; the data wiring is
 * sprint 3 polish. Each preset gracefully no-ops if the backing data
 * isn't available yet.
 */
class InfoCardWidget extends AbstractWidget {

    public function id(): string { return 'info_card'; }

    public function label(): string { return __( 'Info card', 'talenttrack' ); }

    public function defaultSize(): string { return Size::M; }

    /** @return list<string> */
    public function allowedSizes(): array { return [ Size::S, Size::M, Size::L ]; }

    public function defaultMobilePriority(): int { return 30; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $preset = $slot->data_source;
        if ( $preset === '' ) return '';

        switch ( $preset ) {
            case 'coach_nudge':
                return $this->renderShell( $slot, __( 'A note from your coach', 'talenttrack' ), __( 'No new notes right now.', 'talenttrack' ) );
            case 'pending_pdp_ack':
                return $this->renderShell( $slot, __( 'PDP awaiting your acknowledgement', 'talenttrack' ), __( 'No conversations are waiting on you.', 'talenttrack' ) );
            case 'next_activity':
                return $this->renderShell( $slot, __( 'Up next', 'talenttrack' ), __( 'Nothing scheduled.', 'talenttrack' ) );
            case 'license_status':
                return $this->renderShell( $slot, __( 'License & modules', 'talenttrack' ), __( 'Pro · 12 modules active.', 'talenttrack' ) );
        }
        return '';
    }

    private function renderShell( WidgetSlot $slot, string $title, string $body ): string {
        $title = $slot->persona_label !== '' ? $slot->persona_label : $title;
        $inner = '<div class="tt-pd-info-title">' . esc_html( $title ) . '</div>'
            . '<div class="tt-pd-info-body">' . esc_html( $body ) . '</div>';
        return $this->wrap( $slot, $inner, 'info' );
    }
}
