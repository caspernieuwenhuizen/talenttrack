<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Kernel;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Auth\LoginForm;
use TT\Modules\Auth\LogoutHandler;

/**
 * DashboardShortcode — owns [talenttrack_dashboard].
 *
 * v2.6.1: adds a "Go to Admin" link to the user-menu dropdown for
 * administrators only. Non-admins see only Edit profile + Log out.
 */
class DashboardShortcode {

    public static function register(): void {
        add_shortcode( 'talenttrack_dashboard', [ __CLASS__, 'render' ] );
    }

    /**
     * @param array<string,mixed>|string $atts
     */
    public static function render( $atts = [] ): string {
        wp_enqueue_style( 'tt-public', TT_PLUGIN_URL . 'assets/css/public.css', [], TT_VERSION );
        // #0019 Sprint 1 session 3 — shared component + token stylesheet
        // loaded alongside the legacy one. Every new component reads from
        // tokens defined here; public.css keeps the legacy dashboard/login
        // layout untouched.
        wp_enqueue_style( 'tt-frontend-admin', TT_PLUGIN_URL . 'assets/css/frontend-admin.css', [ 'tt-public' ], TT_VERSION );

        wp_enqueue_script( 'tt-public', TT_PLUGIN_URL . 'assets/js/public.js', [], TT_VERSION, true );
        wp_enqueue_script( 'tt-flash',   TT_PLUGIN_URL . 'assets/js/components/flash.js',     [], TT_VERSION, true );
        wp_enqueue_script( 'tt-drafts',  TT_PLUGIN_URL . 'assets/js/drafts.js',               [ 'tt-public' ], TT_VERSION, true );
        wp_enqueue_script( 'tt-rating',  TT_PLUGIN_URL . 'assets/js/components/rating.js',    [], TT_VERSION, true );
        wp_enqueue_script( 'tt-multitag', TT_PLUGIN_URL . 'assets/js/components/multitag.js', [], TT_VERSION, true );
        // F1-F6 sprint — autocomplete-driven player picker.
        wp_enqueue_script( 'tt-player-search-picker', TT_PLUGIN_URL . 'assets/js/components/player-search-picker.js', [], TT_VERSION, true );
        // #0019 Sprint 2 session 2 — FrontendListTable hydrator. The PHP
        // shell embeds its own config/state JSON; this script binds to
        // every `.tt-list-table` on the page and takes over filter/sort/
        // pagination so changes happen without a full reload.
        wp_enqueue_script( 'tt-list-table', TT_PLUGIN_URL . 'assets/js/components/frontend-list-table.js', [], TT_VERSION, true );
        // #0019 Sprint 2 session 2.3 — attendance helpers (bulk-present,
        // mobile pagination at 15, team filter, live summary).
        wp_enqueue_script( 'tt-attendance', TT_PLUGIN_URL . 'assets/js/components/attendance.js', [], TT_VERSION, true );
        // #0019 Sprint 3 session 3.2 — team roster add/remove + CSV import flow.
        wp_enqueue_script( 'tt-team-roster', TT_PLUGIN_URL . 'assets/js/components/team-roster.js', [], TT_VERSION, true );
        wp_enqueue_script( 'tt-csv-import',  TT_PLUGIN_URL . 'assets/js/components/csv-import.js',  [], TT_VERSION, true );
        // #0019 Sprint 4 — functional roles reorder + delete buttons.
        wp_enqueue_script( 'tt-functional-roles', TT_PLUGIN_URL . 'assets/js/components/functional-roles.js', [], TT_VERSION, true );
        // #0019 Sprint 5 — admin-tier reorder helper (eval categories).
        wp_enqueue_script( 'tt-admin-reorder', TT_PLUGIN_URL . 'assets/js/components/admin-reorder.js', [], TT_VERSION, true );

        // #0019 Sprint 1 session 2 — public.js uses fetch() against the
        // REST API. Nonce is the standard WP REST `wp_rest` nonce,
        // sent via the `X-WP-Nonce` header by the script.
        wp_localize_script( 'tt-public', 'TT', [
            'rest_url'   => esc_url_raw( rest_url( 'talenttrack/v1/' ) ),
            'rest_nonce' => wp_create_nonce( 'wp_rest' ),
            'i18n'       => [
                'saving'               => __( 'Saving...', 'talenttrack' ),
                'saved'                => __( 'Saved.', 'talenttrack' ),
                'error_generic'        => __( 'Error.', 'talenttrack' ),
                'network_error'        => __( 'Network error.', 'talenttrack' ),
                'confirm_delete_goal'  => __( 'Delete this goal?', 'talenttrack' ),
                'save_evaluation'      => __( 'Save Evaluation', 'talenttrack' ),
                'save_session'         => __( 'Save Session', 'talenttrack' ),
                'add_goal'             => __( 'Add Goal', 'talenttrack' ),
                'save'                 => __( 'Save', 'talenttrack' ),
                'draft_prompt'         => __( 'You have unsaved changes from an earlier session — restore?', 'talenttrack' ),
                'draft_restore'        => __( 'Restore', 'talenttrack' ),
                'draft_discard'        => __( 'Discard', 'talenttrack' ),
                'show_all_count'       => __( 'Show all (%d)', 'talenttrack' ),
                'attendance_summary'   => __( '%1$d of %2$d marked Present', 'talenttrack' ),
                'csv_preview_summary'  => __( 'Showing %1$d of %2$d rows.', 'talenttrack' ),
                'csv_dupe_of'          => __( 'Matches existing player #%d', 'talenttrack' ),
                'csv_created'          => __( 'Created: %d', 'talenttrack' ),
                'csv_updated'          => __( 'Updated: %d', 'talenttrack' ),
                'csv_skipped'          => __( 'Skipped (dupes): %d', 'talenttrack' ),
                'csv_errored'          => __( 'Errors: %d', 'talenttrack' ),
                'fnrole_delete_confirm' => __( 'Delete this role type?', 'talenttrack' ),
                'eval_cat_delete_confirm' => __( 'Delete this category?', 'talenttrack' ),
            ],
        ]);

        // Route guard — no partial render for logged-out users.
        if ( ! is_user_logged_in() ) {
            /** @var LoginForm $form */
            $form = Kernel::instance()->container()->get( 'auth.login_form' );
            $error = isset( $_GET['tt_login_error'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['tt_login_error'] ) ) : '';

            $reset_notice = '';
            if ( isset( $_GET['checkemail'] ) && $_GET['checkemail'] === 'confirm' ) {
                $reset_notice = '<div class="tt-notice-inline">'
                    . esc_html__( 'Check your email for a password reset link.', 'talenttrack' )
                    . '</div>';
            }
            return $reset_notice . $form->render( $error );
        }

        // NOTE: v2.17.0 — the ?tt_print=<id> frontend short-circuit was
        // removed. The PrintRouter (hooked on template_redirect) now
        // intercepts print requests before the shortcode callback fires.

        // Authenticated dashboard.
        ob_start();
        echo '<div class="tt-dashboard">';
        self::renderHeader();

        // #0019 Sprint 1 — flush any queued flash messages ahead of the
        // body so post-save redirects surface their result.
        FlashMessages::render();

        $user_id  = get_current_user_id();
        $is_admin = current_user_can( 'tt_edit_settings' );
        $is_coach = current_user_can( 'tt_edit_evaluations' );
        $player   = QueryHelpers::get_player_for_user( $user_id );

        // v3.0.0 — tile-based routing. Each ?tt_view=<slug> maps to a
        // focused FrontendXyzView class. Me-group slugs are prefixed
        // with "my-" to disambiguate from the coaching slugs of the
        // same entity (evaluations / sessions / goals).
        $view = isset( $_GET['tt_view'] ) ? sanitize_key( (string) $_GET['tt_view'] ) : '';

        $me_slugs        = [ 'overview', 'my-team', 'my-evaluations', 'my-sessions', 'my-goals', 'profile' ];
        $coaching_slugs  = [ 'teams', 'players', 'players-import', 'people', 'functional-roles', 'evaluations', 'sessions', 'goals', 'podium' ];
        $analytics_slugs = [ 'rate-cards', 'compare' ];
        // #0019 Sprint 5 — admin-tier surfaces, gated by tt_access_frontend_admin.
        $admin_slugs     = [ 'configuration', 'custom-fields', 'eval-categories', 'roles', 'migrations', 'usage-stats', 'usage-stats-details' ];

        if ( $view === '' ) {
            // Tile landing page.
            FrontendTileGrid::render();
        } elseif ( in_array( $view, $me_slugs, true ) ) {
            if ( $player ) {
                self::dispatchMeView( $view, $player );
            } else {
                FrontendBackButton::render();
                echo '<p class="tt-notice">' . esc_html__( 'This section is only available for users linked to a player record.', 'talenttrack' ) . '</p>';
            }
        } elseif ( in_array( $view, $coaching_slugs, true ) ) {
            if ( $is_coach || $is_admin ) {
                self::dispatchCoachingView( $view, $user_id, $is_admin );
            } else {
                FrontendBackButton::render();
                echo '<p class="tt-notice">' . esc_html__( 'This section is only available for coaches and administrators.', 'talenttrack' ) . '</p>';
            }
        } elseif ( in_array( $view, $analytics_slugs, true ) ) {
            // Analytics slugs — tt_view_reports is the gate. Observer
            // has it; so do coaches, admins, club admins, and head dev.
            if ( current_user_can( 'tt_view_reports' ) ) {
                self::dispatchAnalyticsView( $view );
            } else {
                FrontendBackButton::render();
                echo '<p class="tt-notice">' . esc_html__( 'Your role does not have access to analytics views.', 'talenttrack' ) . '</p>';
            }
        } elseif ( in_array( $view, $admin_slugs, true ) ) {
            // #0019 Sprint 5 — admin-tier surfaces. The view classes
            // each re-check the cap, so dispatching unauthenticated
            // is safe; the early-return there shows the friendly
            // "no permission" notice with a back button.
            self::dispatchAdminView( $view, $user_id, $is_admin );
        } else {
            FrontendBackButton::render();
            echo '<p><em>' . esc_html__( 'Unknown section.', 'talenttrack' ) . '</em></p>';
        }

        echo '</div>';

        /** @var string $output */
        $output = ob_get_clean() ?: '';
        /** @var string $filtered */
        $filtered = apply_filters( 'tt_dashboard_data', $output, $user_id );
        return $filtered;
    }

