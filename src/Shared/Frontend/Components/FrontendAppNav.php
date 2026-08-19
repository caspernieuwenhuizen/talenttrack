<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Tiles\TileRegistry;
use TT\Shared\Icons\IconRenderer;
use TT\Infrastructure\Query\QueryHelpers;

/**
 * FrontendAppNav (#2456) — the app shell's single primary navigation.
 *
 * CLAUDE.md §5b: the shell renders ONE primary navigation, once, from
 * `TileRegistry`. Sidebar, collapsed icon rail and off-canvas drawer are
 * presentations of that one affordance, not separate affordances — same
 * markup, same data, switched by CSS at the breakpoints. A view never
 * emits module-level navigation.
 *
 * The data is `TileRegistry::tilesForUserGrouped()`, which already applies
 * the capability check, the per-persona label map (including the
 * `__hidden__` marker), module/feature gating and the `groupOrder()`
 * sequence. There is no second nav registry to keep in sync — that was the
 * point of reading the tile registry rather than declaring a menu.
 *
 * `groups()` is deliberately public and separate from `render()`: #2459's
 * mobile bottom bar is a second presentation of the same resolved list and
 * consumes it unchanged.
 */
final class FrontendAppNav {

    /**
     * Resolved navigation groups for a user.
     *
     * Shape: `list<array{label: string, tiles: list<array>}>`, work tiles
     * first in `groupOrder()` sequence, then setup tiles under their own
     * groups. Empty when the user can see nothing, which the renderer
     * treats as "emit no nav" rather than "emit an empty rail".
     *
     * @return list<array{label: string, tiles: list<array<string,mixed>>}>
     */
    public static function groups( int $user_id ): array {
        $grouped = TileRegistry::tilesForUserGrouped( $user_id );

        $out = [];
        foreach ( $grouped as $group ) {
            $tiles = [];
            foreach ( $group['tiles'] ?? [] as $tile ) {
                $tile['url'] = self::urlFor( $tile );
                if ( empty( $tile['label'] ) || $tile['url'] === '' ) continue;
                $tiles[] = $tile;
            }
            if ( $tiles === [] ) continue;
            $out[] = [
                'label' => (string) ( $group['label'] ?? '' ),
                'tiles' => $tiles,
            ];
        }
        return $out;
    }

