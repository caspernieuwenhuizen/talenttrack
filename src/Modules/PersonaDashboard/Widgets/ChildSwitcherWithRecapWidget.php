<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;

/**
 * ChildSwitcherWithRecapWidget — Parent landing hero.
 *
 * Child pill row + "since you last visited" recap counts. The recap
 * diffs tt_audit_events.created_at against tt_user_meta.tt_last_visited_at
 * for the children linked to the parent. Sprint 3 wires the diff; sprint 1
 * scaffolds the layout with a 0-count default.
 */
class ChildSwitcherWithRecapWidget extends AbstractWidget {

    public function id(): string { return 'child_switcher_with_recap'; }

    public function label(): string { return __( 'Child switcher with recap', 'talenttrack' ); }

    public function defaultSize(): string { return Size::XL; }

    /** @return list<string> */
    public function allowedSizes(): array { return [ Size::XL ]; }

    public function defaultMobilePriority(): int { return 1; }

    public function personaContext(): string { return PersonaContext::PLAYER_PARENT; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $children = $this->fetchChildren( $ctx->user_id );
        $pills    = '';
        if ( empty( $children ) ) {
            $pills = '<div class="tt-pd-children-empty">' . esc_html__( 'No children linked to this account yet.', 'talenttrack' ) . '</div>';
        } else {
            foreach ( $children as $i => $child ) {
                $cls = $i === 0 ? 'tt-pd-child-pill is-active' : 'tt-pd-child-pill';
                $pills .= '<button type="button" class="' . $cls . '" data-tt-pd-child="' . esc_attr( (string) ( $child['id'] ?? '' ) ) . '">'
                    . esc_html( (string) ( $child['name'] ?? '' ) )
                    . '</button>';
            }
        }

        $recap_title = __( 'Since you last visited', 'talenttrack' );
        $recap_body  = esc_html__( 'No new updates.', 'talenttrack' );

        $inner = '<div class="tt-pd-hero-eyebrow">' . esc_html__( 'My children', 'talenttrack' ) . '</div>'
            . '<div class="tt-pd-children">' . $pills . '</div>'
            . '<div class="tt-pd-recap">'
            . '<div class="tt-pd-recap-title">' . esc_html( $recap_title ) . '</div>'
            . '<div class="tt-pd-recap-body">' . $recap_body . '</div>'
            . '</div>';
        return $this->wrap( $slot, $inner, 'hero hero-children' );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchChildren( int $user_id ): array {
        // Sprint 3 wires the actual parent → player(s) lookup. Sprint 1
        // returns empty so the empty-state path is the live UX.
        return [];
    }
}
