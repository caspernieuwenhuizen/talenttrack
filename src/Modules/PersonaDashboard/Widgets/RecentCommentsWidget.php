<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * RecentCommentsWidget (v3.110.113) — five most recent thread messages
 * across the club. Each row: entity (linked to its detail page) +
 * author (display name) + relative date.
 *
 * Reads `tt_thread_messages` only (the #0028 polymorphic conversation
 * primitive — covers goals, players, blueprints, trials, scout reports,
 * PDPs, etc.). System messages (`is_system = 1`) are excluded so the
 * widget surfaces only operator-authored content.
 *
 * Scope is `club_id` — academy-wide. Suitable for academy-wide personas
 * (HoD, Academy Admin); cap-gated on `tt_view_threads` so users without
 * thread visibility don't see the widget at all.
 */
class RecentCommentsWidget extends AbstractWidget {

    public function id(): string { return 'recent_comments'; }

    public function label(): string { return __( 'Recent comments & notes', 'talenttrack' ); }

    public function defaultSize(): string { return Size::M; }

    /** @return list<string> */
    public function allowedSizes(): array { return [ Size::M, Size::L, Size::XL ]; }

    public function defaultMobilePriority(): int { return 50; }

    public function personaContext(): string { return PersonaContext::ACADEMY; }

    public function capRequired(): string { return 'tt_view_threads'; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $rows  = $this->fetchRows( $ctx->club_id );
        $title = $slot->persona_label !== '' ? $slot->persona_label : __( 'Recent comments & notes', 'talenttrack' );

        if ( empty( $rows ) ) {
            $inner = '<div class="tt-pd-panel-head"><span class="tt-pd-panel-title">' . esc_html( $title ) . '</span></div>'
                . '<div class="tt-pd-mini-list-empty">' . esc_html__( 'No comments yet.', 'talenttrack' ) . '</div>';
            return $this->wrap( $slot, $inner, 'panel' );
        }

        $items = '';
        foreach ( $rows as $row ) {
            $entity_label = $row['entity_label'];
            $entity_url   = $row['entity_url'];
            $author       = $row['author'];
            $when         = $row['when'];

            $entity_html = $entity_url !== ''
                ? '<a class="tt-record-link" href="' . esc_url( $entity_url ) . '">' . esc_html( $entity_label ) . '</a>'
                : esc_html( $entity_label );

            $items .= '<li class="tt-pd-comments-row">'
                . '<div class="tt-pd-comments-entity">' . $entity_html . '</div>'
                . '<div class="tt-pd-comments-meta">'
                    . '<span class="tt-pd-comments-author">' . esc_html( $author ) . '</span>'
                    . '<span class="tt-pd-comments-date">' . esc_html( $when ) . '</span>'
                . '</div>'
                . '</li>';
        }

        $inner = '<div class="tt-pd-panel-head">'
            . '<span class="tt-pd-panel-title">' . esc_html( $title ) . '</span>'
            . '</div>'
            . '<ul class="tt-pd-comments-list">' . $items . '</ul>';

        return $this->wrap( $slot, $inner, 'panel' );
    }

