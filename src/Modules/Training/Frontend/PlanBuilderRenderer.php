<?php
namespace TT\Modules\Training\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Exercises\ExercisesRepository;
use TT\Modules\Training\Repositories\TrainingPlanBlocksRepository;
use TT\Modules\Training\Services\PlanCoverageService;

/**
 * PlanBuilderRenderer (#2498) — the editable view of a training plan at
 * `?tt_view=training-plan&id=N&mode=build`.
 *
 * ## What is server-rendered and what is not
 *
 * The shell, the side panel and the whole starting payload come from PHP.
 * The block list, the timeline and the running total are rendered by
 * `frontend-training-builder.js` from that payload, because every one of
 * them changes on every edit and re-rendering them server-side would mean
 * a page load per duration tap.
 *
 * The read-only detail view is the no-JS path: it renders the same plan
 * from the same repositories, and the builder is only ever reached by
 * choosing to edit. A coach without JS keeps a working, readable plan —
 * they just cannot rearrange it in place.
 *
 * ## This class composes; it does not decide (CLAUDE.md §4)
 *
 * Coverage comes from `PlanCoverageService`, block data from the
 * repository, principle labels from the exercise library. Nothing here
 * works out who a plan serves — it lays out the answer.
 */
final class PlanBuilderRenderer {

    /**
     * Duration steps. Five minutes is the unit coaches actually think in;
     * a one-minute stepper would mean twelve taps to change a block from
     * ten to twenty-two.
     */
    private const STEP_MINUTES = 5;
    private const MIN_MINUTES  = 5;
    private const MAX_MINUTES  = 60;

    public static function render( object $plan ): void {
        $plan_id = (int) $plan->id;
        $blocks  = ( new TrainingPlanBlocksRepository() )->listForPlan( $plan_id );

        self::enqueue( $plan_id, $plan, $blocks );

        echo '<div class="tt-builder" data-tt-builder>';

        echo '<div class="tt-builder__main">';
        self::renderBlocksCard();
        echo '</div>';

        echo '<aside class="tt-builder__side">';
        self::renderCoverageCard( $plan_id );
        echo '</aside>';

        echo '</div>';

        self::renderPicker();
    }

    /**
     * The card the JS fills. Rendering the chrome here rather than in JS
     * keeps the headings translatable server-side (§4: no hardcoded
     * English in JS) and means the card does not pop in.
     */
    private static function renderBlocksCard(): void {
        echo '<section class="tt-card tt-builder__blocks">';

        echo '<div class="tt-card__head">';
        echo '<h2 class="tt-card__title">' . esc_html__( 'Blocks', 'talenttrack' ) . '</h2>';
        echo '<span class="tt-card__hint">' . esc_html__( 'Drag, or use the up and down buttons', 'talenttrack' ) . '</span>';
        echo '</div>';

        echo '<div class="tt-builder__timeline" data-tt-builder-timeline role="img" aria-label="'
            . esc_attr__( 'How the training time is split across its blocks', 'talenttrack' ) . '"></div>';

        echo '<p class="tt-builder__total"><span class="tt-muted">'
            . esc_html__( 'Total planned', 'talenttrack' )
            . '</span> <b data-tt-builder-total aria-live="polite"></b></p>';

        echo '<ol class="tt-builder__list" data-tt-builder-list></ol>';

        echo '<div class="tt-form-actions tt-builder__actions">';
        echo '<button type="button" class="tt-btn tt-btn-secondary" data-tt-builder-add>'
            . esc_html__( 'Add a block', 'talenttrack' ) . '</button>';
        echo '<button type="button" class="tt-btn tt-btn-primary" data-tt-builder-save>'
            . esc_html__( 'Save plan', 'talenttrack' ) . '</button>';
        echo '</div>';

        self::renderSecondaryActions();

        echo '<p class="tt-builder__msg" data-tt-builder-msg role="status" aria-live="polite"></p>';

        echo '</section>';
    }

