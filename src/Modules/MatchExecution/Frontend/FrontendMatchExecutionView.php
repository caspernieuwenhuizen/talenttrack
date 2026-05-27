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
 * FrontendMatchExecutionView (#847) — full-screen mobile-first live-
 * match surface that the assistant coach runs from a phone on the
 * sideline.
 *
 * Hard dependency on Match Prep (#838) — if no prep row exists for
 * the activity, the view renders a "plan first" notice with a link to
 * the prep wizard and refuses to start.
 *
 * Layout budget at 360×640: header (40) + score (48) + timer (44) +
 * specific-goals (20 + 3×48) + subs (20 + 5×40) + sticky action (60)
 * = 576px → 64px headroom for the OS chrome.
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
            $prep_url = add_query_arg( [
                'tt_view'     => 'match-prep',
                'activity_id' => $activity_id,
            ], remove_query_arg( [ 'tt_view', 'activity_id' ] ) );
            echo '<p class="tt-notice tt-notice-warning">'
                . esc_html__( 'Plan this match first before you can run it.', 'talenttrack' )
                . ' <a class="tt-btn tt-btn-primary" href="' . esc_url( $prep_url ) . '">'
                . esc_html__( 'Plan match prep', 'talenttrack' )
                . '</a></p>';
            return;
        }

        $exec_repo   = new MatchExecutionRepository();
        $execution   = $exec_repo->findByActivity( $activity_id );
        $execution_id = $execution ? (int) $execution->id : 0;

        $availability    = $prep_repo->listAvailability( (int) $prep->id );
        $lineup          = $prep_repo->listLineup( (int) $prep->id );
        $player_goals    = $prep_repo->listPlayerGoals( (int) $prep->id );

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

        $specific_goal_ids = [];
        foreach ( $player_goals as $g ) {
            if ( ! empty( $g->is_specific_goal ) ) $specific_goal_ids[] = (int) $g->player_id;
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

        $home_label = ( ( $activity->home_away ?? '' ) === 'home' ) ? __( 'Home', 'talenttrack' ) : ( (string) ( $activity->team_name ?? '' ) );
        $away_label = (string) ( $activity->opponent ?? '—' );

        $home_score = $execution ? (int) $execution->home_score : 0;
        $away_score = $execution ? (int) $execution->away_score : 0;
        $state      = $execution ? (string) $execution->state : 'not_started';
        ?>
        <div class="tt-mexec" data-activity-id="<?php echo (int) $activity_id; ?>" data-state="<?php echo esc_attr( $state ); ?>" data-half-length="<?php echo (int) $prep->half_length_minutes; ?>">
            <header class="tt-mexec-header">
                <h1><?php echo esc_html( sprintf( '%s · %s', $home_label, $away_label ) ); ?></h1>
                <p class="tt-mexec-meta"><?php echo esc_html( (string) ( $activity->session_date ?? '' ) ); ?></p>
            </header>

            <section class="tt-mexec-score" aria-label="<?php esc_attr_e( 'Score', 'talenttrack' ); ?>">
                <div class="tt-mexec-team-block">
                    <span class="tt-mexec-team-name"><?php echo esc_html( $home_label ); ?></span>
                    <div class="tt-mexec-score-stepper">
                        <button type="button" class="tt-mexec-step" data-tt-mexec-score="home" data-tt-mexec-delta="-1" aria-label="<?php esc_attr_e( 'Decrease home score', 'talenttrack' ); ?>">−</button>
                        <span class="tt-mexec-score-value" data-tt-mexec-home-score><?php echo (int) $home_score; ?></span>
                        <button type="button" class="tt-mexec-step" data-tt-mexec-score="home" data-tt-mexec-delta="+1" aria-label="<?php esc_attr_e( 'Increase home score', 'talenttrack' ); ?>">+</button>
                    </div>
                </div>
                <div class="tt-mexec-team-block">
                    <span class="tt-mexec-team-name"><?php echo esc_html( $away_label ); ?></span>
                    <div class="tt-mexec-score-stepper">
                        <button type="button" class="tt-mexec-step" data-tt-mexec-score="away" data-tt-mexec-delta="-1" aria-label="<?php esc_attr_e( 'Decrease away score', 'talenttrack' ); ?>">−</button>
                        <span class="tt-mexec-score-value" data-tt-mexec-away-score><?php echo (int) $away_score; ?></span>
                        <button type="button" class="tt-mexec-step" data-tt-mexec-score="away" data-tt-mexec-delta="+1" aria-label="<?php esc_attr_e( 'Increase away score', 'talenttrack' ); ?>">+</button>
                    </div>
                </div>
            </section>

            <section class="tt-mexec-timer" aria-label="<?php esc_attr_e( 'Match timer', 'talenttrack' ); ?>">
                <span class="tt-mexec-timer-half" data-tt-mexec-half-label>—</span>
                <span class="tt-mexec-timer-clock" data-tt-mexec-clock>00:00</span>
                <button type="button" class="tt-mexec-timer-toggle" data-tt-mexec-timer-toggle><?php esc_html_e( 'Start', 'talenttrack' ); ?></button>
            </section>

            <section class="tt-mexec-goals" aria-label="<?php esc_attr_e( 'Specific goals', 'talenttrack' ); ?>">
                <h2><?php esc_html_e( 'Specific goals', 'talenttrack' ); ?></h2>
                <?php if ( empty( $specific_goal_ids ) ) : ?>
                    <p class="tt-mexec-empty"><?php esc_html_e( 'No players flagged with a specific goal in the match prep.', 'talenttrack' ); ?></p>
                <?php else : ?>
                    <ul class="tt-mexec-goal-list">
                        <?php foreach ( $specific_goal_ids as $pid ) :
                            $pl = $players_by_id[ $pid ] ?? null;
                            if ( ! $pl ) continue;
                            $count = (int) ( $goal_counts[ $pid ] ?? 0 );
                            ?>
                            <li data-tt-mexec-goal-row data-player-id="<?php echo (int) $pid; ?>">
                                <span class="tt-mexec-goal-name">! <?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></span>
                                <button type="button" class="tt-mexec-goal-count" data-tt-mexec-goal-inc aria-label="<?php esc_attr_e( 'Tap to add one (long-press to remove last)', 'talenttrack' ); ?>"><?php echo (int) $count; ?>×</button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="tt-mexec-subs" aria-label="<?php esc_attr_e( 'Substitutes', 'talenttrack' ); ?>">
                <h2><?php esc_html_e( 'Bench', 'talenttrack' ); ?></h2>
                <?php if ( empty( $bench_ids ) ) : ?>
                    <p class="tt-mexec-empty"><?php esc_html_e( 'No bench players available.', 'talenttrack' ); ?></p>
                <?php else : ?>
                    <ul class="tt-mexec-bench-list">
                        <?php foreach ( $bench_ids as $pid ) :
                            $pl = $players_by_id[ $pid ] ?? null;
                            if ( ! $pl ) continue;
                            ?>
                            <li data-tt-mexec-bench data-player-id="<?php echo (int) $pid; ?>">
                                <span><?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?></span>
                                <button type="button" class="tt-mexec-sub-on" data-tt-mexec-sub-on aria-label="<?php esc_attr_e( 'Bring on', 'talenttrack' ); ?>">→</button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="tt-mexec-onpitch" hidden data-tt-mexec-onpitch-section>
                <h2><?php esc_html_e( 'On pitch', 'talenttrack' ); ?></h2>
                <ul class="tt-mexec-onpitch-list" data-tt-mexec-onpitch-list></ul>
            </section>

            <footer class="tt-mexec-bottom">
                <button type="button" class="tt-mexec-primary" data-tt-mexec-state-action>—</button>
                <span class="tt-mexec-status" data-tt-mexec-status></span>
            </footer>
        </div>

        <?php
        // Embed initial state for the JS bootstrap.
        $bootstrap = [
            'starting_xi_half1' => array_values( array_filter( array_map( 'intval', $starting_xi_half1 ) ) ),
            'bench'             => array_values( array_filter( array_map( 'intval', $bench_ids ) ) ),
            'players'           => array_map( function( $pl ) {
                return [
                    'id'   => (int) $pl->id,
                    'name' => (string) QueryHelpers::player_display_name( $pl ),
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
                'end_first_half'    => __( 'End 1st half', 'talenttrack' ),
                'start_second_half' => __( 'Start 2nd half', 'talenttrack' ),
                'end_match'         => __( 'End match', 'talenttrack' ),
                'match_finished'    => __( 'Match finished', 'talenttrack' ),
                'sub_label_format'  => __( 'Who comes off for %s?', 'talenttrack' ),
                'sub_toast_format'  => __( '✓ %1$s on for %2$s · %3$s\'', 'talenttrack' ),
                'undo'              => __( 'Undo', 'talenttrack' ),
                'queue_pending'     => __( 'Offline — actions queued', 'talenttrack' ),
                'connection_back'   => __( 'Back online — syncing…', 'talenttrack' ),
                'half_label_first'  => __( '1e', 'talenttrack' ),
                'half_label_second' => __( '2e', 'talenttrack' ),
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
}
