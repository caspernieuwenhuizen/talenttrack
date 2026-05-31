<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Shared\Frontend\Components\BackLink;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * MatchesNeedingReviewWidget (#1050, #1033 follow-up B) — small list
 * card surfacing match executions in PENDING_REVIEW on the coach
 * hero. Pairs with the listing surface at `?tt_view=match-executions`
 * (#1047) — this is the at-a-glance reminder; that's the full list.
 *
 * Visible only when the user has ≥ 1 PENDING_REVIEW execution on a
 * team they can edit. Renders empty (no card) otherwise — the
 * persona dashboard policy is to hide silent widgets, not to render
 * a "No matches need review" placeholder.
 *
 * Persona scoping (per #1050 locked decisions):
 *   - Coach (head / assistant): own teams only, via
 *     `tt_user_team_link`.
 *   - HoD / Admin: club-wide, gated by `tt_view_all_teams` /
 *     `tt_edit_settings`.
 *
 * Cap-required: `tt_view_activities` (matches the listing view).
 */
class MatchesNeedingReviewWidget extends AbstractWidget {

    private const ROW_LIMIT = 5;

    public function id(): string { return 'matches_needing_review'; }

    public function label(): string { return __( 'Matches needing review', 'talenttrack' ); }

    public function description(): string {
        return __( 'Lists match executions that have ended but not yet been finalised, on teams the coach can edit. Empty state renders nothing — the widget disappears when there is nothing to review. Pairs with the listing surface at ?tt_view=match-executions.', 'talenttrack' );
    }

    /** @return list<string> */
    public function intendedPersonas(): array {
        return [ 'head_coach', 'assistant_coach', 'head_of_development', 'club_admin' ];
    }

    public function defaultSize(): string { return Size::M; }

    /** @return list<string> */
    public function allowedSizes(): array { return [ Size::M, Size::L ]; }

    public function defaultMobilePriority(): int { return 2; }

    public function personaContext(): string { return PersonaContext::COACH; }

    public function capRequired(): string { return 'tt_view_activities'; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $rows = $this->fetchRows( $ctx );
        if ( empty( $rows ) ) {
            // #1050 — silent widget. The persona dashboard hides
            // empty-string returns; rendering a "nothing to do"
            // placeholder is noise.
            return '';
        }

        $listing_url = BackLink::appendTo( add_query_arg(
            [ 'tt_view' => 'match-executions', 'state' => MatchExecutionState::PENDING_REVIEW ],
            RecordLink::dashboardUrl()
        ) );

        ob_start();
        ?>
        <div class="tt-mnrw">
            <header class="tt-mnrw-head">
                <h3 class="tt-mnrw-title">
                    <?php esc_html_e( 'Matches needing review', 'talenttrack' ); ?>
                </h3>
                <p class="tt-mnrw-lede">
                    <?php
                    printf(
                        /* translators: %d = number of matches awaiting finalize */
                        esc_html( _n( '%d match ended, waiting for finalize.', '%d matches ended, waiting for finalize.', count( $rows ), 'talenttrack' ) ),
                        (int) count( $rows )
                    );
                    ?>
                </p>
            </header>
            <ul class="tt-mnrw-list">
                <?php foreach ( array_slice( $rows, 0, self::ROW_LIMIT ) as $r ) :
                    $opp   = trim( (string) ( $r->opponent ?? '' ) );
                    if ( $opp === '' ) $opp = '—';
                    $name  = (string) ( $r->team_name ?? ( '#' . (int) $r->team_id ) );
                    $score = sprintf( '%d–%d', (int) $r->home_score, (int) $r->away_score );
                    $url   = BackLink::appendTo( add_query_arg(
                        [ 'tt_view' => 'match-execution', 'activity_id' => (int) $r->activity_id ],
                        RecordLink::dashboardUrl()
                    ) );
                    ?>
                    <li class="tt-mnrw-row">
                        <a class="tt-mnrw-row-link" href="<?php echo esc_url( $url ); ?>">
                            <span class="tt-mnrw-row-main">
                                <span class="tt-mnrw-row-opp"><?php echo esc_html( $opp ); ?></span>
                                <span class="tt-mnrw-row-meta">
                                    <?php echo esc_html( $name ); ?>
                                    ·
                                    <?php echo esc_html( (string) $r->session_date ); ?>
                                </span>
                            </span>
                            <span class="tt-mnrw-row-score"><?php echo esc_html( $score ); ?></span>
                            <span class="tt-mnrw-row-cta"><?php esc_html_e( 'Review ›', 'talenttrack' ); ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php if ( count( $rows ) > self::ROW_LIMIT ) : ?>
                <p class="tt-mnrw-foot">
                    <a href="<?php echo esc_url( $listing_url ); ?>">
                        <?php
                        printf(
                            /* translators: %d = number of extra matches not shown */
                            esc_html__( 'All match executions (%d more)', 'talenttrack' ),
                            (int) ( count( $rows ) - self::ROW_LIMIT )
                        );
                        ?> ›
                    </a>
                </p>
            <?php else : ?>
                <p class="tt-mnrw-foot">
                    <a href="<?php echo esc_url( $listing_url ); ?>">
                        <?php esc_html_e( 'All match executions', 'talenttrack' ); ?> ›
                    </a>
                </p>
            <?php endif; ?>
        </div>
        <style>
            .tt-mnrw {
                background: #fff;
                border: 1px solid #fbc02d;
                border-left: 4px solid #fbc02d;
                border-radius: 8px;
                padding: 14px 16px;
            }
            .tt-mnrw-head { margin: 0 0 10px; }
            .tt-mnrw-title { margin: 0 0 4px; font-size: 14px; font-weight: 700; color: #1a1d21; text-transform: uppercase; letter-spacing: 0.4px; }
            .tt-mnrw-lede { margin: 0; font-size: 12px; color: #5b6e75; }
            .tt-mnrw-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
            .tt-mnrw-row { margin: 0; padding: 0; }
            .tt-mnrw-row-link {
                display: grid;
                grid-template-columns: 1fr auto auto;
                gap: 10px;
                align-items: center;
                padding: 10px 12px;
                min-height: 48px;
                background: #fff8e1;
                border: 1px solid #f5dba0;
                border-radius: 6px;
                text-decoration: none;
                color: #1a1d21;
            }
            .tt-mnrw-row-link:hover { background: #fef0c2; }
            .tt-mnrw-row-main { display: flex; flex-direction: column; min-width: 0; }
            .tt-mnrw-row-opp { font-weight: 600; font-size: 14px; }
            .tt-mnrw-row-meta { font-size: 12px; color: #5b6e75; }
            .tt-mnrw-row-score { font-weight: 700; font-size: 14px; font-variant-numeric: tabular-nums; }
            .tt-mnrw-row-cta { font-size: 12px; font-weight: 600; color: #92651b; white-space: nowrap; }
            .tt-mnrw-foot { margin: 10px 0 0; font-size: 12px; text-align: right; }
            .tt-mnrw-foot a { color: #2271b1; text-decoration: none; }
            .tt-mnrw-foot a:hover { text-decoration: underline; }
            @media (max-width: 480px) {
                .tt-mnrw-row-link { grid-template-columns: 1fr auto; }
                .tt-mnrw-row-cta { grid-column: 1 / -1; text-align: right; }
            }
        </style>
        <?php
        $inner = (string) ob_get_clean();
        return $this->wrap( $slot, $inner, 'matches-needing-review' );
    }

    /**
     * @return list<object>
     */
    private function fetchRows( RenderContext $ctx ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $club_id = (int) $ctx->club_id;
        $user_id = (int) $ctx->user_id;

        $club_wide = user_can( $user_id, 'tt_view_all_teams' )
            || user_can( $user_id, 'tt_edit_settings' );

        if ( $club_wide ) {
            $sql = "SELECT
                        e.id AS execution_id,
                        e.home_score,
                        e.away_score,
                        a.id AS activity_id,
                        a.session_date,
                        a.opponent,
                        a.team_id,
                        t.name AS team_name
                      FROM {$p}tt_match_execution e
                      INNER JOIN {$p}tt_activities a ON a.id = e.activity_id AND a.club_id = e.club_id
                      LEFT JOIN  {$p}tt_teams t ON t.id = a.team_id AND t.club_id = a.club_id
                     WHERE e.club_id = %d
                       AND e.state = %s
                     ORDER BY a.session_date DESC, e.id DESC
                     LIMIT 20";
            $params = [ $club_id, MatchExecutionState::PENDING_REVIEW ];
        } else {
            $sql = "SELECT DISTINCT
                        e.id AS execution_id,
                        e.home_score,
                        e.away_score,
                        a.id AS activity_id,
                        a.session_date,
                        a.opponent,
                        a.team_id,
                        t.name AS team_name
                      FROM {$p}tt_match_execution e
                      INNER JOIN {$p}tt_activities a ON a.id = e.activity_id AND a.club_id = e.club_id
                      LEFT JOIN  {$p}tt_teams t ON t.id = a.team_id AND t.club_id = a.club_id
                      INNER JOIN {$p}tt_user_team_link l ON l.team_id = a.team_id AND l.user_id = %d
                     WHERE e.club_id = %d
                       AND e.state = %s
                     ORDER BY a.session_date DESC, e.id DESC
                     LIMIT 20";
            $params = [ $user_id, $club_id, MatchExecutionState::PENDING_REVIEW ];
        }

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
        return is_array( $rows ) ? $rows : [];
    }
}