    /**
     * The two ways a finished plan earns its keep beyond Tuesday:
     * becoming a club template, or becoming next week's starting point.
     *
     * Both are duplications, both land on `POST /plans/{id}/duplicate`,
     * and both are separated from Save so that a coach reaching for
     * "save my work" cannot accidentally publish a template.
     */
    private static function renderSecondaryActions(): void {
        echo '<div class="tt-builder__secondary">';

        echo '<button type="button" class="tt-btn tt-btn-secondary tt-btn-sm" data-tt-builder-template>'
            . esc_html__( 'Save as club template', 'talenttrack' ) . '</button>';

        echo '<button type="button" class="tt-btn tt-btn-secondary tt-btn-sm" data-tt-builder-duplicate>'
            . esc_html__( 'Copy to a new plan', 'talenttrack' ) . '</button>';

        echo '</div>';

        echo '<p class="tt-small tt-muted tt-builder__secondary-hint">'
            . esc_html__( 'A copy is independent: editing it later leaves this plan untouched, and a training already run keeps its own record either way.', 'talenttrack' )
            . '</p>';
    }

    /**
     * The point of the whole module, on the right-hand side: which
     * players this plan actually works on, by name (CLAUDE.md §1).
     *
     * Server-rendered from the saved plan, then re-rendered by the JS
     * after each save. It is correct on first paint and correct after an
     * edit, without the panel being empty in between.
     */
    private static function renderCoverageCard( int $plan_id ): void {
        $coverage = ( new PlanCoverageService() )->forPlan( $plan_id );

        echo '<section class="tt-card tt-builder__coverage" data-tt-builder-coverage>';
        echo '<div class="tt-card__head"><h2 class="tt-card__title">'
            . esc_html__( 'Player goals this plan touches', 'talenttrack' )
            . '</h2></div>';

        self::renderCoverageBody( $coverage );

        echo '</section>';
    }

    /**
     * Shared by the first paint and — through the same markup shape — the
     * JS re-render, so the panel cannot drift between the two.
     *
     * @param array<string,mixed> $coverage
     */
    public static function renderCoverageBody( array $coverage ): void {
        $players = (array) ( $coverage['players'] ?? [] );

        echo '<div data-tt-builder-coverage-body>';

        if ( $players === [] ) {
            echo '<p class="tt-muted tt-small">'
                . esc_html__( 'No player on this team has an open goal tied to a principle yet, so there is nothing to match this plan against.', 'talenttrack' )
                . '</p>';
            echo '</div>';
            return;
        }

        echo '<p class="tt-muted tt-small">'
            . esc_html__( 'Players with an open goal on a principle this plan trains.', 'talenttrack' )
            . '</p>';

        $missed = [];
        foreach ( $players as $player ) {
            if ( empty( $player['covered'] ) ) { $missed[] = $player; continue; }

            echo '<div class="tt-builder__goalhit">';
            echo '<span class="tt-builder__avatar" aria-hidden="true">'
                . esc_html( self::initials( (string) ( $player['name'] ?? '' ) ) )
                . '</span>';
            echo '<b>' . esc_html( (string) ( $player['name'] ?? '' ) ) . '</b>';
            echo '</div>';
        }

        if ( $missed !== [] ) {
            $names = array_map( static fn( array $p ): string => (string) ( $p['name'] ?? '' ), $missed );

            echo '<p class="tt-builder__missed tt-small">'
                . esc_html( sprintf(
                    /* translators: 1: how many players are not served, 2: their names, comma separated. */
                    _n(
                        '%1$d player with an open goal is not touched by this plan: %2$s.',
                        '%1$d players with an open goal are not touched by this plan: %2$s.',
                        count( $missed ),
                        'talenttrack'
                    ),
                    count( $missed ),
                    implode( ', ', $names )
                ) )
                . '</p>';
        }

        echo '</div>';
    }

