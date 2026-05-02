<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Shared\Tiles\TileRegistry;

/**
 * NavigationTileWidget — wraps an existing TileRegistry tile.
 *
 * The slot's data_source is the tile's `view_slug`. This widget fishes
 * the matching tile out of TileRegistry::tilesForUserGrouped() so
 * persona-aware labels and module-disable rules continue to apply.
 *
 * #0074 dropped the decorative `tt-pd-tile-icon` coloured square. Tiles
 * now lean on typographic hierarchy + a hover chevron (`tt-pd-tile-arrow`,
 * opacity 0 by default → 0.5 on hover/focus). The tile's `color` field
 * is preserved in the data structure for back-compat but no longer
 * rendered.
 */
class NavigationTileWidget extends AbstractWidget {

    public function id(): string { return 'navigation_tile'; }

    public function label(): string { return __( 'Navigation tile', 'talenttrack' ); }

    /**
     * #0077 M1 — runtime catalogue from TileRegistry. Pulls every tile
     * registered for the current admin (the editor user) so the persona-
     * dashboard editor can offer a dropdown instead of a free-text slug
     * field. NavigationTileWidget renders nothing for unknown slugs at
     * runtime, so the closed-set list is correct.
     *
     * @return array<string,string>
     */
    public function dataSourceCatalogue(): array {
        if ( ! class_exists( TileRegistry::class ) ) return [];
        $out = [];
        $groups = TileRegistry::tilesForUserGrouped( get_current_user_id() );
        foreach ( $groups as $g ) {
            foreach ( $g['tiles'] as $t ) {
                $slug = (string) ( $t['view_slug'] ?? '' );
                if ( $slug === '' || isset( $out[ $slug ] ) ) continue;
                $out[ $slug ] = (string) ( $t['label'] ?? $slug );
            }
        }
        return $out;
    }

    public function defaultSize(): string { return Size::S; }

    /** @return list<string> */
    public function allowedSizes(): array { return [ Size::S, Size::M ]; }

    public function defaultMobilePriority(): int { return 60; }

    public function personaContext(): string { return PersonaContext::PLAYER_PARENT; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $slug = $slot->data_source;
        if ( $slug === '' ) return '';

        $tile = $this->findTile( $slug, $ctx->user_id );
        if ( $tile === null ) return '';

        $label = $slot->persona_label !== '' ? $slot->persona_label : (string) ( $tile['label'] ?? $slug );
        $desc  = (string) ( $tile['desc'] ?? $tile['description'] ?? '' );
        $url   = $ctx->viewUrl( $slug );

        $inner = '<a class="tt-pd-tile-link" href="' . esc_url( $url ) . '">'
            . '<span class="tt-pd-tile-label">' . esc_html( $label ) . '</span>'
            . ( $desc !== '' ? '<span class="tt-pd-tile-desc">' . esc_html( $desc ) . '</span>' : '' )
            . '<span class="tt-pd-tile-arrow" aria-hidden="true">&rarr;</span>'
            . '</a>';
        return $this->wrap( $slot, $inner );
    }

    /** @return array<string,mixed>|null */
    private function findTile( string $view_slug, int $user_id ): ?array {
        if ( ! class_exists( TileRegistry::class ) ) return null;
        $groups = TileRegistry::tilesForUserGrouped( $user_id );
        foreach ( $groups as $g ) {
            foreach ( $g['tiles'] as $t ) {
                if ( (string) ( $t['view_slug'] ?? '' ) === $view_slug ) return $t;
            }
        }
        return null;
    }
}
