<?php
namespace TT\Modules\Evaluations\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\CategoryWeightsRepository;
use TT\Infrastructure\Evaluations\EvalCategoriesRepository;
use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\REST\RestResponse;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Shared\Frontend\Components\FrontendBreadcrumbs;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendCategoryWeightsView (#2977) — per-age-group category weights on
 * the frontend.
 *
 * Category weights decide what an evaluation score *means*: change them and
 * every composite rating the academy reads is re-weighted. That is the most
 * consequential piece of evaluation configuration there is, and until now it
 * lived only behind `wp-admin/admin.php?page=tt-category-weights`.
 *
 * ## Its own slug, not folded into `eval-categories`
 *
 * The issue offered both. This is an **age-group × main-category matrix** —
 * one form per age group, each with a weight per main category, a live total
 * and its own reset. Four age groups and four mains is sixteen inputs across
 * four independent forms, which is not a column that can hang off the
 * categories list without taking it over.
 *
 * ## The same repository, deliberately
 *
 * `CategoryWeightsRepository` already owns every decision: the equal-weights
 * fallback, the must-sum-to-100 validation, the save, and the reset-by-delete.
 * This view and `Admin\CategoryWeightsPage` both call into it and neither
 * re-implements any of it, which is what stops the two screens disagreeing.
 *
 * ## Capabilities
 *
 * Reads gate on `tt_view_category_weights`, writes on
 * `tt_edit_category_weights` — the purpose-built pair, mapped in
 * `LegacyCapMapper` to the `category_weights` entity and already used by the
 * admin menu entry and the dashboard tile.
 *
 * The wp-admin page itself gates both its render *and* its save on the
 * broader `tt_view_settings`, which is narrower-is-safer in the wrong
 * direction: a view capability guarding a write. Deliberately not changed
 * here, because altering who can edit in wp-admin is a permissions change
 * that deserves its own review rather than riding along with a port.
 *
 * ## §6 Save + Cancel
 *
 * Exemption (a): multiple independent settings sub-forms on one page. Each
 * age group saves on its own and "leaving without saving" is just navigating
 * away, exactly as on `FrontendConfigurationView`.
 */
class FrontendCategoryWeightsView extends FrontendViewBase {

    public const SLUG     = 'eval-category-weights';
    public const CAP_VIEW = 'tt_view_category_weights';
    public const CAP_EDIT = 'tt_edit_category_weights';

    /** Wired from `Kernel::boot`, like the other frontend settings surfaces. */
    public static function init(): void {
        add_action( 'admin_post_tt_category_weights_frontend_save', [ self::class, 'handleSave' ] );
        add_action( 'admin_post_tt_category_weights_frontend_reset', [ self::class, 'handleReset' ] );
        add_action( 'rest_api_init', [ self::class, 'registerRest' ] );
    }

    // ── REST ───────────────────────────────────────────────────────────

    public static function registerRest(): void {
        register_rest_route( 'talenttrack/v1', '/evaluations/category-weights', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'restGet' ],
                'permission_callback' => static fn() => AuthorizationService::userCanOrMatrix( get_current_user_id(), self::CAP_VIEW ),
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ self::class, 'restPut' ],
                'permission_callback' => static fn() => AuthorizationService::userCanOrMatrix( get_current_user_id(), self::CAP_EDIT ),
                'args'                => [
                    'age_group_id' => [ 'required' => true, 'type' => 'integer' ],
                    'weights'      => [ 'required' => false, 'type' => 'object' ],
                ],
            ],
        ] );
    }

    /**
     * GET /evaluations/category-weights
     *
     * Every age group, whether or not it has a configured set — an age group
     * on the equal-weights fallback is a real answer, not an absent one, and
     * a consumer that only saw configured rows could not tell the difference
     * between "equal by choice" and "not set up".
     */
    public static function restGet( \WP_REST_Request $r ): \WP_REST_Response {
        /** @var list<object{id:int,label:string}> $mains */
        $mains = ( new EvalCategoriesRepository() )->getMainCategories( true );
        /** @var list<object{id:int}> $groups */
        $groups = QueryHelpers::get_lookups( 'age_group' );

        $main_ids = array_map( static fn( $m ) => (int) $m->id, $mains );
        $stored   = ( new CategoryWeightsRepository() )->getForAgeGroups(
            array_map( static fn( $g ) => (int) $g->id, $groups )
        );
        $equal = CategoryWeightsRepository::equalWeightsForMains( $main_ids );

        $items = [];
        foreach ( $groups as $group ) {
            $id        = (int) $group->id;
            $weights   = $stored[ $id ] ?? [];
            $configured = $weights !== [];

            $out = [];
            foreach ( $mains as $main ) {
                $mid   = (int) $main->id;
                $out[] = [
                    'category_id' => $mid,
                    'label'       => EvalCategoriesRepository::displayLabel( (string) $main->label ),
                    'weight'      => (int) ( $weights[ $mid ] ?? $equal[ $mid ] ?? 0 ),
                ];
            }

            $items[] = [
                'age_group_id' => $id,
                'age_group'    => LookupTranslator::name( $group ),
                'configured'   => $configured,
                'weights'      => $out,
            ];
        }

        return RestResponse::success( [ 'items' => $items ] );
    }

    /**
     * PUT /evaluations/category-weights
     *
     * An empty `weights` object resets the age group to the equal fallback,
     * which is the same operation the screen's "Reset to equal" performs —
     * the repository deletes the rows rather than storing 25/25/25/25, so
     * "equal because nobody configured it" stays distinguishable from
     * "equal because somebody chose it".
     */
    public static function restPut( \WP_REST_Request $r ): \WP_REST_Response {
        $age_group_id = (int) $r['age_group_id'];
        if ( $age_group_id <= 0 ) {
            return RestResponse::error( 'missing_age_group', __( 'Missing age group.', 'talenttrack' ), 400 );
        }

        $raw     = (array) ( $r['weights'] ?? [] );
        $repo    = new CategoryWeightsRepository();

        if ( $raw === [] ) {
            $repo->deleteForAgeGroup( $age_group_id );
            return RestResponse::success( [ 'age_group_id' => $age_group_id, 'configured' => false ] );
        }

        $weights = self::sanitizeWeights( $raw );

        $sum = CategoryWeightsRepository::validateSumsTo100( $weights );
        if ( $sum !== null ) {
            return RestResponse::error(
                'sum_not_100',
                sprintf(
                    /* translators: %d: the total the submitted weights add up to. */
                    __( 'Weights must sum to 100. Current total: %d.', 'talenttrack' ),
                    $sum
                ),
                400
            );
        }

        if ( ! $repo->saveForAgeGroup( $age_group_id, $weights ) ) {
            return RestResponse::error( 'save_failed', __( 'The database rejected the save. Try again.', 'talenttrack' ), 500 );
        }

        return RestResponse::success( [ 'age_group_id' => $age_group_id, 'configured' => true ] );
    }

    // ── Render ─────────────────────────────────────────────────────────

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! AuthorizationService::userCanOrMatrix( $user_id, self::CAP_VIEW ) ) {
            FrontendBreadcrumbs::fromDashboard( __( 'Not authorized', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view category weights.', 'talenttrack' ) . '</p>';
            return;
        }

        FrontendBreadcrumbs::fromDashboard(
            __( 'Category weights', 'talenttrack' ),
            [ FrontendBreadcrumbs::viewCrumb( 'configuration', __( 'Configuration', 'talenttrack' ) ) ]
        );

        self::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-category-weights',
            TT_PLUGIN_URL . 'assets/css/frontend-category-weights.css',
            [ 'tt-public' ],
            TT_VERSION
        );
        wp_enqueue_script(
            'tt-category-weights',
            TT_PLUGIN_URL . 'assets/js/frontend-category-weights.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script( 'tt-category-weights', 'TT_CATEGORY_WEIGHTS', [
            'i18n' => [
                'sum_ok'  => __( 'Adds up to 100', 'talenttrack' ),
                'sum_bad' => __( 'Must add up to 100', 'talenttrack' ),
            ],
        ] );

        self::renderHeader( __( 'Category weights', 'talenttrack' ) );

        $can_edit = AuthorizationService::userCanOrMatrix( $user_id, self::CAP_EDIT );

        echo '<p class="tt-muted tt-cw-intro">'
            . esc_html__( 'How much each main category counts towards a player\'s overall rating, per age group. The values are percentages and must add up to 100. An age group with nothing configured weights every category equally.', 'talenttrack' )
            . '</p>';

        self::renderFlash();

        /** @var list<object{id:int,label:string}> $mains */
        $mains = ( new EvalCategoriesRepository() )->getMainCategories( true );
        if ( $mains === [] ) {
            echo '<p class="tt-empty">' . esc_html__( 'No active main categories yet. Add them under Evaluation categories first.', 'talenttrack' ) . '</p>';
            return;
        }

        /** @var list<object{id:int}> $groups */
        $groups = QueryHelpers::get_lookups( 'age_group' );
        if ( $groups === [] ) {
            echo '<p class="tt-empty">' . esc_html__( 'No age groups yet. Add them under Configuration first.', 'talenttrack' ) . '</p>';
            return;
        }

        $stored = ( new CategoryWeightsRepository() )->getForAgeGroups(
            array_map( static fn( $g ) => (int) $g->id, $groups )
        );
        $equal = CategoryWeightsRepository::equalWeightsForMains(
            array_map( static fn( $m ) => (int) $m->id, $mains )
        );

        foreach ( $groups as $group ) {
            self::renderGroupForm( $group, $mains, $stored, $equal, $can_edit );
        }
    }

    /**
     * @param object{id:int}                    $group
     * @param list<object{id:int,label:string}> $mains
     * @param array<int,array<int,int>>         $stored
     * @param array<int,int>                    $equal
     */
    private static function renderGroupForm( object $group, array $mains, array $stored, array $equal, bool $can_edit ): void {
        $ag_id      = (int) $group->id;
        $weights    = $stored[ $ag_id ] ?? [];
        $configured = $weights !== [];

        echo '<section class="tt-panel tt-cw-group">';

        echo '<h3 class="tt-panel-title tt-cw-group__title">'
            . esc_html( LookupTranslator::name( $group ) )
            . '<span class="tt-cw-status ' . ( $configured ? 'tt-cw-status--set' : 'tt-cw-status--fallback' ) . '">'
            . esc_html( $configured
                ? __( 'Configured', 'talenttrack' )
                : __( 'Equal weights', 'talenttrack' ) )
            . '</span></h3>';

        echo '<form class="tt-cw-form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" data-tt-cw-form="1">';
        wp_nonce_field( 'tt_category_weights_frontend_save_' . $ag_id, 'tt_nonce' );
        echo '<input type="hidden" name="action" value="tt_category_weights_frontend_save" />';
        echo '<input type="hidden" name="age_group_id" value="' . (int) $ag_id . '" />';

        echo '<div class="tt-cw-rows">';
        foreach ( $mains as $main ) {
            $mid   = (int) $main->id;
            $value = (int) ( $weights[ $mid ] ?? $equal[ $mid ] ?? 0 );
            $id    = 'tt-cw-' . $ag_id . '-' . $mid;

            echo '<div class="tt-cw-row">';
            echo '<label class="tt-cw-row__label" for="' . esc_attr( $id ) . '">'
                . esc_html( EvalCategoriesRepository::displayLabel( (string) $main->label ) )
                . '</label>';
            echo '<span class="tt-cw-row__input">';
            printf(
                '<input type="number" inputmode="numeric" id="%1$s" name="weights[%2$d]" value="%3$d" min="0" max="100" step="1" class="tt-input tt-cw-input"%4$s />',
                esc_attr( $id ),
                (int) $mid,
                (int) $value,
                $can_edit ? '' : ' disabled'
            );
            echo '<span class="tt-cw-row__unit" aria-hidden="true">%</span>';
            echo '</span>';
            echo '</div>';
        }
        echo '</div>';

        // The live total is the whole reason this is not four loose inputs:
        // the rule is "must add up to 100", so the sum has to be visible
        // while typing rather than reported after a failed save.
        echo '<p class="tt-cw-total" data-tt-cw-total="1">'
            . '<span class="tt-cw-total__label">' . esc_html__( 'Total', 'talenttrack' ) . '</span> '
            . '<span class="tt-cw-total__value" data-tt-cw-sum="1">—</span>'
            . '<span class="tt-cw-total__hint" data-tt-cw-hint="1" role="status"></span>'
            . '</p>';

        if ( $can_edit ) {
            echo '<div class="tt-form-actions tt-cw-actions">';
            if ( $configured ) {
                $reset = wp_nonce_url(
                    admin_url( 'admin-post.php?action=tt_category_weights_frontend_reset&age_group_id=' . $ag_id ),
                    'tt_category_weights_frontend_reset_' . $ag_id
                );
                echo '<a class="tt-btn tt-btn-secondary tt-cw-reset" href="' . esc_url( $reset ) . '"'
                    . ' data-tt-confirm="' . esc_attr__( 'Reset this age group to equal weights?', 'talenttrack' ) . '">'
                    . esc_html__( 'Reset to equal', 'talenttrack' )
                    . '</a>';
            }
            echo '<button type="submit" class="tt-btn tt-btn-primary" data-tt-cw-save="1">'
                . esc_html__( 'Save weights', 'talenttrack' )
                . '</button>';
            echo '</div>';
        }

        echo '</form>';
        echo '</section>';
    }

    private static function renderFlash(): void {
        $msg = isset( $_GET['tt_msg'] ) ? sanitize_key( (string) wp_unslash( $_GET['tt_msg'] ) ) : '';
        if ( $msg === 'saved' ) {
            echo '<div class="tt-flash tt-flash-success">' . esc_html__( 'Weights saved.', 'talenttrack' ) . '</div>';
        } elseif ( $msg === 'reset' ) {
            echo '<div class="tt-flash tt-flash-success">' . esc_html__( 'Weights reset to equal.', 'talenttrack' ) . '</div>';
        }

        $err = isset( $_GET['tt_error'] ) ? sanitize_key( (string) wp_unslash( $_GET['tt_error'] ) ) : '';
        if ( $err === '' ) return;

        if ( $err === 'sum_not_100' ) {
            $detail = isset( $_GET['tt_detail'] ) ? (int) $_GET['tt_detail'] : 0;
            echo '<div class="tt-flash tt-flash-error">' . esc_html( sprintf(
                /* translators: %d: the total the submitted weights add up to. */
                __( 'Weights must sum to 100. Current total: %d.', 'talenttrack' ),
                $detail
            ) ) . '</div>';
            return;
        }

        $map = [
            'missing_age_group' => __( 'Missing age group.', 'talenttrack' ),
            'save_failed'       => __( 'The database rejected the save. Try again.', 'talenttrack' ),
            'forbidden'         => __( 'You do not have permission to change category weights.', 'talenttrack' ),
        ];

        echo '<div class="tt-flash tt-flash-error">'
            . esc_html( $map[ $err ] ?? __( 'Something went wrong.', 'talenttrack' ) )
            . '</div>';
    }

    // ── Handlers ───────────────────────────────────────────────────────

    public static function handleSave(): void {
        $age_group_id = isset( $_POST['age_group_id'] ) ? absint( $_POST['age_group_id'] ) : 0;
        check_admin_referer( 'tt_category_weights_frontend_save_' . $age_group_id, 'tt_nonce' );

        if ( ! AuthorizationService::userCanOrMatrix( get_current_user_id(), self::CAP_EDIT ) ) {
            self::back( [ 'tt_error' => 'forbidden' ] );
        }
        if ( $age_group_id <= 0 ) {
            self::back( [ 'tt_error' => 'missing_age_group' ] );
        }

        $raw     = isset( $_POST['weights'] ) && is_array( $_POST['weights'] ) ? wp_unslash( $_POST['weights'] ) : [];
        $weights = self::sanitizeWeights( (array) $raw );

        $sum = CategoryWeightsRepository::validateSumsTo100( $weights );
        if ( $sum !== null ) {
            self::back( [ 'tt_error' => 'sum_not_100', 'tt_detail' => $sum ] );
        }

        if ( ! ( new CategoryWeightsRepository() )->saveForAgeGroup( $age_group_id, $weights ) ) {
            self::back( [ 'tt_error' => 'save_failed' ] );
        }

        self::back( [ 'tt_msg' => 'saved' ] );
    }

    public static function handleReset(): void {
        $age_group_id = isset( $_GET['age_group_id'] ) ? absint( $_GET['age_group_id'] ) : 0;
        check_admin_referer( 'tt_category_weights_frontend_reset_' . $age_group_id );

        if ( ! AuthorizationService::userCanOrMatrix( get_current_user_id(), self::CAP_EDIT ) ) {
            self::back( [ 'tt_error' => 'forbidden' ] );
        }

        if ( $age_group_id > 0 ) {
            ( new CategoryWeightsRepository() )->deleteForAgeGroup( $age_group_id );
        }

        self::back( [ 'tt_msg' => 'reset' ] );
    }

    /**
     * Clamp each submitted weight to 0-100 and drop anything that is not a
     * real category id.
     *
     * Clamping mirrors `Admin\CategoryWeightsPage::handleSave()` exactly. It
     * is not a substitute for the sum check — a clamped set still has to
     * total 100, and the caller runs `validateSumsTo100()` afterwards.
     *
     * @param array<mixed,mixed> $raw
     * @return array<int,int>
     */
    private static function sanitizeWeights( array $raw ): array {
        $weights = [];
        foreach ( $raw as $main_id => $value ) {
            $main_id = (int) $main_id;
            if ( $main_id <= 0 ) continue;
            $weights[ $main_id ] = max( 0, min( 100, (int) $value ) );
        }
        return $weights;
    }

    /** @param array<string,int|string> $args */
    private static function back( array $args ): void {
        $url = add_query_arg(
            array_merge( [ 'tt_view' => self::SLUG ], $args ),
            \TT\Shared\Frontend\Components\RecordLink::dashboardUrl() /* tt-xview-ok — returns to this same view */
        );
        wp_safe_redirect( $url );
        exit;
    }
}