    /**
     * v3.0.0 — dispatch a Me-group tile slug to its FrontendXyzView
     * class. Called only when the user has a player record AND the
     * view slug is a Me-group slug.
     */
    private static function dispatchMeView( string $view, object $player ): void {
        switch ( $view ) {
            case 'overview':
                FrontendOverviewView::render( $player );
                break;
            case 'my-team':
                FrontendMyTeamView::render( $player );
                break;
            case 'teammate':
                $teammate_id = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;
                FrontendTeammateView::render( $player, $teammate_id );
                break;
            case 'my-evaluations':
                FrontendMyEvaluationsView::render( $player );
                break;
            case 'my-sessions':
                FrontendMySessionsView::render( $player );
                break;
            case 'my-goals':
                FrontendMyGoalsView::render( $player );
                break;
            case 'profile':
                FrontendMyProfileView::render( $player );
                break;
            default:
                FrontendBackButton::render();
                echo '<p><em>' . esc_html__( 'Unknown section.', 'talenttrack' ) . '</em></p>';
        }
    }

    /**
     * v3.0.0 slice 4 — dispatch a coaching-group tile slug.
     * Only called when the user has coach or admin caps.
     */
    private static function dispatchCoachingView( string $view, int $user_id, bool $is_admin ): void {
        switch ( $view ) {
            case 'teams':
                FrontendTeamsManageView::render( $user_id, $is_admin );
                break;
            case 'players':
                FrontendPlayersManageView::render( $user_id, $is_admin );
                break;
            case 'players-import':
                FrontendPlayersCsvImportView::render( $user_id, $is_admin );
                break;
            case 'people':
                FrontendPeopleManageView::render( $user_id, $is_admin );
                break;
            case 'functional-roles':
                FrontendFunctionalRolesView::render( $user_id, $is_admin );
                break;
            case 'evaluations':
                FrontendEvaluationsView::render( $user_id, $is_admin );
                break;
            case 'sessions':
                FrontendSessionsManageView::render( $user_id, $is_admin );
                break;
            case 'goals':
                FrontendGoalsManageView::render( $user_id, $is_admin );
                break;
            case 'podium':
                FrontendPodiumView::render( $user_id, $is_admin );
                break;
            default:
                FrontendBackButton::render();
                echo '<p><em>' . esc_html__( 'Unknown section.', 'talenttrack' ) . '</em></p>';
        }
    }