    /**
     * The library picker. A bottom sheet on a phone and a bottom-right
     * panel on desktop — one element, two presentations, so there is one
     * set of behaviour to get right.
     *
     * `overscroll-behavior: contain` lives in the sheet's CSS: without it
     * a flick past the end of the list scrolls the page underneath and
     * the coach loses their place in the plan.
     */
    private static function renderPicker(): void {
        echo '<div class="tt-builder__scrim" data-tt-builder-scrim hidden></div>';

        echo '<section class="tt-builder__picker" data-tt-builder-picker hidden'
            . ' role="dialog" aria-modal="true" aria-label="' . esc_attr__( 'Choose an exercise', 'talenttrack' ) . '">';

        echo '<div class="tt-builder__picker-head">';
        echo '<h2>' . esc_html__( 'Choose an exercise', 'talenttrack' ) . '</h2>';
        echo '<button type="button" class="tt-btn-icon" data-tt-builder-picker-close aria-label="'
            . esc_attr__( 'Close', 'talenttrack' ) . '">&times;</button>';
        echo '</div>';

        echo '<label class="tt-field"><span class="tt-sr-only">' . esc_html__( 'Search exercises', 'talenttrack' ) . '</span>'
            . '<input type="search" data-tt-builder-picker-search inputmode="search"'
            . ' placeholder="' . esc_attr__( 'Search by name or code', 'talenttrack' ) . '"></label>';

        echo '<p class="tt-small tt-muted" data-tt-builder-picker-sort>'
            . esc_html__( 'Sorted by how many of this team\'s open player goals each exercise would serve.', 'talenttrack' )
            . '</p>';

        echo '<ul class="tt-builder__picker-rows" data-tt-builder-picker-rows></ul>';

        echo '</section>';
    }

    /**
     * @param list<object> $blocks
     */
    private static function enqueue( int $plan_id, object $plan, array $blocks ): void {
        wp_enqueue_style(
            'tt-frontend-training-builder',
            TT_PLUGIN_URL . 'assets/css/frontend-training-builder.css',
            [ 'tt-frontend-training' ],
            TT_VERSION
        );

        wp_enqueue_script(
            'tt-frontend-training-builder',
            TT_PLUGIN_URL . 'assets/js/frontend-training-builder.js',
            [],
            TT_VERSION,
            true
        );

        wp_localize_script( 'tt-frontend-training-builder', 'TT_TRAINING_BUILDER', [
            'planId'     => $plan_id,
            'restBase'   => esc_url_raw( rest_url( 'talenttrack/v1' ) ),
            'nonce'      => wp_create_nonce( 'wp_rest' ),
            'blocks'     => self::shapeBlocks( $blocks ),
            'blockTypes' => self::blockTypeOptions(),
            'step'       => self::STEP_MINUTES,
            'min'        => self::MIN_MINUTES,
            'max'        => self::MAX_MINUTES,
            'planUrl'    => esc_url_raw( add_query_arg(
                [ 'tt_view' => 'training-plan', 'id' => $plan_id ],
                \TT\Shared\Frontend\Components\RecordLink::dashboardUrl()
            ) ),
            'i18n'       => self::strings(),
        ] );
    }

    /**
     * The block shape the JS edits and posts back. Deliberately the same
     * field names the REST controller reads, so a save is the array sent
     * straight back with no translation layer to drift.
     *
     * @param list<object> $blocks
     * @return list<array<string,mixed>>
     */
    private static function shapeBlocks( array $blocks ): array {
        $exercises  = new ExercisesRepository();
        $principles = self::principleCodes( $exercises );

        $out = [];
        foreach ( $blocks as $block ) {
            $exercise_id = (int) ( $block->exercise_id ?? 0 );

            $codes = [];
            foreach ( $exercise_id > 0 ? $exercises->principleIdsFor( $exercise_id ) : [] as $principle_id ) {
                if ( isset( $principles[ $principle_id ] ) ) $codes[] = $principles[ $principle_id ];
            }

            $out[] = [
                'id'               => (int) ( $block->id ?? 0 ),
                'block_type'       => (string) ( $block->block_type ?? 'main' ),
                'exercise_id'      => $exercise_id ?: null,
                'exercise_name'    => (string) ( $block->exercise_name ?? '' ),
                'title_override'   => (string) ( $block->title_override ?? '' ),
                'organisation'     => (string) ( $block->organisation ?? '' ),
                'coaching_points'  => (string) ( $block->coaching_points ?? '' ),
                'duration_minutes' => (int) ( $block->duration_minutes ?? 0 ),
                'intensity_band'   => isset( $block->intensity_band ) ? (int) $block->intensity_band : null,
                'principle_codes'  => $codes,
            ];
        }

        return $out;
    }

