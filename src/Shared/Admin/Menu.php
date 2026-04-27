<?php
namespace TT\Shared\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Configuration\Admin\ConfigurationPage;
use TT\Modules\Configuration\Admin\CustomFieldsPage;
use TT\Modules\Documentation\Admin\DocumentationPage;
use TT\Modules\Evaluations\Admin\CategoryWeightsPage;
use TT\Modules\Evaluations\Admin\EvalCategoriesPage;
use TT\Modules\Evaluations\Admin\EvaluationsPage;
use TT\Modules\Goals\Admin\GoalsPage;
use TT\Modules\Players\Admin\PlayersPage;
use TT\Modules\Reports\Admin\ReportsPage;
use TT\Modules\Activities\Admin\ActivitiesPage;
use TT\Modules\Stats\Admin\PlayerRateCardsPage;
use TT\Modules\Teams\Admin\TeamsPage;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Admin\BulkActionsHelper;
use TT\Shared\Admin\DragReorder;
use TT\Shared\Admin\SchemaStatus;
use TT\Shared\Icons\IconRenderer;

/**
 * Menu — registers the top-level TalentTrack admin menu plus subpages.
 *
 * v2.11.0: added Custom Fields submenu (TalentTrack → Custom Fields).
 * v2.6.0: enqueues admin-sortable.js on TT admin pages. (Originally for
 * the old CustomFieldsTab drag-sort UI; still used by OptionSetEditor.)
 */
class Menu {

