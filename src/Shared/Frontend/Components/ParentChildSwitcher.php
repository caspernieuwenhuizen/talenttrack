<?php
namespace TT\Shared\Frontend\Components;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Players\ParentChildResolver;
use TT\Infrastructure\Query\QueryHelpers;

/**
 * ParentChildSwitcher (#1991 / #1992) — child picker for a parent who is
 * linked to more than one player.
 *
 * Two uses:
 *   - `renderPickerPage()` — a full picker screen the me-view dispatch
 *     shows when a parent opens a `?tt_view=my-*` slug with no explicit
 *     `?player_id` and has >1 linked child. The parent chooses which
 *     child's section to open; the chosen child's `?player_id=N` (plus
 *     the original view slug) is carried on each link so #1991's
 *     resolution + canViewPlayer auth pass.
 *   - `renderInlineSwitcher()` — a compact strip shown on the dashboard
 *     parent rail (#1992) so the parent can change which child the rail
 *     is scoped to.
 *
 * Pure presentation. The "which children, in what order" decision lives
 * in ParentChildResolver (§4); this component only paints.
 */
final class ParentChildSwitcher {

    /**
     * Full child-picker screen. Emits the two nav affordances (breadcrumb
     * chain + auto tt_back pill) like any routable view, then a grid of
     * child cards. Each card links back to the same me-view slug scoped to
     * that child.
     *
     * @param list<object> $children tt_players rows (ParentChildResolver order).
     */
    public static function renderPickerPage( string $view_slug, array $children, int $active_child_id = 0 ): void {
        FrontendBreadcrumbs::fromDashboard( __( 'Choose a child', 'talenttrack' ) );

        echo '<div class="tt-child-switcher tt-child-switcher--page">';
        echo '<p class="tt-child-switcher-lead">'
            . esc_html__( 'You are linked to more than one child. Choose whose record to open.', 'talenttrack' )
            . '</p>';
        echo '<ul class="tt-child-switcher-grid" role="list">';
        foreach ( $children as $child ) {
            $pid = (int) ( $child->id ?? 0 );
            if ( $pid <= 0 ) continue;
            $url = BackLink::appendTo( add_query_arg(
                [ 'tt_view' => $view_slug, 'player_id' => $pid ],
                RecordLink::dashboardUrl()
            ) );
            $is_active = $pid === $active_child_id;
            self::renderCard( $child, $url, $is_active );
        }
        echo '</ul>';
        echo '</div>';
    }

    /**
     * Compact inline switcher (dashboard rail). Renders nothing for a
     * single-child parent — the caller decides whether to call it.
     *
     * @param list<object> $children
     */
    public static function renderInlineSwitcher( array $children, int $active_child_id, string $base_url ): void {
        if ( count( $children ) < 2 ) return;

        echo '<nav class="tt-child-switcher tt-child-switcher--inline" aria-label="' . esc_attr__( 'Switch child', 'talenttrack' ) . '">';
        echo '<span class="tt-child-switcher-label">' . esc_html__( 'Viewing', 'talenttrack' ) . '</span>';
        foreach ( $children as $child ) {
            $pid = (int) ( $child->id ?? 0 );
            if ( $pid <= 0 ) continue;
            $url = add_query_arg( [ 'tt_child' => $pid ], $base_url );
            $cls = $pid === $active_child_id ? 'tt-child-switcher-pill is-active' : 'tt-child-switcher-pill';
            echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $url ) . '"'
                . ( $pid === $active_child_id ? ' aria-current="true"' : '' ) . '>'
                . esc_html( self::childName( $child ) )
                . '</a>';
        }
        echo '</nav>';
    }

    /**
     * @param object $child tt_players row.
     */
    private static function renderCard( object $child, string $url, bool $is_active ): void {
        $name  = self::childName( $child );
        $photo = (string) ( $child->photo_url ?? '' );
        $cls   = $is_active ? 'tt-child-switcher-card is-active' : 'tt-child-switcher-card';

        echo '<li>';
        echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $url ) . '">';
        if ( $photo !== '' ) {
            echo '<img class="tt-child-switcher-photo" src="' . esc_url( $photo ) . '" alt="" loading="lazy" width="48" height="48" />';
        } else {
            echo '<span class="tt-child-switcher-photo tt-child-switcher-photo--blank" aria-hidden="true">'
                . esc_html( self::initials( $child ) ) . '</span>';
        }
        echo '<span class="tt-child-switcher-name">' . esc_html( $name ) . '</span>';
        echo '</a>';
        echo '</li>';
    }

    private static function childName( object $child ): string {
        $name = QueryHelpers::player_display_name( $child );
        $name = trim( $name );
        return $name !== '' ? $name : __( 'Player', 'talenttrack' );
    }

    private static function initials( object $child ): string {
        $first = (string) ( $child->first_name ?? '' );
        $last  = (string) ( $child->last_name ?? '' );
        $a = $first !== '' ? mb_substr( $first, 0, 1 ) : '';
        $b = $last !== ''  ? mb_substr( $last, 0, 1 )  : '';
        $out = strtoupper( $a . $b );
        return $out !== '' ? $out : '?';
    }
}