    /** @return array<int,string> principle id => code */
    private static function principleCodes( ExercisesRepository $exercises ): array {
        $out = [];
        foreach ( $exercises->listPrinciples() as $principle ) {
            $out[ (int) $principle->id ] = (string) ( $principle->code ?? '' );
        }

        return $out;
    }

    /**
     * Block types as label/value pairs. Translated here rather than in
     * JS — §4 keeps user-facing strings server-side.
     *
     * @return list<array{value:string, label:string}>
     */
    private static function blockTypeOptions(): array {
        $labels = [
            'warmup'    => __( 'Warm-up', 'talenttrack' ),
            'rondo'     => __( 'Rondo', 'talenttrack' ),
            'main'      => __( 'Main', 'talenttrack' ),
            'game'      => __( 'Game', 'talenttrack' ),
            'finishing' => __( 'Finishing', 'talenttrack' ),
            'cooldown'  => __( 'Cool-down', 'talenttrack' ),
            'talk'      => __( 'Team talk', 'talenttrack' ),
        ];

        $out = [];
        foreach ( $labels as $value => $label ) {
            $out[] = [ 'value' => $value, 'label' => $label ];
        }

        return $out;
    }

    /** @return array<string,string> */
    private static function strings(): array {
        return [
            'up'            => __( 'Move up', 'talenttrack' ),
            'down'          => __( 'Move down', 'talenttrack' ),
            'drag'          => __( 'Drag to reorder', 'talenttrack' ),
            'shorter'       => __( 'Shorter', 'talenttrack' ),
            'longer'        => __( 'Longer', 'talenttrack' ),
            'swap'          => __( 'Swap exercise', 'talenttrack' ),
            'remove'        => __( 'Remove block', 'talenttrack' ),
            'blockType'     => __( 'Kind of block', 'talenttrack' ),
            'coachingPts'   => __( 'Coaching points for this block', 'talenttrack' ),
            'untitled'      => __( 'Empty block — choose an exercise', 'talenttrack' ),
            'minutes'       => __( '%d min', 'talenttrack' ),
            'totalMinutes'  => __( '%d minutes', 'talenttrack' ),
            'empty'         => __( 'This plan has no blocks yet. Add one to start building.', 'talenttrack' ),
            'saving'        => __( 'Saving…', 'talenttrack' ),
            'saved'         => __( 'Saved.', 'talenttrack' ),
            'saveFailed'    => __( 'That did not save. Check your connection and try again.', 'talenttrack' ),
            'loadFailed'    => __( 'The library could not be loaded. Try again in a moment.', 'talenttrack' ),
            'noOptions'     => __( 'No exercise in the library matches that.', 'talenttrack' ),
            'choose'        => __( 'Choose', 'talenttrack' ),
            'servesNobody'  => __( 'serves no open goal', 'talenttrack' ),
            'servesPlayers' => __( 'serves %d player goals', 'talenttrack' ),
            'movedTo'       => __( 'Moved to position %d', 'talenttrack' ),
            'unsaved'       => __( 'You have unsaved changes.', 'talenttrack' ),
            'templateAsk'   => __( 'Save a club-wide template from this plan? The template is a copy — editing it later will not change this plan.', 'talenttrack' ),
            'duplicateAsk'  => __( 'Copy this plan into a new one for the same team?', 'talenttrack' ),
            'copyFailed'    => __( 'The copy could not be made. Try again in a moment.', 'talenttrack' ),
            'saveFirst'     => __( 'Save your changes first — a copy is made from the saved plan.', 'talenttrack' ),
        ];
    }

    private static function initials( string $name ): string {
        $parts = preg_split( '/\s+/', trim( $name ) ) ?: [];
        $out   = '';
        foreach ( array_slice( $parts, 0, 2 ) as $part ) {
            $out .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
        }

        return $out !== '' ? $out : '?';
    }
}
