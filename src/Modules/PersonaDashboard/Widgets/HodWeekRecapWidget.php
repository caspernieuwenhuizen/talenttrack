<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * HodWeekRecapWidget (#1374) — "This week at the academy".
 *
 * The HoD dashboard answers "what needs my attention" but not "what
 * happened since I was here". This widget diffs against the recap
 * baseline (`tt_recap_since_at`, rotated by PersonaLandingRenderer at
 * the start of each visit session; capped at 14 days; 7-day default
 * on first visit) and counts: evaluations written (by N coaches),
 * sessions completed (of N planned), new prospects, trial decisions,
 * behaviour ratings logged, PDP conversations conducted. Each line
 * links to the relevant view.
 *
 * Note: the audit asked for "players flagged red/orange" — the
 * traffic-light status is computed live per player with no dated
 * snapshot store, so there is nothing to diff against a window.
 * Behaviour ratings logged (a dated, queryable signal feeding that
 * status) stands in; a status-snapshot store is its own feature.
 */
class HodWeekRecapWidget extends AbstractWidget {

    public function id(): string { return 'hod_week_recap'; }

    public function label(): string { return __( 'This week at the academy', 'talenttrack' ); }

    public function description(): string {
        return __( 'Since-you-last-visited recap for academy-wide roles: evaluations written, sessions completed, new prospects, trial decisions, behaviour ratings, and PDP conversations in the window, each linking to the relevant view. Window = your previous visit, capped at 14 days; 7 days on first visit.', 'talenttrack' );
    }

    public function defaultSize(): string { return Size::XL; }

    /** @return list<string> */
    public function allowedSizes(): array { return [ Size::L, Size::XL ]; }

    public function defaultMobilePriority(): int { return 8; }

    public function personaContext(): string { return PersonaContext::ACADEMY; }

    public function capRequired(): string { return 'tt_view_reports'; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $since = self::sinceTimestamp( $ctx->user_id );
        $stats = self::collect( $since, $ctx->club_id );
        $base  = RecordLink::dashboardUrl();

        $title = $slot->persona_label !== '' ? $slot->persona_label : __( 'This week at the academy', 'talenttrack' );
        $sub   = sprintf(
            /* translators: %s: localized date the recap window starts at */
            __( 'Since %s', 'talenttrack' ),
            wp_date( get_option( 'date_format', 'Y-m-d' ), strtotime( $since ) )
        );

        $lines = [];
        $lines[] = [
            sprintf(
                /* translators: 1: evaluation count, 2: coach count */
                _n( '%1$d evaluation written by %2$d coach', '%1$d evaluations written by %2$d coaches', $stats['evals'], 'talenttrack' ),
                $stats['evals'],
                $stats['eval_coaches']
            ),
            add_query_arg( [ 'tt_view' => 'evaluations', 'filter' => [ 'date_from' => gmdate( 'Y-m-d', strtotime( $since ) ) ] ], $base ),
        ];
        $lines[] = [
            sprintf(
                /* translators: 1: completed session count, 2: planned session count in the window */
                __( '%1$d of %2$d planned sessions completed', 'talenttrack' ),
                $stats['sessions_completed'],
                $stats['sessions_total']
            ),
            add_query_arg( [ 'tt_view' => 'activities' ], $base ),
        ];
        if ( $stats['prospects'] !== null ) {
            $lines[] = [
                sprintf(
                    /* translators: %d: new prospect count */
                    _n( '%d new prospect logged', '%d new prospects logged', $stats['prospects'], 'talenttrack' ),
                    $stats['prospects']
                ),
                add_query_arg( [ 'tt_view' => 'onboarding-pipeline' ], $base ),
            ];
        }
        if ( $stats['trial_decisions'] !== null ) {
            $lines[] = [
                sprintf(
                    /* translators: %d: trial decision count */
                    _n( '%d trial decision made', '%d trial decisions made', $stats['trial_decisions'], 'talenttrack' ),
                    $stats['trial_decisions']
                ),
                add_query_arg( [ 'tt_view' => 'trials' ], $base ),
            ];
        }
        if ( $stats['behaviour'] !== null ) {
            $lines[] = [
                sprintf(
                    /* translators: %d: behaviour rating count */
                    _n( '%d behaviour rating logged', '%d behaviour ratings logged', $stats['behaviour'], 'talenttrack' ),
                    $stats['behaviour']
                ),
                add_query_arg( [ 'tt_view' => 'players' ], $base ),
            ];
        }
        if ( $stats['pdp_conversations'] !== null ) {
            $lines[] = [
                sprintf(
                    /* translators: %d: PDP conversation count */
                    _n( '%d PDP conversation logged', '%d PDP conversations logged', $stats['pdp_conversations'], 'talenttrack' ),
                    $stats['pdp_conversations']
                ),
                add_query_arg( [ 'tt_view' => 'pdp-planning' ], $base ),
            ];
        }

        $items = '';
        foreach ( $lines as [ $text, $url ] ) {
            $items .= '<li class="tt-pd-recap-line"><a class="tt-record-link" href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a></li>';
        }

        $inner = '<div class="tt-pd-panel-head">'
            . '<span class="tt-pd-panel-title">' . esc_html( $title ) . '</span>'
            . '<span class="tt-pd-panel-sub">' . esc_html( $sub ) . '</span>'
            . '</div>'
            . '<ul class="tt-pd-recap-list" style="margin:0; padding:0; list-style:none; display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:6px 16px;">'
            . $items
            . '</ul>';
        return $this->wrap( $slot, $inner, 'panel' );
    }

    /**
     * Recap window start, as a MySQL datetime: the rotated visit
     * baseline when present, capped at 14 days back; 7 days back on a
     * first visit (no baseline yet).
     */
    private static function sinceTimestamp( int $user_id ): string {
        $raw   = get_user_meta( $user_id, 'tt_recap_since_at', true );
        $floor = gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS );
        if ( ! is_string( $raw ) || $raw === '' || strtotime( $raw ) === false ) {
            return gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
        }
        return max( $raw, $floor );
    }

    /**
     * @return array{
     *   evals:int, eval_coaches:int,
     *   sessions_completed:int, sessions_total:int,
     *   prospects:?int, trial_decisions:?int, behaviour:?int, pdp_conversations:?int
     * }
     */
    private static function collect( string $since, int $club_id ): array {
        global $wpdb;
        $p          = $wpdb->prefix;
        $since_date = gmdate( 'Y-m-d', strtotime( $since ) );

        $eval_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS n, COUNT(DISTINCT coach_id) AS coaches
               FROM {$p}tt_evaluations
              WHERE archived_at IS NULL AND created_at > %s
                AND ( club_id = %d OR club_id IS NULL )",
            $since, $club_id
        ) );

        $sess_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN activity_status_key = 'completed' THEN 1 ELSE 0 END) AS done
               FROM {$p}tt_activities
              WHERE archived_at IS NULL
                AND session_date >= %s AND session_date <= %s
                AND activity_status_key <> 'cancelled'
                AND club_id = %d",
            $since_date, gmdate( 'Y-m-d' ), $club_id
        ) );

        return [
            'evals'              => (int) ( $eval_row->n ?? 0 ),
            'eval_coaches'       => (int) ( $eval_row->coaches ?? 0 ),
            'sessions_completed' => (int) ( $sess_row->done ?? 0 ),
            'sessions_total'     => (int) ( $sess_row->total ?? 0 ),
            'prospects'          => self::guardedCount(
                "{$p}tt_prospects",
                "SELECT COUNT(*) FROM {$p}tt_prospects WHERE archived_at IS NULL AND created_at > %s AND club_id = %d",
                [ $since, $club_id ]
            ),
            'trial_decisions'    => self::guardedCount(
                "{$p}tt_trial_cases",
                "SELECT COUNT(*) FROM {$p}tt_trial_cases WHERE decided_at IS NOT NULL AND decided_at > %s AND club_id = %d",
                [ $since, $club_id ]
            ),
            'behaviour'          => self::guardedCount(
                "{$p}tt_player_behaviour_ratings",
                "SELECT COUNT(*) FROM {$p}tt_player_behaviour_ratings WHERE rated_at > %s AND club_id = %d",
                [ $since, $club_id ]
            ),
            'pdp_conversations'  => self::guardedCount(
                "{$p}tt_pdp_conversations",
                "SELECT COUNT(*) FROM {$p}tt_pdp_conversations WHERE conducted_at IS NOT NULL AND conducted_at > %s AND club_id = %d",
                [ $since, $club_id ]
            ),
        ];
    }

    /**
     * Count with a table-exists guard — pre-migration installs simply
     * drop the line instead of fataling the dashboard.
     *
     * @param array<int,mixed> $params
     */
    private static function guardedCount( string $table, string $sql, array $params ): ?int {
        global $wpdb;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return null;
        }
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $n = $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );
        return $n === null && $wpdb->last_error !== '' ? null : (int) $n;
    }
}
