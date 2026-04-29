<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Stats\PlayerStatsService;
use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;

/**
 * RateCardHeroWidget — Player landing hero.
 *
 * Pulls the player's rolling rating via PlayerStatsService and scales it
 * to a FIFA-style 0–99 number (rolling × 20). Falls back to a neutral
 * card when the player has no evaluations yet.
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

        $first    = (string) ( $player->first_name ?? '' );
        $last     = (string) ( $player->last_name ?? '' );
        $name     = trim( $first . ' ' . $last );
        $position = self::primaryPosition( $player );
        $photo    = (string) ( $player->photo_url ?? '' );

        // Rolling rating from the existing stats service. 5-point scale → 99-scale.
        $headline = self::headline( (int) $player->id );
        $rolling  = $headline['rolling'];
        $latest   = $headline['latest'];
        $overall  = $rolling !== null ? (int) round( ( (float) $rolling / 5.0 ) * 99 ) : 0;
        $delta    = self::trendDelta( $rolling, $latest );

        $greeting = sprintf(
            /* translators: %s is the player's first name */
            __( 'Hey %s', 'talenttrack' ),
            $first !== '' ? $first : $name
        );
        $detail = $headline['eval_count'] > 0
            ? sprintf(
                /* translators: %d is the count of evaluations behind the rolling rating */
                __( 'Rolling rating across your last %d evaluations.', 'talenttrack' ),
                (int) $headline['rolling_count']
            )
            : __( 'Your rate card fills in once your coach saves your first evaluation.', 'talenttrack' );

        $photo_html = $photo !== ''
            ? '<img class="tt-pd-rate-photo" src="' . esc_url( $photo ) . '" alt="" loading="lazy" width="64" height="64" />'
            : '<div class="tt-pd-rate-photo tt-pd-rate-photo-empty" aria-hidden="true">' . esc_html( self::initials( $name ) ) . '</div>';

        $inner = '<div class="tt-pd-hero-row">'
            . '<div class="tt-pd-hero-left">'
            . '<div class="tt-pd-hero-eyebrow">' . esc_html( $position ) . '</div>'
            . '<div class="tt-pd-hero-greeting">' . esc_html( $greeting ) . '</div>'
            . '<div class="tt-pd-hero-detail">' . esc_html( $detail ) . '</div>'
            . '</div>'
            . '<div class="tt-pd-hero-right">'
            . '<div class="tt-pd-rate-card">'
            . $photo_html
            . '<div class="tt-pd-rate-overall">' . esc_html( $overall > 0 ? (string) $overall : '—' ) . '</div>'
            . '<div class="tt-pd-rate-pos">' . esc_html( $position !== '' ? $position : '—' ) . '</div>'
            . '<div class="tt-pd-rate-name">' . esc_html( $name ) . '</div>'
            . ( $delta !== '' ? '<div class="tt-pd-rate-delta">' . esc_html( $delta ) . '</div>' : '' )
            . '</div>'
            . '</div>'
            . '</div>';
        return $this->wrap( $slot, $inner, 'hero hero-rate-card' );
    }

    /**
     * @return array{latest:?float, rolling:?float, eval_count:int, rolling_count:int}
     */
    private static function headline( int $player_id ): array {
        if ( ! class_exists( PlayerStatsService::class ) ) {
            return [ 'latest' => null, 'rolling' => null, 'eval_count' => 0, 'rolling_count' => 0 ];
        }
        try {
            $svc = new PlayerStatsService();
            $h   = $svc->getHeadlineNumbers( $player_id, [], 5 );
            return [
                'latest'        => $h['latest']        ?? null,
                'rolling'       => $h['rolling']       ?? null,
                'eval_count'    => (int) ( $h['eval_count']    ?? 0 ),
                'rolling_count' => (int) ( $h['rolling_count'] ?? 0 ),
            ];
        } catch ( \Throwable $e ) {
            return [ 'latest' => null, 'rolling' => null, 'eval_count' => 0, 'rolling_count' => 0 ];
        }
    }

    private static function trendDelta( ?float $rolling, ?float $latest ): string {
        if ( $rolling === null || $latest === null ) return '';
        $diff = round( $latest - $rolling, 1 );
        if ( $diff > 0 )  return '▲ +' . number_format_i18n( $diff, 1 );
        if ( $diff < 0 )  return '▼ ' . number_format_i18n( $diff, 1 );
        return '— ' . number_format_i18n( 0, 1 );
    }

    private static function primaryPosition( object $player ): string {
        $raw = (string) ( $player->preferred_positions ?? $player->position ?? '' );
        if ( $raw === '' ) return '';
        // preferred_positions can be a comma-list; show the first.
        $first = explode( ',', $raw );
        return trim( (string) $first[0] );
    }

    private static function initials( string $name ): string {
        $parts = preg_split( '/\s+/', trim( $name ) ) ?: [];
        $out = '';
        foreach ( $parts as $p ) {
            if ( $p === '' ) continue;
            $out .= mb_substr( $p, 0, 1 );
            if ( mb_strlen( $out ) >= 2 ) break;
        }
        return mb_strtoupper( $out !== '' ? $out : '?' );
    }
}
