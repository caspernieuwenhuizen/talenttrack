<?php
namespace TT\Modules\Exercises\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Exercises\ExercisesRepository;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Frontend\Components\FormSaveButton;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\Components\FrontendListTable;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * FrontendExerciseLibraryView (#2495) — the one exercise library at
 * `?tt_view=exercises`, and a single exercise at `?tt_view=exercises&id=N`.
 *
 * Since migration 0212 there is one catalogue: the club's own drills and
 * the VCT conditioning exercises live in the same table, and this is the
 * surface that administers all of it.
 *
 * Authoring model (epic #2493 D9). A coach with `tt_manage_exercises`
 * creates freely; a new drill defaults to their own team's visibility and
 * is usable in their plans immediately — nothing waits on approval. The
 * head of development sees a promotion queue and decides what becomes
 * club-wide. That queue is hidden entirely, not disabled, for anyone who
 * cannot act on it.
 *
 * Create / edit is an inline form rather than a wizard: CLAUDE.md §3
 * exemption (a) — an exercise is a tagged vocabulary row, not a narrative
 * record. Save + Cancel via `FormSaveButton` per §6.
 */
final class FrontendExerciseLibraryView extends FrontendViewBase {

    /**
     * Handle the inline create form. Hooked early on `init` so the
     * redirect happens before any output, which keeps the
     * post/redirect/get shape and stops a refresh re-submitting.
     */
    public static function maybeHandlePost(): void {
        if ( ! isset( $_POST['tt_action'] ) || $_POST['tt_action'] !== 'create_exercise' ) return;
        if ( ! is_user_logged_in() ) return;

        check_admin_referer( 'tt_exercise_create', 'tt_exercise_nonce' );

        $user_id = get_current_user_id();
        if ( ! self::canWrite( $user_id ) ) {
            wp_die( esc_html__( 'You do not have permission to add exercises.', 'talenttrack' ), '', [ 'response' => 403 ] );
        }

        $post    = wp_unslash( $_POST );
        $payload = [
            'name'           => sanitize_text_field( (string) ( $post['name'] ?? '' ) ),
            'description'    => sanitize_textarea_field( (string) ( $post['description'] ?? '' ) ),
            'diagram_url'    => esc_url_raw( (string) ( $post['diagram_url'] ?? '' ) ),
            'author_user_id' => $user_id,
        ];
        if ( $payload['name'] === '' ) {
            wp_safe_redirect( add_query_arg( 'tt_exercise', 'name_required', self::listUrl() ) );
            exit;
        }

        foreach ( [ 'category_id', 'duration_minutes', 'players_min', 'players_max', 'intensity_band' ] as $key ) {
            if ( isset( $post[ $key ] ) && $post[ $key ] !== '' ) $payload[ $key ] = (int) $post[ $key ];
        }
        if ( isset( $post['code'] ) ) $payload['code'] = sanitize_text_field( (string) $post['code'] );

        // D9 — team by default, and 'club' only from someone who may
        // curate the methodology. The select hides the option for anyone
        // else; this is the check that actually enforces it.
        $visibility = (string) ( $post['visibility'] ?? 'team' );
        if ( ! in_array( $visibility, [ 'club', 'team', 'private' ], true ) ) $visibility = 'team';
        if ( $visibility === 'club' && ! self::canPromote( $user_id ) ) $visibility = 'team';
        $payload['visibility'] = $visibility;

        $repo = new ExercisesRepository();
        $id   = $repo->create( $payload );

        // The principle links are what make the drill visible to the
        // generator and to the training-exposure figures, so they are
        // saved with the exercise rather than left to a second step
        // nobody comes back for.
        if ( $id > 0 && isset( $post['principle_ids'] ) && is_array( $post['principle_ids'] ) ) {
            $repo->setPrincipleIds( $id, array_map( 'intval', $post['principle_ids'] ) );
        }

        wp_safe_redirect( add_query_arg(
            $id > 0 ? [ 'tt_exercise' => 'created', 'id' => $id ] : [ 'tt_exercise' => 'failed' ],
            self::listUrl()
        ) );
        exit;
    }

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_view_activities' ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">'
                . esc_html__( 'You do not have permission to view the exercise library.', 'talenttrack' )
                . '</p>';
            return;
        }

        self::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-exercises',
            TT_PLUGIN_URL . 'assets/css/frontend-exercises.css',
            [],
            TT_VERSION
        );

        $detail_id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
        if ( $detail_id > 0 ) {
            self::renderDetail( $detail_id, $user_id );
            return;
        }

        self::renderList( $user_id );
    }

    private static function canWrite( int $user_id ): bool {
        return AuthorizationService::userCanOrMatrix( $user_id, 'tt_manage_exercises' );
    }

    /** Who may decide what the whole club trains from. See the REST controller. */
    private static function canPromote( int $user_id ): bool {
        return AuthorizationService::userCanOrMatrix( $user_id, 'tt_edit_methodology' );
    }

    private static function listUrl(): string {
        return add_query_arg( [ 'tt_view' => 'exercises' ], RecordLink::dashboardUrl() ); /* tt-xview-ok — same view */
    }

    private static function renderList( int $user_id ): void {
        FrontendBreadcrumbs::fromDashboard( __( 'Exercises', 'talenttrack' ) );
        self::renderHeader( __( 'Exercise library', 'talenttrack' ) );

        echo '<p class="tt-muted tt-ex-intro">'
            . esc_html__( 'Every drill the club can build a training from — your own and the conditioning exercises that ship with VCT. Add one and it is yours to use straight away.', 'talenttrack' )
            . '</p>';

        if ( self::canPromote( $user_id ) ) {
            self::renderPromotionQueue();
        }

        if ( self::canWrite( $user_id ) ) {
            self::renderCreateForm( $user_id );
        }

        echo FrontendListTable::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — render() returns escaped HTML.
            'rest_path'      => 'exercises',
            'static_filters' => [ 'browse' => 1 ],
            'row_url_key'    => 'detail_url',
            'columns'        => [
                'name'             => [ 'label' => __( 'Exercise', 'talenttrack' ),  'sortable' => true ],
                'origin_label'     => [ 'label' => __( 'Origin', 'talenttrack' ) ],
                'visibility_label' => [ 'label' => __( 'Visible to', 'talenttrack' ) ],
                'duration_minutes' => [ 'label' => __( 'Minutes', 'talenttrack' ),   'sortable' => true ],
                'players_label'    => [ 'label' => __( 'Group size', 'talenttrack' ) ],
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
                'category_id' => [
                    'type'    => 'select',
                    'label'   => __( 'Category', 'talenttrack' ),
                    'options' => self::categoryOptions(),
                ],
                'visibility' => [
                    'type'    => 'select',
                    'label'   => __( 'Visible to', 'talenttrack' ),
                    'options' => [
                        ''        => __( 'Anyone', 'talenttrack' ),
                        'club'    => __( 'Whole club', 'talenttrack' ),
                        'team'    => __( 'One team', 'talenttrack' ),
                        'private' => __( 'Only me', 'talenttrack' ),
                    ],
                ],
                'intensity_band' => [
                    'type'    => 'select',
                    'label'   => __( 'Intensity', 'talenttrack' ),
                    'options' => self::intensityOptions(),
                ],
            ],
            'search'       => [ 'placeholder' => __( 'Search by name, code or description…', 'talenttrack' ) ],
            'default_sort' => [ 'orderby' => 'name', 'order' => 'asc' ],
            'empty_state'  => __( 'No exercises match your search.', 'talenttrack' ),
            'empty_state_card' => [
                'icon'      => 'activities',
                'headline'  => __( 'Nothing in the library yet', 'talenttrack' ),
                'explainer' => __( 'Add the drills you already run. Each one you add can be dropped into a training plan and counts towards what your players have been taught.', 'talenttrack' ),
            ],
        ] );
    }

    /**
     * The head-of-development queue (D9). Team-scoped drills a coach has
     * written, waiting on a decision about whether the rest of the club
     * gets them.
     */
    private static function renderPromotionQueue(): void {
        $repo  = new ExercisesRepository();
        $queue = $repo->promotionQueue( 5 );
        if ( ! $queue ) return;

        $total = $repo->countPromotionQueue();

        echo '<section class="tt-ex-queue" aria-labelledby="tt-ex-queue-title">';
        echo '<h2 class="tt-ex-queue__title" id="tt-ex-queue-title">'
            . esc_html__( 'Added by teams', 'talenttrack' )
            . '</h2>';
        echo '<p class="tt-ex-queue__lede">'
            . esc_html__( 'Coaches wrote these for their own team. Make one club-wide when it fits the academy methodology — the team is already using it either way.', 'talenttrack' )
            . '</p>';

        echo '<ul class="tt-ex-queue__list">';
        foreach ( $queue as $row ) {
            $used = isset( $row->used_in_plans ) ? (int) $row->used_in_plans : 0;
            echo '<li class="tt-ex-queue__item">';
            echo '<div class="tt-ex-queue__body">';
            echo '<span class="tt-ex-queue__name">' . esc_html( (string) $row->name ) . '</span>';
            echo '<span class="tt-ex-queue__meta">'
                . esc_html( sprintf(
                    /* translators: %d is how many training plans already use this exercise. */
                    _n( 'Used in %d plan', 'Used in %d plans', $used, 'talenttrack' ),
                    $used
                ) )
                . '</span>';
            echo '</div>';
            printf(
                '<button type="button" class="tt-btn tt-btn-secondary tt-ex-queue__promote" data-exercise-id="%d">%s</button>',
                (int) $row->id,
                esc_html__( 'Make club-wide', 'talenttrack' )
            );
            echo '</li>';
        }
        echo '</ul>';

        if ( $total > count( $queue ) ) {
            echo '<p class="tt-ex-queue__more">'
                . esc_html( sprintf(
                    /* translators: %d is how many more exercises are waiting. */
                    _n( '%d more waiting.', '%d more waiting.', $total - count( $queue ), 'talenttrack' ),
                    $total - count( $queue )
                ) )
                . '</p>';
        }
        echo '</section>';

        self::enqueuePromoteScript();
    }

    /**
     * The promote button posts to the REST route and reloads. Small
     * enough to stay a behaviour on this surface rather than a shared
     * component, and it degrades to nothing without JS — the exercise is
     * still usable by its own team, which is the point of D9.
     */
    private static function enqueuePromoteScript(): void {
        wp_enqueue_script(
            'tt-exercise-promote',
            TT_PLUGIN_URL . 'assets/js/components/exercise-promote.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-exercise-promote', 'TTExercisePromote', [
            'root'  => esc_url_raw( rest_url( 'talenttrack/v1/exercises/' ) ),
            'nonce' => wp_create_nonce( 'wp_rest' ),
            'i18n'  => [
                'failed' => __( 'That did not work. Try again in a moment.', 'talenttrack' ),
                'busy'   => __( 'Working…', 'talenttrack' ),
            ],
        ] );
    }

    /**
     * Inline create form — §3 exemption (a). Collapsed by default so the
     * list stays the point of the page.
     */
    private static function renderCreateForm( int $user_id ): void {
        echo '<details class="tt-ex-create">';
        echo '<summary class="tt-ex-create__summary">' . esc_html__( '+ New exercise', 'talenttrack' ) . '</summary>';
        echo '<form class="tt-ex-create__form" method="post" action="">';
        wp_nonce_field( 'tt_exercise_create', 'tt_exercise_nonce' );
        echo '<input type="hidden" name="tt_action" value="create_exercise">';

        echo '<div class="tt-ex-create__grid">';

        self::field( 'name', __( 'Name', 'talenttrack' ), 'text', [ 'required' => true ] );
        self::field( 'code', __( 'Code (optional)', 'talenttrack' ), 'text' );

        echo '<label class="tt-field"><span>' . esc_html__( 'Category', 'talenttrack' ) . '</span>';
        echo '<select name="category_id">';
        foreach ( self::categoryOptions() as $value => $label ) {
            printf( '<option value="%s">%s</option>', esc_attr( (string) $value ), esc_html( (string) $label ) );
        }
        echo '</select></label>';

        self::field( 'duration_minutes', __( 'Typical duration (minutes)', 'talenttrack' ), 'number', [ 'inputmode' => 'numeric', 'min' => 0, 'max' => 240 ] );
        self::field( 'players_min', __( 'Smallest group', 'talenttrack' ), 'number', [ 'inputmode' => 'numeric', 'min' => 1, 'max' => 40 ] );
        self::field( 'players_max', __( 'Largest group', 'talenttrack' ), 'number', [ 'inputmode' => 'numeric', 'min' => 1, 'max' => 40 ] );

        // 1–10, matching the ten seeded `vct_intensity_band` lookup rows
        // and the age profiles, which cap U13/U14 at 7. An earlier 1–5
        // select here did not just mislabel: saving through it clamped a
        // band 6–7 exercise down to 5.
        echo '<div class="tt-field">';
        echo '<label class="tt-field__label" for="tt-ex-band">' . esc_html__( 'Intensity', 'talenttrack' ) . '</label>';
        echo '<select id="tt-ex-band" name="intensity_band">';
        echo '<option value="">' . esc_html__( '— not set —', 'talenttrack' ) . '</option>';
        for ( $band = 1; $band <= 10; $band++ ) {
            printf(
                '<option value="%1$d">%2$s</option>',
                $band,
                /* translators: %d is an intensity level from 1 to 10. */
                esc_html( sprintf( __( 'Level %d', 'talenttrack' ), $band ) )
            );
        }
        echo '</select>';
        echo '<p class="tt-field__hint">'
            . esc_html__( 'Higher is harder. Each age group has its own ceiling, and the generator never proposes an exercise above it.', 'talenttrack' )
            . '</p>';
        echo '</div>';

        echo '<label class="tt-field tt-field--full"><span>' . esc_html__( 'Description and organisation', 'talenttrack' ) . '</span>';
        echo '<textarea name="description" rows="4"></textarea></label>';

        echo '<label class="tt-field tt-field--full"><span>' . esc_html__( 'Diagram image URL (optional)', 'talenttrack' ) . '</span>';
        echo '<input type="url" name="diagram_url" inputmode="url" autocomplete="off"></label>';

        self::renderPrincipleField();

        echo '<div class="tt-field tt-field--full">';
        echo '<span class="tt-field__label">' . esc_html__( 'Visible to', 'talenttrack' ) . '</span>';
        echo '<select name="visibility">';
        echo '<option value="team">' . esc_html__( 'My team', 'talenttrack' ) . '</option>';
        echo '<option value="private">' . esc_html__( 'Only me', 'talenttrack' ) . '</option>';
        if ( self::canPromote( $user_id ) ) {
            echo '<option value="club">' . esc_html__( 'Whole club', 'talenttrack' ) . '</option>';
        }
        echo '</select>';
        echo '<p class="tt-field__hint">'
            . esc_html__( 'A new exercise stays with your team and is usable in your plans right away. The head of development decides what becomes club-wide.', 'talenttrack' )
            . '</p>';
        echo '</div>';

        echo '</div>';

        // §6 — Cancel first in DOM, Save right on screen.
        echo FormSaveButton::render( [ // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — helper returns escaped HTML.
            'label'      => __( 'Save exercise', 'talenttrack' ),
            'cancel_url' => self::listUrl(),
        ] );

        echo '</form>';
        echo '</details>';
    }

    /**
     * Which principles this drill trains (#2497).
     *
     * This is not decoration. It is the link the generator ranks
     * candidates by and the one wave 7 computes per-player training
     * exposure through — an untagged exercise is invisible to both, which
     * is why the hint says so rather than leaving the field looking
     * optional-in-the-boring-sense.
     */
    private static function renderPrincipleField(): void {
        $principles = ( new ExercisesRepository() )->listPrinciples();
        if ( ! $principles ) return;

        echo '<div class="tt-field tt-field--full">';
        echo '<label class="tt-field__label" for="tt-ex-principles">'
            . esc_html__( 'Trains which principles', 'talenttrack' )
            . '</label>';
        echo '<select id="tt-ex-principles" name="principle_ids[]" multiple size="6">';

        $current_group = null;
        foreach ( $principles as $principle ) {
            $group = self::phaseLabel(
                (string) ( $principle->team_function_key ?? '' ),
                (string) ( $principle->team_task_key ?? '' )
            );
            if ( $group !== $current_group ) {
                if ( $current_group !== null ) echo '</optgroup>';
                echo '<optgroup label="' . esc_attr( $group ) . '">';
                $current_group = $group;
            }

            printf(
                '<option value="%1$d">%2$s</option>',
                (int) $principle->id,
                esc_html( trim( (string) ( $principle->code ?? '' ) . ' · ' . self::principleTitle( $principle ) ) )
            );
        }
        if ( $current_group !== null ) echo '</optgroup>';

        echo '</select>';
        echo '<p class="tt-field__hint">'
            . esc_html__( 'Hold Ctrl or Cmd to pick more than one. An exercise with no principle never gets suggested by the generator, and the time spent on it does not count towards what your players have been taught.', 'talenttrack' )
            . '</p>';
        echo '</div>';
    }

    private static function principleTitle( object $principle ): string {
        $decoded = json_decode( (string) ( $principle->title_json ?? '' ), true );
        if ( ! is_array( $decoded ) ) return '';

        $locale = function_exists( 'determine_locale' ) ? determine_locale() : 'nl_NL';
        $title  = $decoded[ $locale ] ?? $decoded['nl_NL'] ?? $decoded['en_US'] ?? reset( $decoded );

        return is_string( $title ) ? mb_substr( $title, 0, 70 ) : '';
    }

    /** Group the select by game phase, which is how a coach thinks about it. */
    private static function phaseLabel( string $function, string $task ): string {
        switch ( $function . '|' . $task ) {
            case 'aanvallen|opbouwen':                            return __( 'Attacking — build-up', 'talenttrack' );
            case 'aanvallen|scoren':                              return __( 'Attacking — finishing', 'talenttrack' );
            case 'verdedigen|storen':                             return __( 'Defending — pressing', 'talenttrack' );
            case 'verdedigen|doelpunten_voorkomen':               return __( 'Defending — preventing goals', 'talenttrack' );
            case 'omschakelen_naar_aanvallen|overgang_balwinst':  return __( 'Transition — winning the ball', 'talenttrack' );
            case 'omschakelen_naar_verdedigen|overgang_balverlies': return __( 'Transition — losing the ball', 'talenttrack' );
        }
        return __( 'Other', 'talenttrack' );
    }

    /**
     * @param array<string,mixed> $attrs
     */
    private static function field( string $name, string $label, string $type, array $attrs = [] ): void {
        $extra = '';
        foreach ( $attrs as $key => $value ) {
            if ( $key === 'required' ) { $extra .= ' required'; continue; }
            $extra .= sprintf( ' %s="%s"', esc_attr( (string) $key ), esc_attr( (string) $value ) );
        }
        printf(
            '<label class="tt-field"><span>%1$s</span><input type="%2$s" name="%3$s"%4$s></label>',
            esc_html( $label ),
            esc_attr( $type ),
            esc_attr( $name ),
            $extra // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — each attribute escaped above.
        );
    }

    /**
     * The ten intensity bands, as filter options.
     *
     * @return array<string,string>
     */
    private static function intensityOptions(): array {
        $out = [ '' => __( 'Any intensity', 'talenttrack' ) ];
        for ( $band = 1; $band <= 10; $band++ ) {
            /* translators: %d is an intensity level from 1 to 10. */
            $out[ (string) $band ] = sprintf( __( 'Level %d', 'talenttrack' ), $band );
        }
        return $out;
    }

    /**
     * @return array<string,string>
     */
    private static function categoryOptions(): array {
        $out = [ '' => __( 'Any category', 'talenttrack' ) ];
        foreach ( ( new ExercisesRepository() )->listCategories() as $category ) {
            $out[ (string) ( $category->id ?? '' ) ] = (string) ( $category->label ?? '' );
        }
        return $out;
    }

    private static function renderDetail( int $id, int $user_id ): void {
        $repo = new ExercisesRepository();
        $row  = $repo->findById( $id );

        if ( ! $row ) {
            FrontendBreadcrumbs::fromDashboard(
                __( 'Not found', 'talenttrack' ),
                [ FrontendBreadcrumbs::viewCrumb( 'exercises', __( 'Exercises', 'talenttrack' ) ) ]
            );
            echo '<p class="tt-notice">' . esc_html__( 'That exercise no longer exists.', 'talenttrack' ) . '</p>';
            return;
        }

        FrontendBreadcrumbs::fromDashboard(
            (string) $row->name,
            [ FrontendBreadcrumbs::viewCrumb( 'exercises', __( 'Exercises', 'talenttrack' ) ) ]
        );
        self::renderHeader( (string) $row->name );

        $facts = [];
        if ( ! empty( $row->duration_minutes ) ) {
            $facts[ __( 'Duration', 'talenttrack' ) ] = sprintf(
                /* translators: %d is a number of minutes. */
                _n( '%d minute', '%d minutes', (int) $row->duration_minutes, 'talenttrack' ),
                (int) $row->duration_minutes
            );
        }
        if ( isset( $row->players_min, $row->players_max ) && $row->players_min && $row->players_max ) {
            $facts[ __( 'Group size', 'talenttrack' ) ] = sprintf(
                /* translators: 1: smallest group size, 2: largest group size. */
                __( '%1$d–%2$d players', 'talenttrack' ),
                (int) $row->players_min,
                (int) $row->players_max
            );
        }
        if ( ! empty( $row->intensity_band ) ) {
            $facts[ __( 'Intensity', 'talenttrack' ) ] = (string) (int) $row->intensity_band;
        }
        $facts[ __( 'Visible to', 'talenttrack' ) ] = self::visibilityLabel( (string) ( $row->visibility ?? 'club' ) );

        echo '<div class="tt-ex-facts">';
        foreach ( $facts as $label => $value ) {
            echo '<div class="tt-ex-fact">'
                . '<span class="tt-ex-fact__label">' . esc_html( (string) $label ) . '</span>'
                . '<span class="tt-ex-fact__value">' . esc_html( (string) $value ) . '</span>'
                . '</div>';
        }
        echo '</div>';

        if ( ! empty( $row->diagram_url ) ) {
            printf(
                '<img class="tt-ex-diagram" src="%s" alt="%s" loading="lazy">',
                esc_url( (string) $row->diagram_url ),
                esc_attr__( 'Diagram for this exercise', 'talenttrack' )
            );
        }

        if ( ! empty( $row->description ) ) {
            echo '<p class="tt-ex-description">' . esc_html( (string) $row->description ) . '</p>';
        }

        if ( (string) ( $row->source ?? 'club' ) !== 'club' ) {
            echo '<p class="tt-notice tt-ex-shipped">'
                . esc_html__( 'This exercise ships with TalentTrack, so it cannot be edited here. Duplicate it to make a version of your own.', 'talenttrack' )
                . '</p>';
        }
    }

    private static function visibilityLabel( string $visibility ): string {
        switch ( $visibility ) {
            case 'team':    return __( 'One team', 'talenttrack' );
            case 'private': return __( 'Only me', 'talenttrack' );
        }
        return __( 'Whole club', 'talenttrack' );
    }
}
