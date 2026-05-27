<?php
namespace TT\Modules\MatchExecution\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendMatchExecutionView (#847, redesigned in #956) — sideline-first
 * mobile live-match surface the assistant coach runs from a phone.
 *
 * Hard dependency on Match Prep (#838): no prep row → "plan first"
 * notice with a link to the prep wizard.
 *
 * v4.3.19 (#956) — UX redesign per [`.local-mockups/match-execution/`](.local-mockups/match-execution/):
 *
 *   - Single-line condensed header (team A vs team B · date time).
 *   - Side-by-side score columns with team abbreviation labels.
 *   - Timer: half label + pulsing live dot + clock + state-aware button.
 *   - "Tracked players" — flagged-only on-pitch rows with inline goal
 *     chips (replacing the v4.1.7 "Specific goals" section).
 *   - Bench: tap "→ on" to reveal a dedicated sub-target section below
 *     (replacing the modal-based picker).
 *   - Sticky footer: state-aware primary CTA with colour coding.
 *
 * No backend changes — same MatchExecutionRepository, same REST routes,
 * same state machine. The redesign is HTML + CSS + JS sub-flow only.
 */
class FrontendMatchExecutionView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_edit_activities' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Match execution is restricted to coaches and admins.', 'talenttrack' ) . '</p>';
            return;
        }

        $activity_id = isset( $_GET['activity_id'] ) ? absint( $_GET['activity_id'] ) : 0;
        if ( $activity_id <= 0 ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Match execution', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Open Match execution from a match activity\'s detail page.', 'talenttrack' ) . '</p>';
            return;
        }

        $activity = self::loadActivity( $activity_id );
        if ( ! $activity ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Match execution', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'Activity not found.', 'talenttrack' ) . '</p>';
            return;
        }

        $prep_repo = new MatchPrepRepository();
        $prep      = $prep_repo->findByActivity( $activity_id );
        if ( ! $prep ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Match execution', 'talenttrack' ) );
            self::renderHeader( __( 'Match execution', 'talenttrack' ) );
            $prep_url = \TT\Shared\Wizards\WizardEntryPoint::buildUrl( 'match-prep', [ 'activity_id' => $activity_id ] );
            echo '<p class="tt-notice tt-notice-warning">'
                . esc_html__( 'Plan this match first before you can run it.', 'talenttrack' )
                . ' <a class="tt-btn tt-btn-primary" href="' . esc_url( $prep_url ) . '">'
                . esc_html__( 'Plan match prep', 'talenttrack' )
                . '</a></p>';
            return;
        }

        $exec_repo    = new MatchExecutionRepository();
        $execution    = $exec_repo->findByActivity( $activity_id );
        $execution_id = $execution ? (int) $execution->id : 0;

        $availability = $prep_repo->listAvailability( (int) $prep->id );
        $lineup       = $prep_repo->listLineup( (int) $prep->id );
        $player_goals = $prep_repo->listPlayerGoals( (int) $prep->id );

        $starting_xi_half1 = [];
        foreach ( $lineup as $l ) {
            if ( (int) $l->half === 1 ) $starting_xi_half1[] = (int) $l->player_id;
        }

        $available_ids = [];
        foreach ( $availability as $a ) {
            if ( strcasecmp( (string) $a->status, 'Present' ) === 0 ) {
                $available_ids[] = (int) $a->player_id;
            }
        }
        $bench_ids = array_values( array_diff( $available_ids, $starting_xi_half1 ) );

        $players_by_id = self::loadPlayersById( array_merge( $available_ids, $starting_xi_half1, $bench_ids ) );

        // Flagged players (with a specific goal in match prep) get the
        // inline goal-chip + action-counter row in the Tracked Players
        // section.
        $specific_goal_ids = [];
        $player_goal_labels = [];
        foreach ( $player_goals as $g ) {
            if ( ! empty( $g->is_specific_goal ) ) {
                $pid = (int) $g->player_id;
                $specific_goal_ids[] = $pid;
                // Capture the operator-set goal label per player for the
                // chip text. Falls back gracefully when not set.
                $player_goal_labels[ $pid ] = (string) ( $g->goal_label ?? $g->label ?? '' );
            }
        }

        $goal_events = $execution ? $exec_repo->listGoalEvents( $execution_id ) : [];
        $goal_counts = [];
        foreach ( $goal_events as $ge ) {
            $pid = (int) $ge->player_id;
            $goal_counts[ $pid ] = ( $goal_counts[ $pid ] ?? 0 ) + 1;
        }

        FrontendBreadcrumbs::fromDashboard( __( 'Match execution', 'talenttrack' ) );
        parent::enqueueAssets();
        self::enqueueViewAssets( $activity_id, $execution );

        $home_label = ( ( $activity->home_away ?? '' ) === 'home' ) ? (string) ( $activity->team_name ?? __( 'Home', 'talenttrack' ) ) : (string) ( $activity->team_name ?? '' );
        $away_label = (string) ( $activity->opponent ?? '—' );

        $home_abbr = self::abbreviate( $home_label );
        $away_abbr = self::abbreviate( $away_label );

        $home_score = $execution ? (int) $execution->home_score : 0;
        $away_score = $execution ? (int) $execution->away_score : 0;
        $state      = $execution ? (string) $execution->state : 'not_started';

        $session_date = (string) ( $activity->session_date ?? '' );
        $kickoff      = (string) ( $activity->kickoff_time ?? '' );
        $when         = trim( $session_date . ( $kickoff !== '' ? ' ' . substr( $kickoff, 0, 5 ) : '' ) );
        ?>
        <div class="tt-mexec" data-activity-id="<?php echo (int) $activity_id; ?>" data-state="<?php echo esc_attr( $state ); ?>" data-half-length="<?php echo (int) $prep->half_length_minutes; ?>">

            <header class="tt-mexec-header">
                <p class="tt-mexec-header-meta">
                    <span class="tt-mexec-team-name"><?php echo esc_html( $home_label ); ?></span>
                    <span class="tt-mexec-vs"><?php esc_html_e( 'vs', 'talenttrack' ); ?></span>
                    <span class="tt-mexec-team-name"><?php echo esc_html( $away_label ); ?></span>
                    <?php if ( $when !== '' ) : ?>
                        <span class="tt-mexec-sep">·</span>
                        <span class="tt-mexec-when"><?php echo esc_html( $when ); ?></span>
                    <?php endif; ?>
                </p>
            </header>

            <section class="tt-mexec-score" aria-label="<?php esc_attr_e( 'Score', 'talenttrack' ); ?>">
                <div class="tt-mexec-score-line">
                    <div class="tt-mexec-score-col">
                        <p class="tt-mexec-score-team-label"><?php echo esc_html( $home_abbr ); ?></p>
                        <div class="tt-mexec-score-stepper" aria-label="<?php echo esc_attr( sprintf( __( '%s score', 'talenttrack' ), $home_abbr ) ); ?>">
                            <button type="button" class="tt-mexec-score-btn tt-mexec-score-btn--minus tt-mexec-step" data-tt-mexec-score="home" data-tt-mexec-delta="-1" aria-label="<?php esc_attr_e( 'Decrease home score', 'talenttrack' ); ?>">−</button>
                            <output class="tt-mexec-score-num" data-tt-mexec-home-score><?php echo (int) $home_score; ?></output>
                            <button type="button" class="tt-mexec-score-btn tt-mexec-score-btn--plus tt-mexec-step" data-tt-mexec-score="home" data-tt-mexec-delta="+1" aria-label="<?php esc_attr_e( 'Increase home score', 'talenttrack' ); ?>">+</button>
                        </div>
                    </div>
                    <div class="tt-mexec-score-col">
                        <p class="tt-mexec-score-team-label"><?php echo esc_html( $away_abbr ); ?></p>
                        <div class="tt-mexec-score-stepper" aria-label="<?php echo esc_attr( sprintf( __( '%s score', 'talenttrack' ), $away_abbr ) ); ?>">
                            <button type="button" class="tt-mexec-score-btn tt-mexec-score-btn--minus tt-mexec-step" data-tt-mexec-score="away" data-tt-mexec-delta="-1" aria-label="<?php esc_attr_e( 'Decrease away score', 'talenttrack' ); ?>">−</button>
                            <output class="tt-mexec-score-num" data-tt-mexec-away-score><?php echo (int) $away_score; ?></output>
                            <button type="button" class="tt-mexec-score-btn tt-mexec-score-btn--plus tt-mexec-step" data-tt-mexec-score="away" data-tt-mexec-delta="+1" aria-label="<?php esc_attr_e( 'Increase away score', 'talenttrack' ); ?>">+</button>
                        </div>
                    </div>
                </div>
            </section>

            <section class="tt-mexec-timer" aria-label="<?php esc_attr_e( 'Match timer', 'talenttrack' ); ?>">
                <div class="tt-mexec-timer-main">
                    <p class="tt-mexec-timer-half" data-status="" data-tt-mexec-half-label>—</p>
                    <p class="tt-mexec-timer-clock" data-tt-mexec-clock>00:00</p>
                </div>
                <button type="button" class="tt-mexec-timer-btn" data-tt-mexec-timer-toggle><?php esc_html_e( 'Start', 'talenttrack' ); ?></button>
            </section>

            <section class="tt-mexec-section tt-mexec-on-pitch" aria-label="<?php esc_attr_e( 'Tracked players', 'talenttrack' ); ?>">
                <div class="tt-mexec-section-head">
                    <h2 class="tt-mexec-section-title"><?php esc_html_e( 'Tracked players', 'talenttrack' ); ?></h2>
                    <span class="tt-mexec-section-count"><?php echo esc_html( sprintf(
                        /* translators: %d: number of flagged players */
                        _n( '%d flagged', '%d flagged', count( $specific_goal_ids ), 'talenttrack' ),
                        count( $specific_goal_ids )
                    ) ); ?></span>
                </div>
                <?php if ( empty( $specific_goal_ids ) ) : ?>
                    <p class="tt-mexec-empty"><?php esc_html_e( 'No players flagged with a specific goal in the match prep.', 'talenttrack' ); ?></p>
                <?php else : ?>
                    <ul class="tt-mexec-player-list">
                        <?php foreach ( $specific_goal_ids as $pid ) :
                            $pl = $players_by_id[ $pid ] ?? null;
                            if ( ! $pl ) continue;
                            $count = (int) ( $goal_counts[ $pid ] ?? 0 );
                            $goal_label = trim( (string) ( $player_goal_labels[ $pid ] ?? '' ) );
                            $jersey = $pl->jersey_number !== null ? (string) (int) $pl->jersey_number : '';
                            ?>
                            <li class="tt-mexec-player" data-flagged="true" data-tt-mexec-goal-row data-player-id="<?php echo (int) $pid; ?>">
                                <span class="tt-mexec-player-number"><?php echo esc_html( $jersey ); ?></span>
                                <span class="tt-mexec-player-name">
                                    <?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?>
                                    <?php if ( $goal_label !== '' ) : ?>
                                        <small><?php echo esc_html( sprintf( __( 'flagged: %s', 'talenttrack' ), $goal_label ) ); ?></small>
                                    <?php endif; ?>
                                </span>
                                <div class="tt-mexec-player-actions">
                                    <button type="button" class="tt-mexec-action-btn tt-mexec-action-btn--goal" data-tt-mexec-goal-inc aria-label="<?php esc_attr_e( 'Tap to add one (long-press to remove last)', 'talenttrack' ); ?>"><?php esc_html_e( '+ action', 'talenttrack' ); ?></button>
                                </div>
                                <div class="tt-mexec-player-goals">
                                    <?php if ( $goal_label !== '' ) : ?>
                                        <span class="tt-mexec-goal-chip"><?php echo esc_html( $goal_label ); ?> <strong data-tt-mexec-goal-count><?php echo (int) $count; ?></strong></span>
                                    <?php else : ?>
                                        <span class="tt-mexec-goal-chip"><?php esc_html_e( 'actions', 'talenttrack' ); ?> <strong data-tt-mexec-goal-count><?php echo (int) $count; ?></strong></span>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="tt-mexec-section tt-mexec-bench" aria-label="<?php esc_attr_e( 'Bench', 'talenttrack' ); ?>">
                <div class="tt-mexec-section-head">
                    <h2 class="tt-mexec-section-title"><?php esc_html_e( 'Bench', 'talenttrack' ); ?></h2>
                    <span class="tt-mexec-section-count"><?php echo esc_html( sprintf(
                        /* translators: %d: number of bench players */
                        _n( '%d available', '%d available', count( $bench_ids ), 'talenttrack' ),
                        count( $bench_ids )
                    ) ); ?></span>
                </div>
                <?php if ( empty( $bench_ids ) ) : ?>
                    <p class="tt-mexec-empty"><?php esc_html_e( 'No bench players available.', 'talenttrack' ); ?></p>
                <?php else : ?>
                    <ul class="tt-mexec-player-list">
                        <?php foreach ( $bench_ids as $pid ) :
                            $pl = $players_by_id[ $pid ] ?? null;
                            if ( ! $pl ) continue;
                            $jersey = $pl->jersey_number !== null ? (string) (int) $pl->jersey_number : '';
                            ?>
                            <li class="tt-mexec-player" data-tt-mexec-bench data-player-id="<?php echo (int) $pid; ?>">
                                <span class="tt-mexec-player-number"><?php echo esc_html( $jersey ); ?></span>
                                <span class="tt-mexec-player-name"><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></span>
                                <div class="tt-mexec-player-actions">
                                    <button type="button" class="tt-mexec-action-btn tt-mexec-action-btn--sub-on" data-tt-mexec-sub-on aria-label="<?php esc_attr_e( 'Bring on', 'talenttrack' ); ?>"><?php esc_html_e( '→ on', 'talenttrack' ); ?></button>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="tt-mexec-sub-target" aria-label="<?php esc_attr_e( 'Choose who comes off', 'talenttrack' ); ?>" data-tt-mexec-onpitch-section>
                <div class="tt-mexec-sub-target-banner" role="status">
                    <span data-tt-mexec-sub-banner><?php esc_html_e( 'Tap a player to swap', 'talenttrack' ); ?></span>
                    <button type="button" class="tt-mexec-sub-cancel" data-tt-mexec-sub-cancel><?php esc_html_e( 'Cancel', 'talenttrack' ); ?></button>
                </div>
                <ul class="tt-mexec-player-list" data-tt-mexec-onpitch-list></ul>
            </section>

            <footer class="tt-mexec-footer">
                <div class="tt-mexec-footer-inner">
                    <button type="button" class="tt-mexec-footer-cta" data-tt-mexec-state-action data-action="start-match"><?php esc_html_e( 'Start match', 'talenttrack' ); ?></button>
                    <p class="tt-mexec-footer-sub" data-state="online" data-tt-mexec-status>
                        <span class="tt-mexec-footer-dot"></span>
                        <span data-tt-mexec-status-text><?php esc_html_e( 'Synced', 'talenttrack' ); ?></span>
                    </p>
                </div>
            </footer>
        </div>

        <?php
        $bootstrap = [
            'starting_xi_half1' => array_values( array_filter( array_map( 'intval', $starting_xi_half1 ) ) ),
            'bench'             => array_values( array_filter( array_map( 'intval', $bench_ids ) ) ),
            'players'           => array_map( function( $pl ) {
                return [
                    'id'     => (int) $pl->id,
                    'name'   => (string) QueryHelpers::player_display_name( $pl ),
                    'jersey' => $pl->jersey_number !== null ? (int) $pl->jersey_number : null,
                ];
            }, array_values( $players_by_id ) ),
            'half_length'   => (int) $prep->half_length_minutes,
            'state'         => $state,
            'home_score'    => $home_score,
            'away_score'    => $away_score,
            'execution_id'  => $execution_id,
        ];
        ?>
        <script type="application/json" id="tt-mexec-bootstrap"><?php echo wp_json_encode( $bootstrap ); ?></script>
        <?php
    }

    private static function enqueueViewAssets( int $activity_id, ?object $execution ): void {
        wp_enqueue_style(
            'tt-match-execution',
            TT_PLUGIN_URL . 'assets/css/frontend-match-execution.css',
            [],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-match-execution',
            TT_PLUGIN_URL . 'assets/js/frontend-match-execution.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-match-execution', 'TT_MATCH_EXECUTION', [
            'rest_url'    => esc_url_raw( rest_url( 'talenttrack/v1/match-execution/' . $activity_id . '/' ) ),
            'rest_nonce'  => wp_create_nonce( 'wp_rest' ),
            'activity_id' => $activity_id,
            'i18n'        => [
                'start'             => __( 'Start', 'talenttrack' ),
                'pause'             => __( 'Pause', 'talenttrack' ),
                'resume'            => __( 'Resume', 'talenttrack' ),
                'start_match'       => __( 'Start match', 'talenttrack' ),
                'end_first_half'    => __( 'End first half', 'talenttrack' ),
                'start_second_half' => __( 'Start second half', 'talenttrack' ),
                'end_match'         => __( 'End match', 'talenttrack' ),
                'match_finished'    => __( 'Return to dashboard', 'talenttrack' ),
                'sub_label_format'  => __( 'Tap a player to swap in %s', 'talenttrack' ),
                'sub_toast_format'  => __( '✓ %1$s on for %2$s · %3$s\'', 'talenttrack' ),
                'undo'              => __( 'Undo', 'talenttrack' ),
                'queue_pending'     => __( 'Offline — actions queued', 'talenttrack' ),
                'connection_back'   => __( 'Back online — syncing…', 'talenttrack' ),
                'half_label_first'  => __( 'First half', 'talenttrack' ),
                'half_label_second' => __( 'Second half', 'talenttrack' ),
                'half_label_break'  => __( 'Half time', 'talenttrack' ),
                'half_label_pending'=> __( 'Kickoff pending', 'talenttrack' ),
                'half_label_final'  => __( 'Final', 'talenttrack' ),
            ],
        ] );
    }

    private static function loadActivity( int $activity_id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.id, a.team_id, a.title, a.session_date, a.activity_type_key,
                    a.opponent, a.home_away, a.kickoff_time,
                    t.name AS team_name
               FROM {$wpdb->prefix}tt_activities a
               LEFT JOIN {$wpdb->prefix}tt_teams t ON t.id = a.team_id AND t.club_id = a.club_id
              WHERE a.id = %d AND a.club_id = %d",
            $activity_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * @param list<int> $ids
     * @return array<int, object>
     */
    private static function loadPlayersById( array $ids ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
        if ( empty( $ids ) ) return [];
        global $wpdb;
        $in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_players WHERE id IN ($in) AND club_id = %d",
            array_merge( $ids, [ CurrentClub::id() ] )
        );
        $rows = $wpdb->get_results( $sql );
        $out = [];
        foreach ( (array) $rows as $r ) $out[ (int) $r->id ] = $r;
        return $out;
    }

    /**
     * Three-letter uppercase abbreviation for the team-name label above
     * each score stepper. Falls back to first 3 chars of the name if no
     * spaces; "—" when name is empty.
     */
    private static function abbreviate( string $name ): string {
        $name = trim( $name );
        if ( $name === '' || $name === '—' ) return '—';
        // Strip punctuation, split on whitespace.
        $clean = preg_replace( '/[^\p{L}\p{N}\s]/u', '', $name );
        $parts = preg_split( '/\s+/', (string) $clean, -1, PREG_SPLIT_NO_EMPTY );
        if ( count( $parts ) >= 2 ) {
            return strtoupper( substr( $parts[0], 0, 1 ) . substr( $parts[1], 0, 1 ) . substr( $parts[ count( $parts ) - 1 ] !== $parts[1] ? $parts[ count( $parts ) - 1 ] : '', 0, 1 ) );
        }
        return strtoupper( substr( $clean, 0, 3 ) );
    }
}
