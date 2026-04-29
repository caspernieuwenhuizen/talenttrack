<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Modules\Reports\Frontend\FrontendScoutMyPlayersView;

/**
 * AssignedPlayersGridWidget — Scout landing's primary surface.
 *
 * Resolves the scout's assigned-player ids via the existing
 * FrontendScoutMyPlayersView::assignedPlayerIds() helper (single source
 * of truth for the HoD-managed user-meta key) and renders a grid of
 * cards. Each card links to the inline scout report.
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
        $eyebrow = __( 'Scout · assigned by HoD', 'talenttrack' );
        $title   = __( 'My players', 'talenttrack' );
        $cta_url = $ctx->viewUrl( 'scout-history' );

        $ids = self::assignedIds( $ctx->user_id );
        $body = '';
        if ( empty( $ids ) ) {
            $body = '<div class="tt-pd-assigned-empty">'
                . esc_html__( 'You have no assigned players yet. Ask your Head of Development to share players with you.', 'talenttrack' )
                . '</div>';
        } else {
            $cards = '';
            foreach ( $ids as $pid ) {
                $player = QueryHelpers::get_player( $pid );
                if ( ! $player ) continue;
                $name = QueryHelpers::player_display_name( $player );
                $url  = add_query_arg(
                    [ 'tt_view' => 'scout-my-players', 'player_id' => $pid ],
                    $ctx->base_url
                );
                $photo = (string) ( $player->photo_url ?? '' );
                $photo_html = $photo !== ''
                    ? '<img src="' . esc_url( $photo ) . '" alt="" loading="lazy" width="40" height="40" />'
                    : '<span class="tt-pd-assigned-initials" aria-hidden="true">' . esc_html( self::initials( $name ) ) . '</span>';
                $cards .= '<a class="tt-pd-assigned-card" href="' . esc_url( $url ) . '">'
                    . $photo_html
                    . '<span class="tt-pd-assigned-name">' . esc_html( $name ) . '</span>'
                    . '</a>';
            }
            $body = '<div class="tt-pd-assigned-grid">' . $cards . '</div>';
        }

        $inner = '<div class="tt-pd-hero-eyebrow">' . esc_html( $eyebrow ) . '</div>'
            . '<div class="tt-pd-hero-title">' . esc_html( $title ) . '</div>'
            . $body
            . '<div class="tt-pd-hero-cta-row">'
            . '<a class="tt-pd-cta tt-pd-cta-primary" href="' . esc_url( $cta_url ) . '">' . esc_html__( 'My reports', 'talenttrack' ) . '</a>'
            . '</div>';
        return $this->wrap( $slot, $inner, 'hero hero-assigned-players' );
    }

    /**
     * @return int[]
     */
    private static function assignedIds( int $user_id ): array {
        if ( class_exists( FrontendScoutMyPlayersView::class ) ) {
            return FrontendScoutMyPlayersView::assignedPlayerIds( $user_id );
        }
        $raw = get_user_meta( $user_id, 'tt_scout_player_ids', true );
        if ( ! is_string( $raw ) || $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) return [];
        $ids = array_map( 'intval', $decoded );
        return array_values( array_unique( array_filter( $ids, static fn( $i ) => $i > 0 ) ) );
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
