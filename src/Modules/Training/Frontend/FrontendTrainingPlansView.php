<?php
namespace TT\Modules\Training\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Training\Repositories\TrainingPlanBlocksRepository;
use TT\Modules\Training\Repositories\TrainingPlanRunsRepository;
use TT\Modules\Training\Repositories\TrainingPlansRepository;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Frontend\Components\CrossViewLink;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\FrontendListTable;
use TT\Shared\Frontend\Components\RecordLink;
use TT\Shared\Frontend\Components\RecordSpine;
use TT\Shared\Wizards\WizardEntryPoint;

/**
 * FrontendTrainingPlansView (#2496) — the training plan list at
 * `?tt_view=training-plans` and the read-only detail at
 * `?tt_view=training-plan&id=N`.
 *
 * Read-only by design in this wave. The editable builder is #2498 and the
 * generator that fills a plan in the first place is #2497, so everything
 * here composes what the repositories return and offers no writes beyond
 * the archive action the list table owns.
 *
 * Navigation (CLAUDE.md §5): breadcrumb chain plus the `tt_back` pill the
 * breadcrumb component renders, and nothing else.
 *
 * As the module's later surfaces land — the library (#2495), the generator
 * (#2497), the coverage report (#2500) — they arrive as header actions on
 * this one page, each registered through `CrossViewLink` so the affordance
 * hides when the user cannot reach its target. They do NOT become sibling
 * tiles: §5b makes module-level navigation the shell's job, rendered once,
 * and D10 settled on a single `Training` entry point.
 */
final class FrontendTrainingPlansView extends FrontendViewBase {

