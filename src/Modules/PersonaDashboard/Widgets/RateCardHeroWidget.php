<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;

/**
 * RateCardHeroWidget — Player landing hero.
 *
 * Renders greeting + position + age-group + the FIFA-style rate card.
 * The rate card itself reuses the player profile's existing rate-card
 * partial when available; sprint 1 ships a simplified inline version.
 */
class RateCardHeroWidget extends AbstractWidget {

    public function id(): string { return 'rate_card_hero'; }

    public function label(): string { return __( 'Rate card hero', 'talenttrack' ); }

    public function defaultSize(): string { return Size::XL; }

    /** @return list<string> */
    public function allowedSizes(): array { return [ Size::XL ]; }

    public function defaultMobilePriority(): int { return 1; }

    public function personaContext(): string { return PersonaContext::PLAYER_PARENT; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $player = QueryHelpers::get_player_for_user( $ctx->user_id );
        if ( ! $player ) return '';

        $name     = (string) ( $player->display_name ?? $player->first_name ?? '' );
        $position = (string) ( $player->position ?? '' );
        $rating   = isset( $player->overall_rating ) ? (int) $player->overall_rating : 0;

        $greeting = sprintf(
            /* translators: %s is the player's first name */
            __( 'Hey %s', 'talenttrack' ),
            $name
        );

        $inner = '<div class="tt-pd-hero-row">'
            . '<div class="tt-pd-hero-left">'
            . '<div class="tt-pd-hero-eyebrow">' . esc_html( $position ) . '</div>'
            . '<div class="tt-pd-hero-greeting">' . esc_html( $greeting ) . '</div>'
            . '</div>'
            . '<div class="tt-pd-hero-right">'
            . '<div class="tt-pd-rate-card">'
            . '<div class="tt-pd-rate-overall">' . esc_html( $rating > 0 ? (string) $rating : '—' ) . '</div>'
            . '<div class="tt-pd-rate-pos">' . esc_html( $position ) . '</div>'
            . '<div class="tt-pd-rate-name">' . esc_html( $name ) . '</div>'
            . '</div>'
            . '</div>'
            . '</div>';
        return $this->wrap( $slot, $inner, 'hero hero-rate-card' );
    }
}