    /**
     * v3.0.0 slice 5 — dispatch an analytics-group tile slug.
     * Gate is tt_view_reports (the default Analytics separator cap),
     * which the Read-Only Observer role has. This is the observer's
     * primary frontend entry point.
     */
    private static function dispatchAnalyticsView( string $view ): void {
        switch ( $view ) {
            case 'rate-cards':
                FrontendRateCardView::render();
                break;
            case 'compare':
                FrontendComparisonView::render();
                break;
            default:
                FrontendBackButton::render();
                echo '<p><em>' . esc_html__( 'Unknown section.', 'talenttrack' ) . '</em></p>';
        }
    }

    /**
     * #0019 Sprint 5 — dispatch an admin-tier slug. Cap re-check
     * happens inside each view so the friendly back-button + notice
     * pattern is consistent.
     */
    private static function dispatchAdminView( string $view, int $user_id, bool $is_admin ): void {
        switch ( $view ) {
            case 'configuration':
                FrontendConfigurationView::render( $user_id, $is_admin );
                break;
            case 'custom-fields':
                FrontendCustomFieldsView::render( $user_id, $is_admin );
                break;
            case 'eval-categories':
                FrontendEvalCategoriesView::render( $user_id, $is_admin );
                break;
            case 'roles':
                FrontendRolesView::render( $user_id, $is_admin );
                break;
            case 'migrations':
                FrontendMigrationsView::render( $user_id, $is_admin );
                break;
            case 'usage-stats':
                FrontendUsageStatsView::render( $user_id, $is_admin );
                break;
            case 'usage-stats-details':
                FrontendUsageStatsDetailsView::render( $user_id, $is_admin );
                break;
            default:
                FrontendBackButton::render();
                echo '<p><em>' . esc_html__( 'Unknown section.', 'talenttrack' ) . '</em></p>';
        }
    }