    /** Colour-coded spine per block type — the same six the mockups use. */
    private const BLOCK_TYPES = [
        'warmup', 'rondo', 'main', 'game', 'finishing', 'cooldown', 'talk',
    ];

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_training_plan' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">'
                . esc_html__( 'You do not have permission to view training plans.', 'talenttrack' )
                . '</p>';
            return;
        }

        self::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-training',
            TT_PLUGIN_URL . 'assets/css/frontend-training.css',
            [],
            TT_VERSION
        );

        $detail_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
        if ( $detail_id > 0 ) {
            self::renderDetail( $detail_id );
            return;
        }

        self::renderList();
    }

    private static function renderList(): void {
        FrontendBreadcrumbs::fromDashboard( __( 'Training', 'talenttrack' ) );

        // The library is reached from inside this page, not from a second
        // tile (D10). `CrossViewLink::allows()` mirrors the library view's
        // own guard, so the action hides rather than leading someone to a
        // "not authorized" notice.
        $actions = [];

        // #2497 — the generator is the way in, so it leads. A blank
        // builder is where coaching apps go to die; the coach answers
        // four short questions and gets a finished session back.
        $actions[] = [
            'label'   => __( 'New plan', 'talenttrack' ),
            // The wizard gates itself on `tt_training_plan`, the same cap
            // this whole view already required to render, and the
            // fallback is this module's own create route rather than a
            // different surface. Nothing to hide that is not already
            // hidden. /* tt-xview-ok — same module, wizard-gated */
            'href'    => WizardEntryPoint::urlFor(
                'new-training-plan',
                add_query_arg( [ 'tt_view' => 'training-plan', 'action' => 'new' ], RecordLink::dashboardUrl() ) /* tt-xview-ok */
            ),
            'primary' => true,
            'icon'    => '+',
        ];

        if ( CrossViewLink::allows( 'exercises' ) ) {
            $actions[] = [
                'label' => __( 'Exercises', 'talenttrack' ),
                'href'  => add_query_arg( [ 'tt_view' => 'exercises' ], RecordLink::dashboardUrl() ), /* tt-xview-ok — gated by CrossViewLink::allows above */
            ];
        }

        // #2500 — the coverage matrix, reached from inside this page like
        // the library (D10), and hidden for anyone whose exposure access
        // is team-scoped rather than academy-wide.
        if ( CrossViewLink::allows( 'training-coverage' ) ) {
            $actions[] = [
                'label' => __( 'Coverage', 'talenttrack' ),
                'href'  => add_query_arg( [ 'tt_view' => 'training-coverage' ], RecordLink::dashboardUrl() ), /* tt-xview-ok — gated by CrossViewLink::allows above */
            ];
        }
        // #2502 — the way in to photographing a hand-written plan. Shown
        // only when the feature is on AND this install has declared where
        // photographs would go, because an entry point that leads to a
        // refusal is worse than no entry point: the coach has already
        // decided to use it by the time they are told they cannot.
        if ( \TT\Core\FeatureRegistry::isEnabled( 'exercises_vision_extraction' )
            && \TT\Modules\Exercises\Vision\VisionDataRegion::isDeclared() ) {
            $actions[] = [
                'label' => __( 'From a photo', 'talenttrack' ),
                'href'  => add_query_arg( [ 'tt_view' => 'training-photo' ], RecordLink::dashboardUrl() ), /* tt-xview-ok — same module, gated above */
            ];

            // #2735 — a photo held on this phone is waiting somewhere the
            // coach may not be. The count is client-side (the photo never
            // left the device), so PHP renders the slot and the hold script
            // fills it, or leaves it hidden when there is nothing waiting.
            FrontendTrainingPhotoView::enqueuePhotoHold();
        }

        self::renderHeader( __( 'Training plans', 'talenttrack' ), self::pageActionsHtml( $actions ) );

        if ( \TT\Core\FeatureRegistry::isEnabled( 'exercises_vision_extraction' )
            && \TT\Modules\Exercises\Vision\VisionDataRegion::isDeclared() ) {
            // The link IS the slot: the script writes the count into it and
            // unhides it. A wrapper would render an empty notice box on
            // every load where nothing is waiting, which is most of them.
            printf(
                '<a class="tt-notice tt-notice-info tt-training-photo-pending" href="%s" data-tt-photo-hold hidden></a>',
                esc_url( add_query_arg( [ 'tt_view' => 'training-photo' ], RecordLink::dashboardUrl() ) ) /* tt-xview-ok — same module, gated above */
            );
        }

        echo '<p class="tt-muted tt-training-intro">'
            . esc_html__( 'Your training plans and the club templates you can build from. A plan you attach to a training records what was actually trained, so it lands on the player record.', 'talenttrack' )
            . '</p>';

        echo FrontendListTable::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — render() returns escaped HTML.
            'rest_path'   => 'training/plans',
            'row_url_key' => 'detail_url',
            'columns'     => [
                'title'          => [ 'label' => __( 'Plan', 'talenttrack' ),     'sortable' => true ],
                'kind_label'     => [ 'label' => __( 'Kind', 'talenttrack' ) ],
                'duration_label' => [ 'label' => __( 'Duration', 'talenttrack' ), 'sortable' => true ],
                'theme_key'      => [ 'label' => __( 'Theme', 'talenttrack' ),    'sortable' => true ],
                'created_at'     => [ 'label' => __( 'Created', 'talenttrack' ),  'sortable' => true, 'render' => 'date' ],
            ],
            'filters' => [
                // #2625 — canonical archive-state param; `status` is reserved
                // for domain status.
                'archived' => [
                    'type'    => 'select',
                    'render'  => 'status',
                    'label'   => __( 'Archive', 'talenttrack' ),
                    'options' => [
                        'active'   => __( 'Active', 'talenttrack' ),
                        'archived' => __( 'Archived', 'talenttrack' ),
                    ],
                ],
                'is_template' => [
                    'type'    => 'select',
                    'label'   => __( 'Kind', 'talenttrack' ),
                    'options' => [
                        ''  => __( 'Plans and templates', 'talenttrack' ),
                        '0' => __( 'Plans only', 'talenttrack' ),
                        '1' => __( 'Templates only', 'talenttrack' ),
                    ],
                ],
            ],
            'row_actions' => [
                'archive' => [
                    'label'       => __( 'Archive', 'talenttrack' ),
                    'rest_method' => 'DELETE',
                    'rest_path'   => 'training/plans/{id}',
                    'confirm'     => __( 'Archive this training plan? Trainings already run with it keep their record.', 'talenttrack' ),
                    'cap'         => 'tt_training_plan',
                    'variant'     => 'danger',
                ],
            ],
            'search'       => [ 'placeholder' => __( 'Search plans…', 'talenttrack' ) ],
            'default_sort' => [ 'orderby' => 'created_at', 'order' => 'desc' ],
            'empty_state'  => __( 'No training plans match your search.', 'talenttrack' ),
            'empty_state_card' => [
                'icon'      => 'activities',
                'headline'  => __( 'No training plans yet', 'talenttrack' ),
                'explainer' => __( 'A training plan holds the blocks of one training. Attach it to a training in the calendar and what you trained lands on each player who was there.', 'talenttrack' ),
            ],
        ] );
    }

    private static function renderDetail( int $id ): void {
        $plans = new TrainingPlansRepository();
        $plan  = $plans->findById( $id );

        if ( ! $plan ) {
            FrontendBreadcrumbs::fromDashboard(
                __( 'Not found', 'talenttrack' ),
                [ FrontendBreadcrumbs::viewCrumb( 'training-plans', __( 'Training', 'talenttrack' ) ) ]
            );
            echo '<p class="tt-notice">' . esc_html__( 'That training plan no longer exists.', 'talenttrack' ) . '</p>';
            return;
        }

        FrontendBreadcrumbs::fromDashboard(
            (string) $plan->title,
            [ FrontendBreadcrumbs::viewCrumb( 'training-plans', __( 'Training', 'talenttrack' ) ) ]
        );

        $building = self::isBuildMode();

        self::renderHeader( (string) $plan->title, self::pageActionsHtml( self::detailActions( $id, $building ) ) );
        self::renderSpine( $id, $plan, $building );

        if ( $building ) {
            PlanBuilderRenderer::render( $plan );
            return;
        }

        $blocks = ( new TrainingPlanBlocksRepository() )->listForPlan( $id );
        $runs   = ( new TrainingPlanRunsRepository() )->listForPlan( $id );

        self::renderSummary( $plan, $blocks );
        self::renderTimeline( $blocks, (int) $plan->total_duration_minutes );
        self::renderBlocks( $blocks );
        self::renderRuns( $runs );
    }

    private static function isBuildMode(): bool {
        return isset( $_GET['mode'] ) && sanitize_key( wp_unslash( $_GET['mode'] ) ) === 'build';
    }

    private static function detailUrl( int $id, bool $build ): string {
        $args = [ 'tt_view' => 'training-plan', 'id' => $id ];
        if ( $build ) $args['mode'] = 'build';

        return add_query_arg( $args, RecordLink::dashboardUrl() );
    }

    /**
     * The record's two tabs, through the shared spine (CLAUDE.md §5c).
     *
     * The spine renders nothing under the `classic` shell, which is why
     * Edit / Done also exists as a header action — under classic, that
     * link is the only way into the builder, and a feature reachable
     * only through chrome that half the installs do not render is a
     * feature half the installs do not have.
     */
    private static function renderSpine( int $id, object $plan, bool $building ): void {
        RecordSpine::render( [
            'name' => (string) $plan->title,
            'meta' => $plan->is_template
                ? __( 'Club template', 'talenttrack' )
                : __( 'Team plan', 'talenttrack' ),
            'tabs' => [
                [
                    'label'  => __( 'Overview', 'talenttrack' ),
                    'url'    => self::detailUrl( $id, false ),
                    'active' => ! $building,
                ],
                [
                    'label'  => __( 'Build', 'talenttrack' ),
                    'url'    => self::detailUrl( $id, true ),
                    'active' => $building,
                ],
            ],
        ] );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function detailActions( int $id, bool $building ): array {
        if ( $building ) {
            return [
                [
                    'label' => __( 'Done editing', 'talenttrack' ),
                    'href'  => self::detailUrl( $id, false ), /* tt-xview-ok — same view, same record */
                ],
            ];
        }

        // Save-as-template lives inside the builder rather than here.
        // It is a write, so it needs the builder's REST client; putting
        // it on the read-only page would mean enqueuing that script on a
        // view whose whole point is that it does not need one.
        return [
            [
                'label'   => __( 'Edit blocks', 'talenttrack' ),
                'href'    => self::detailUrl( $id, true ), /* tt-xview-ok — same view, same record */
                'primary' => true,
            ],
            // #2499 — the clipboard sheet. Opens in a new tab because it
            // is a standalone print document, not a dashboard page: the
            // coach prints it and comes back to where they were.
            [
                'label'  => __( 'Print', 'talenttrack' ),
                'href'   => add_query_arg( [ 'print' => '1' ], self::detailUrl( $id, false ) ),
                'target' => '_blank',
            ],
        ];
    }

    /**
     * @param list<object> $blocks
     */
    private static function renderSummary( object $plan, array $blocks ): void {
        $facts = [
            __( 'Duration', 'talenttrack' ) => sprintf(
                /* translators: %d is a number of minutes. */
                _n( '%d minute', '%d minutes', (int) $plan->total_duration_minutes, 'talenttrack' ),
                (int) $plan->total_duration_minutes
            ),
            __( 'Blocks', 'talenttrack' ) => (string) count( $blocks ),
            __( 'Kind', 'talenttrack' )   => $plan->is_template
                ? __( 'Club template', 'talenttrack' )
                : __( 'Team plan', 'talenttrack' ),
        ];
        if ( ! empty( $plan->theme_key ) ) {
            $facts[ __( 'Theme', 'talenttrack' ) ] = (string) $plan->theme_key;
        }

        echo '<div class="tt-training-facts">';
        foreach ( $facts as $label => $value ) {
            echo '<div class="tt-training-fact">'
                . '<span class="tt-training-fact__label">' . esc_html( (string) $label ) . '</span>'
                . '<span class="tt-training-fact__value">' . esc_html( (string) $value ) . '</span>'
                . '</div>';
        }
        echo '</div>';

        if ( ! empty( $plan->notes ) ) {
            echo '<p class="tt-training-notes">' . esc_html( (string) $plan->notes ) . '</p>';
        }
    }

    /**
     * The proportional strip of the session. Block widths are the one
     * genuinely computed style on this surface, so they carry the
     * inline-style grandfather marker rather than living in the sheet.
     *
     * @param list<object> $blocks
     */
    private static function renderTimeline( array $blocks, int $total ): void {
        if ( ! $blocks || $total <= 0 ) return;

        echo '<div class="tt-training-timeline" role="img" aria-label="'
            . esc_attr__( 'How the training time is split across its blocks', 'talenttrack' )
            . '">';
        foreach ( $blocks as $block ) {
            $minutes = max( 0, (int) $block->duration_minutes );
            if ( $minutes === 0 ) continue;
            $type = self::blockType( (string) $block->block_type );
            printf(
                '<span class="tt-training-timeline__seg tt-training-timeline__seg--%1$s" style="flex:%2$d" title="%3$s">%4$s</span>', /* tt-inline-ok */
                esc_attr( $type ),
                (int) $minutes,
                esc_attr( self::blockTypeLabel( $type ) . ' · ' . $minutes ),
                esc_html( (string) $minutes )
            );
        }
        echo '</div>';
    }

    /**
     * @param list<object> $blocks
     */
    private static function renderBlocks( array $blocks ): void {
        echo '<h2 class="tt-training-section-title">' . esc_html__( 'Blocks', 'talenttrack' ) . '</h2>';

        if ( ! $blocks ) {
            echo '<p class="tt-muted">' . esc_html__( 'This plan has no blocks yet.', 'talenttrack' ) . '</p>';
            return;
        }

        echo '<ol class="tt-training-blocks">';
        foreach ( $blocks as $index => $block ) {
            $type  = self::blockType( (string) $block->block_type );
            $title = $block->title_override !== null && $block->title_override !== ''
                ? (string) $block->title_override
                : (string) ( $block->exercise_name ?? __( 'Untitled block', 'talenttrack' ) );

            echo '<li class="tt-training-block tt-training-block--' . esc_attr( $type ) . '">';
            echo '<div class="tt-training-block__head">';
            echo '<span class="tt-training-block__type">'
                . esc_html( sprintf( '%d · %s', $index + 1, self::blockTypeLabel( $type ) ) )
                . '</span>';
            echo '<span class="tt-training-block__duration">'
                . esc_html( sprintf(
                    /* translators: %d is a number of minutes. */
                    _n( '%d minute', '%d minutes', (int) $block->duration_minutes, 'talenttrack' ),
                    (int) $block->duration_minutes
                ) )
                . '</span>';
            echo '</div>';

            echo '<p class="tt-training-block__title">' . esc_html( $title ) . '</p>';

            if ( ! empty( $block->organisation ) ) {
                echo '<p class="tt-training-block__body">' . esc_html( (string) $block->organisation ) . '</p>';
            }
            if ( ! empty( $block->coaching_points ) ) {
                echo '<p class="tt-training-block__points">' . esc_html( (string) $block->coaching_points ) . '</p>';
            }
            echo '</li>';
        }
        echo '</ol>';
    }

    /**
     * @param list<object> $runs
     */
    private static function renderRuns( array $runs ): void {
        echo '<h2 class="tt-training-section-title">' . esc_html__( 'Times this plan was run', 'talenttrack' ) . '</h2>';

        if ( ! $runs ) {
            echo '<p class="tt-muted">'
                . esc_html__( 'This plan has not been run yet. Attaching it to a training records what was actually trained.', 'talenttrack' )
                . '</p>';
            return;
        }

        echo '<ul class="tt-training-runs">';
        foreach ( $runs as $run ) {
            echo '<li class="tt-training-run">';
            echo '<span class="tt-training-run__date">' . esc_html( (string) $run->run_date ) . '</span>';
            echo '<span class="tt-training-run__status">' . esc_html( self::runStatusLabel( (string) $run->status ) ) . '</span>';
            echo '</li>';
        }
        echo '</ul>';

        echo '<p class="tt-muted tt-training-hint">'
            . esc_html__( 'Each run keeps its own copy of the blocks as they were on the day, so editing this plan never changes a training that already happened.', 'talenttrack' )
            . '</p>';
    }

    private static function blockType( string $type ): string {
        return in_array( $type, self::BLOCK_TYPES, true ) ? $type : 'main';
    }

    private static function blockTypeLabel( string $type ): string {
        switch ( $type ) {
            case 'warmup':    return __( 'Warm-up', 'talenttrack' );
            case 'rondo':     return __( 'Rondo', 'talenttrack' );
            case 'game':      return __( 'Game', 'talenttrack' );
            case 'finishing': return __( 'Finishing', 'talenttrack' );
            case 'cooldown':  return __( 'Cool-down', 'talenttrack' );
            case 'talk':      return __( 'Team talk', 'talenttrack' );
        }
        return __( 'Main', 'talenttrack' );
    }

    private static function runStatusLabel( string $status ): string {
        switch ( $status ) {
            case 'running':   return __( 'In progress', 'talenttrack' );
            case 'completed': return __( 'Completed', 'talenttrack' );
            case 'abandoned': return __( 'Abandoned', 'talenttrack' );
        }
        return __( 'Planned', 'talenttrack' );
    }
}
