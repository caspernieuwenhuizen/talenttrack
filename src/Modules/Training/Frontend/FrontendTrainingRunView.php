<?php
namespace TT\Modules\Training\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Training\Repositories\TrainingPlanRunsRepository;
use TT\Modules\Training\Repositories\TrainingPlansRepository;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * FrontendTrainingRunView (#2499) — running a plan on the pitch.
 *
 * Two jobs behind one route:
 *
 *   `?tt_view=training-run&activity_id=N`  attach a plan to a training
 *   `?tt_view=training-run&id=N`           the sideline view of that run
 *
 * They are one view because they are one errand: a coach walking onto the
 * pitch wants to run tonight's training, and whether a run row already
 * exists is bookkeeping they should not have to know about. Landing on
 * the attach screen for an activity that already has a run is not an
 * error — it redirects to the run, which is what "already attached"
 * should feel like.
 *
 * ## The sideline view is a different kind of screen
 *
 * Everything else in this plugin is read at a desk. This is read at arm's
 * length, outdoors, in the rain, by someone whose attention belongs to
 * fifteen children. So it is deliberately not built like the rest:
 * one block at a time rather than a list, a dark ground so the screen is
 * legible without full brightness, and controls in the thumb zone at
 * 56px rather than the usual 48.
 *
 * ## What is written, and what is not
 *
 * Completing a block writes `actual_duration_minutes` and, for a skip,
 * `was_skipped` — **against the run**, never against the plan. A plan is
 * a document; a run is what happened. The snapshot taken at attach time
 * is what the sideline view reads, so editing the plan mid-session
 * cannot change the session already underway.
 *
 * ## Online-only, by decision D15
 *
 * The epic originally promised network-disabled operation. That was
 * traded away deliberately (epic #2493, D15) and the offline write queue
 * is #2552. This view therefore reports a failed write instead of
 * swallowing it — a coach who loses signal needs to know their block
 * timings did not save, not to discover it on Thursday.
 */
final class FrontendTrainingRunView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_training_plan' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">'
                . esc_html__( 'You do not have permission to run training plans.', 'talenttrack' )
                . '</p>';
            return;
        }

        self::enqueueAssets();

        $run_id      = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
        $activity_id = isset( $_GET['activity_id'] ) ? absint( wp_unslash( $_GET['activity_id'] ) ) : 0;

        $runs = new TrainingPlanRunsRepository();

        // An activity that already has a run goes straight to it. That is
        // the "already attached" case the REST contract returns 200 for,
        // and it should read as continuity, not as a refusal.
        if ( $run_id <= 0 && $activity_id > 0 ) {
            $existing = $runs->findForActivity( $activity_id );
            if ( $existing ) {
                $run_id = (int) $existing->id;
            } else {
                self::renderAttach( $activity_id );
                return;
            }
        }

        if ( $run_id <= 0 ) {
            self::renderNotFound();
            return;
        }

        $run = $runs->findById( $run_id );
        if ( ! $run ) {
            self::renderNotFound();
            return;
        }

        self::renderSideline( $run, $runs );
    }

    private static function renderNotFound(): void {
        FrontendBreadcrumbs::fromDashboard(
            __( 'Not found', 'talenttrack' ),
            [ FrontendBreadcrumbs::viewCrumb( 'training-plans', __( 'Training', 'talenttrack' ) ) ]
        );
        echo '<p class="tt-notice">'
            . esc_html__( 'That training run no longer exists.', 'talenttrack' )
            . '</p>';
    }

    // ---- attach ----------------------------------------------------------

    /**
     * Pick a plan for this training. One screen, Save + Cancel — §3
     * exemption (b): this operates on records that already exist, so it
     * does not need a wizard.
     */
    private static function renderAttach( int $activity_id ): void {
        FrontendBreadcrumbs::fromDashboard(
            __( 'Attach a plan', 'talenttrack' ),
            [ FrontendBreadcrumbs::viewCrumb( 'training-plans', __( 'Training', 'talenttrack' ) ) ]
        );

        self::renderHeader( __( 'Attach a plan to this training', 'talenttrack' ) );

        $activity = self::activity( $activity_id );
        if ( ! $activity ) {
            echo '<p class="tt-notice">' . esc_html__( 'That training no longer exists.', 'talenttrack' ) . '</p>';
            return;
        }

        $team_id = (int) ( $activity->team_id ?? 0 );
        // A team_id filter returns that team's plans *plus* the club-wide
        // templates it can draw on, which is exactly the choice a coach
        // wants here: tonight's plan, or a template to run as-is.
        $plans = ( new TrainingPlansRepository() )->listPlans( [
            'team_id' => $team_id ?: null,
            'limit'   => 50,
        ] );

        echo '<p class="tt-muted">'
            . esc_html__( 'The plan is copied onto this training as it is right now. Editing the plan afterwards will not change what this training recorded.', 'talenttrack' )
            . '</p>';

        if ( ! $plans ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'This team has no training plans yet. Make one first, then come back here to attach it.', 'talenttrack' )
                . '</p>';
            return;
        }

        $cancel_url = RecordLink::detailUrlFor( 'activities', $activity_id );

        self::enqueueAttach( $activity_id );

        // Posts to `POST /training/runs`, which is already the contract
        // that returns **200 with the existing run** rather than 201 when
        // the activity has one. Re-using it here is what makes a double
        // attach read as "already attached" instead of an error, without
        // this screen re-deriving that rule.
        echo '<form class="tt-training-attach" data-tt-attach="' . esc_attr( (string) $activity_id ) . '">';

        echo '<label class="tt-field"><span>' . esc_html__( 'Plan', 'talenttrack' ) . '</span>';
        echo '<select name="plan_id" required>';
        foreach ( $plans as $plan ) {
            printf(
                '<option value="%1$d">%2$s</option>',
                (int) $plan->id,
                esc_html( sprintf(
                    /* translators: 1: plan title, 2: total duration in minutes. */
                    __( '%1$s — %2$d min', 'talenttrack' ),
                    (string) $plan->title,
                    (int) $plan->total_duration_minutes
                ) )
            );
        }
        echo '</select></label>';

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — render() returns escaped HTML.
        echo FormSaveButton::render( [
            'label'      => __( 'Attach plan', 'talenttrack' ),
            'cancel_url' => $cancel_url,
        ] );

        echo '<p class="tt-run__msg" data-tt-run-msg role="status" aria-live="polite"></p>';

        echo '</form>';
    }

    private static function enqueueAttach( int $activity_id ): void {
        wp_enqueue_style(
            'tt-frontend-training-run',
            TT_PLUGIN_URL . 'assets/css/frontend-training-run.css',
            [],
            TT_VERSION
        );

        wp_enqueue_script(
            'tt-frontend-training-run',
            TT_PLUGIN_URL . 'assets/js/frontend-training-run.js',
            [],
            TT_VERSION,
            true
        );

        wp_localize_script( 'tt-frontend-training-run', 'TT_TRAINING_RUN', [
            'mode'       => 'attach',
            'activityId' => $activity_id,
            'restBase'   => esc_url_raw( rest_url( 'talenttrack/v1' ) ),
            'nonce'      => wp_create_nonce( 'wp_rest' ),
            'runUrl'     => esc_url_raw( add_query_arg( [ 'tt_view' => 'training-run' ], RecordLink::dashboardUrl() ) ), /* tt-xview-ok — this view's own route */
            'i18n'       => self::strings(),
        ] );
    }

    // ---- sideline --------------------------------------------------------

    private static function renderSideline( object $run, TrainingPlanRunsRepository $runs ): void {
        $run_id = (int) $run->id;
        $plan   = ( new TrainingPlansRepository() )->findById( (int) $run->plan_id );
        $title  = $plan ? (string) $plan->title : __( 'Training', 'talenttrack' );

        FrontendBreadcrumbs::fromDashboard(
            $title,
            [ FrontendBreadcrumbs::viewCrumb( 'training-plans', __( 'Training', 'talenttrack' ) ) ]
        );

        self::renderHeader( $title );

        self::enqueueSideline( $run, $runs, $title );

        // The dark ground is scoped to this wrapper rather than applied to
        // the page, so the shell around it is untouched and nothing else
        // in the dashboard inherits a pitch-side treatment.
        echo '<div class="tt-run" data-tt-run>';

        echo '<div class="tt-run__progress" data-tt-run-progress role="img" aria-label="'
            . esc_attr__( 'How far through the training you are', 'talenttrack' ) . '"></div>';

        echo '<section class="tt-run__card" data-tt-run-card aria-live="polite"></section>';

        echo '<div class="tt-run__controls" data-tt-run-controls></div>';

        echo '<p class="tt-run__msg" data-tt-run-msg role="status" aria-live="polite"></p>';

        echo '</div>';
    }

    /**
     * @return object|null
     */
    private static function activity( int $activity_id ) {
        global $wpdb;

        return $wpdb->get_row( $wpdb->prepare(
            "SELECT id, team_id, session_date, activity_type_key
               FROM {$wpdb->prefix}tt_activities
              WHERE id = %d",
            $activity_id
        ) );
    }

    private static function enqueueSideline( object $run, TrainingPlanRunsRepository $runs, string $title ): void {
        wp_enqueue_style(
            'tt-frontend-training-run',
            TT_PLUGIN_URL . 'assets/css/frontend-training-run.css',
            [],
            TT_VERSION
        );

        wp_enqueue_script(
            'tt-frontend-training-run',
            TT_PLUGIN_URL . 'assets/js/frontend-training-run.js',
            [],
            TT_VERSION,
            true
        );

        wp_localize_script( 'tt-frontend-training-run', 'TT_TRAINING_RUN', [
            'runId'           => (int) $run->id,
            'status'          => (string) $run->status,
            'title'           => $title,
            'restBase'        => esc_url_raw( rest_url( 'talenttrack/v1' ) ),
            'nonce'           => wp_create_nonce( 'wp_rest' ),
            'blocks'          => self::shapeBlocks( $run, $runs ),
            'blockTypeLabels' => self::blockTypeLabels(),
            'planUrl'         => esc_url_raw( add_query_arg(
                [ 'tt_view' => 'training-plan', 'id' => (int) $run->plan_id ], /* tt-xview-ok — the plan this run came from, same module */
                RecordLink::dashboardUrl()
            ) ),
            'i18n'            => self::strings(),
        ] );
    }

    /**
     * The blocks the coach will actually work through.
     *
     * Read from the run's own snapshot, not from the plan: the snapshot
     * is what was agreed when the plan was attached, and it is the only
     * version that stays true if someone edits the plan tonight.
     *
     * @return list<array<string,mixed>>
     */
    private static function shapeBlocks( object $run, TrainingPlanRunsRepository $runs ): array {
        $snapshot = $runs->snapshot( (int) $run->id );
        $by_order = [];
        foreach ( (array) ( $snapshot['blocks'] ?? [] ) as $block ) {
            $by_order[ (int) ( $block['order_index'] ?? 0 ) ] = $block;
        }

        $out = [];
        foreach ( $runs->listBlocks( (int) $run->id ) as $row ) {
            $order = (int) ( $row->order_index ?? 0 );
            $snap  = $by_order[ $order ] ?? [];

            $out[] = [
                'id'              => (int) ( $row->id ?? 0 ),
                'order_index'     => $order,
                'block_type'      => (string) ( $snap['block_type'] ?? $row->planned_block_type ?? 'main' ),
                'name'            => (string) ( $snap['title'] ?? $snap['exercise_name'] ?? '' ),
                'organisation'    => (string) ( $snap['organisation'] ?? '' ),
                'coaching_points' => (string) ( $snap['coaching_points'] ?? '' ),
                'planned_minutes' => (int) ( $snap['duration_minutes'] ?? $row->planned_duration_minutes ?? 0 ),
                'actual_minutes'  => isset( $row->actual_duration_minutes ) ? (int) $row->actual_duration_minutes : null,
                'was_skipped'     => (bool) ( $row->was_skipped ?? false ),
            ];
        }

        return $out;
    }

    /**
     * Block-type labels for the JS, translated here — §4 keeps
     * user-facing strings server-side.
     *
     * @return array<string,string>
     */
    private static function blockTypeLabels(): array {
        return [
            'warmup'    => __( 'Warm-up', 'talenttrack' ),
            'rondo'     => __( 'Rondo', 'talenttrack' ),
            'main'      => __( 'Main', 'talenttrack' ),
            'game'      => __( 'Game', 'talenttrack' ),
            'finishing' => __( 'Finishing', 'talenttrack' ),
            'cooldown'  => __( 'Cool-down', 'talenttrack' ),
            'talk'      => __( 'Team talk', 'talenttrack' ),
        ];
    }

    /** @return array<string,string> */
    private static function strings(): array {
        return [
            'ready'        => __( 'Ready to start', 'talenttrack' ),
            'readySummary' => __( '%1$d blocks · %2$d minutes', 'talenttrack' ),
            'start'        => __( 'Start the training', 'talenttrack' ),
            'blockOf'      => __( 'Block %1$d of %2$d · %3$s', 'talenttrack' ),
            'of'           => __( 'of %s', 'talenttrack' ),
            'organisation' => __( 'Organisation', 'talenttrack' ),
            'coachingPts'  => __( 'Coaching points', 'talenttrack' ),
            'previous'     => __( 'Previous block', 'talenttrack' ),
            'next'         => __( 'Next block', 'talenttrack' ),
            'finishBlock'  => __( 'Finish this block', 'talenttrack' ),
            'skipBlock'    => __( 'Skip this block', 'talenttrack' ),
            'finishRun'    => __( 'Finish the training', 'talenttrack' ),
            'unnamed'      => __( 'Untitled block', 'talenttrack' ),
            'saving'       => __( 'Saving…', 'talenttrack' ),
            'saveFailed'   => __( 'That did not save — you are offline or the connection dropped. Note the time and try again; nothing is recorded until it saves.', 'talenttrack' ),
            'skipped'      => __( 'Skipped', 'talenttrack' ),
            // States the consequence rather than nagging: the coach can
            // see they are over, what they cannot see is what finishing
            // now would write down.
            'overBy'       => __( 'You are %1$s over. Finish now and this block is recorded as %2$d minutes.', 'talenttrack' ),
            'doneTitle'    => __( '%d minutes trained', 'talenttrack' ),
            'donePlanned'  => __( 'planned %d', 'talenttrack' ),
            'doneMinutes'  => __( 'Minutes', 'talenttrack' ),
            'doneBlocks'   => __( 'Blocks run', 'talenttrack' ),
            'doneSkipped'  => __( 'Skipped', 'talenttrack' ),
            'doneNote'     => __( 'Recorded against this training. The plan itself is unchanged, so the next team to use it starts from the same place you did.', 'talenttrack' ),
            'skippedNote'  => __( 'Skipped blocks are recorded here, not removed from the plan.', 'talenttrack' ),
            'backToPlan'   => __( 'Back to the plan', 'talenttrack' ),
            'confirmEnd'   => __( 'Finish the training? Any blocks you have not run are recorded as skipped.', 'talenttrack' ),
            'attaching'    => __( 'Attaching…', 'talenttrack' ),
            'attachFailed' => __( 'The plan could not be attached. Check your connection and try again.', 'talenttrack' ),
            'alreadyOne'   => __( 'This training already had a plan attached — opening it.', 'talenttrack' ),
        ];
    }
}