    private static function renderHeader(): void {
        $logo = QueryHelpers::get_config( 'logo_url', '' );
        $name = QueryHelpers::get_config( 'academy_name', 'TalentTrack' );
        $user = wp_get_current_user();

        $profile_url = get_edit_profile_url( (int) $user->ID );
        $logout_url  = LogoutHandler::url();
        $is_wp_admin = current_user_can( 'administrator' );

        echo '<div class="tt-dash-header">';
        echo '<div class="tt-dash-brand">';
        if ( $logo ) echo '<img src="' . esc_url( $logo ) . '" class="tt-dash-logo" alt="" />';
        echo '<h2 class="tt-dash-title">' . esc_html( $name ) . '</h2>';
        echo '</div>';

        echo '<div class="tt-user-menu">';
        echo '<button type="button" class="tt-user-menu-trigger" aria-haspopup="true" aria-expanded="false">';
        echo '<span class="tt-user-menu-name">' . esc_html( $user->display_name ) . '</span>';
        echo '<span class="tt-user-menu-caret" aria-hidden="true">▾</span>';
        echo '</button>';
        echo '<div class="tt-user-menu-dropdown" role="menu">';
        echo '<a href="' . esc_url( $profile_url ) . '" class="tt-user-menu-item" role="menuitem">';
        echo esc_html__( 'Edit profile', 'talenttrack' );
        echo '</a>';
        if ( $is_wp_admin ) {
            echo '<a href="' . esc_url( admin_url() ) . '" class="tt-user-menu-item" role="menuitem">';
            echo esc_html__( 'Go to Admin', 'talenttrack' );
            echo '</a>';
        }
        echo '<a href="' . esc_url( $logout_url ) . '" class="tt-user-menu-item tt-user-menu-item-logout" role="menuitem">';
        echo esc_html__( 'Log out', 'talenttrack' );
        echo '</a>';
        echo '</div>';
        echo '</div>';
        echo '</div>';

        // Inline dropdown behaviour.
        ?>
        <script>
        (function(){
            var menu = document.currentScript.parentNode.querySelector('.tt-user-menu');
            if (!menu) return;
            var trigger = menu.querySelector('.tt-user-menu-trigger');
            var dropdown = menu.querySelector('.tt-user-menu-dropdown');
            if (!trigger || !dropdown) return;

            function closeMenu() {
                menu.classList.remove('is-open');
                trigger.setAttribute('aria-expanded', 'false');
            }
            function openMenu() {
                menu.classList.add('is-open');
                trigger.setAttribute('aria-expanded', 'true');
            }

            trigger.addEventListener('click', function(e){
                e.stopPropagation();
                if (menu.classList.contains('is-open')) { closeMenu(); } else { openMenu(); }
            });
            document.addEventListener('click', function(e){
                if (menu.classList.contains('is-open') && !menu.contains(e.target)) {
                    closeMenu();
                }
            });
            document.addEventListener('keydown', function(e){
                if (e.key === 'Escape' && menu.classList.contains('is-open')) {
                    closeMenu();
                    trigger.focus();
                }
            });
        })();
        </script>
        <?php
    }
}
