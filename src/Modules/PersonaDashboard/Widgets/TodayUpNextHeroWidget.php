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
 * TodayUpNextHeroWidget — Coach landing hero.
 *
 * Picks the soonest upcoming activity scoped to the user's coached
 * teams (legacy + modern role-scope paths via QueryHelpers) and renders
 * it with date, team, location, plus the two pitch-side CTAs (attendance
 * + evaluation). Falls back to a "schedule something" prompt when the
 * calendar is empty.
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
        $teams      = QueryHelpers::get_teams_for_coach( $ctx->user_id );
        $team_ids   = array_map( static fn( $t ): int => (int) $t->id, is_array( $teams ) ? $teams : [] );
        $next       = self::nextActivity( $team_ids, $ctx->club_id );

        $attendance_url = $ctx->viewUrl( 'activities' );
        $eval_url       = $ctx->viewUrl( 'evaluations' );

        if ( $next === null ) {
            $eyebrow = __( 'Up next', 'talenttrack' );
            $title   = __( 'No upcoming activity', 'talenttrack' );
            $detail  = __( 'Schedule a training or game to populate this card.', 'talenttrack' );
        } else {
            $eyebrow = self::eyebrowFor( (string) $next->session_date );
            $title   = (string) ( $next->title ?? __( 'Activity', 'talenttrack' ) );
            $detail  = self::buildDetail( $next );
        }

        $inner = '<div class="tt-pd-hero-eyebrow">' . esc_html( $eyebrow ) . '</div>'
            . '<div class="tt-pd-hero-title">' . esc_html( $title ) . '</div>'
            . '<div class="tt-pd-hero-detail">' . esc_html( $detail ) . '</div>'
            . '<div class="tt-pd-hero-cta-row">'
            . '<a class="tt-pd-cta tt-pd-cta-primary" href="' . esc_url( $attendance_url ) . '">' . esc_html__( 'Attendance', 'talenttrack' ) . '</a>'
            . '<a class="tt-pd-cta tt-pd-cta-ghost" href="' . esc_url( $eval_url ) . '">' . esc_html__( 'Evaluation', 'talenttrack' ) . '</a>'
            . '</div>';
        return $this->wrap( $slot, $inner, 'hero hero-today' );
    }

    /**
     * @param list<int> $team_ids
     */
    private static function nextActivity( array $team_ids, int $club_id ): ?object {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_activities';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return null;
        }
        $today       = gmdate( 'Y-m-d' );
        $has_club    = self::hasClubColumn( $table );
        $club_clause = $has_club ? ' AND club_id = ' . (int) $club_id : '';

        if ( ! empty( $team_ids ) ) {
            $team_ids = array_values( array_unique( array_map( 'intval', $team_ids ) ) );
            $placeholders = implode( ',', array_fill( 0, count( $team_ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared
            $sql = $wpdb->prepare(
                "SELECT * FROM {$table}
                  WHERE session_date >= %s
                    AND team_id IN ({$placeholders})
                    {$club_clause}
                  ORDER BY session_date ASC
                  LIMIT 1",
                array_merge( [ $today ], $team_ids )
            );
            return $wpdb->get_row( $sql );
        }
        // No coached teams — fall back to any club-scoped upcoming activity.
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE session_date >= %s {$club_clause} ORDER BY session_date ASC LIMIT 1",
            $today
        ) );
    }

    private static function hasClubColumn( string $table ): bool {
        global $wpdb;
        return null !== $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'club_id'",
            $table
        ) );
    }

    private static function eyebrowFor( string $session_date ): string {
        $today    = gmdate( 'Y-m-d' );
        $tomorrow = gmdate( 'Y-m-d', strtotime( '+1 day' ) );
        if ( $session_date === $today )    return __( 'Today', 'talenttrack' );
        if ( $session_date === $tomorrow ) return __( 'Tomorrow', 'talenttrack' );
        $ts = strtotime( $session_date );
        if ( $ts === false ) return __( 'Up next', 'talenttrack' );
        return sprintf(
            /* translators: %s is a localized date for an upcoming activity */
            __( 'Up next · %s', 'talenttrack' ),
            (string) wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $ts )
        );
    }

    private static function buildDetail( object $row ): string {
        $bits = [];
        $team_name = self::teamName( (int) ( $row->team_id ?? 0 ) );
        if ( $team_name !== '' ) $bits[] = $team_name;
        $location = (string) ( $row->location ?? '' );
        if ( $location !== '' ) $bits[] = $location;
        return implode( ' · ', $bits );
    }

    private static function teamName( int $team_id ): string {
        if ( $team_id <= 0 ) return '';
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}tt_teams WHERE id = %d",
            $team_id
        ) );
        return $row ? (string) $row->name : '';
    }
}
