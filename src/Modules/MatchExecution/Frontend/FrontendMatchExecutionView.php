<?php
namespace TT\Modules\MatchExecution\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchExecution\Services\MatchEventFeedService;
use TT\Modules\MatchExecution\Services\PitchLayoutService;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;
use TT\Shared\Club\ClubIdentity;
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

        // #1864 — per-player logged minutes, read from the persisted
        // tt_attendance.minutes_played the finish/finalize step writes
        // (same source the minutes report reads). Empty until the match
        // is ended; players without a value get no chip.
        $minutes_by_id = $exec_repo->loggedMinutesByActivity( $activity_id );

        // #1713 — vertical positional pitch (first-half starting XI) and
        // the chronological "Live verloop" feed. Both come from domain
        // services so the REST endpoints and this render agree.
        $slot_to_player_h1 = [];
        foreach ( $lineup as $l ) {
            if ( (int) $l->half === 1 ) {
                $slot = (int) $l->slot_number;
                $pid  = (int) $l->player_id;
                if ( $slot >= 1 && $slot <= 11 && $pid > 0 ) {
                    $slot_to_player_h1[ $slot ] = $pid;
                }
            }
        }
        $pitch_meta = [];
        foreach ( $players_by_id as $ppid => $ppl ) {
            $pitch_meta[ (int) $ppid ] = [
                'name'   => (string) QueryHelpers::player_display_name( $ppl ),
                'jersey' => $ppl->jersey_number !== null ? (int) $ppl->jersey_number : null,
            ];
        }
        $pitch_slots = ( new PitchLayoutService() )->positionedXi(
            (int) ( $prep->formation_template_id ?? 0 ),
            $slot_to_player_h1,
            $pitch_meta
        );
        $event_feed = ( new MatchEventFeedService() )->feedForActivity( $activity_id );
        FrontendBreadcrumbs::fromDashboard( __( 'Match execution', 'talenttrack' ) );
        parent::enqueueAssets();
        self::enqueueViewAssets( $activity_id, $execution );

        // v4.12.11 (#1024) — score-box labels:
        //   - Home abbreviation is the club code (`tt_config['club_short_code']`),
        //     not the per-team name. The home team in the score box represents
        //     the club; "Hedel JO14-1" and "Hedel JO15-1" both render as
        //     `HED`.
        //   - Away label uses `$activity->opponent` when set; falls back to
        //     a localised `OPP` placeholder so the score box never shows the
        //     unreadable em-dash mid-match.
        //   - Header line drops the `vs —` pair when no opponent is set;
        //     the row reads `<Team> · <Date>` instead.
        $home_team_name = (string) ( $activity->team_name ?? '' );
        $home_label = ( ( $activity->home_away ?? '' ) === 'home' )
            ? ( $home_team_name !== '' ? $home_team_name : __( 'Home', 'talenttrack' ) )
            : $home_team_name;
        $opponent_raw = trim( (string) ( $activity->opponent ?? '' ) );
        $has_opponent = $opponent_raw !== '';
        $away_label = $has_opponent ? $opponent_raw : '';

        $home_abbr = ClubIdentity::shortCode();
        $away_abbr = $has_opponent
            ? self::abbreviate( $opponent_raw )
            : __( 'OPP', 'talenttrack' );

        $home_score = $execution ? (int) $execution->home_score : 0;
        $away_score = $execution ? (int) $execution->away_score : 0;
        $state      = $execution ? (string) $execution->state : MatchExecutionState::NOT_STARTED;

        $session_date = (string) ( $activity->session_date ?? '' );
        $kickoff      = (string) ( $activity->kickoff_time ?? '' );
        $when         = trim( $session_date . ( $kickoff !== '' ? ' ' . substr( $kickoff, 0, 5 ) : '' ) );

        // #1473 — starting the match is gated to match day (server date).
        // Before then the Start CTA + timer button render disabled with a
        // dated tooltip; the start transition is also rejected server-side.
        // #1520 — shared match-day rule so this lock and the activity
        // detail-page "Start match" button can't drift.
        $is_match_day   = MatchExecutionState::isMatchDay( $session_date );
        $start_locked   = ( ! $is_match_day && $state === MatchExecutionState::NOT_STARTED );
        $start_lock_msg = '';
        if ( $start_locked ) {
            $start_lock_msg = sprintf(
                /* translators: %s: localized match date, e.g. "14 Jun" */
                __( 'Available on match day (%s)', 'talenttrack' ),
                $session_date !== '' ? (string) wp_date( 'j M', (int) strtotime( $session_date ) ) : ''
            );
        }
        ?>
        <div class="tt-mexec" data-activity-id="<?php echo (int) $activity_id; ?>" data-state="<?php echo esc_attr( $state ); ?>" data-half-length="<?php echo (int) $prep->half_length_minutes; ?>">

            <header class="tt-mexec-header">
                <p class="tt-mexec-header-meta">
                    <span class="tt-mexec-team-name"><?php echo esc_html( $home_label ); ?></span>
                    <?php if ( $has_opponent ) : ?>
                        <span class="tt-mexec-vs"><?php esc_html_e( 'vs', 'talenttrack' ); ?></span>
                        <span class="tt-mexec-team-name"><?php echo esc_html( $away_label ); ?></span>
                    <?php endif; ?>
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
                <button type="button" class="tt-mexec-timer-btn" data-tt-mexec-timer-toggle<?php echo $start_locked ? ' disabled title="' . esc_attr( $start_lock_msg ) . '"' : ''; ?>><?php esc_html_e( 'Start', 'talenttrack' ); ?></button>
            </section>

            <?php // #1684 — match-summary KPI strip (2026 chrome). Mirrors
                  // the mockup's "Minuten gelogd" footer with the real
                  // figures this view already has: how many players are
                  // flagged for tracking, and how many are available to log
                  // minutes for. Logic-free — both counts are computed above.
                  $tracked_count   = count( $specific_goal_ids );
                  $available_count = count( $available_ids );
                  ?>
            <section class="tt-mexec-kpis" aria-label="<?php esc_attr_e( 'Match summary', 'talenttrack' ); ?>">
                <?php
                echo \TT\Shared\Frontend\Components\FrontendAppChrome::kpiTile( [
                    'label' => __( 'Tracked players', 'talenttrack' ),
                    'value' => (string) $tracked_count,
                ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                echo \TT\Shared\Frontend\Components\FrontendAppChrome::kpiTile( [
                    'label' => __( 'Available squad', 'talenttrack' ),
                    'value' => (string) $available_count,
                ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                ?>
            </section>

            <?php // #1713 — vertical positional pitch: the first-half
                  // starting XI laid out by position. Coordinates are the
                  // shared slot layout (% of pitch), positioned via an
                  // inline left/top that cannot live in static CSS. ?>
            <section class="tt-mxp-pitch-section" aria-label="<?php esc_attr_e( 'Starting line-up on the pitch', 'talenttrack' ); ?>">
                <div class="tt-mexec-section-head">
                    <h2 class="tt-mexec-section-title"><?php esc_html_e( 'Line-up', 'talenttrack' ); ?></h2>
                </div>
                <div class="tt-mxp-pitch" role="img" aria-label="<?php esc_attr_e( 'Vertical football pitch with the starting eleven by position', 'talenttrack' ); ?>">
                    <?php foreach ( $pitch_slots as $slot ) :
                        $label  = (string) $slot['label'];
                        $name   = (string) $slot['player_name'];
                        $jersey = $slot['jersey'];
                        $filled = (int) $slot['player_id'] > 0 && $name !== '';
                        // Surname-first short label keeps the slot legible at 360px.
                        $short  = $name !== '' ? self::pitchShortName( $name ) : '';
                        ?>
                        <div class="tt-mxp-slot<?php echo $filled ? '' : ' tt-mxp-slot-empty'; ?>"
                             style="left:<?php echo esc_attr( (string) (float) $slot['x'] ); ?>%; top:<?php echo esc_attr( (string) (float) $slot['y'] ); ?>%;"<?php /* tt-inline-ok */ ?>>
                            <span class="tt-mxp-slot-badge">
                                <?php echo esc_html( $jersey !== null ? (string) (int) $jersey : $label ); ?>
                            </span>
                            <span class="tt-mxp-slot-name">
                                <?php echo esc_html( $filled ? $short : $label ); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <?php // #1713 — chronological event log ("Live verloop"):
                  // goals + substitutions merged, time-ordered, each row
                  // carrying a minute, a type chip (icon + text, not colour
                  // alone) and a running-score chip. Cards are not modelled. ?>
            <section class="tt-mxp-log-section" aria-label="<?php esc_attr_e( 'Live progress', 'talenttrack' ); ?>">
                <div class="tt-mexec-section-head">
                    <h2 class="tt-mexec-section-title"><?php esc_html_e( 'Live progress', 'talenttrack' ); ?></h2>
                    <span class="tt-mexec-section-count"><?php echo esc_html( sprintf(
                        /* translators: %d: number of logged match events */
                        _n( '%d event', '%d events', count( $event_feed ), 'talenttrack' ),
                        count( $event_feed )
                    ) ); ?></span>
                </div>
                <?php if ( empty( $event_feed ) ) : ?>
                    <p class="tt-mexec-empty"><?php esc_html_e( 'No goals or substitutions logged yet.', 'talenttrack' ); ?></p>
                <?php else : ?>
                    <ol class="tt-mxp-log">
                        <?php foreach ( $event_feed as $ev ) :
                            $type    = (string) $ev['type'];
                            $minute  = (int) $ev['minute'];
                            $half    = (int) $ev['half'];
                            $is_goal = ( $type === 'goal' );
                            if ( $is_goal ) {
                                $type_label = __( 'Goal scored', 'talenttrack' );
                                $icon       = '⚽';
                                $detail     = (string) $ev['player_name'];
                            } else {
                                $type_label = __( 'Substitution', 'talenttrack' );
                                $icon       = '⇄';
                                $detail     = sprintf(
                                    /* translators: 1: player coming on, 2: player coming off */
                                    __( '%1$s on for %2$s', 'talenttrack' ),
                                    (string) $ev['player_on_name'],
                                    (string) $ev['player_off_name']
                                );
                            }
                            $minute_label = sprintf(
                                /* translators: 1: half number, 2: minute within the half */
                                __( 'H%1$d %2$d\'', 'talenttrack' ),
                                $half,
                                $minute
                            );
                            ?>
                            <li class="tt-mxp-log-row tt-mxp-log-row--<?php echo esc_attr( $type ); ?>">
                                <span class="tt-mxp-log-minute"><?php echo esc_html( $minute_label ); ?></span>
                                <span class="tt-mxp-log-chip tt-mxp-log-chip--<?php echo esc_attr( $type ); ?>">
                                    <span class="tt-mxp-log-icon" aria-hidden="true"><?php echo esc_html( $icon ); ?></span>
                                    <span class="tt-mxp-log-type"><?php echo esc_html( $type_label ); ?></span>
                                </span>
                                <span class="tt-mxp-log-detail"><?php echo esc_html( $detail ); ?></span>
                                <?php if ( $is_goal ) : ?>
                                    <span class="tt-mxp-log-score" aria-label="<?php esc_attr_e( 'Running score', 'talenttrack' ); ?>">
                                        <?php echo esc_html( sprintf( '%d–%d', (int) $ev['running_home'], (int) $ev['running_away'] ) ); ?>
                                    </span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
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
                                    <?php $mins = $minutes_by_id[ $pid ] ?? null; ?>
                                    <?php if ( $mins !== null ) : ?>
                                        <span class="tt-mexec-goal-chip tt-mexec-minutes-chip" aria-label="<?php esc_attr_e( 'Logged minutes', 'talenttrack' ); ?>"><?php echo esc_html( sprintf( "%d'", $mins ) ); ?></span>
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
                                <?php $mins = $minutes_by_id[ $pid ] ?? null; ?>
                                <?php if ( $mins !== null ) : ?>
                                    <div class="tt-mexec-player-goals">
                                        <span class="tt-mexec-goal-chip tt-mexec-minutes-chip" aria-label="<?php esc_attr_e( 'Logged minutes', 'talenttrack' ); ?>"><?php echo esc_html( sprintf( "%d'", $mins ) ); ?></span>
                                    </div>
                                <?php endif; ?>
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

            <?php // #1033 — post-match status bar. PENDING_REVIEW shows
                  // a visible "ended · pending review" pill + an explicit
                  // Finalize CTA. FINALIZED shows only the locked pill —
                  // score / goal / sub endpoints already refuse writes
                  // server-side via assertEditable(), but the visible
                  // affordances stay so the operator understands why.
                  ?>
            <?php if ( $state === MatchExecutionState::PENDING_REVIEW || $state === MatchExecutionState::FINALIZED ) : ?>
                <section class="tt-mexec-post-match" aria-label="<?php esc_attr_e( 'Post-match status', 'talenttrack' ); ?>">
                    <p class="tt-mexec-state-pill tt-mexec-state-pill--<?php echo esc_attr( $state ); ?>">
                        <?php
                        if ( $state === MatchExecutionState::PENDING_REVIEW ) {
                            esc_html_e( 'Match ended · pending review', 'talenttrack' );
                        } else {
                            esc_html_e( 'Finalized — read-only', 'talenttrack' );
                        }
                        ?>
                    </p>
                    <?php if ( $state === MatchExecutionState::PENDING_REVIEW ) : ?>
                        <button type="button" class="tt-mexec-finalize-btn" data-tt-mexec-finalize>
                            <?php esc_html_e( 'Finalize match', 'talenttrack' ); ?>
                        </button>
                        <p class="tt-mexec-finalize-help">
                            <?php esc_html_e( 'Locks the match. Goals, subs, and score cannot be edited after.', 'talenttrack' ); ?>
                        </p>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php // #1049 — late-event affordances. Two collapsible
                  // panels in PENDING_REVIEW for adding the goal or
                  // sub the coach forgot to tap during the live match.
                  // Existing endpoints accept the writes; this is
                  // purely the view affordance + minute validator.
                  ?>
            <?php if ( $state === MatchExecutionState::PENDING_REVIEW ) :
                $half_length    = (int) $prep->half_length_minutes;
                $minute_max     = $half_length + 10; // stoppage allowance per locked default.
                $picker_players = $players_by_id;
                ksort( $picker_players );
                ?>
                <section class="tt-mexec-late-event" aria-label="<?php esc_attr_e( 'Add late events', 'talenttrack' ); ?>">
                    <header class="tt-mexec-late-event-head">
                        <h2 class="tt-mexec-late-event-title">
                            <?php esc_html_e( 'Add late events', 'talenttrack' ); ?>
                        </h2>
                        <span class="tt-mexec-late-event-hint">
                            <?php
                            printf(
                                /* translators: %d = max minute accepted (half length + 10 stoppage) */
                                esc_html__( 'Minute 0–%d', 'talenttrack' ),
                                (int) $minute_max
                            );
                            ?>
                        </span>
                    </header>

                    <details class="tt-mexec-late-event-panel">
                        <summary class="tt-mexec-late-event-summary">
                            <?php esc_html_e( '+ Add late goal', 'talenttrack' ); ?>
                        </summary>
                        <form class="tt-mexec-late-event-form" data-tt-mexec-late-goal-form>
                            <label class="tt-mexec-late-event-field">
                                <span><?php esc_html_e( 'Player', 'talenttrack' ); ?></span>
                                <select name="player_id" required>
                                    <option value=""><?php esc_html_e( '— pick player —', 'talenttrack' ); ?></option>
                                    <?php foreach ( $picker_players as $pid => $pl ) : ?>
                                        <option value="<?php echo (int) $pid; ?>">
                                            <?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="tt-mexec-late-event-field">
                                <span><?php esc_html_e( 'Half', 'talenttrack' ); ?></span>
                                <select name="half" required>
                                    <option value="1"><?php esc_html_e( '1st half', 'talenttrack' ); ?></option>
                                    <option value="2"><?php esc_html_e( '2nd half', 'talenttrack' ); ?></option>
                                </select>
                            </label>
                            <label class="tt-mexec-late-event-field">
                                <span><?php esc_html_e( 'Minute', 'talenttrack' ); ?></span>
                                <input type="number" inputmode="numeric" name="minute"
                                       min="0" max="<?php echo (int) $minute_max; ?>"
                                       placeholder="<?php echo esc_attr( (string) $minute_max ); ?>" required />
                            </label>
                            <button type="submit" class="tt-mexec-late-event-submit">
                                <?php esc_html_e( 'Log goal', 'talenttrack' ); ?>
                            </button>
                        </form>
                    </details>

                    <details class="tt-mexec-late-event-panel">
                        <summary class="tt-mexec-late-event-summary">
                            <?php esc_html_e( '+ Add late substitution', 'talenttrack' ); ?>
                        </summary>
                        <form class="tt-mexec-late-event-form" data-tt-mexec-late-sub-form>
                            <label class="tt-mexec-late-event-field">
                                <span><?php esc_html_e( 'Off (came off)', 'talenttrack' ); ?></span>
                                <select name="player_off" required>
                                    <option value=""><?php esc_html_e( '— pick player —', 'talenttrack' ); ?></option>
                                    <?php foreach ( $picker_players as $pid => $pl ) : ?>
                                        <option value="<?php echo (int) $pid; ?>">
                                            <?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="tt-mexec-late-event-field">
                                <span><?php esc_html_e( 'On (came on)', 'talenttrack' ); ?></span>
                                <select name="player_on" required>
                                    <option value=""><?php esc_html_e( '— pick player —', 'talenttrack' ); ?></option>
                                    <?php foreach ( $picker_players as $pid => $pl ) : ?>
                                        <option value="<?php echo (int) $pid; ?>">
                                            <?php echo esc_html( QueryHelpers::player_display_name( $pl ) ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label class="tt-mexec-late-event-field">
                                <span><?php esc_html_e( 'Half', 'talenttrack' ); ?></span>
                                <select name="half" required>
                                    <option value="1"><?php esc_html_e( '1st half', 'talenttrack' ); ?></option>
                                    <option value="2"><?php esc_html_e( '2nd half', 'talenttrack' ); ?></option>
                                </select>
                            </label>
                            <label class="tt-mexec-late-event-field">
                                <span><?php esc_html_e( 'Minute', 'talenttrack' ); ?></span>
                                <input type="number" inputmode="numeric" name="minute"
                                       min="0" max="<?php echo (int) $minute_max; ?>"
                                       placeholder="<?php echo esc_attr( (string) $minute_max ); ?>" required />
                            </label>
                            <button type="submit" class="tt-mexec-late-event-submit">
                                <?php esc_html_e( 'Log substitution', 'talenttrack' ); ?>
                            </button>
                        </form>
                    </details>
                </section>
            <?php endif; ?>

            <footer class="tt-mexec-footer">
                <div class="tt-mexec-footer-inner">
                    <button type="button" class="tt-mexec-footer-cta" data-tt-mexec-state-action data-action="start-match"<?php echo $start_locked ? ' disabled title="' . esc_attr( $start_lock_msg ) . '"' : ''; ?>><?php esc_html_e( 'Start match', 'talenttrack' ); ?></button>
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
            // #1473 — match-day gate for the Start CTA / timer.
            'is_match_day'   => $is_match_day,
            'start_lock_msg' => $start_lock_msg,
        ];
        ?>
        <script type="application/json" id="tt-mexec-bootstrap"><?php echo wp_json_encode( $bootstrap ); ?></script>
        <style>
            /* #1033 — post-match status pill + Finalize CTA. Mobile-first
             * 48px touch target on the button; visible warning colour on
             * the pending pill so it doesn\'t get missed in the coach\'s
             * scroll past the score / timer. */
            .tt-mexec-post-match {
                margin: 12px 0;
                padding: 12px 14px;
                border: 1px solid #e3e6ea;
                border-radius: 8px;
                background: #fff;
                display: flex;
                flex-direction: column;
                gap: 8px;
                align-items: stretch;
            }
            .tt-mexec-state-pill {
                margin: 0;
                display: inline-block;
                align-self: flex-start;
                padding: 4px 10px;
                border-radius: 999px;
                font-size: 12px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.4px;
            }
            .tt-mexec-state-pill--pending_review { background: #fff4d4; color: #92651b; }
            .tt-mexec-state-pill--finalized     { background: #e6e9ed; color: #5b6e75; }
            .tt-mexec-finalize-btn {
                display: block;
                width: 100%;
                min-height: 48px;
                padding: 12px 16px;
                border-radius: 8px;
                border: 1.5px solid #d63638;
                background: #d63638;
                color: #fff;
                font: inherit;
                font-size: 15px;
                font-weight: 700;
                cursor: pointer;
            }
            .tt-mexec-finalize-btn:hover { background: #b32a2c; border-color: #b32a2c; }
            .tt-mexec-finalize-btn:disabled { background: #b0b3b6; border-color: #b0b3b6; cursor: not-allowed; }
            .tt-mexec-finalize-help {
                margin: 0;
                font-size: 12px;
                color: #5b6e75;
                line-height: 1.4;
            }
            /* #1049 — late-event affordances. Two collapsible panels
             * for adding retroactive goals/subs the coach forgot to
             * tap live. Dashed warn-border so it reads as a corrective
             * surface, not a primary action. */
            .tt-mexec-late-event {
                margin: 12px 0;
                padding: 12px 14px;
                border: 2px dashed #c75c1f;
                border-radius: 8px;
                background: #fff;
                display: flex;
                flex-direction: column;
                gap: 10px;
            }
            .tt-mexec-late-event-head {
                display: flex;
                align-items: baseline;
                justify-content: space-between;
                gap: 8px;
            }
            .tt-mexec-late-event-title {
                margin: 0;
                font-size: 13px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.4px;
                color: #c75c1f;
            }
            .tt-mexec-late-event-hint {
                font-size: 11px;
                color: #5b6e75;
            }
            .tt-mexec-late-event-panel {
                border: 1px solid #f5dba0;
                background: #fff8e1;
                border-radius: 6px;
                padding: 0;
            }
            .tt-mexec-late-event-summary {
                padding: 12px 14px;
                cursor: pointer;
                font-weight: 600;
                color: #8a5e0a;
                min-height: 48px;
                display: flex;
                align-items: center;
                list-style: none;
            }
            .tt-mexec-late-event-summary::-webkit-details-marker { display: none; }
            .tt-mexec-late-event-panel[open] .tt-mexec-late-event-summary {
                border-bottom: 1px solid #f5dba0;
            }
            .tt-mexec-late-event-form {
                display: flex;
                flex-direction: column;
                gap: 10px;
                padding: 12px 14px;
            }
            .tt-mexec-late-event-field {
                display: flex;
                flex-direction: column;
                gap: 4px;
            }
            .tt-mexec-late-event-field > span {
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 0.4px;
                color: #5b6e75;
            }
            .tt-mexec-late-event-field select,
            .tt-mexec-late-event-field input[type="number"] {
                font: inherit;
                font-size: 16px;
                padding: 10px 12px;
                border: 1px solid #d6dadd;
                border-radius: 6px;
                background: #fff;
                color: #1a1d21;
                min-height: 48px;
                width: 100%;
            }
            .tt-mexec-late-event-submit {
                margin-top: 4px;
                min-height: 48px;
                padding: 12px 16px;
                border-radius: 6px;
                border: 1.5px solid #8a5e0a;
                background: #8a5e0a;
                color: #fff;
                font: inherit;
                font-size: 14px;
                font-weight: 700;
                cursor: pointer;
            }
            .tt-mexec-late-event-submit:hover { background: #6e4a08; border-color: #6e4a08; }
            .tt-mexec-late-event-submit:disabled { background: #b0b3b6; border-color: #b0b3b6; cursor: not-allowed; }
        </style>
        <script>
        (function () {
            var cfg = window.TT_MATCH_EXECUTION || {};
            var errPrefix = <?php echo wp_json_encode( __( 'Could not save:', 'talenttrack' ) ); ?>;

            // Finalize button.
            var finalizeBtn = document.querySelector( '[data-tt-mexec-finalize]' );
            if ( finalizeBtn ) {
                var confirmMsg = <?php echo wp_json_encode( __( 'Finalize this match? Goals, subs, and score cannot be edited after.', 'talenttrack' ) ); ?>;
                var finErrPrefix = <?php echo wp_json_encode( __( 'Could not finalize:', 'talenttrack' ) ); ?>;
                finalizeBtn.addEventListener( 'click', function () {
                    if ( ! window.confirm( confirmMsg ) ) return;
                    finalizeBtn.disabled = true;
                    fetch( cfg.rest_url + 'finalize', {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.rest_nonce },
                        body: '{}'
                    } )
                        .then( function ( r ) {
                            if ( r.ok ) { window.location.reload(); return; }
                            return r.json().then( function ( j ) {
                                finalizeBtn.disabled = false;
                                var msg = ( j && j.errors && j.errors[0] && j.errors[0].message ) || ( finErrPrefix + ' ' + r.status );
                                window.alert( msg );
                            } );
                        } )
                        .catch( function () {
                            finalizeBtn.disabled = false;
                            window.alert( finErrPrefix + ' network error.' );
                        } );
                } );
            }

            // #1049 — late-event UUIDs are client-generated so the
            // existing offline-queue replay path doesn't double-insert.
            function uuid() {
                if ( window.crypto && crypto.randomUUID ) return crypto.randomUUID();
                return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( c ) {
                    var r = ( Math.random() * 16 ) | 0;
                    var v = c === 'x' ? r : ( r & 0x3 ) | 0x8;
                    return v.toString( 16 );
                } );
            }

            function wireLateForm( form, endpoint, build ) {
                if ( ! form ) return;
                form.addEventListener( 'submit', function ( e ) {
                    e.preventDefault();
                    var body = build( form );
                    if ( ! body ) return;
                    var btn = form.querySelector( '.tt-mexec-late-event-submit' );
                    if ( btn ) btn.disabled = true;
                    fetch( cfg.rest_url + endpoint, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.rest_nonce },
                        body: JSON.stringify( body )
                    } )
                        .then( function ( r ) {
                            if ( r.ok ) { window.location.reload(); return; }
                            return r.json().then( function ( j ) {
                                if ( btn ) btn.disabled = false;
                                var msg = ( j && j.errors && j.errors[0] && j.errors[0].message ) || ( errPrefix + ' ' + r.status );
                                window.alert( msg );
                            } );
                        } )
                        .catch( function () {
                            if ( btn ) btn.disabled = false;
                            window.alert( errPrefix + ' network error.' );
                        } );
                } );
            }

            wireLateForm(
                document.querySelector( '[data-tt-mexec-late-goal-form]' ),
                'goal-event',
                function ( f ) {
                    var pid = parseInt( f.querySelector( '[name="player_id"]' ).value, 10 ) || 0;
                    var half = parseInt( f.querySelector( '[name="half"]' ).value, 10 ) || 0;
                    var minute = parseInt( f.querySelector( '[name="minute"]' ).value, 10 );
                    if ( pid <= 0 || ( half !== 1 && half !== 2 ) ) return null;
                    if ( isNaN( minute ) || minute < 0 ) return null;
                    return { event_uuid: uuid(), player_id: pid, half: half, minute: minute };
                }
            );

            wireLateForm(
                document.querySelector( '[data-tt-mexec-late-sub-form]' ),
                'substitution',
                function ( f ) {
                    var off = parseInt( f.querySelector( '[name="player_off"]' ).value, 10 ) || 0;
                    var on  = parseInt( f.querySelector( '[name="player_on"]' ).value, 10 ) || 0;
                    var half = parseInt( f.querySelector( '[name="half"]' ).value, 10 ) || 0;
                    var minute = parseInt( f.querySelector( '[name="minute"]' ).value, 10 );
                    if ( off <= 0 || on <= 0 || off === on ) return null;
                    if ( half !== 1 && half !== 2 ) return null;
                    if ( isNaN( minute ) || minute < 0 ) return null;
                    return { event_uuid: uuid(), half: half, minute: minute, player_off: off, player_on: on };
                }
            );
        })();
        </script>
        <?php
    }

    private static function enqueueViewAssets( int $activity_id, ?object $execution ): void {
        wp_enqueue_style(
            'tt-match-execution',
            TT_PLUGIN_URL . 'assets/css/frontend-match-execution.css',
            [],
            TT_VERSION
        );
        // #1684 — 2026 "chrome" restyle layers on top of the base sheet.
        // Depends on the shared app-chrome sheet (#1690) so the KPI tile
        // styles + brand tokens are present; loading after the base sheet
        // means its additive rules win without !important.
        wp_enqueue_style(
            'tt-match-execution-2026',
            TT_PLUGIN_URL . 'assets/css/frontend-match-execution-2026.css',
            [ 'tt-match-execution', 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
        // #1713 — vertical positional pitch + chronological event log.
        wp_enqueue_style(
            'tt-match-execution-pitch',
            TT_PLUGIN_URL . 'assets/css/frontend-match-execution-pitch.css',
            [ 'tt-frontend-app-chrome' ],
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
     * Three-letter uppercase abbreviation for the opponent label above
     * the away score stepper. v4.12.11 (#1024): home label now reads
     * from `ClubIdentity::shortCode()`; this helper only services the
     * away column.
     *
     * Strips common age-group / team-number suffixes (`JO13`, `U14`,
     * `-1`) before deriving so `Den Helder JO13` → `DEN`, not `DEN13`.
     * Falls back to a localised `OPP` placeholder when the name is
     * empty — the score-box never renders the unreadable em-dash.
     */
    private static function abbreviate( string $name ): string {
        $name = trim( $name );
        if ( $name === '' ) {
            return __( 'OPP', 'talenttrack' );
        }
        // Strip age-group / team-number suffixes (JO13, U14, O19, MO14, -1, etc.)
        // so `Den Helder JO13` derives from `Den Helder`, not the suffix.
        $name = preg_replace( '/\s*(?:JO|MO|O|U|-)\s*\d+\s*$/iu', '', $name );
        $name = (string) preg_replace( '/-\d+$/u', '', (string) $name );
        $name = trim( (string) $name );
        if ( $name === '' ) {
            return __( 'OPP', 'talenttrack' );
        }
        // Strip punctuation, split on whitespace.
        $clean = preg_replace( '/[^\p{L}\p{N}\s]/u', '', $name );
        $parts = preg_split( '/\s+/', (string) $clean, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $parts ) || count( $parts ) === 0 ) {
            return __( 'OPP', 'talenttrack' );
        }
        if ( count( $parts ) === 1 ) {
            return mb_strtoupper( mb_substr( $parts[0], 0, 3 ) );
        }
        // Two or more parts: take the first letter of each of the first
        // three significant parts (or pad from the last word's letters).
        $abbr = '';
        foreach ( $parts as $part ) {
            $abbr .= mb_substr( $part, 0, 1 );
            if ( mb_strlen( $abbr ) >= 3 ) break;
        }
        if ( mb_strlen( $abbr ) < 3 ) {
            // Pad from the last word so two-word names like "Den Helder"
            // come out as `DEH` rather than `DE`.
            $last = $parts[ count( $parts ) - 1 ];
            $abbr .= mb_substr( $last, 1, 3 - mb_strlen( $abbr ) );
        }
        return mb_strtoupper( $abbr );
    }

    /**
     * #1713 — compact pitch label for a player. Prefers the surname so
     * the slot stays legible at 360px; falls back to the first token
     * when there's only one. Display-only formatting, no business logic.
     */
    private static function pitchShortName( string $name ): string {
        $name = trim( $name );
        if ( $name === '' ) {
            return '';
        }
        $parts = preg_split( '/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $parts ) || count( $parts ) === 0 ) {
            return $name;
        }
        return (string) $parts[ count( $parts ) - 1 ];
    }
}
