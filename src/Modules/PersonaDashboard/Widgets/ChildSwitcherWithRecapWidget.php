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
 * Resolves the parent's children by matching the parent user's email
 * against tt_players.guardian_email (the canonical link until a richer
 * tt_player_parents table lands). For each child, counts evaluations
 * created since tt_user_meta.tt_last_visited_at — the "since you last
 * visited" recap.
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
        $children = self::fetchChildren( $ctx->user_id, $ctx->club_id );
        $since    = self::lastVisited( $ctx->user_id );
        $recap    = self::recap( $children, $since );

        $pills = '';
        if ( empty( $children ) ) {
            $pills = '<div class="tt-pd-children-empty">' . esc_html__( 'No children linked to this account yet.', 'talenttrack' ) . '</div>';
        } else {
            foreach ( $children as $i => $child ) {
                $cls = $i === 0 ? 'tt-pd-child-pill is-active' : 'tt-pd-child-pill';
                $pills .= '<button type="button" class="' . $cls . '" data-tt-pd-child="' . esc_attr( (string) $child->id ) . '">'
                    . esc_html( trim( $child->first_name . ' ' . $child->last_name ) )
                    . '</button>';
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
     * @return list<object>
     */
    private static function fetchChildren( int $user_id, int $club_id ): array {
        global $wpdb;
        $user = get_user_by( 'id', $user_id );
        if ( ! $user instanceof \WP_User ) return [];
        $email = (string) $user->user_email;
        if ( $email === '' ) return [];

        $table = $wpdb->prefix . 'tt_players';
        $has_club = null !== $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'club_id'",
            $table
        ) );
        $club_clause = $has_club ? ' AND club_id = ' . (int) $club_id : '';

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name, photo_url
               FROM {$table}
              WHERE guardian_email = %s
                AND status = 'active'
                {$club_clause}
              ORDER BY last_name, first_name",
            $email
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    private static function lastVisited( int $user_id ): ?string {
        $raw = get_user_meta( $user_id, 'tt_last_visited_at', true );
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
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
              WHERE player_id IN ({$placeholders})
                AND created_at > %s",
            array_merge( $ids, [ $since ] )
        ) );
        return [ 'total' => $count ];
    }
}