    public static function init(): void {
        add_action( 'admin_menu', [ __CLASS__, 'register' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
        // v2.17.0: wire bulk-action post handler + admin JS.
        BulkActionsHelper::init();
        // v2.19.0: wire drag-to-reorder AJAX handler.
        DragReorder::init();
        // v3.0.0: migration UX — admin notice + Plugins-page action link +
        // admin-post handler for the "Run now" button.
        SchemaStatus::init();
        // Result notice for the redirect from the Run handler.
        add_action( 'admin_notices', [ SchemaStatus::class, 'renderResultNotice' ] );
    }

    public static function register(): void {
        // v2.17.0 — admin menu overhaul. WordPress has no native grouped
        // submenus, so we fake it with CSS-styled separator entries. The
        // separators are functional submenu pages whose callback redirects
        // back to the dashboard (in case someone clicks one anyway) and
        // whose rendered <li> is styled via the admin_head CSS emitted in
        // injectMenuCss() below to look like a heading row, not a link.
        //
        // Group order (top → bottom):
        //   Dashboard
        //   ── People ──
        //     Teams, Players
        //   ── Performance ──
        //     Evaluations, Sessions, Goals
        //   ── Analytics ──
        //     Reports, Player Rate Cards, Usage Statistics
        //   ── Configuration ── (admin-only)
        //     Configuration, Custom Fields, Evaluation Categories,
        //     Category Weights, Archived items
        //   Help & Docs

        add_menu_page( __( 'TalentTrack', 'talenttrack' ), __( 'TalentTrack', 'talenttrack' ), 'read', 'talenttrack', [ __CLASS__, 'dashboard' ], 'dashicons-groups', 26 );
        add_submenu_page( 'talenttrack', __( 'Dashboard', 'talenttrack' ), __( 'Dashboard', 'talenttrack' ), 'read', 'talenttrack', [ __CLASS__, 'dashboard' ] );

        // #0024 — Setup wizard menu entry. Visible only while the
        // wizard hasn't been completed (or the admin came in via the
        // ?force_welcome=1 reset flow). Sits directly under Dashboard
        // so it's the first thing a fresh installer sees.
        if ( self::shouldShowWelcome() ) {
            add_submenu_page(
                'talenttrack',
                __( 'Welcome', 'talenttrack' ),
                __( 'Welcome', 'talenttrack' ),
                \TT\Modules\Onboarding\Admin\OnboardingPage::CAP,
                \TT\Modules\Onboarding\Admin\OnboardingPage::SLUG,
                [ \TT\Modules\Onboarding\Admin\OnboardingPage::class, 'render' ]
            );
        }

        // #0011 Sprint 1 — Account submenu (tier, trial state, usage vs
        // caps, upgrade CTA). Always visible regardless of the legacy
        // menus toggle since billing/account info is critical.
        add_submenu_page(
            'talenttrack',
            __( 'Account', 'talenttrack' ),
            __( 'Account', 'talenttrack' ),
            \TT\Modules\License\Admin\AccountPage::CAP,
            \TT\Modules\License\Admin\AccountPage::SLUG,
            [ \TT\Modules\License\Admin\AccountPage::class, 'render' ]
        );

        // #0019 Sprint 6 — legacy-UI toggle. When `tt_show_legacy_menus`
        // is OFF (default), the migrated wp-admin pages are hidden from
        // the menu; direct URLs still work as an emergency fallback.
        // The parent menu + dashboard page + Help & Docs are always
        // visible regardless. Help & Docs is the always-visible
        // landmark so admins can still reach the documentation when
        // legacy menus are hidden.
        if ( ! self::shouldShowLegacyMenus() ) {
            add_submenu_page( 'talenttrack', __( 'Help & Docs', 'talenttrack' ), __( 'Help & Docs', 'talenttrack' ), 'read', 'tt-docs', [ DocumentationPage::class, 'render_page' ] );
            add_action( 'admin_head', [ __CLASS__, 'injectMenuCss' ] );
            return;
        }

        self::addSeparator( 'tt-sep-people', __( 'People', 'talenttrack' ), 'tt_view_players' );
        add_submenu_page( 'talenttrack', __( 'Teams', 'talenttrack' ), __( 'Teams', 'talenttrack' ), 'tt_view_teams', 'tt-teams', [ TeamsPage::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', __( 'Players', 'talenttrack' ), __( 'Players', 'talenttrack' ), 'tt_view_players', 'tt-players', [ PlayersPage::class, 'render_page' ] );
        // v2.20.0: People moved in from PeopleModule so it sits under the group.
        add_submenu_page( 'talenttrack', __( 'People', 'talenttrack' ), __( 'People', 'talenttrack' ), 'tt_view_people', 'tt-people', [ \TT\Modules\People\Admin\PeoplePage::class, 'render' ] );

        self::addSeparator( 'tt-sep-performance', __( 'Performance', 'talenttrack' ), 'tt_view_evaluations' );
        add_submenu_page( 'talenttrack', __( 'Evaluations', 'talenttrack' ), __( 'Evaluations', 'talenttrack' ), 'tt_view_evaluations', 'tt-evaluations', [ EvaluationsPage::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', __( 'Activities', 'talenttrack' ), __( 'Activities', 'talenttrack' ), 'tt_view_activities', 'tt-activities', [ ActivitiesPage::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', __( 'Goals', 'talenttrack' ), __( 'Goals', 'talenttrack' ), 'tt_view_goals', 'tt-goals', [ GoalsPage::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', __( 'Methodology', 'talenttrack' ), __( 'Methodology', 'talenttrack' ), \TT\Modules\Methodology\Admin\MethodologyPage::CAP_VIEW, \TT\Modules\Methodology\Admin\MethodologyPage::SLUG, [ \TT\Modules\Methodology\Admin\MethodologyPage::class, 'render' ] );
        // Hidden edit pages — registered with parent = null so they
        // route via slug but no menu item appears.
        add_submenu_page( null, __( 'Edit principle', 'talenttrack' ), __( 'Edit principle', 'talenttrack' ), \TT\Modules\Methodology\Admin\PrincipleEditPage::CAP, \TT\Modules\Methodology\Admin\PrincipleEditPage::SLUG, [ \TT\Modules\Methodology\Admin\PrincipleEditPage::class, 'render' ] );
        add_submenu_page( null, __( 'Edit position',  'talenttrack' ), __( 'Edit position',  'talenttrack' ), \TT\Modules\Methodology\Admin\PositionEditPage::CAP,  \TT\Modules\Methodology\Admin\PositionEditPage::SLUG,  [ \TT\Modules\Methodology\Admin\PositionEditPage::class,  'render' ] );
        add_submenu_page( null, __( 'Edit set piece', 'talenttrack' ), __( 'Edit set piece', 'talenttrack' ), \TT\Modules\Methodology\Admin\SetPieceEditPage::CAP,  \TT\Modules\Methodology\Admin\SetPieceEditPage::SLUG,  [ \TT\Modules\Methodology\Admin\SetPieceEditPage::class,  'render' ] );
        add_submenu_page( null, __( 'Edit vision',    'talenttrack' ), __( 'Edit vision',    'talenttrack' ), \TT\Modules\Methodology\Admin\VisionEditPage::CAP,    \TT\Modules\Methodology\Admin\VisionEditPage::SLUG,    [ \TT\Modules\Methodology\Admin\VisionEditPage::class,    'render' ] );
        // Methodology framework primer + sub-rows (#0027 expansion).
        add_submenu_page( null, __( 'Edit framework',         'talenttrack' ), __( 'Edit framework',         'talenttrack' ), \TT\Modules\Methodology\Admin\FrameworkPrimerEditPage::CAP, \TT\Modules\Methodology\Admin\FrameworkPrimerEditPage::SLUG, [ \TT\Modules\Methodology\Admin\FrameworkPrimerEditPage::class, 'render' ] );
        add_submenu_page( null, __( 'Edit phase',             'talenttrack' ), __( 'Edit phase',             'talenttrack' ), \TT\Modules\Methodology\Admin\PhaseEditPage::CAP,           \TT\Modules\Methodology\Admin\PhaseEditPage::SLUG,           [ \TT\Modules\Methodology\Admin\PhaseEditPage::class, 'render' ] );
        add_submenu_page( null, __( 'Edit learning goal',     'talenttrack' ), __( 'Edit learning goal',     'talenttrack' ), \TT\Modules\Methodology\Admin\LearningGoalEditPage::CAP,    \TT\Modules\Methodology\Admin\LearningGoalEditPage::SLUG,    [ \TT\Modules\Methodology\Admin\LearningGoalEditPage::class, 'render' ] );
        add_submenu_page( null, __( 'Edit influence factor',  'talenttrack' ), __( 'Edit influence factor',  'talenttrack' ), \TT\Modules\Methodology\Admin\InfluenceFactorEditPage::CAP, \TT\Modules\Methodology\Admin\InfluenceFactorEditPage::SLUG, [ \TT\Modules\Methodology\Admin\InfluenceFactorEditPage::class, 'render' ] );
        // Football actions (voetbalhandelingen) — own visible top-level
        // page next to Methodology, plus its hidden edit page.
        add_submenu_page( 'talenttrack', __( 'Voetbalhandelingen', 'talenttrack' ), __( 'Voetbalhandelingen', 'talenttrack' ), \TT\Modules\Methodology\Admin\FootballActionsPage::CAP_VIEW, \TT\Modules\Methodology\Admin\FootballActionsPage::SLUG, [ \TT\Modules\Methodology\Admin\FootballActionsPage::class, 'render' ] );
        add_submenu_page( null, __( 'Edit football action', 'talenttrack' ), __( 'Edit football action', 'talenttrack' ), \TT\Modules\Methodology\Admin\FootballActionEditPage::CAP, \TT\Modules\Methodology\Admin\FootballActionEditPage::SLUG, [ \TT\Modules\Methodology\Admin\FootballActionEditPage::class, 'render' ] );

        self::addSeparator( 'tt-sep-analytics', __( 'Analytics', 'talenttrack' ), 'tt_view_reports' );
        add_submenu_page( 'talenttrack', __( 'Reports', 'talenttrack' ), __( 'Reports', 'talenttrack' ), 'tt_view_reports', 'tt-reports', [ ReportsPage::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', __( 'Player Rate Cards', 'talenttrack' ), __( 'Player Rate Cards', 'talenttrack' ), 'tt_view_reports', 'tt-rate-cards', [ PlayerRateCardsPage::class, 'render' ] );
        add_submenu_page( 'talenttrack', __( 'Player Comparison', 'talenttrack' ), __( 'Player Comparison', 'talenttrack' ), 'tt_view_reports', 'tt-compare', [ \TT\Modules\Stats\Admin\PlayerComparisonPage::class, 'render' ] );
        add_submenu_page( 'talenttrack', __( 'Usage Statistics', 'talenttrack' ), __( 'Usage Statistics', 'talenttrack' ), 'tt_view_settings', 'tt-usage-stats', [ \TT\Modules\Stats\Admin\UsageStatsPage::class, 'render' ] );
        // v2.19.0: hidden details page for drill-down from KPI cards.
        // Registered with null parent so it doesn't appear in the menu
        // but the page slug routes correctly.
        add_submenu_page( null, __( 'Usage Detail', 'talenttrack' ), __( 'Usage Detail', 'talenttrack' ), 'tt_view_settings', 'tt-usage-stats-details', [ \TT\Modules\Stats\Admin\UsageStatsDetailsPage::class, 'render' ] );

        self::addSeparator( 'tt-sep-config', __( 'Configuration', 'talenttrack' ), 'tt_view_settings' );
        add_submenu_page( 'talenttrack', __( 'Configuration', 'talenttrack' ), __( 'Configuration', 'talenttrack' ), 'tt_view_settings', 'tt-config', [ ConfigurationPage::class, 'render_page' ] );
        add_submenu_page( 'talenttrack', __( 'Custom Fields', 'talenttrack' ), __( 'Custom Fields', 'talenttrack' ), 'tt_view_settings', 'tt-custom-fields', [ CustomFieldsPage::class, 'render' ] );
        add_submenu_page( 'talenttrack', __( 'Evaluation Categories', 'talenttrack' ), __( 'Evaluation Categories', 'talenttrack' ), 'tt_view_settings', 'tt-eval-categories', [ EvalCategoriesPage::class, 'render' ] );
        add_submenu_page( 'talenttrack', __( 'Category Weights', 'talenttrack' ), __( 'Category Weights', 'talenttrack' ), 'tt_view_settings', 'tt-category-weights', [ CategoryWeightsPage::class, 'render' ] );

        // v2.20.0: Access Control group — Authorization pages moved in
        // from AuthorizationModule so they sit under a proper separator.
        self::addSeparator( 'tt-sep-access', __( 'Access Control', 'talenttrack' ), 'tt_view_settings' );
        add_submenu_page( 'talenttrack', __( 'Roles & Permissions', 'talenttrack' ), __( 'Roles & Permissions', 'talenttrack' ), 'tt_view_settings', 'tt-roles', [ \TT\Modules\Authorization\Admin\RolesPage::class, 'render' ] );
        add_submenu_page( 'talenttrack', __( 'Functional Roles', 'talenttrack' ), __( 'Functional Roles', 'talenttrack' ), 'tt_view_settings', 'tt-functional-roles', [ \TT\Modules\Authorization\Admin\FunctionalRolesPage::class, 'render' ] );
        add_submenu_page( 'talenttrack', __( 'Permission Debug', 'talenttrack' ), __( 'Permission Debug', 'talenttrack' ), 'tt_view_settings', 'tt-roles-debug', [ \TT\Modules\Authorization\Admin\DebugPage::class, 'render' ] );
        // #0033 Sprint 3 + 5 + 8 — matrix editor + module toggles +
        // migration preview. All gated to administrator-only.
        add_submenu_page( 'talenttrack', __( 'Authorization Matrix', 'talenttrack' ), __( 'Authorization Matrix', 'talenttrack' ), 'administrator', 'tt-matrix', [ \TT\Modules\Authorization\Admin\MatrixPage::class, 'render' ] );
        add_submenu_page( 'talenttrack', __( 'Migration preview', 'talenttrack' ), __( 'Migration preview', 'talenttrack' ), 'administrator', 'tt-matrix-preview', [ \TT\Modules\Authorization\Admin\PreviewPage::class, 'render' ] );
        add_submenu_page( 'talenttrack', __( 'Modules', 'talenttrack' ), __( 'Modules', 'talenttrack' ), 'administrator', 'tt-modules', [ \TT\Modules\Authorization\Admin\ModulesPage::class, 'render' ] );

        add_submenu_page( 'talenttrack', __( 'Help & Docs', 'talenttrack' ), __( 'Help & Docs', 'talenttrack' ), 'read', 'tt-docs', [ DocumentationPage::class, 'render_page' ] );

        add_action( 'admin_head', [ __CLASS__, 'injectMenuCss' ] );
    }

    /**
     * Add a fake-separator submenu row. It's a real submenu entry (so
     * WordPress renders it in the right place), but the slug begins with
     * "tt-sep-" and the CSS in injectMenuCss() styles it as a non-clickable
     * heading. The callback redirects to the dashboard as a fallback if
     * someone manages to click it anyway (e.g. keyboard navigation).
     */
    private static function addSeparator( string $slug, string $label, string $cap ): void {
        add_submenu_page(
            'talenttrack',
            $label,
            '<span class="tt-menu-separator-label">' . esc_html( $label ) . '</span>',
            $cap,
            $slug,
            function() { wp_safe_redirect( admin_url( 'admin.php?page=talenttrack' ) ); exit; }
        );
    }

    /**
     * CSS that styles tt-sep-* entries as non-clickable heading rows.
     */
    public static function injectMenuCss(): void {
        ?>
        <style>
        #adminmenu .wp-submenu a[href*="page=tt-sep-"] {
            pointer-events: none;
            cursor: default !important;
            padding: 14px 12px 4px !important;
            color: #8a9099 !important;
            font-size: 10px !important;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            font-weight: 600;
            border-top: 1px solid rgba(255,255,255,0.08);
            margin-top: 4px;
        }
        #adminmenu .wp-submenu a[href*="page=tt-sep-"]:hover,
        #adminmenu .wp-submenu a[href*="page=tt-sep-"]:focus {
            color: #8a9099 !important;
            background: transparent !important;
        }
        /* The first separator in a menu doesn't need the top border */
        #adminmenu .wp-submenu li:first-child a[href*="page=tt-sep-"] {
            border-top: none;
            margin-top: 0;
        }
        #adminmenu .wp-submenu a[href*="page=tt-sep-"] .tt-menu-separator-label {
            pointer-events: none;
        }
        </style>
        <?php
    }

    /**
     * Whether the legacy wp-admin menu entries should be shown.
     * Default false (Sprint 6's aggressive-push policy). Direct URLs
     * to legacy pages keep working regardless of this option.
     *
     * Stored in `tt_config` (the plugin's central key-value table)
     * under the `show_legacy_menus` key. Both the frontend
     * Configuration view (REST POST /config) and the wp-admin
     * Configuration page write here.
     */
    public static function shouldShowLegacyMenus(): bool {
        $value = QueryHelpers::get_config( 'show_legacy_menus', '0' );
        return $value === '1' || $value === 1 || $value === true;
    }

    /**
     * #0024 — show the Welcome submenu while the setup wizard hasn't
     * been completed/dismissed. Force-on via `?force_welcome=1` so the
     * Reset link can re-enter the wizard from a completed install.
     */
    public static function shouldShowWelcome(): bool {
        if ( isset( $_GET['force_welcome'] ) && $_GET['force_welcome'] === '1' ) return true;
        if ( ! class_exists( '\TT\Modules\Onboarding\OnboardingState' ) ) return false;
        return \TT\Modules\Onboarding\OnboardingState::shouldShowWelcome();
    }

    public static function dashboard(): void {
        global $wpdb; $p = $wpdb->prefix;

        // v2.18.0 — dashboard overhaul. Stats section (5 overview cards,
        // clickable) + tiles section (mirrors the menu groups, each tile
        // = one menu entry, cap-gated to respect user role).
        //
        // v2.19.0 — stat cards redesigned: horizontal layout, compact,
        // each card shows a weekly delta ("+5 this week") computed from
        // the entity's created_at column. Delta reflects row additions
        // in the last 7 days.

        $week_ago = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );

        $player_scope = QueryHelpers::apply_demo_scope( 'pl', 'player' );
        $team_scope   = QueryHelpers::apply_demo_scope( 't',  'team' );
        $eval_scope   = QueryHelpers::apply_demo_scope( 'e',  'evaluation' );
        $sess_scope   = QueryHelpers::apply_demo_scope( 's',  'activity' );
        $goal_scope   = QueryHelpers::apply_demo_scope( 'g',  'goal' );

        $delta_players = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}tt_players pl WHERE pl.status='active' AND pl.archived_at IS NULL AND pl.created_at >= %s {$player_scope}", $week_ago ) );
        $delta_teams   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}tt_teams t WHERE t.archived_at IS NULL AND t.created_at >= %s {$team_scope}", $week_ago ) );
        $delta_evals   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}tt_evaluations e WHERE e.archived_at IS NULL AND e.created_at >= %s {$eval_scope}", $week_ago ) );
        $delta_sess    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}tt_activities s WHERE s.archived_at IS NULL AND s.created_at >= %s {$sess_scope}", $week_ago ) );
        $delta_goals   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}tt_goals g WHERE g.archived_at IS NULL AND g.created_at >= %s {$goal_scope}", $week_ago ) );

