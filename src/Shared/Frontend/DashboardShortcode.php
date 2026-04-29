<?php
namespace TT\Shared\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Kernel;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Auth\LoginForm;
use TT\Modules\Auth\LogoutHandler;
use TT\Shared\Tiles\TileRegistry;

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
        // #0016 Part B — context-aware help drawer.
        wp_enqueue_script( 'tt-docs-drawer', TT_PLUGIN_URL . 'assets/js/components/docs-drawer.js', [], TT_VERSION, true );
        wp_localize_script( 'tt-docs-drawer', 'TT_DocsDrawer', [
            'rest_url'   => esc_url_raw( rest_url( 'talenttrack/v1/docs' ) ),
            'rest_nonce' => wp_create_nonce( 'wp_rest' ),
            'view_to_topic' => self::viewToTopicMap(),
            'default_slug'  => 'getting-started',
            'i18n' => [
                'loading'   => __( 'Loading…', 'talenttrack' ),
                'failed'    => __( 'Could not load this topic. Try the full Help & Docs page.', 'talenttrack' ),
                'no_topic'  => __( 'No matching topic for this section. Showing the default.', 'talenttrack' ),
            ],
        ] );

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
                // #3 — confirm dialog + post-event flash strings.
                'confirm_ok'                => __( 'OK', 'talenttrack' ),
                'confirm_cancel'            => __( 'Cancel', 'talenttrack' ),
                'confirm_delete_goal_title' => __( 'Delete goal?', 'talenttrack' ),
                'delete_label'              => __( 'Delete', 'talenttrack' ),
                'deleted_goal'              => __( 'Goal deleted.', 'talenttrack' ),
            ],
        ]);

        // #0032 — invitation acceptance must render before the login
        // guard. Token is the credential; the recipient may not have
        // an account yet.
        $tt_view_param = isset( $_GET['tt_view'] ) ? sanitize_key( (string) $_GET['tt_view'] ) : '';
        if ( $tt_view_param === 'accept-invite' ) {
            ob_start();
            echo '<div class="tt-dashboard">';
            FlashMessages::render();
            \TT\Modules\Invitations\Frontend\AcceptanceView::render();
            echo '</div>';
            return (string) ob_get_clean();
        }

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

        $me_slugs        = [ 'overview', 'my-team', 'my-evaluations', 'my-activities', 'my-goals', 'my-pdp', 'profile', 'my-journey' ];
        $coaching_slugs  = [ 'teams', 'players', 'players-import', 'people', 'functional-roles', 'evaluations', 'activities', 'goals', 'pdp', 'pdp-planning', 'player-status-methodology', 'team-chemistry', 'podium', 'methodology', 'player-journey' ];
        $analytics_slugs = [ 'rate-cards', 'compare' ];
        // #0019 Sprint 5 — admin-tier surfaces, gated by tt_access_frontend_admin.
        // #0021 — `audit-log` added; uses the same admin tier (cap-checked
        // again inside FrontendAuditLogView::render).
        $admin_slugs     = [ 'configuration', 'custom-fields', 'eval-categories', 'roles', 'migrations', 'usage-stats', 'usage-stats-details', 'audit-log', 'docs', 'cohort-transitions' ];
        // #0022 Sprint 2/5 — workflow surfaces, each cap-gated in dispatch.
        $workflow_slugs  = [ 'my-tasks', 'tasks-dashboard', 'workflow-config' ];
        // #0009 — Development management slugs. Each view re-checks its
        // own capability so dispatching here is safe.
        $dev_slugs       = [ 'submit-idea', 'ideas-board', 'ideas-refine', 'ideas-approval', 'dev-tracks' ];
        // #0032 — Invitation flow surfaces (logged-in admin tab; the
        // accept-invite route is handled before the login guard above).
        $invitation_slugs = [ 'invitations-config' ];
        // #0014 Sprints 4+5 — Reports wizard + scout flow.
        $report_slugs = [ 'report-wizard', 'scout-access', 'scout-history', 'scout-my-players' ];
        // #0017 — Trial player module surfaces.
        $trial_slugs = [ 'trials', 'trial-case', 'trial-parent-meeting', 'trial-tracks-editor', 'trial-letter-templates-editor' ];
        // #0055 — Record-creation wizards. Single slug; the actual
        // wizard is selected via &slug=… on the query string.
        // `wizards-admin` is the configuration + analytics surface.
        $wizard_slugs = [ 'wizard', 'wizards-admin' ];

        if ( $view !== '' ) {
            // #0056 — render the desktop_preferred banner at the top of
            // any dispatched view that carries the flag. CSS-gated
            // visibility means desktop / tablet users never see it; phone
            // users see it once until dismissed.
            FrontendDesktopPreferredBanner::render( $view );
        }

        // #0042 — install nudge for player + parent personas with no
        // active push subscription. Renders above every dispatched
        // view so the prompt is unmissable but not blocking.
        FrontendInstallBanner::render();

        if ( $view === '' ) {
            // #0060 — when the persona-dashboard feature flag is on,
            // PersonaLandingRenderer takes over the empty-view landing
            // and falls back to FrontendTileGrid internally if no
            // persona resolves or the resolved template is empty.
            if ( class_exists( '\\TT\\Modules\\PersonaDashboard\\Frontend\\PersonaLandingRenderer' )
                 && \TT\Modules\PersonaDashboard\Frontend\PersonaLandingRenderer::shouldRender() ) {
                \TT\Modules\PersonaDashboard\Frontend\PersonaLandingRenderer::render( $user_id, self::shortcodeBaseUrl() );
            } else {
                FrontendTileGrid::render();
            }
        } elseif ( TileRegistry::isViewSlugDisabled( $view ) ) {
            // #0033 finalisation — slug is owned by a module that is
            // currently disabled. Surface a friendly notice rather
            // than dispatching to a view whose backing module didn't
            // boot. Owner is resolved from the TileRegistry entry whose
            // `view_slug` matches.
            self::renderModuleDisabledNotice();
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
        } elseif ( in_array( $view, $workflow_slugs, true ) ) {
            // #0022 Sprint 2/5 — workflow surfaces. Each slug enforces its
            // own cap inside dispatch (my-tasks → tt_view_own_tasks,
            // tasks-dashboard → tt_view_tasks_dashboard, workflow-config
            // → tt_configure_workflow_templates).
            self::dispatchWorkflowView( $view, $user_id );
        } elseif ( in_array( $view, $dev_slugs, true ) ) {
            self::dispatchDevView( $view );
        } elseif ( in_array( $view, $invitation_slugs, true ) ) {
            self::dispatchInvitationView( $view );
        } elseif ( in_array( $view, $report_slugs, true ) ) {
            self::dispatchReportView( $view, $user_id, $is_admin );
        } elseif ( in_array( $view, $trial_slugs, true ) ) {
            self::dispatchTrialView( $view, $user_id, $is_admin );
        } elseif ( in_array( $view, $wizard_slugs, true ) ) {
            if ( $view === 'wizards-admin' ) {
                FrontendWizardsAdminView::render( $user_id, $is_admin );
            } else {
                FrontendWizardView::render( $user_id, $is_admin );
            }
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
            case 'my-activities':
                FrontendMyActivitiesView::render( $player );
                break;
            case 'my-goals':
                FrontendMyGoalsView::render( $player );
                break;
            case 'my-pdp':
                \TT\Modules\Pdp\Frontend\FrontendMyPdpView::render( $player );
                break;
            case 'profile':
                FrontendMyProfileView::render( $player );
                break;
            case 'my-journey':
                \TT\Modules\Journey\Frontend\FrontendJourneyView::render( $player );
                break;
            default:
                FrontendBackButton::render();
                echo '<p><em>' . esc_html__( 'Unknown section.', 'talenttrack' ) . '</em></p>';
        }
    }

    /**
     * #0022 Sprint 2 — dispatch a workflow-group tile slug. Currently
     * one slug (`my-tasks`); the inbox doubles as the task-detail
     * surface when `?task_id=N` is present.
     */
    private static function dispatchWorkflowView( string $view, int $user_id ): void {
        switch ( $view ) {
            case 'my-tasks':
                if ( ! current_user_can( 'tt_view_own_tasks' ) ) {
                    FrontendBackButton::render();
                    echo '<p class="tt-notice">' . esc_html__( 'Your role does not have access to tasks.', 'talenttrack' ) . '</p>';
                    return;
                }
                $task_id = isset( $_GET['task_id'] ) ? absint( $_GET['task_id'] ) : 0;
                if ( $task_id > 0 ) {
                    \TT\Modules\Workflow\Frontend\FrontendTaskDetailView::render( $user_id, $task_id );
                } else {
                    if ( ! empty( $_GET['tt_workflow_done'] ) ) {
                        echo '<div class="tt-notice notice-success" style="background:#e9f5e9; border-left:4px solid #2c8a2c; padding:8px 12px; margin: 8px 0 16px;">'
                            . esc_html__( 'Task completed.', 'talenttrack' ) . '</div>';
                    }
                    \TT\Modules\Workflow\Frontend\FrontendMyTasksView::render( $user_id );
                }
                break;
            case 'tasks-dashboard':
                \TT\Modules\Workflow\Frontend\FrontendTasksDashboardView::render( $user_id );
                break;
            case 'workflow-config':
                \TT\Modules\Workflow\Frontend\FrontendWorkflowConfigView::render( $user_id );
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
            case 'activities':
                FrontendActivitiesManageView::render( $user_id, $is_admin );
                break;
            case 'goals':
                FrontendGoalsManageView::render( $user_id, $is_admin );
                break;
            case 'pdp':
                \TT\Modules\Pdp\Frontend\FrontendPdpManageView::render( $user_id, $is_admin );
                break;
            case 'pdp-planning':
                FrontendBackButton::render();
                \TT\Modules\Pdp\Frontend\FrontendPdpPlanningView::render( $user_id, $is_admin );
                break;
            case 'player-status-methodology':
                FrontendBackButton::render();
                \TT\Modules\Players\Frontend\FrontendPlayerStatusMethodologyView::render( $user_id, $is_admin );
                break;
            case 'team-chemistry':
                if ( ! current_user_can( 'tt_view_team_chemistry' ) ) {
                    FrontendBackButton::render();
                    echo '<p class="tt-notice">' . esc_html__( 'Your role does not have access to team chemistry boards.', 'talenttrack' ) . '</p>';
                    break;
                }
                \TT\Modules\TeamDevelopment\Frontend\FrontendTeamChemistryView::render( $user_id, $is_admin );
                break;
            case 'podium':
                FrontendPodiumView::render( $user_id, $is_admin );
                break;
            case 'methodology':
                \TT\Modules\Methodology\Frontend\MethodologyView::render();
                break;
            case 'player-journey':
                $journey_player_id = isset( $_GET['player_id'] ) ? absint( $_GET['player_id'] ) : 0;
                $journey_player = $journey_player_id > 0 ? QueryHelpers::get_player( $journey_player_id ) : null;
                if ( ! $journey_player ) {
                    FrontendBackButton::render();
                    echo '<p class="tt-notice">' . esc_html__( 'Player not found.', 'talenttrack' ) . '</p>';
                } else {
                    \TT\Modules\Journey\Frontend\FrontendJourneyView::render( $journey_player );
                }
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
            case 'audit-log':
                FrontendAuditLogView::render( $user_id, $is_admin );
                break;
            case 'docs':
                FrontendDocsView::render( $user_id, $is_admin );
                break;
            case 'cohort-transitions':
                \TT\Modules\Journey\Frontend\FrontendCohortTransitionsView::render( $user_id, $is_admin );
                break;
            default:
                FrontendBackButton::render();
                echo '<p><em>' . esc_html__( 'Unknown section.', 'talenttrack' ) . '</em></p>';
        }
    }

    /**
     * #0032 — dispatch an invitation-management slug.
     */
    private static function dispatchInvitationView( string $view ): void {
        switch ( $view ) {
            case 'invitations-config':
                \TT\Modules\Invitations\Frontend\InvitationsConfigView::render();
                break;
            default:
                FrontendBackButton::render();
                echo '<p><em>' . esc_html__( 'Unknown section.', 'talenttrack' ) . '</em></p>';
        }
    }

    /**
     * #0009 — dispatch a development-management slug. Each view inside
     * the Development module re-checks its capability and renders the
     * "no permission" back-button pattern for non-eligible roles.
     */
    private static function dispatchDevView( string $view ): void {
        switch ( $view ) {
            case 'submit-idea':
                \TT\Modules\Development\Frontend\IdeaSubmitView::render();
                break;
            case 'ideas-board':
                \TT\Modules\Development\Frontend\IdeasBoardView::render();
                break;
            case 'ideas-refine':
                \TT\Modules\Development\Frontend\IdeasRefineView::render();
                break;
            case 'ideas-approval':
                \TT\Modules\Development\Frontend\IdeasApprovalView::render();
                break;
            case 'dev-tracks':
                \TT\Modules\Development\Frontend\TracksView::render();
                break;
            default:
                FrontendBackButton::render();
                echo '<p><em>' . esc_html__( 'Unknown section.', 'talenttrack' ) . '</em></p>';
        }
    }

    /**
     * #0014 Sprints 4+5 — Reports wizard + scout flow surfaces. Each
     * view re-checks its own capability so dispatching here is safe.
     */
    private static function dispatchReportView( string $view, int $user_id, bool $is_admin ): void {
        switch ( $view ) {
            case 'report-wizard':
                FrontendReportWizardView::render( $user_id, $is_admin );
                break;
            case 'scout-access':
                if ( ! current_user_can( 'tt_generate_scout_report' ) ) {
                    FrontendBackButton::render();
                    echo '<p class="tt-notice">' . esc_html__( 'You need scout-management permission to view this page.', 'talenttrack' ) . '</p>';
                    return;
                }
                \TT\Modules\Reports\Frontend\FrontendScoutAccessView::render( $user_id, $is_admin );
                break;
            case 'scout-history':
                if ( ! current_user_can( 'tt_generate_scout_report' ) ) {
                    FrontendBackButton::render();
                    echo '<p class="tt-notice">' . esc_html__( 'You need scout-management permission to view this page.', 'talenttrack' ) . '</p>';
                    return;
                }
                \TT\Modules\Reports\Frontend\FrontendScoutHistoryView::render( $user_id, $is_admin );
                break;
            case 'scout-my-players':
                if ( ! current_user_can( 'tt_view_scout_assignments' ) ) {
                    FrontendBackButton::render();
                    echo '<p class="tt-notice">' . esc_html__( 'This area is for scout users.', 'talenttrack' ) . '</p>';
                    return;
                }
                \TT\Modules\Reports\Frontend\FrontendScoutMyPlayersView::render( $user_id );
                break;
            default:
                FrontendBackButton::render();
                echo '<p><em>' . esc_html__( 'Unknown section.', 'talenttrack' ) . '</em></p>';
        }
    }

    /**
     * #0017 — Trial player module surfaces. Each view re-checks its
     * own capability so dispatching here is safe.
     */
    private static function dispatchTrialView( string $view, int $user_id, bool $is_admin ): void {
        switch ( $view ) {
            case 'trials':
                FrontendTrialsManageView::render( $user_id, $is_admin );
                break;
            case 'trial-case':
                FrontendTrialCaseView::render( $user_id, $is_admin );
                break;
            case 'trial-parent-meeting':
                FrontendTrialParentMeetingView::render( $user_id, $is_admin );
                break;
            case 'trial-tracks-editor':
                FrontendTrialTracksEditorView::render( $user_id, $is_admin );
                break;
            case 'trial-letter-templates-editor':
                FrontendTrialLetterTemplatesEditorView::render( $user_id, $is_admin );
                break;
            default:
                FrontendBackButton::render();
                echo '<p><em>' . esc_html__( 'Unknown section.', 'talenttrack' ) . '</em></p>';
        }
    }

    /**
     * #0051 — friendly back-button + notice rendered when the user
     * lands on a `tt_view=<slug>` whose owning module is disabled.
     * Mirrors the "no permission" / "unknown section" pattern used
     * elsewhere in the dispatcher so the surface stays consistent.
     */
    private static function renderModuleDisabledNotice(): void {
        FrontendBackButton::render();
        echo '<div class="tt-notice" style="background:#fff7e6; border-left:4px solid #f0c890; padding:12px 16px; margin:8px 0 16px;">';
        echo '<p style="margin:0 0 6px; font-weight:600;">'
            . esc_html__( 'This section is currently unavailable.', 'talenttrack' )
            . '</p>';
        echo '<p style="margin:0; color:#5b6e75;">'
            . esc_html__( 'The administrator has temporarily turned off this part of TalentTrack. Please check back later, or ask your administrator if you need access.', 'talenttrack' )
            . '</p>';
        echo '</div>';
    }

    private static function renderHeader(): void {
        $logo      = QueryHelpers::get_config( 'logo_url', '' );
        $show_logo = QueryHelpers::get_config( 'show_logo', '0' ) === '1';
        $name      = QueryHelpers::get_config( 'academy_name', 'TalentTrack' );
        $user      = wp_get_current_user();

        $profile_url = get_edit_profile_url( (int) $user->ID );
        $logout_url  = LogoutHandler::url();
        $is_wp_admin = current_user_can( 'administrator' );
        $base_url    = self::shortcodeBaseUrl();
        $help_url    = $base_url ? add_query_arg( 'tt_view', 'docs', $base_url ) : admin_url( 'admin.php?page=tt-docs' );
        $demo_on     = class_exists( '\\TT\\Modules\\DemoData\\DemoMode' ) && \TT\Modules\DemoData\DemoMode::isOn();
        $demo_tip    = __( 'TalentTrack is running in demo mode. Real club records are hidden until demo mode is turned off.', 'talenttrack' );

        echo '<div class="tt-dash-header">';
        echo '<div class="tt-dash-brand">';
        if ( $show_logo && $logo ) echo '<img src="' . esc_url( $logo ) . '" class="tt-dash-logo" alt="" />';
        echo '<h2 class="tt-dash-title">' . esc_html( $name ) . '</h2>';
        echo '</div>';

        echo '<div class="tt-dash-actions">';

        // DEMO indicator. Info-only span (not a link) — hover or focus
        // for the explanation. The wp-admin Tools page is reachable from
        // the user dropdown if an admin actually wants to manage demo
        // data.
        if ( $demo_on ) {
            echo '<span class="tt-dash-demo-pill" tabindex="0" title="' . esc_attr( $demo_tip ) . '" aria-label="' . esc_attr( $demo_tip ) . '">'
                . esc_html__( 'DEMO', 'talenttrack' )
                . '</span>';
        }

        // Help icon — opens the context-aware docs drawer. Visible to
        // every logged-in user; the drawer's REST endpoint enforces
        // the audience-based cap gate so non-admins only see topics
        // their role can read. The href falls back to the full
        // ?tt_view=docs page so middle-click / right-click "open in
        // new tab" still works without JS.
        echo '<a href="' . esc_url( $help_url ) . '" class="tt-dash-help" '
            . 'data-tt-docs-drawer-open '
            . 'title="' . esc_attr__( 'Help & docs', 'talenttrack' ) . '" '
            . 'aria-label="' . esc_attr__( 'Help & docs', 'talenttrack' ) . '">'
            . '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . '<circle cx="12" cy="12" r="10"></circle>'
            . '<path d="M9.5 9a2.5 2.5 0 0 1 4.9.7c0 1.7-2.5 2.3-2.5 4"></path>'
            . '<circle cx="12" cy="17.5" r="0.6" fill="currentColor"></circle>'
            . '</svg>'
            . '</a>';

        echo '<div class="tt-user-menu">';
        echo '<button type="button" class="tt-user-menu-trigger" aria-haspopup="true" aria-expanded="false">';
        echo '<span class="tt-user-menu-name">' . esc_html( $user->display_name ) . '</span>';
        echo '<span class="tt-user-menu-caret" aria-hidden="true">▾</span>';
        echo '</button>';
        echo '<div class="tt-user-menu-dropdown" role="menu">';
        echo '<a href="' . esc_url( $profile_url ) . '" class="tt-user-menu-item" role="menuitem">';
        echo esc_html__( 'Edit profile', 'talenttrack' );
        echo '</a>';

        // #0033 Sprint 4 — persona switcher. Visible only when the user
        // resolves to 2+ personas. The switch is a client-side
        // sessionStorage lens; it resets on browser close. Default view
        // is the union (no active persona).
        if ( class_exists( '\\TT\\Modules\\Authorization\\PersonaResolver' ) ) {
            $personas = \TT\Modules\Authorization\PersonaResolver::personasFor( (int) $user->ID );
            if ( count( $personas ) >= 2 ) {
                echo '<div class="tt-user-menu-section" style="border-top:1px solid #e5e7ea; padding-top:6px; margin-top:6px;">';
                echo '<div class="tt-user-menu-section-label" style="font-size:11px; color:#888; text-transform:uppercase; letter-spacing:0.05em; padding:0 12px 4px;">'
                    . esc_html__( 'View as', 'talenttrack' )
                    . '</div>';
                echo '<a href="#" class="tt-user-menu-item tt-persona-switch" data-persona="" role="menuitem">'
                    . esc_html__( 'All personas (default)', 'talenttrack' )
                    . '</a>';
                $persona_labels = [
                    'player'              => __( 'Player', 'talenttrack' ),
                    'parent'              => __( 'Parent', 'talenttrack' ),
                    'assistant_coach'     => __( 'Assistant Coach', 'talenttrack' ),
                    'head_coach'          => __( 'Head Coach', 'talenttrack' ),
                    'head_of_development' => __( 'Head of Development', 'talenttrack' ),
                    'scout'               => __( 'Scout', 'talenttrack' ),
                    'team_manager'        => __( 'Team Manager', 'talenttrack' ),
                    'academy_admin'       => __( 'Academy Admin', 'talenttrack' ),
                ];
                foreach ( $personas as $p ) {
                    $label = $persona_labels[ $p ] ?? $p;
                    echo '<a href="#" class="tt-user-menu-item tt-persona-switch" data-persona="' . esc_attr( $p ) . '" role="menuitem">'
                        . esc_html( $label )
                        . '</a>';
                }
                echo '</div>';
            }
        }

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
        echo '</div>'; // .tt-dash-actions
        echo '</div>'; // .tt-dash-header

        // #0016 Part B — context-aware docs drawer. Hidden by default;
        // opens when the help icon is clicked. The drawer fetches the
        // matching topic for the current ?tt_view= slug via REST and
        // renders inline. CSS lives in public.css; the small hydrator
        // is at assets/js/components/docs-drawer.js.
        echo '<aside class="tt-docs-drawer" data-tt-docs-drawer hidden aria-hidden="true" aria-labelledby="tt-docs-drawer-title">';
        echo '<div class="tt-docs-drawer__backdrop" data-tt-docs-drawer-close></div>';
        echo '<div class="tt-docs-drawer__panel" role="dialog">';
        echo '<header class="tt-docs-drawer__head">';
        echo '<h3 id="tt-docs-drawer-title" data-tt-docs-drawer-title>' . esc_html__( 'Help', 'talenttrack' ) . '</h3>';
        echo '<a href="' . esc_url( $help_url ) . '" class="tt-docs-drawer__expand" data-tt-docs-drawer-expand title="' . esc_attr__( 'Open full Help & Docs', 'talenttrack' ) . '" aria-label="' . esc_attr__( 'Open full Help & Docs', 'talenttrack' ) . '">↗</a>';
        echo '<button type="button" class="tt-docs-drawer__close" data-tt-docs-drawer-close aria-label="' . esc_attr__( 'Close', 'talenttrack' ) . '">×</button>';
        echo '</header>';
        echo '<div class="tt-docs-drawer__body" data-tt-docs-drawer-body>';
        echo '<p class="tt-docs-drawer__loading">' . esc_html__( 'Loading…', 'talenttrack' ) . '</p>';
        echo '</div>';
        echo '</div>';
        echo '</aside>';

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

            // #0033 Sprint 4 — persona switcher. Stores the active lens
            // in sessionStorage so it resets on browser close. A banner
            // surfaces at the top of the dashboard showing the active
            // persona until the user clicks "back to all".
            var STORAGE_KEY = 'tt_active_persona';
            menu.querySelectorAll('.tt-persona-switch').forEach(function(link){
                link.addEventListener('click', function(e){
                    e.preventDefault();
                    var p = link.dataset.persona || '';
                    if (p) {
                        sessionStorage.setItem(STORAGE_KEY, p);
                    } else {
                        sessionStorage.removeItem(STORAGE_KEY);
                    }
                    location.reload();
                });
            });

            var active = sessionStorage.getItem(STORAGE_KEY);
            if (active) {
                var dash = document.querySelector('.tt-dashboard');
                if (dash) {
                    var banner = document.createElement('div');
                    banner.style.cssText = 'background:#fff7e6; border:1px solid #f0c890; border-radius:6px; padding:8px 12px; margin:6px 0 12px; font-size:13px; display:flex; justify-content:space-between; align-items:center;';
                    banner.innerHTML = '<span><?php echo esc_js( __( 'You are viewing as', 'talenttrack' ) ); ?> <strong>' + active.replace(/_/g, ' ') + '</strong>.</span>'
                        + '<a href="#" id="tt-persona-reset" style="text-decoration:none;"><?php echo esc_js( __( 'Switch back to all personas', 'talenttrack' ) ); ?></a>';
                    dash.insertBefore(banner, dash.firstChild.nextSibling);
                    var reset = document.getElementById('tt-persona-reset');
                    if (reset) reset.addEventListener('click', function(e){
                        e.preventDefault();
                        sessionStorage.removeItem(STORAGE_KEY);
                        location.reload();
                    });
                }
            }
        })();
        </script>
        <?php
    }

    /**
     * Current page URL with TT-specific query args stripped. Used as
     * the base for the help link. Duplicate of the same helper on
     * FrontendTileGrid so this class stays self-contained.
     */
    private static function shortcodeBaseUrl(): string {
        $current = '';
        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $current = esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) );
        }
        return remove_query_arg(
            [ 'tt_view', 'player_id', 'eval_id', 'activity_id', 'goal_id', 'team_id', 'tab' ],
            $current ?: home_url( '/' )
        );
    }

    /**
     * tt_view slug → docs topic slug map for the context-aware help
     * drawer (#0016 part B). Slugs missing from this map fall back to
     * `default_slug` (getting-started) on the JS side. Add new
     * mappings here whenever a new view ships with its own help topic.
     *
     * @return array<string, string>
     */
    private static function viewToTopicMap(): array {
        return [
            'players'             => 'teams-players',
            'players-import'      => 'teams-players',
            'teams'               => 'teams-players',
            'people'              => 'people-staff',
            'functional-roles'    => 'people-staff',
            'evaluations'         => 'evaluations',
            'eval-categories'     => 'eval-categories-weights',
            'activities'          => 'activities',
            'goals'               => 'goals',
            'reports'             => 'reports',
            'rate-cards'          => 'rate-cards',
            'compare'             => 'player-comparison',
            'methodology'         => 'methodology',
            'configuration'       => 'configuration-branding',
            'custom-fields'       => 'custom-fields',
            'roles'               => 'access-control',
            'overview'            => 'player-dashboard',
            'my-team'             => 'player-dashboard',
            'my-evaluations'      => 'player-dashboard',
            'my-activities'       => 'player-dashboard',
            'my-goals'            => 'player-dashboard',
            'profile'             => 'player-dashboard',
            'my-tasks'            => 'workflow-tasks',
            'tasks-dashboard'     => 'workflow-tasks',
            'workflow-config'     => 'workflow-tasks',
        ];
    }
}