    /**
     * Fetch the 5 most recent non-deleted, non-system thread messages.
     *
     * @return list<array{ entity_label: string, entity_url: string, author: string, when: string }>
     */
    private function fetchRows( int $club_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $effective_club = $club_id > 0 ? $club_id : CurrentClub::id();

        $table = $p . 'tt_thread_messages';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [];
        }

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT tm.id, tm.thread_type, tm.thread_id, tm.author_user_id, tm.created_at,
                    u.display_name AS author_name
               FROM {$table} tm
               LEFT JOIN {$wpdb->users} u ON u.ID = tm.author_user_id
              WHERE tm.club_id = %d
                AND tm.deleted_at IS NULL
                AND tm.is_system = 0
              ORDER BY tm.created_at DESC, tm.id DESC
              LIMIT 5",
            $effective_club
        ) );

        $out = [];
        foreach ( (array) $rows as $r ) {
            $entity = self::resolveEntity( (string) $r->thread_type, (int) $r->thread_id );
            $author = (string) ( $r->author_name ?? '' );
            if ( $author === '' ) $author = __( '— Unknown —', 'talenttrack' );
            $out[] = [
                'entity_label' => $entity['label'],
                'entity_url'   => $entity['url'],
                'author'       => $author,
                'when'         => self::relativeDate( (string) $r->created_at ),
            ];
        }
        return $out;
    }

    /**
     * Map a (thread_type, thread_id) tuple to a human-readable label
     * and click-through URL. The four most common thread types get
     * proper entity lookups; everything else falls back to
     * `<Capitalised Type> #<ID>`.
     *
     * @return array{label:string, url:string}
     */
    private static function resolveEntity( string $type, int $id ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        switch ( $type ) {
            case 'goal':
                $title = (string) $wpdb->get_var( $wpdb->prepare(
                    "SELECT title FROM {$p}tt_goals WHERE id = %d AND club_id = %d",
                    $id, CurrentClub::id()
                ) );
                if ( $title !== '' ) {
                    return [
                        'label' => sprintf( /* translators: %s = goal title */ __( 'Goal: %s', 'talenttrack' ), $title ),
                        'url'   => BackLink::appendTo( RecordLink::detailUrlFor( 'goals', $id ) ),
                    ];
                }
                break;
            case 'player':
                $pl = QueryHelpers::get_player( $id );
                if ( $pl ) {
                    return [
                        'label' => sprintf( /* translators: %s = player name */ __( 'Player: %s', 'talenttrack' ), QueryHelpers::player_display_name( $pl ) ),
                        'url'   => BackLink::appendTo( RecordLink::detailUrlFor( 'players', $id ) ),
                    ];
                }
                break;
            case 'trial_case':
                $name = (string) $wpdb->get_var( $wpdb->prepare(
                    "SELECT CONCAT(pl.first_name, ' ', pl.last_name)
                       FROM {$p}tt_trial_cases tc
                       LEFT JOIN {$p}tt_players pl ON pl.id = tc.player_id
                      WHERE tc.id = %d AND tc.club_id = %d",
                    $id, CurrentClub::id()
                ) );
                if ( trim( $name ) !== '' ) {
                    return [
                        'label' => sprintf( /* translators: %s = player name */ __( 'Trial: %s', 'talenttrack' ), trim( $name ) ),
                        'url'   => '',
                    ];
                }
                break;
            case 'pdp_conversation':
                $name = (string) $wpdb->get_var( $wpdb->prepare(
                    "SELECT CONCAT(pl.first_name, ' ', pl.last_name)
                       FROM {$p}tt_pdp_conversations c
                       LEFT JOIN {$p}tt_pdp_files f ON f.id = c.pdp_file_id
                       LEFT JOIN {$p}tt_players pl ON pl.id = f.player_id
                      WHERE c.id = %d",
                    $id
                ) );
                if ( trim( $name ) !== '' ) {
                    return [
                        'label' => sprintf( /* translators: %s = player name */ __( 'PDP: %s', 'talenttrack' ), trim( $name ) ),
                        'url'   => '',
                    ];
                }
                break;
            case 'blueprint':
                $name = (string) $wpdb->get_var( $wpdb->prepare(
                    "SELECT name FROM {$p}tt_teams WHERE id = %d AND club_id = %d",
                    $id, CurrentClub::id()
                ) );
                if ( $name !== '' ) {
                    return [
                        'label' => sprintf( /* translators: %s = team name */ __( 'Blueprint: %s', 'talenttrack' ), $name ),
                        'url'   => '',
                    ];
                }
                break;
        }
        return [
            'label' => sprintf( '%s #%d', ucfirst( str_replace( '_', ' ', $type ) ), $id ),
            'url'   => '',
        ];
    }

    /**
     * "5m ago" / "2h ago" / "3d ago" / fallback to a localised date when
     * the message is older than a week. Same vocabulary the task list
     * widget uses so the dashboard reads consistently.
     */
    private static function relativeDate( string $created_at ): string {
        if ( $created_at === '' ) return '';
        $ts = strtotime( $created_at );
        if ( $ts === false ) return $created_at;
        $now = current_time( 'timestamp' );
        $diff = $now - $ts;
        if ( $diff < 60 ) return __( 'just now', 'talenttrack' );
        if ( $diff < 3600 ) {
            $mins = max( 1, (int) round( $diff / 60 ) );
            return sprintf( _n( '%d min ago', '%d mins ago', $mins, 'talenttrack' ), $mins );
        }
        if ( $diff < 86400 ) {
            $hours = max( 1, (int) round( $diff / 3600 ) );
            return sprintf( _n( '%d h ago', '%d h ago', $hours, 'talenttrack' ), $hours );
        }
        if ( $diff < 604800 ) {
            $days = max( 1, (int) round( $diff / 86400 ) );
            return sprintf( _n( '%d d ago', '%d d ago', $days, 'talenttrack' ), $days );
        }
        $format = (string) QueryHelpers::get_config( 'date_format', 'Y-m-d' );
        return wp_date( $format, $ts );
    }
}
