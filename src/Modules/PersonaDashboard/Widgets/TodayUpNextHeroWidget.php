<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;

/**
 * TodayUpNextHeroWidget — Coach landing hero.
 *
 * Today's or next activity, with attendance + evaluation CTAs sized for
 * iPad pitch-side use. Sprint 3 wires the actual upcoming-activity
 * query; sprint 1 ships the chrome with an empty fallback.
 */
class TodayUpNextHeroWidget extends AbstractWidget {

    public function id(): string { return 'today_up_next_hero'; }

    public function label(): string { return __( 'Today / Up next hero', 'talenttrack' ); }

    public function defaultSize(): string { return Size::XL; }

    /** @return list<string> */
    public function allowedSizes(): array { return [ Size::XL ]; }

    public function defaultMobilePriority(): int { return 1; }

    public function personaContext(): string { return PersonaContext::COACH; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $eyebrow = __( 'Up next', 'talenttrack' );
        $title   = __( 'No upcoming activity', 'talenttrack' );
        $detail  = __( 'Schedule a training or game to populate this card.', 'talenttrack' );

        $attendance_url = $ctx->viewUrl( 'activities' );
        $eval_url       = $ctx->viewUrl( 'evaluations' );

        $inner = '<div class="tt-pd-hero-eyebrow">' . esc_html( $eyebrow ) . '</div>'
            . '<div class="tt-pd-hero-title">' . esc_html( $title ) . '</div>'
            . '<div class="tt-pd-hero-detail">' . esc_html( $detail ) . '</div>'
            . '<div class="tt-pd-hero-cta-row">'
            . '<a class="tt-pd-cta tt-pd-cta-primary" href="' . esc_url( $attendance_url ) . '">' . esc_html__( 'Attendance', 'talenttrack' ) . '</a>'
            . '<a class="tt-pd-cta tt-pd-cta-ghost" href="' . esc_url( $eval_url ) . '">' . esc_html__( 'Evaluation', 'talenttrack' ) . '</a>'
            . '</div>';
        return $this->wrap( $slot, $inner, 'hero hero-today' );
    }
}