    /**
     * The URL a tile routes to.
     *
     * `TileRegistry` materialises `url` only for the handful of tiles that
     * register a `url_callback`; every other tile carries `view_slug` and
     * the consumer resolves it. Filtering on a bare `url` therefore dropped
     * 56 of 59 destinations from the sidebar (#2505) — the registry was not
     * incomplete, the nav was asking for a field it resolves lazily.
     *
     * The base is the current page minus the drill-down params, matching
     * `FrontendTileGrid`: both surfaces read the same registry, so a tile
     * must lead to the same place whether it was clicked in the hub or in
     * the sidebar.
     */
    private static function urlFor( array $tile ): string {
        $url = (string) ( $tile['url'] ?? '' );
        if ( $url !== '' ) return $url;

        $slug = self::slugFor( $tile );
        if ( $slug === '' ) return '';

        $current = '';
        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $current = esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) );
        }
        $base = remove_query_arg(
            [ 'tt_view', 'player_id', 'eval_id', 'activity_id', 'goal_id', 'team_id', 'tab' ],
            $current ?: home_url( '/' )
        );
        return (string) add_query_arg( 'tt_view', $slug, $base );
    }

    /**
     * Academy identity at the head of the rail (#2530).
     *
     * The name already appears in the top bar, but the rail is where a user
     * spends the session, and on a multi-academy install "which academy am I
     * in" has to be answerable without looking away from the navigation.
     *
     * Same source as the header — `logo_url` when the operator set one,
     * otherwise the gold initials mark — so the two can never disagree.
     * In the 60px rail the wordmark is clip-path-hidden rather than removed,
     * matching how nav labels behave there: the crest still shows, and a
     * screen reader still reads the academy name.
     */
    private static function renderBrand(): void {
        $name = (string) QueryHelpers::get_config( 'academy_name', 'TalentTrack' );
        $logo = (string) QueryHelpers::get_config( 'logo_url', '' );

        echo '<div class="tt-shell-nav__brand">';
        if ( $logo !== '' ) {
            echo '<img class="tt-shell-nav__logo" src="' . esc_url( $logo ) . '" alt="" width="32" height="32" />';
        } else {
            echo '<span class="tt-shell-nav__mark" aria-hidden="true">'
                . esc_html( FrontendAppChrome::initials( $name ) )
                . '</span>';
        }
        echo '<span class="tt-shell-nav__academy">' . esc_html( $name ) . '</span>';
        echo '</div>';
    }

    /**
     * The signed-in user at the foot of the rail (#2530).
     *
     * Deliberately identity only — no menu. The account menu stays the top
     * bar's job; duplicating its actions here would be a second place for the
     * same controls to drift. Collapses to the avatar alone in the rail.
     */
    private static function renderUser( int $user_id ): void {
        $user = get_userdata( $user_id );
        if ( ! $user ) return;

        $name    = (string) ( $user->display_name ?: $user->user_login );
        $persona = (string) ( \TT\Modules\Authorization\PersonaResolver::activePersona( $user_id ) ?? '' );
        $role    = $persona !== '' ? FrontendAppChrome::personaLabel( $persona ) : '';

        echo '<div class="tt-shell-nav__user">';
        echo '<span class="tt-shell-nav__avatar" aria-hidden="true">'
            . esc_html( FrontendAppChrome::initials( $name ) ) . '</span>';
        echo '<span class="tt-shell-nav__whoami">';
        echo '<span class="tt-shell-nav__username">' . esc_html( $name ) . '</span>';
        if ( $role !== '' ) {
            echo '<span class="tt-shell-nav__role">' . esc_html( $role ) . '</span>';
        }
        echo '</span>';
        echo '</div>';
    }

    /**
     * The `tt_view` slug a tile routes to, used for the active state.
     * Tiles registered by `CoreSurfaceRegistration` carry `view_slug`;
     * the rest fall back to `slug`, which the registry backfills from
     * `view_slug` anyway.
     */
    private static function slugFor( array $tile ): string {
        $slug = (string) ( $tile['view_slug'] ?? '' );
        return $slug !== '' ? $slug : (string) ( $tile['slug'] ?? '' );
    }

    /**
     * Render the nav. One element, styled as sidebar / rail / drawer by
     * `frontend-app-shell.css` at the breakpoints.
     *
     * `$active_view` is the current `tt_view` slug ('' on the dashboard
     * root). A tile is active when its slug matches exactly — descendant
     * highlighting (a player detail page lighting up Players) lands with
     * the spine in #2457, which is where the record-to-module mapping
     * lives.
     */
    public static function render( int $user_id, string $active_view = '' ): void {
        $groups = self::groups( $user_id );
        if ( $groups === [] ) return;

        echo '<nav class="tt-shell-nav" id="tt-shell-nav" data-tt-shell-nav aria-label="'
            . esc_attr__( 'Main navigation', 'talenttrack' ) . '">';

        self::renderBrand();

        // Collapse toggle — sidebar <-> icon rail at >=1024px. Hidden by
        // CSS below that width, where the drawer is the presentation and
        // the header hamburger is the control.
        echo '<button type="button" class="tt-shell-nav__collapse" data-tt-shell-collapse '
            . 'aria-controls="tt-shell-nav" aria-expanded="true" '
            . 'title="' . esc_attr__( 'Collapse navigation', 'talenttrack' ) . '" '
            . 'aria-label="' . esc_attr__( 'Collapse navigation', 'talenttrack' ) . '">'
            . IconRenderer::render( 'chevron-left', [ 'width' => 16, 'height' => 16 ] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — trusted SVG.
            . '</button>';

        // #2504 — which group starts open. Normally the one holding the
        // current view. On the dashboard root nothing is active, and leaving
        // every group shut would present a wall of headings with no
        // destinations, so the first group opens instead.
        $active_group = -1;
        foreach ( $groups as $i => $group ) {
            if ( self::groupHasActive( $group, $active_view ) ) {
                $active_group = $i;
                break;
            }
        }
        if ( $active_group === -1 ) {
            $active_group = 0;
        }

        echo '<div class="tt-shell-nav__scroll">';
        foreach ( $groups as $i => $group ) {
            // #2504 — groups collapse so a full destination list fits without
            // the sidebar becoming a scrolling column. Native <details>: it
            // works with JS off, is keyboard-operable and announces its own
            // expanded state, so no ARIA of ours to keep in sync.
            //
            // Open the group holding the current view, closed otherwise —
            // that keeps the rail short while never hiding where you are. A
            // group with no label has nothing to collapse behind, so it stays
            // a plain list.
            $has_label = $group['label'] !== '';
            $is_open   = ( $i === $active_group );

            if ( $has_label ) {
                echo '<details class="tt-shell-nav__group-wrap"' . ( $is_open ? ' open' : '' ) . '>';
                echo '<summary class="tt-shell-nav__group">'
                    . '<span class="tt-shell-nav__group-label">' . esc_html( $group['label'] ) . '</span>'
                    . '<span class="tt-shell-nav__group-chev" aria-hidden="true"></span>'
                    . '</summary>';
                // The panel is what the open/close animation measures and
                // clips; the <ul> inside keeps its natural height so the
                // measurement is honest.
                echo '<div class="tt-shell-nav__panel">';
            }
            echo '<ul class="tt-shell-nav__list">';
            foreach ( $group['tiles'] as $tile ) {
                $slug   = self::slugFor( $tile );
                $active = ( $slug !== '' && $slug === $active_view );
                $label  = (string) $tile['label'];

                echo '<li class="tt-shell-nav__item">';
                echo '<a class="tt-shell-nav__link' . ( $active ? ' is-active' : '' ) . '" '
                    . 'href="' . esc_url( (string) $tile['url'] ) . '"'
                    . ( $active ? ' aria-current="page"' : '' ) . '>';

                echo '<span class="tt-shell-nav__icon" aria-hidden="true">';
                $icon = (string) ( $tile['icon'] ?? '' );
                if ( $icon !== '' ) {
                    echo IconRenderer::render( $icon, [ 'width' => 18, 'height' => 18 ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — trusted SVG.
                }
                echo '</span>';

                // The label is hidden by CSS in the collapsed rail, so the
                // link keeps its accessible name from the text itself and
                // the title attribute supplies the rail tooltip.
                echo '<span class="tt-shell-nav__label">' . esc_html( $label ) . '</span>';
                echo '</a>';
                echo '</li>';
            }
            echo '</ul>';
            if ( $has_label ) {
                echo '</div>';
                echo '</details>';
            }
        }
        echo '</div>';
        self::renderUser( $user_id );
        echo '</nav>';
    }

    /**
     * True when the active view sits inside this group — the one group that
     * opens on load, so the rail shows where you are without expanding
     * everything (#2504).
     *
     * @param array{label:string,tiles:list<array<string,mixed>>} $group
     */
    private static function groupHasActive( array $group, string $active_view ): bool {
        if ( $active_view === '' ) return false;
        foreach ( $group['tiles'] as $tile ) {
            if ( self::slugFor( $tile ) === $active_view ) return true;
        }
        return false;
    }

    /**
     * The drawer trigger, rendered into the existing header actions row.
     * Visible below 1024px only; above it the nav is always on screen and
     * the collapse toggle takes over.
     */
    public static function renderDrawerToggle(): void {
        echo '<button type="button" class="tt-shell-drawer-toggle" data-tt-shell-drawer-open '
            . 'aria-controls="tt-shell-nav" aria-expanded="false" '
            . 'aria-label="' . esc_attr__( 'Open navigation', 'talenttrack' ) . '">'
            . IconRenderer::render( 'menu', [ 'width' => 20, 'height' => 20 ] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — trusted SVG.
            . '</button>';
    }

    /** Scrim behind the open drawer. Click or Escape closes. */
    public static function renderDrawerScrim(): void {
        echo '<div class="tt-shell-scrim" data-tt-shell-drawer-close hidden></div>';
    }
}
