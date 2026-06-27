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
 * ChildSwitcherWithRecapWidget — Parent landing hero.
 *
 * Resolves the parent's children from the canonical tt_player_parents
 * pivot via ParentChildResolver (#1993 — guardian_email is no longer a
 * live linkage source). For each child, counts evaluations created since
 * tt_user_meta.tt_last_visited_at — the "since you last visited" recap.
 */
class ChildSwitcherWithRecapWidget extends AbstractWidget {

    public function id(): string { return 'child_switcher_with_recap'; }

    public function label(): string { return __( 'Child switcher with recap', 'talenttrack' ); }

    public function description(): string {
        return __( 'Parent hero: pickers between the parent\'s linked players (when more than one) plus a weekly recap card — last activity attended, latest evaluation rating, open PDP conversation. Sourced from tt_players + tt_attendance + tt_evaluations scoped to the parent\'s linked children.', 'talenttrack' );
    }

    /** @return list<string> */
    public function intendedPersonas(): array {
        return [ 'parent' ];
    }

    public function defaultSize(): string { return Size::XL; }

    /** @return list<string> */
    public function allowedSizes(): array { return [ Size::XL ]; }

    public function defaultMobilePriority(): int { return 1; }

    public function personaContext(): string { return PersonaContext::PLAYER_PARENT; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $children = self::fetchChildren( $ctx->user_id );
        $since    = self::lastVisited( $ctx->user_id );
        $recap    = self::recap( $children, $since );

        $pills = '';
        if ( empty( $children ) ) {
            $pills = '<div class="tt-pd-children-empty">' . esc_html__( 'No children linked to this account yet.', 'talenttrack' ) . '</div>';
        } else {
            // #915 — pills were `<button>` elements with no JS handler
            // bound, so multi-child parents couldn't navigate to a
            // non-primary child. Render as `<a href>` so the parent can
            // drill into each child's record. No JS needed. Tap target
            // 48×min via the existing `.tt-pd-child-pill` CSS.
            // #1849 — point at the child's *own* development overview
            // (the `overview` / My-card slug + `player_id`), not the staff
            // detail view, so the parent gets the rich `FrontendMy*` views
            // (the routing resolves the child via canViewPlayer). `tt_back`
            // lets the destination show the contextual back-pill (§5).
            foreach ( $children as $child ) {
                $pid = (int) $child->id;
                if ( $pid <= 0 ) continue;
                $url = \TT\Shared\Frontend\Components\BackLink::appendTo( add_query_arg(
                    [ 'tt_view' => 'overview', 'player_id' => $pid ],
                    \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
                ) );
                $pills .= '<a class="tt-pd-child-pill" data-tt-pd-child="' . esc_attr( (string) $pid ) . '" href="' . esc_url( $url ) . '">'
                    . esc_html( trim( $child->first_name . ' ' . $child->last_name ) )
                    . '</a>';
            }
        }

        $recap_title = __( 'Since you last visited', 'talenttrack' );
        $recap_body  = $recap['total'] > 0
            ? sprintf(
                /* translators: %d is the count of new evaluations across the parent's children */
                _n(
                    '%d new evaluation across your children.',
                    '%d new evaluations across your children.',
                    $recap['total'],
                    'talenttrack'
                ),
                $recap['total']
            )
            : __( 'No new updates.', 'talenttrack' );

        $inner = '<div class="tt-pd-hero-eyebrow">' . esc_html__( 'My children', 'talenttrack' ) . '</div>'
            . '<div class="tt-pd-children">' . $pills . '</div>'
            . '<div class="tt-pd-recap">'
            . '<div class="tt-pd-recap-title">' . esc_html( $recap_title ) . '</div>'
            . '<div class="tt-pd-recap-body">' . esc_html( $recap_body ) . '</div>'
            . '</div>';
        return $this->wrap( $slot, $inner, 'hero hero-children' );
    }

    /**
     * #1993 — resolve the parent's children from the canonical
     * `tt_player_parents` pivot via ParentChildResolver, NOT by matching
     * `tt_players.guardian_email` to the parent's WP email. This is what
     * keeps the switcher in agreement with the me-view authorization
     * (both read the same pivot): a parent linked in the pivot sees their
     * child even when guardian_email is blank or different. The resolver
     * already scopes to the current club and `status = 'active'`.
     *
     * @return list<object>
     */
    private static function fetchChildren( int $user_id ): array {
        return \TT\Infrastructure\Players\ParentChildResolver::children( $user_id );
    }

    private static function lastVisited( int $user_id ): ?string {
        // #1374 — read the rotated visit baseline, not the live bump:
        // PersonaLandingRenderer updates `tt_last_visited_at` BEFORE
        // widgets render, so diffing against it collapsed the window
        // to ~zero. `tt_recap_since_at` holds the previous session's
        // timestamp; fall back to the live key for installs where the
        // baseline hasn't rotated yet.
        $raw = get_user_meta( $user_id, 'tt_recap_since_at', true );
        if ( ! is_string( $raw ) || $raw === '' ) {
            $raw = get_user_meta( $user_id, 'tt_last_visited_at', true );
        }
        if ( ! is_string( $raw ) || $raw === '' ) return null;
        return $raw;
    }

    /**
     * @param list<object> $children
     * @return array{total:int}
     */
    private static function recap( array $children, ?string $since ): array {
        if ( empty( $children ) || $since === null ) return [ 'total' => 0 ];
        global $wpdb;
        $table = $wpdb->prefix . 'tt_evaluations';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [ 'total' => 0 ];
        }
        $ids = array_map( static fn( $c ): int => (int) $c->id, $children );
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // v3.110.182 (#781) — demo-mode scope so the parent recap matches
        // what the player's evaluation list page surfaces.
        $scope = QueryHelpers::apply_demo_scope( 'e', 'evaluation' );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} e
              WHERE e.player_id IN ({$placeholders})
                AND e.created_at > %s
                {$scope}",
            array_merge( $ids, [ $since ] )
        ) );
        return [ 'total' => $count ];
    }
}