        // Stats for the Overview section
        $stats = [
            [
                'label'    => __( 'Players', 'talenttrack' ),
                'count'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tt_players pl WHERE pl.status='active' AND pl.archived_at IS NULL {$player_scope}" ),
                'delta'    => $delta_players,
                'icon'     => 'players',
                'url'      => admin_url( 'admin.php?page=tt-players' ),
                'cap'      => 'tt_view_players',
                'color'    => '#1d7874',
            ],
            [
                'label'    => __( 'Teams', 'talenttrack' ),
                'count'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tt_teams t WHERE t.archived_at IS NULL {$team_scope}" ),
                'delta'    => $delta_teams,
                'icon'     => 'teams',
                'url'      => admin_url( 'admin.php?page=tt-teams' ),
                'cap'      => 'tt_view_teams',
                'color'    => '#2271b1',
            ],
            [
                'label'    => __( 'Evaluations', 'talenttrack' ),
                'count'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tt_evaluations e WHERE e.archived_at IS NULL {$eval_scope}" ),
                'delta'    => $delta_evals,
                'icon'     => 'evaluations',
                'url'      => admin_url( 'admin.php?page=tt-evaluations' ),
                'cap'      => 'tt_view_evaluations',
                'color'    => '#7c3a9e',
            ],
            [
                'label'    => __( 'Activities', 'talenttrack' ),
                'count'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tt_activities s WHERE s.archived_at IS NULL {$sess_scope}" ),
                'delta'    => $delta_sess,
                'icon'     => 'activities',
                'url'      => admin_url( 'admin.php?page=tt-activities' ),
                'cap'      => 'tt_view_activities',
                'color'    => '#c9962a',
            ],
            [
                'label'    => __( 'Goals', 'talenttrack' ),
                'count'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}tt_goals g WHERE g.archived_at IS NULL {$goal_scope}" ),
                'delta'    => $delta_goals,
                'icon'     => 'goals',
                'url'      => admin_url( 'admin.php?page=tt-goals' ),
                'cap'      => 'tt_view_goals',
                'color'    => '#b32d2e',
            ],
        ];

        // Grouped tiles section — mirrors the menu structure. Per decision
        // D-x: primary entities appear both in Overview (top) AND in their
        // corresponding Performance/People tiles here.
        $groups = [
            [
                'id'     => 'people',
                'label'  => __( 'People', 'talenttrack' ),
                'accent' => '#1d7874',
                'tiles'  => [
                    [ 'label' => __( 'Teams', 'talenttrack' ),   'icon' => 'teams',   'url' => admin_url( 'admin.php?page=tt-teams' ),   'cap' => 'tt_view_teams', 'desc' => __( 'Manage teams, staff assignments, and age groups.', 'talenttrack' ) ],
                    [ 'label' => __( 'Players', 'talenttrack' ), 'icon' => 'players', 'url' => admin_url( 'admin.php?page=tt-players' ), 'cap' => 'tt_view_players', 'desc' => __( 'Player roster, positions, photos, guardian info.', 'talenttrack' ) ],
                    [ 'label' => __( 'People', 'talenttrack' ),  'icon' => 'people',  'url' => admin_url( 'admin.php?page=tt-people' ),  'cap' => 'tt_view_people', 'desc' => __( 'Coaches, assistants, medical staff, volunteers.', 'talenttrack' ) ],
                ],
            ],
            [
                'id'     => 'performance',
                'label'  => __( 'Performance', 'talenttrack' ),
                'accent' => '#7c3a9e',
                'tiles'  => [
                    [ 'label' => __( 'Evaluations', 'talenttrack' ), 'icon' => 'evaluations', 'url' => admin_url( 'admin.php?page=tt-evaluations' ), 'cap' => 'tt_view_evaluations', 'desc' => __( 'Rate players across training and match sessions.', 'talenttrack' ) ],
                    [ 'label' => __( 'Activities', 'talenttrack' ),    'icon' => 'activities',    'url' => admin_url( 'admin.php?page=tt-activities' ),    'cap' => 'tt_view_activities', 'desc' => __( 'Record training sessions and attendance.', 'talenttrack' ) ],
                    [ 'label' => __( 'Goals', 'talenttrack' ),       'icon' => 'goals',       'url' => admin_url( 'admin.php?page=tt-goals' ),       'cap' => 'tt_view_goals', 'desc' => __( 'Set and track development goals per player.', 'talenttrack' ) ],
                ],
            ],
            [
                'id'     => 'analytics',
                'label'  => __( 'Analytics', 'talenttrack' ),
                'accent' => '#2271b1',
                'tiles'  => [
                    [ 'label' => __( 'Reports', 'talenttrack' ),           'icon' => 'reports',     'url' => admin_url( 'admin.php?page=tt-reports' ),      'cap' => 'tt_view_reports',    'desc' => __( 'Saved report presets and exports.', 'talenttrack' ) ],
                    [ 'label' => __( 'Player Rate Cards', 'talenttrack' ), 'icon' => 'rate-card',   'url' => admin_url( 'admin.php?page=tt-rate-cards' ),   'cap' => 'tt_view_reports',    'desc' => __( 'Per-player rate cards with trends and charts.', 'talenttrack' ) ],
                    [ 'label' => __( 'Player Comparison', 'talenttrack' ), 'icon' => 'compare',     'url' => admin_url( 'admin.php?page=tt-compare' ),      'cap' => 'tt_view_reports',    'desc' => __( 'Side-by-side comparison of up to 4 players.', 'talenttrack' ) ],
                    [ 'label' => __( 'Usage Statistics', 'talenttrack' ),  'icon' => 'usage-stats', 'url' => admin_url( 'admin.php?page=tt-usage-stats' ),  'cap' => 'tt_view_settings', 'desc' => __( 'Logins, active users, most-visited pages.', 'talenttrack' ) ],
                ],
            ],
            [
                'id'     => 'configuration',
                'label'  => __( 'Configuration', 'talenttrack' ),
                'accent' => '#555',
                'tiles'  => [
                    [ 'label' => __( 'Configuration', 'talenttrack' ),        'icon' => 'settings',       'url' => admin_url( 'admin.php?page=tt-config' ),             'cap' => 'tt_view_settings', 'desc' => __( 'Academy name, logo, rating scale, colors.', 'talenttrack' ) ],
                    [ 'label' => __( 'Custom Fields', 'talenttrack' ),        'icon' => 'custom-fields',  'url' => admin_url( 'admin.php?page=tt-custom-fields' ),      'cap' => 'tt_view_settings', 'desc' => __( 'Add club-specific fields to any entity.', 'talenttrack' ) ],
                    [ 'label' => __( 'Evaluation Categories', 'talenttrack' ),'icon' => 'categories',     'url' => admin_url( 'admin.php?page=tt-eval-categories' ),    'cap' => 'tt_view_settings', 'desc' => __( 'Main + subcategories used in evaluations.', 'talenttrack' ) ],
                    [ 'label' => __( 'Category Weights', 'talenttrack' ),     'icon' => 'weights',        'url' => admin_url( 'admin.php?page=tt-category-weights' ),   'cap' => 'tt_view_settings', 'desc' => __( 'Per-age-group weighting for overall ratings.', 'talenttrack' ) ],
                ],
            ],
            [
                'id'     => 'access',
                'label'  => __( 'Access Control', 'talenttrack' ),
                'accent' => '#b32d2e',
                'tiles'  => [
                    [ 'label' => __( 'Roles & Permissions', 'talenttrack' ), 'icon' => 'roles',             'url' => admin_url( 'admin.php?page=tt-roles' ),           'cap' => 'tt_view_settings', 'desc' => __( 'Who can do what — grant or revoke TalentTrack roles per user.', 'talenttrack' ) ],
                    [ 'label' => __( 'Functional Roles', 'talenttrack' ),   'icon' => 'functional-roles',  'url' => admin_url( 'admin.php?page=tt-functional-roles' ), 'cap' => 'tt_view_settings', 'desc' => __( 'Head coach, assistant, physio — map club roles to permissions.', 'talenttrack' ) ],
                    [ 'label' => __( 'Permission Debug', 'talenttrack' ),   'icon' => 'permission-debug',  'url' => admin_url( 'admin.php?page=tt-roles-debug' ),      'cap' => 'tt_view_settings', 'desc' => __( 'Inspect any user\'s effective permissions.', 'talenttrack' ) ],
                ],
            ],
            [
                'id'     => 'help',
                'label'  => __( 'Help', 'talenttrack' ),
                'accent' => '#888',
                'tiles'  => [
                    [ 'label' => __( 'Help & Docs', 'talenttrack' ), 'icon' => 'docs', 'url' => admin_url( 'admin.php?page=tt-docs' ), 'cap' => 'read', 'desc' => __( 'How to use TalentTrack.', 'talenttrack' ) ],
                ],
            ],
        ];
        ?>

        <style>
        /* Scoped dashboard styles — v2.18.0 */
        .tt-dash-page { max-width: 1400px; }
        .tt-dash-page h1 { margin-bottom: 4px; }
        .tt-dash-intro { color: #666; margin-bottom: 24px; max-width: 760px; }

        .tt-dash-section-label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #8a9099;
            margin: 28px 0 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .tt-dash-section-label::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #dcdcde;
        }

        /* Stat cards (Overview section) — v2.19.0 horizontal compact */
        .tt-dash-overview {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
        }
        .tt-dash-stat {
            position: relative;
            display: flex;
            align-items: center;
            gap: 12px;
            background: #fff;
            border-radius: 8px;
            padding: 12px 14px;
            text-decoration: none;
            color: #1a1d21;
            border-left: 3px solid #ccc;
            transition: transform 180ms cubic-bezier(0.2, 0.8, 0.2, 1),
                        box-shadow 180ms ease;
            box-shadow: 0 1px 2px rgba(0,0,0,0.04);
            min-height: 58px;
        }
        .tt-dash-stat:hover, .tt-dash-stat:focus {
            transform: translateY(-1px);
            box-shadow: 0 4px 10px rgba(0,0,0,0.1);
            color: #1a1d21;
        }
        .tt-dash-stat-icon {
            width: 34px;
            height: 34px;
            border-radius: 7px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            color: #fff;
        }
        .tt-dash-stat-icon .dashicons {
            font-size: 18px;
            width: 18px;
            height: 18px;
            line-height: 18px;
        }
        .tt-dash-stat-icon .tt-icon { width: 18px; height: 18px; }
        .tt-dash-stat-body {
            flex: 1;
            min-width: 0;
            display: flex;
            flex-direction: column;
        }
        .tt-dash-stat-top {
            display: flex;
            align-items: baseline;
            gap: 6px;
            line-height: 1.1;
        }
        .tt-dash-stat-count {
            font-size: 20px;
            font-weight: 700;
            color: #1a1d21;
        }
        .tt-dash-stat-delta {
            font-size: 11px;
            font-weight: 600;
            padding: 2px 6px;
            border-radius: 3px;
            background: #edf7ed;
            color: #00a32a;
            white-space: nowrap;
        }
        .tt-dash-stat-delta.is-zero {
            background: #f0f0f1;
            color: #888;
        }
        .tt-dash-stat-label {
            color: #555;
            font-size: 12px;
            margin-top: 2px;
        }

        /* Tile groups */
        .tt-dash-group {
            margin-bottom: 8px;
        }
        .tt-dash-tiles {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 12px;
        }
        .tt-dash-tile {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            background: #fff;
            border: 1px solid #e5e7ea;
            border-radius: 8px;
            padding: 14px 16px;
            text-decoration: none;
            color: #1a1d21;
            transition: transform 200ms cubic-bezier(0.2, 0.8, 0.2, 1),
                        box-shadow 200ms ease,
                        border-color 200ms ease;
        }
        .tt-dash-tile:hover, .tt-dash-tile:focus {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            color: #1a1d21;
        }
        .tt-dash-tile-icon {
            flex-shrink: 0;
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
        }
        .tt-dash-tile-icon .dashicons {
            font-size: 20px;
            width: 20px;
            height: 20px;
            line-height: 20px;
        }
        .tt-dash-tile-icon .tt-icon { width: 20px; height: 20px; }
        .tt-dash-tile-body { flex: 1; min-width: 0; }
        .tt-dash-tile-label {
            font-weight: 600;
            font-size: 14px;
            margin: 0 0 3px;
            color: #1a1d21;
        }
        .tt-dash-tile-desc {
            color: #666;
            font-size: 12px;
            line-height: 1.4;
            margin: 0;
        }

        @media (max-width: 640px) {
            .tt-dash-overview { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); }
            .tt-dash-stat-count { font-size: 24px; }
            .tt-dash-tiles { grid-template-columns: 1fr; }
        }
        </style>

        <div class="wrap tt-dash-page">
            <h1><?php esc_html_e( 'TalentTrack Dashboard', 'talenttrack' ); ?></h1>
            <p class="tt-dash-intro">
                <?php esc_html_e( 'Your club at a glance. Tap any card or tile to jump straight into that section.', 'talenttrack' ); ?>
            </p>

            <?php if ( ! self::shouldShowLegacyMenus() ) :
                $frontend_url    = home_url( '/' );
                $admin_config    = admin_url( 'admin.php?page=tt-config' );
                ?>
                <div class="notice notice-info" style="margin:0 0 24px; padding:14px 18px; border-left-color:#0b3d2e;">
                    <p style="margin:0 0 6px; font-size:14px;">
                        <strong><?php esc_html_e( 'TalentTrack admin tools have moved to the frontend.', 'talenttrack' ); ?></strong>
                    </p>
                    <p style="margin:0 0 8px; color:#555;">
                        <?php esc_html_e( 'Coaches, HoD and admins manage everything from the frontend dashboard now. Direct URLs to legacy wp-admin pages keep working as an emergency fallback — the tiles below still work, and the Configuration page (where this toggle lives) is reachable via direct URL.', 'talenttrack' ); ?>
                    </p>
                    <p style="margin:0;">
                        <a class="button button-primary" href="<?php echo esc_url( $frontend_url ); ?>">
                            <?php esc_html_e( 'Open the frontend dashboard', 'talenttrack' ); ?>
                        </a>
                        <a class="button" href="<?php echo esc_url( $admin_config ); ?>" style="margin-left:6px;">
                            <?php esc_html_e( 'Re-enable legacy menus (wp-admin Configuration)', 'talenttrack' ); ?>
                        </a>
                    </p>
                </div>
            <?php endif; ?>

            <!-- Overview stats -->
            <?php
            $visible_stats = array_filter( $stats, function ( $s ) { return current_user_can( $s['cap'] ); } );
            if ( ! empty( $visible_stats ) ) :
                ?>
                <div class="tt-dash-section-label">
                    <span><?php esc_html_e( 'Overview', 'talenttrack' ); ?></span>
                </div>
                <div class="tt-dash-overview">
                    <?php foreach ( $visible_stats as $s ) :
                        $delta = (int) $s['delta'];
                        $delta_class = $delta === 0 ? 'is-zero' : '';
                        $delta_text = $delta > 0
                            ? sprintf( '+%d %s', $delta, __( 'this week', 'talenttrack' ) )
                            : sprintf( '%d %s', $delta, __( 'this week', 'talenttrack' ) );
                        ?>
                        <a class="tt-dash-stat" href="<?php echo esc_url( $s['url'] ); ?>" style="border-left-color:<?php echo esc_attr( $s['color'] ); ?>;">
                            <span class="tt-dash-stat-icon" style="background:<?php echo esc_attr( $s['color'] ); ?>;">
                                <?php echo IconRenderer::render( $s['icon'] ); ?>
                            </span>
                            <div class="tt-dash-stat-body">
                                <div class="tt-dash-stat-top">
                                    <span class="tt-dash-stat-count"><?php echo esc_html( (string) $s['count'] ); ?></span>
                                    <span class="tt-dash-stat-delta <?php echo esc_attr( $delta_class ); ?>"><?php echo esc_html( $delta_text ); ?></span>
                                </div>
                                <div class="tt-dash-stat-label"><?php echo esc_html( $s['label'] ); ?></div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <!-- Grouped tiles -->
            <?php foreach ( $groups as $group ) :
                $visible_tiles = array_filter( $group['tiles'], function ( $t ) { return current_user_can( $t['cap'] ); } );
                if ( empty( $visible_tiles ) ) continue;
                ?>
                <div class="tt-dash-group">
                    <div class="tt-dash-section-label">
                        <span><?php echo esc_html( $group['label'] ); ?></span>
                    </div>
                    <div class="tt-dash-tiles">
                        <?php foreach ( $visible_tiles as $tile ) :
                            // Tint the tile icon by the group's accent color, with
                            // a subtle gradient towards white for visual depth.
                            $icon_bg = sprintf( 'linear-gradient(135deg, %s 0%%, %s 100%%)',
                                $group['accent'],
                                self::lighten( $group['accent'], 25 )
                            );
                            ?>
                            <a class="tt-dash-tile" href="<?php echo esc_url( $tile['url'] ); ?>">
                                <span class="tt-dash-tile-icon" style="background:<?php echo esc_attr( $icon_bg ); ?>;">
                                    <?php echo IconRenderer::render( $tile['icon'] ); ?>
                                </span>
                                <div class="tt-dash-tile-body">
                                    <div class="tt-dash-tile-label"><?php echo esc_html( $tile['label'] ); ?></div>
                                    <p class="tt-dash-tile-desc"><?php echo esc_html( $tile['desc'] ); ?></p>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
    }

    /**
     * Lighten a hex color by a given percentage (0-100). Used to build
     * subtle gradients for tile icons.
     */
    private static function lighten( string $hex, int $percent ): string {
        $hex = ltrim( $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if ( strlen( $hex ) !== 6 ) return '#' . $hex;
        $r = hexdec( substr( $hex, 0, 2 ) );
        $g = hexdec( substr( $hex, 2, 2 ) );
        $b = hexdec( substr( $hex, 4, 2 ) );
        $pct = max( 0, min( 100, $percent ) ) / 100;
        $r = min( 255, (int) round( $r + ( 255 - $r ) * $pct ) );
        $g = min( 255, (int) round( $g + ( 255 - $g ) * $pct ) );
        $b = min( 255, (int) round( $b + ( 255 - $b ) * $pct ) );
        return sprintf( '#%02x%02x%02x', $r, $g, $b );
    }

    public static function enqueue( string $hook ): void {
        if ( strpos( $hook, 'talenttrack' ) === false && strpos( $hook, 'tt-' ) === false ) return;
        wp_enqueue_style( 'tt-admin', TT_PLUGIN_URL . 'assets/css/admin.css', [], TT_VERSION );
        // Pull frontend-admin.css so the .tt-confirm-overlay / .tt-flash-near
        // styles are available on wp-admin pages that use admin-confirm.js
        // (they're scoped to .tt-confirm-* / .tt-flash-near-* selectors and
        // don't bleed into core admin styles).
        wp_enqueue_style( 'tt-frontend-admin', TT_PLUGIN_URL . 'assets/css/frontend-admin.css', [ 'tt-admin' ], TT_VERSION );
        wp_enqueue_script( 'tt-admin', TT_PLUGIN_URL . 'assets/js/admin.js', [ 'jquery' ], TT_VERSION, true );

        // Confirm + flash bridge: shipped on every TT-prefixed admin page so
        // any button can declare data-tt-confirm-* attributes and get the
        // in-app modal instead of browser window.confirm(). (#3)
        wp_enqueue_script( 'tt-confirm',       TT_PLUGIN_URL . 'assets/js/components/confirm.js', [], TT_VERSION, true );
        wp_enqueue_script( 'tt-flash',         TT_PLUGIN_URL . 'assets/js/components/flash.js',   [], TT_VERSION, true );
        wp_enqueue_script( 'tt-admin-confirm', TT_PLUGIN_URL . 'assets/js/admin-confirm.js', [ 'tt-confirm', 'tt-flash' ], TT_VERSION, true );

        // F4 — client-side search + sort + filter on TT admin tables.
        // Tables opt in by adding the `tt-table-sortable` class to the
        // <table> element. The script auto-adds a search input above
        // and makes every <th> sortable on click.
        wp_enqueue_script(
            'tt-table-tools',
            TT_PLUGIN_URL . 'assets/js/tt-table-tools.js',
            [],
            TT_VERSION,
            true
        );
        wp_localize_script(
            'tt-table-tools',
            'ttTableToolsStrings',
            [
                'search'            => __( 'Search:', 'talenttrack' ),
                'searchPlaceholder' => __( 'Filter rows…', 'talenttrack' ),
                'rowsTotal'         => __( '{n} row(s)', 'talenttrack' ),
                'rowsFiltered'      => __( '{v} of {n}', 'talenttrack' ),
            ]
        );

        // Register (not auto-enqueue) the sortable script. The CustomFieldsTab
        // and OptionSetEditor call wp_enqueue_script('tt-admin-sortable') on
        // demand — this registration makes that call effective.
        wp_register_script(
            'tt-admin-sortable',
            TT_PLUGIN_URL . 'assets/js/admin-sortable.js',
            [],
            TT_VERSION,
            true
        );
    }
}
