<?php
namespace TT\Shared;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Shared\Admin\AdminMenuRegistry;
use TT\Shared\Tiles\TileRegistry;

/**
 * CoreSurfaceRegistration (#0033 finalisation) — single seed point for
 * every tile + wp-admin menu surface that the plugin renders.
 *
 * The historical literals lived inline in `FrontendTileGrid::buildGroups()`
 * and `Menu::register()` / `Menu::renderDashboardTiles()`. They are now
 * declaratively re-homed here, each tagged with the owning
 * `module_class` so the registries' built-in `ModuleRegistry::isEnabled`
 * filter takes effect.
 *
 * Called once from `Kernel::boot()` after `bootAll()` so every module's
 * REST/hook registrations have already happened before the frontend
 * dashboard or admin menu first renders.
 *
 * Goal: satisfy the `#0033` Sprint 4 acceptance criterion ("Every tile
 * rendered on admin + frontend comes from `TileRegistry::tilesForUser()`.
 * No tile literals remain in `Menu.php` or `FrontendTileGrid.php`.").
 *
 * Where data is genuinely dynamic per request (open task count badge,
 * coach-vs-admin label variant), tiles register a `label_callback` /
 * `color_callback` / `cap_callback` resolved at render time.
 */
final class CoreSurfaceRegistration {

    /** Module class shorthands kept here for legibility. */
    private const M_ACTIVITIES    = 'TT\\Modules\\Activities\\ActivitiesModule';
    private const M_AUTH          = 'TT\\Modules\\Auth\\AuthModule';
    private const M_AUTHORIZATION = 'TT\\Modules\\Authorization\\AuthorizationModule';
    private const M_CONFIG        = 'TT\\Modules\\Configuration\\ConfigurationModule';
    private const M_DEVELOPMENT   = 'TT\\Modules\\Development\\DevelopmentModule';
    private const M_DOCUMENTATION = 'TT\\Modules\\Documentation\\DocumentationModule';
    private const M_EVALUATIONS   = 'TT\\Modules\\Evaluations\\EvaluationsModule';
    private const M_GOALS         = 'TT\\Modules\\Goals\\GoalsModule';
    private const M_INVITATIONS   = 'TT\\Modules\\Invitations\\InvitationsModule';
    private const M_LICENSE       = 'TT\\Modules\\License\\LicenseModule';
    private const M_METHODOLOGY   = 'TT\\Modules\\Methodology\\MethodologyModule';
    private const M_ONBOARDING    = 'TT\\Modules\\Onboarding\\OnboardingModule';
    private const M_PDP           = 'TT\\Modules\\Pdp\\PdpModule';
    private const M_PEOPLE        = 'TT\\Modules\\People\\PeopleModule';
    private const M_PLAYERS       = 'TT\\Modules\\Players\\PlayersModule';
    private const M_REPORTS       = 'TT\\Modules\\Reports\\ReportsModule';
    private const M_SPOND         = 'TT\\Modules\\Spond\\SpondModule';
    private const M_STATS         = 'TT\\Modules\\Stats\\StatsModule';
    private const M_TEAMDEV       = 'TT\\Modules\\TeamDevelopment\\TeamDevelopmentModule';
    private const M_TEAMS         = 'TT\\Modules\\Teams\\TeamsModule';
    private const M_TRIALS        = 'TT\\Modules\\Trials\\TrialsModule';
    private const M_PROSPECTS     = 'TT\\Modules\\Prospects\\ProspectsModule';
    private const M_PLANNING      = 'TT\\Modules\\Planning\\PlanningModule';
    private const M_STAFF_DEV     = 'TT\\Modules\\StaffDevelopment\\StaffDevelopmentModule';
    private const M_WIZARDS       = 'TT\\Modules\\Wizards\\WizardsModule';
    private const M_WORKFLOW      = 'TT\\Modules\\Workflow\\WorkflowModule';
    private const M_JOURNEY       = 'TT\\Modules\\Journey\\JourneyModule';

    /**
     * Run all registrations. Idempotent — call once during boot.
     * (Both registries dedupe by virtue of being append-only stores
     * cleared explicitly via `::clear()` in tests.)
     */
    public static function register(): void {
        self::registerFrontendTiles();
        self::registerSlugOwnerships();
        self::registerAdminSubmenu();
        self::registerAdminDashboardTiles();
        self::registerMobileClasses();
    }

    /**
     * #0084 Child 1 — initial mobile classification declarations.
     * #0084 Child 3 (v3.104.0) — extends with the `native` set + the
     * remaining desktop_only surfaces that landed after Child 1's
     * 17-slug seed.
     *
     * Other slugs resolve to `viewable` via `MobileSurfaceRegistry`'s
     * default — backwards-compatible with the existing inventory.
     */
    private static function registerMobileClasses(): void {
        $desktop_only = [
            'configuration',
            'custom-fields',
            'eval-categories',
            'roles',
            'migrations',
            'usage-stats',
            'usage-stats-details',
            'audit-log',
            'cohort-transitions',
            'custom-css',
            'workflow-config',
            'team-blueprints',
            'methodology',
            'invitations-config',
            'trial-tracks-editor',
            'trial-letter-templates-editor',
            'wizards-admin',
            // #0084 Child 3 — additional desktop_only routes.
            'players-import',     // CSV mapping flow — laptop required.
            'onboarding-pipeline', // xl-size pipeline widget; doesn't fit on phones.
            'reports',            // wizard + multi-column tables.
            // #0083 Child 3 — analytics dimension explorer.
            'explore',
        ];
        foreach ( $desktop_only as $slug ) {
            \TT\Shared\MobileSurfaceRegistry::register( $slug, \TT\Shared\MobileSurfaceRegistry::CLASS_DESKTOP_ONLY );
        }

        // #0084 Child 3 — `native` declarations. Coaches reach the
        // player profile from the sideline ("is this kid match-fit?")
        // and finish a training via the new-evaluation wizard on a
        // phone all the time. These surfaces get the mobile pattern
        // library auto-enqueued.
        $native = [
            'players',   // coach player profile
            'wizard',    // every wizard goes through this aggregator slug
            'teammate',  // player viewing a teammate's card
        ];
        foreach ( $native as $slug ) {
            \TT\Shared\MobileSurfaceRegistry::register( $slug, \TT\Shared\MobileSurfaceRegistry::CLASS_NATIVE );
        }
    }

    /**
     * Tile-less view slugs the dashboard dispatcher reaches directly —
     * sub-views opened from the Configuration tile-landing or hidden
     * drill-downs. They get their owning module declared here so the
     * dispatcher's module-disabled gate works for them too.
     */
    private static function registerSlugOwnerships(): void {
        TileRegistry::registerSlugOwnership( 'players-import',     self::M_PLAYERS );
        TileRegistry::registerSlugOwnership( 'teammate',           self::M_TEAMS );
        TileRegistry::registerSlugOwnership( 'custom-fields',      self::M_CONFIG );
        TileRegistry::registerSlugOwnership( 'eval-categories',    self::M_EVALUATIONS );
        TileRegistry::registerSlugOwnership( 'roles',              self::M_AUTHORIZATION );
        TileRegistry::registerSlugOwnership( 'usage-stats-details', self::M_STATS );
        TileRegistry::registerSlugOwnership( 'docs',               self::M_DOCUMENTATION );
        TileRegistry::registerSlugOwnership( 'wizard',             self::M_WIZARDS );
        TileRegistry::registerSlugOwnership( 'wizards-admin',      self::M_WIZARDS );
        // #0081 child 3 — standalone onboarding-pipeline view.
        TileRegistry::registerSlugOwnership( 'onboarding-pipeline', self::M_PROSPECTS );
        // #0006 — team-planning calendar.
        TileRegistry::registerSlugOwnership( 'team-planner', self::M_PLANNING );
        // #0083 Child 3 — dimension explorer (Analytics module).
        TileRegistry::registerSlugOwnership( 'explore', 'TT\\Modules\\Analytics\\AnalyticsModule' );
        // `accept-invite` and `audit-log` are intentionally absent —
        // the first must keep working for not-yet-registered
        // recipients, the second is infrastructure (no module owner).
    }

    /* ───────────────────────────────────────────────────────────
       FRONTEND TILES — `[talenttrack_dashboard]` tile landing.
       Groups (top → bottom): Me / Tasks / People / Performance /
       Reference / Analytics / Development / Administration.
       ─────────────────────────────────────────────────────────── */

    private static function registerFrontendTiles(): void {
        $is_player_cb = static function ( int $user_id ): bool {
            return (bool) QueryHelpers::get_player_for_user( $user_id );
        };
        // Me-group tiles are also visible to parents (scoped to their
        // child via the matrix `parent` persona seed). Without this,
        // parents land on an empty dashboard.
        $is_player_or_parent_cb = static function ( int $user_id ) use ( $is_player_cb ): bool {
            return $is_player_cb( $user_id ) || user_can( $user_id, 'tt_parent' );
        };
        // #0079 — `$is_coach_or_admin_cb` removed. Coach-tier tiles now
        // declare a *_panel matrix entity and matrix-active installs gate
        // visibility through MatrixGate. The closure was the dormant-matrix
        // fallback for nine tiles in the People + Performance + Development
        // groups; those tiles are matrix-only post-#0079.

        // ── Me group — visible only when user has a player record. ──
        $me_group = __( 'Me', 'talenttrack' );
        TileRegistry::register([
            'module_class' => self::M_PLAYERS,
            'view_slug'    => 'overview',
            'entity'       => 'my_card',
            'group'        => $me_group,
            'kind'         => 'work',
            'order'        => 10,
            'label'        => __( 'My card', 'talenttrack' ),
            'description'  => __( 'Your FIFA-style card, ratings, and headline numbers.', 'talenttrack' ),
            'icon'         => 'rate-card',
            'color'        => '#1d7874',
            'cap_callback' => $is_player_or_parent_cb,
        ]);
        TileRegistry::register([
            'module_class' => self::M_TEAMS,
            'view_slug'    => 'my-team',
            'entity'       => 'my_team',
            'group'        => $me_group,
            'kind'         => 'work',
            'order'        => 20,
            'label'        => __( 'My team', 'talenttrack' ),
            'description'  => __( 'Your teammates and the team podium.', 'talenttrack' ),
            'icon'         => 'teams',
            'color'        => '#2271b1',
            'cap_callback' => $is_player_or_parent_cb,
        ]);
        TileRegistry::register([
            'module_class' => self::M_EVALUATIONS,
            'view_slug'    => 'my-evaluations',
            'entity'       => 'my_evaluations',
            'group'        => $me_group,
            'kind'         => 'work',
            'order'        => 30,
            'label'        => __( 'My evaluations', 'talenttrack' ),
            'description'  => __( 'Ratings and feedback from your coaches.', 'talenttrack' ),
            'icon'         => 'evaluations',
            'color'        => '#7c3a9e',
            'cap_callback' => $is_player_or_parent_cb,
        ]);
        TileRegistry::register([
            'module_class' => self::M_ACTIVITIES,
            'view_slug'    => 'my-activities',
            'entity'       => 'my_activities',
            'group'        => $me_group,
            'kind'         => 'work',
            'order'        => 40,
            'label'        => __( 'My activities', 'talenttrack' ),
            'description'  => __( 'Training activities and games you\'ve attended.', 'talenttrack' ),
            'icon'         => 'activities',
            'color'        => '#c9962a',
            'cap_callback' => $is_player_or_parent_cb,
        ]);
        TileRegistry::register([
            'module_class' => self::M_GOALS,
            'view_slug'    => 'my-goals',
            'entity'       => 'my_goals',
            'group'        => $me_group,
            'kind'         => 'work',
            'order'        => 50,
            'label'        => __( 'My goals', 'talenttrack' ),
            'description'  => __( 'Development goals to work toward.', 'talenttrack' ),
            'icon'         => 'goals',
            'color'        => '#b32d2e',
            'cap_callback' => $is_player_or_parent_cb,
        ]);
        // v3.92.0 — uses tile-visibility entity `my_pdp_panel` (only
        // granted to player + parent in the seed) instead of the data
        // entity `pdp_file`. Coaches/HoD/scout legitimately read PDP
        // data at team/global scope but should not see the player-self
        // "My PDP" tile on their dashboard. Same disambiguation pattern
        // as #0079.
        TileRegistry::register([
            'module_class' => self::M_PDP,
            'view_slug'    => 'my-pdp',
            'entity'       => 'my_pdp_panel',
            'group'        => $me_group,
            'kind'         => 'work',
            'order'        => 60,
            'label'        => __( 'My PDP', 'talenttrack' ),
            'description'  => __( 'Your development plan: conversations, reflections, end-of-season verdict.', 'talenttrack' ),
            'icon'         => 'goals',
            'color'        => '#1d7874',
        ]);
        // v3.62.0 — "My profile" tile retired. The four sections that
        // used to live there (Playing details / Recent performance /
        // Active goals / Upcoming) are folded into "My card", and the
        // Account section moved to "My settings" under the username
        // dropdown. The `?tt_view=profile` slug still routes (to My
        // card) so existing bookmarks keep working — see
        // DashboardShortcode::dispatchMeView().
        TileRegistry::register([
            'module_class' => self::M_JOURNEY,
            'view_slug'    => 'my-journey',
            'entity'       => 'my_journey',
            'group'        => $me_group,
            'kind'         => 'work',
            'order'        => 5,
            'label'        => __( 'My journey', 'talenttrack' ),
            'description'  => __( 'Your story at the academy — milestones, evaluations, goals.', 'talenttrack' ),
            'icon'         => 'goals',
            'color'        => '#0d9488',
            'cap_callback' => $is_player_or_parent_cb,
        ]);

        // ── Tasks group — workflow inbox + dashboards. ──
        $tasks_group = __( 'Tasks', 'talenttrack' );
        TileRegistry::register([
            'module_class' => self::M_WORKFLOW,
            'view_slug'    => 'my-tasks',
            'entity'       => 'workflow_tasks',
            'group'        => $tasks_group,
            'kind'         => 'work',
            'order'        => 10,
            'label'        => __( 'My tasks', 'talenttrack' ),
            'description'  => __( 'Open tasks waiting on you — evaluations, goals, reviews.', 'talenttrack' ),
            'icon'         => 'inbox',
            'color'        => '#5b6e75',
            'cap'          => 'tt_view_own_tasks',
            'label_callback' => static function ( int $user_id ): string {
                if ( ! class_exists( '\\TT\\Modules\\Workflow\\Frontend\\FrontendMyTasksView' ) ) {
                    return __( 'My tasks', 'talenttrack' );
                }
                $count = \TT\Modules\Workflow\Frontend\FrontendMyTasksView::openCountForUser( $user_id );
                if ( $count > 0 ) {
                    /* translators: %d: number of open tasks */
                    return sprintf( __( 'My tasks (%d)', 'talenttrack' ), $count );
                }
                return __( 'My tasks', 'talenttrack' );
            },
            'color_callback' => static function ( int $user_id ): string {
                if ( ! class_exists( '\\TT\\Modules\\Workflow\\Frontend\\FrontendMyTasksView' ) ) {
                    return '#5b6e75';
                }
                $count = \TT\Modules\Workflow\Frontend\FrontendMyTasksView::openCountForUser( $user_id );
                return $count > 0 ? '#b32d2e' : '#5b6e75';
            },
        ]);
        TileRegistry::register([
            'module_class' => self::M_WORKFLOW,
            'view_slug'    => 'tasks-dashboard',
            'entity'       => 'tasks_dashboard',
            'group'        => $tasks_group,
            'kind'         => 'work',
            'order'        => 20,
            'label'        => __( 'Tasks dashboard', 'talenttrack' ),
            'description'  => __( 'Per-template and per-coach completion rates plus currently overdue tasks.', 'talenttrack' ),
            'icon'         => 'kanban',
            'color'        => '#2271b1',
            'cap'          => 'tt_view_tasks_dashboard',
        ]);
        TileRegistry::register([
            'module_class' => self::M_WORKFLOW,
            'view_slug'    => 'workflow-config',
            'entity'       => 'workflow_templates',
            'group'        => $tasks_group,
            'kind'         => 'setup',
            'order'        => 30,
            'label'        => __( 'Workflow templates', 'talenttrack' ),
            'description'  => __( 'Enable or disable templates and override their cadence + deadline.', 'talenttrack' ),
            'icon'         => 'workflow',
            'color'        => '#5b6e75',
            'cap'          => 'tt_configure_workflow_templates',
            'desktop_preferred' => true,
        ]);

        // ── People group ──
        $people_group = __( 'People', 'talenttrack' );
        // #0079 — `team_roster_panel` is the tile-visibility entity, distinct
        // from the underlying data entity `team` (which gates REST + repo
        // reads). Scout legitimately reads team data globally for scouting
        // workflows; they should not see the coach-side roster tile.
        TileRegistry::register([
            'module_class' => self::M_TEAMS,
            'view_slug'    => 'teams',
            'entity'       => 'team_roster_panel',
            'group'        => $people_group,
            'kind'         => 'work',
            'order'        => 10,
            'label'        => __( 'My teams', 'talenttrack' ),
            'description'  => __( 'Teams you coach — roster, podium, evaluations.', 'talenttrack' ),
            'icon'         => 'teams',
            'color'        => '#2271b1',
        ]);
        TileRegistry::register([
            'module_class' => self::M_PLAYERS,
            'view_slug'    => 'players',
            'entity'       => 'coach_player_list_panel',
            'group'        => $people_group,
            'kind'         => 'work',
            'order'        => 20,
            'label'        => __( 'My players', 'talenttrack' ),
            'description'  => __( 'Players on the teams you coach.', 'talenttrack' ),
            'icon'         => 'players',
            'color'        => '#1d7874',
            'label_callback' => static function ( int $user_id ): string {
                return user_can( $user_id, 'tt_edit_settings' )
                    ? __( 'Players', 'talenttrack' )
                    : __( 'My players', 'talenttrack' );
            },
        ]);
        TileRegistry::register([
            'module_class' => self::M_PEOPLE,
            'view_slug'    => 'people',
            'entity'       => 'people_directory_panel',
            'group'        => $people_group,
            'kind'         => 'work',
            'order'        => 30,
            'label'        => __( 'People', 'talenttrack' ),
            'description'  => __( 'Staff, parents, scouts and other non-player records.', 'talenttrack' ),
            'icon'         => 'people',
            'color'        => '#5b6e75',
        ]);
        // Functional roles is owned by Authorization (always-on), so this
        // tile never disappears via the module-enabled gate.
        TileRegistry::register([
            'module_class' => self::M_AUTHORIZATION,
            'view_slug'    => 'functional-roles',
            'entity'       => 'functional_role_assignments',
            'group'        => $people_group,
            'kind'         => 'setup',
            'order'        => 40,
            'label'        => __( 'Functional roles', 'talenttrack' ),
            'description'  => __( 'Per-team staff assignments — head coach, assistant, manager, physio. Different from academy-wide Roles & rights.', 'talenttrack' ),
            'icon'         => 'functional-roles',
            'color'        => '#5b6e75',
            'cap_callback' => static function ( int $uid ): bool {
                return user_can( $uid, 'tt_manage_functional_roles' ) || user_can( $uid, 'tt_view_people' );
            },
            // #0069 — HoD doesn't manage per-team staff slots day-to-day;
            // their lens is academy-wide development. Hide the tile.
            'hide_for_personas' => [ 'head_of_development' ],
        ]);

        // ── Performance group ──
        $performance_group = __( 'Performance', 'talenttrack' );
        // #0069 — PDP work moves out of Performance into its own
        // Development group so player-development surfaces don't sit
        // alongside the activity / evaluation / podium tiles.
        $development_group = __( 'Development', 'talenttrack' );
        // #0079 — coach-side panels declare tile-visibility entities. The
        // underlying data entities (`evaluations`, `activities`, `goals`,
        // `pdp_file`) keep their REST/repo gating role; the *_panel
        // entities answer "should this user see this dashboard tile?".
        TileRegistry::register([
            'module_class' => self::M_EVALUATIONS,
            'view_slug'    => 'evaluations',
            'entity'       => 'evaluations_panel',
            'group'        => $performance_group,
            'kind'         => 'work',
            'order'        => 10,
            'label'        => __( 'Evaluations', 'talenttrack' ),
            'description'  => __( 'Record player ratings, add notes and scores.', 'talenttrack' ),
            'icon'         => 'evaluations',
            'color'        => '#7c3a9e',
        ]);
        TileRegistry::register([
            'module_class' => self::M_ACTIVITIES,
            'view_slug'    => 'activities',
            'entity'       => 'activities_panel',
            'group'        => $performance_group,
            'kind'         => 'work',
            'order'        => 20,
            'label'        => __( 'Activities', 'talenttrack' ),
            'description'  => __( 'Log training activities and attendance.', 'talenttrack' ),
            'icon'         => 'activities',
            'color'        => '#c9962a',
        ]);
        // #0006 — team-planning calendar tile, in the Performance group
        // alongside Activities. The planner is the forward-looking
        // surface; the activities tile is the backward log. Caps reuse
        // the existing `activities_panel` entity gate, plus the planner
        // checks `tt_view_plan` at render time for the soft "you don't
        // have access" message.
        TileRegistry::register([
            'module_class' => self::M_PLANNING,
            'view_slug'    => 'team-planner',
            'entity'       => 'activities_panel',
            'group'        => $performance_group,
            'kind'         => 'work',
            'order'        => 25,
            'label'        => __( 'Team planner', 'talenttrack' ),
            'description'  => __( 'Plan training activities week by week, with principle tagging and plan-state tracking.', 'talenttrack' ),
            'icon'         => 'kanban',
            'color'        => '#2271b1',
            'cap'          => 'tt_view_plan',
        ]);
        TileRegistry::register([
            'module_class' => self::M_GOALS,
            'view_slug'    => 'goals',
            'entity'       => 'goals_panel',
            'group'        => $performance_group,
            'kind'         => 'work',
            'order'        => 30,
            'label'        => __( 'Goals', 'talenttrack' ),
            'description'  => __( 'Set and track player development goals.', 'talenttrack' ),
            'icon'         => 'goals',
            'color'        => '#b32d2e',
        ]);
        TileRegistry::register([
            'module_class' => self::M_PDP,
            'view_slug'    => 'pdp',
            'entity'       => 'pdp_panel',
            'group'        => $development_group,
            'kind'         => 'work',
            'order'        => 40,
            'label'        => __( 'PDP', 'talenttrack' ),
            'description'  => __( 'Per-season development files: conversations, goals, end-of-season verdict.', 'talenttrack' ),
            'icon'         => 'goals',
            'color'        => '#1d7874',
        ]);
        TileRegistry::register([
            'module_class' => self::M_PDP,
            'view_slug'    => 'pdp-planning',
            'entity'       => 'pdp_planning',
            'group'        => $development_group,
            'kind'         => 'work',
            'order'        => 41,
            'label'        => __( 'PDP planning', 'talenttrack' ),
            'description'  => __( 'HoD matrix: per-team-per-block planned vs conducted conversations.', 'talenttrack' ),
            'icon'         => 'goals',
            'color'        => '#1d7874',
            // Players + parents hold tt_view_pdp for their own self-scope
            // per #0033, but the planning matrix is the HoD/coach cross-
            // team surface. Gate on the edit cap so the tile + view render
            // only for roles that legitimately plan.
            'cap'          => 'tt_edit_pdp',
        ]);
        TileRegistry::register([
            'module_class' => self::M_PLAYERS,
            'view_slug'    => 'player-status-methodology',
            'entity'       => 'player_status_methodology',
            'group'        => $performance_group,
            'kind'         => 'setup',
            'order'        => 60,
            'label'        => __( 'Player status methodology', 'talenttrack' ),
            'description'  => __( 'Per-age-group input weights, thresholds, and behaviour-floor rule for the traffic-light status.', 'talenttrack' ),
            'icon'         => 'settings',
            'color'        => '#7c3aed',
            'cap'          => 'tt_edit_settings',
            'desktop_preferred' => true,
        ]);
        TileRegistry::register([
            'module_class' => self::M_TEAMDEV,
            'view_slug'    => 'team-chemistry',
            'entity'       => 'team_chemistry_panel',
            'group'        => $performance_group,
            'kind'         => 'work',
            'order'        => 50,
            'label'        => __( 'Team chemistry', 'talenttrack' ),
            'description'  => __( 'Formation board with auto-suggested XI, depth chart, and chemistry breakdown.', 'talenttrack' ),
            'icon'         => 'teams',
            'color'        => '#3a6f8f',
        ]);
        TileRegistry::register([
            'module_class' => self::M_TEAMDEV,
            'view_slug'    => 'team-blueprints',
            'entity'       => 'team_chemistry_panel',
            'group'        => $performance_group,
            'kind'         => 'work',
            'order'        => 55,
            'label'        => __( 'Team blueprint', 'talenttrack' ),
            'description'  => __( 'Coach-authored lineups: drag players onto the pitch, see chemistry update live, share with staff and lock when set.', 'talenttrack' ),
            'icon'         => 'teams',
            'color'        => '#1d6cb1',
        ]);
        TileRegistry::register([
            'module_class' => self::M_STATS,
            'view_slug'    => 'podium',
            'entity'       => 'podium_panel',
            'group'        => $performance_group,
            'kind'         => 'work',
            'order'        => 60,
            'label'        => __( 'Podium', 'talenttrack' ),
            'description'  => __( 'Team rankings and top performers.', 'talenttrack' ),
            'icon'         => 'podium',
            'color'        => '#e8b624',
        ]);

        // ── Reference group ──
        TileRegistry::register([
            'module_class' => self::M_METHODOLOGY,
            'view_slug'    => 'methodology',
            'entity'       => 'methodology',
            'group'        => __( 'Reference', 'talenttrack' ),
            'kind'         => 'work',
            'order'        => 10,
            'label'        => __( 'Methodology', 'talenttrack' ),
            'description'  => __( 'Principles, formations, positions and set pieces.', 'talenttrack' ),
            'icon'         => 'methodology',
            'color'        => '#1d7874',
            'cap'          => 'tt_view_methodology',
        ]);

        // ── Trials group (#0017) ──
        $trials_group = __( 'Trials', 'talenttrack' );
        TileRegistry::register([
            'module_class' => self::M_TRIALS,
            'view_slug'    => 'trials',
            'entity'       => 'trial_cases',
            'group'        => $trials_group,
            'kind'         => 'work',
            'order'        => 10,
            'label'        => __( 'Trial cases', 'talenttrack' ),
            'description'  => __( 'Manage prospective players: track, dates, staff input, and decisions.', 'talenttrack' ),
            'icon'         => 'track',
            'color'        => '#c9962a',
            'cap'          => 'tt_view_trial_synthesis',
        ]);
        // #0081 child 3 — Onboarding pipeline standalone view tile.
        // Sits in the Trials group adjacent to the existing trials tile;
        // the funnel feeds the trials backlog so they belong together.
        TileRegistry::register([
            'module_class' => self::M_PROSPECTS,
            'view_slug'    => 'onboarding-pipeline',
            'entity'       => 'prospects',
            'group'        => $trials_group,
            'kind'         => 'work',
            'order'        => 5,
            'label'        => __( 'Onboarding pipeline', 'talenttrack' ),
            'description'  => __( 'Six-stage funnel from scout-logged prospect through team-offer acceptance. HoD + Scout + Academy Admin only.', 'talenttrack' ),
            'icon'         => 'kanban',
            'color'        => '#2271b1',
            'cap'          => 'tt_view_prospects',
        ]);
        TileRegistry::register([
            'module_class' => self::M_TRIALS,
            'view_slug'    => 'trial-tracks-editor',
            'entity'       => 'trial_tracks',
            'group'        => $trials_group,
            'kind'         => 'setup',
            'order'        => 20,
            'label'        => __( 'Trial tracks', 'talenttrack' ),
            'description'  => __( 'Edit the track templates new trial cases use as defaults.', 'talenttrack' ),
            'icon'         => 'categories',
            'color'        => '#5b6e75',
            'cap'          => 'tt_manage_trials',
        ]);
        TileRegistry::register([
            'module_class' => self::M_TRIALS,
            'view_slug'    => 'trial-letter-templates-editor',
            'entity'       => 'trial_letter_templates',
            'group'        => $trials_group,
            'kind'         => 'setup',
            'order'        => 30,
            'label'        => __( 'Letter templates', 'talenttrack' ),
            'description'  => __( 'Customize the admit / decline letter wording per locale.', 'talenttrack' ),
            'icon'         => 'docs',
            'color'        => '#5b6e75',
            'cap'          => 'tt_manage_trials',
            'desktop_preferred' => true,
        ]);

        // Sub-views without their own tile.
        TileRegistry::registerSlugOwnership( 'trial-case',           self::M_TRIALS );
        TileRegistry::registerSlugOwnership( 'trial-parent-meeting', self::M_TRIALS );

        // ── Analytics group ──
        $analytics_group = __( 'Analytics', 'talenttrack' );
        TileRegistry::register([
            'module_class' => self::M_STATS,
            'view_slug'    => 'rate-cards',
            'entity'       => 'rate_cards',
            'group'        => $analytics_group,
            'kind'         => 'work',
            'order'        => 10,
            'label'        => __( 'Rate cards', 'talenttrack' ),
            'description'  => __( 'Per-player rating cards with trends.', 'talenttrack' ),
            'icon'         => 'rate-card',
            'color'        => '#2271b1',
            'cap'          => 'tt_view_reports',
        ]);
        TileRegistry::register([
            'module_class' => self::M_STATS,
            'view_slug'    => 'compare',
            'entity'       => 'compare',
            'group'        => $analytics_group,
            'kind'         => 'work',
            'order'        => 20,
            'label'        => __( 'Player comparison', 'talenttrack' ),
            'description'  => __( 'Compare up to 4 players side-by-side.', 'talenttrack' ),
            'icon'         => 'compare',
            'color'        => '#7c3a9e',
            'cap'          => 'tt_view_reports',
        ]);
        // #0063 — frontend Reports tile that mirrors the wp-admin
        // launcher. Detail views still render in wp-admin (heavy
        // form-submit + Chart.js paths), but the launcher gives the
        // frontend dashboard a discoverable entry point.
        TileRegistry::register([
            'module_class' => self::M_REPORTS,
            'view_slug'    => 'reports',
            'entity'       => 'reports',
            'group'        => $analytics_group,
            'kind'         => 'work',
            'order'        => 25,
            'label'        => __( 'Reports', 'talenttrack' ),
            'description'  => __( 'Player progress, team rating averages, coach activity.', 'talenttrack' ),
            'icon'         => 'reports',
            'color'        => '#00a32a',
            'cap'          => 'tt_view_reports',
        ]);
        TileRegistry::register([
            'module_class' => self::M_STATS,
            'view_slug'    => 'usage-stats',
            'entity'       => 'usage_stats',
            'group'        => $analytics_group,
            'kind'         => 'work',
            'order'        => 30,
            'label'        => __( 'Application KPIs', 'talenttrack' ),
            'description'  => __( 'Active users, evaluations per coach, attendance %, top players, goal completion.', 'talenttrack' ),
            'icon'         => 'usage-stats',
            'color'        => '#555',
            'cap'          => 'tt_access_frontend_admin',
        ]);
        TileRegistry::register([
            'module_class' => self::M_JOURNEY,
            'view_slug'    => 'cohort-transitions',
            'entity'       => 'cohort_transitions',
            'group'        => $analytics_group,
            'kind'         => 'work',
            'order'        => 40,
            'label'        => __( 'Cohort transitions', 'talenttrack' ),
            'description'  => __( 'Find every player whose journey contains a particular event in a date range.', 'talenttrack' ),
            'icon'         => 'inbox',
            'color'        => '#0d9488',
            'cap'          => 'tt_view_settings',
        ]);

        // Coach-side player journey is reached from a player detail link;
        // no tile needed, but the slug must be owned so the
        // module-disabled gate works for direct URLs.
        TileRegistry::registerSlugOwnership( 'player-journey', self::M_JOURNEY );

        // ── Idea pipeline group ──
        // v3.92.0 — was "Development" but collided with the player-
        // development group above (`$development_group`) which holds the
        // PDP tiles. Both groups rendered under one heading and
        // `FrontendTileGrid::splitByKind` couldn't tell them apart by
        // label. Renamed to disambiguate; the tiles inside (Submit idea
        // / Development board / Approval queue / Development tracks)
        // are all about plugin-improvement ideas, not player development.
        $dev_group = __( 'Idea pipeline', 'talenttrack' );
        TileRegistry::register([
            'module_class' => self::M_DEVELOPMENT,
            'view_slug'    => 'submit-idea',
            'entity'       => 'dev_ideas',
            'group'        => $dev_group,
            'kind'         => 'setup',
            'order'        => 10,
            'label'        => __( 'Submit an idea', 'talenttrack' ),
            'description'  => __( 'Spotted a bug or feature? Send it to the development queue.', 'talenttrack' ),
            'icon'         => 'lightbulb',
            'color'        => '#c9962a',
            'cap'          => 'tt_submit_idea',
        ]);
        TileRegistry::register([
            'module_class' => self::M_DEVELOPMENT,
            'view_slug'    => 'ideas-board',
            'entity'       => 'dev_ideas',
            'group'        => $dev_group,
            'kind'         => 'setup',
            'order'        => 20,
            'label'        => __( 'Development board', 'talenttrack' ),
            'description'  => __( 'Kanban view of every staged idea — submitted through done.', 'talenttrack' ),
            'icon'         => 'kanban',
            'color'        => '#7c3a9e',
            'cap'          => 'tt_view_dev_board',
        ]);
        TileRegistry::register([
            'module_class' => self::M_DEVELOPMENT,
            'view_slug'    => 'ideas-approval',
            'entity'       => 'dev_ideas',
            'group'        => $dev_group,
            'kind'         => 'setup',
            'order'        => 30,
            'label'        => __( 'Approval queue', 'talenttrack' ),
            'description'  => __( 'Approve & promote ideas straight to GitHub, or reject with a note.', 'talenttrack' ),
            'icon'         => 'approval',
            'color'        => '#1d7874',
            'cap'          => 'tt_promote_idea',
        ]);
        TileRegistry::register([
            'module_class' => self::M_DEVELOPMENT,
            'view_slug'    => 'dev-tracks',
            'entity'       => 'dev_ideas',
            'group'        => $dev_group,
            'kind'         => 'setup',
            'order'        => 40,
            'label'        => __( 'Development tracks', 'talenttrack' ),
            'description'  => __( 'Group ideas into a player-development roadmap.', 'talenttrack' ),
            'icon'         => 'track',
            'color'        => '#2271b1',
            'cap'          => 'tt_view_dev_board',
        ]);

        // ── Administration group ──
        $admin_group = __( 'Administration', 'talenttrack' );
        TileRegistry::register([
            'module_class' => self::M_CONFIG,
            'view_slug'    => 'configuration',
            'entity'       => 'settings',
            'group'        => $admin_group,
            'kind'         => 'setup',
            'order'        => 10,
            'label'        => __( 'Configuration', 'talenttrack' ),
            'description'  => __( 'Lookups, branding, authorization, system settings.', 'talenttrack' ),
            'icon'         => 'settings',
            'color'        => '#555',
            'cap'          => 'tt_access_frontend_admin',
            'desktop_preferred' => true,
            // #0069 — HoD's job is player development, not configuration.
            // They get the cap (it cascades through allCapsTrue) but the
            // tile shouldn't sit in their day-to-day surface.
            'hide_for_personas' => [ 'head_of_development' ],
        ]);
        TileRegistry::register([
            'module_class' => self::M_CONFIG,
            'view_slug'    => 'migrations',
            'entity'       => 'migrations',
            'group'        => $admin_group,
            'kind'         => 'setup',
            'order'        => 20,
            'label'        => __( 'Migrations', 'talenttrack' ),
            'description'  => __( 'Database migration status (read-only).', 'talenttrack' ),
            'icon'         => 'migrations',
            'color'        => '#555',
            'cap'          => 'tt_access_frontend_admin',
            'desktop_preferred' => true,
            'hide_for_personas' => [ 'head_of_development' ],
        ]);
        TileRegistry::register([
            'module_class' => self::M_WIZARDS,
            'view_slug'    => 'wizards-admin',
            'entity'       => 'setup_wizard',
            'group'        => $admin_group,
            'kind'         => 'setup',
            'order'        => 25,
            'label'        => __( 'Wizards', 'talenttrack' ),
            'description'  => __( 'Toggle the create-record wizards on or off, and see completion analytics.', 'talenttrack' ),
            'icon'         => 'lightbulb',
            'color'        => '#5b6e75',
            'cap'          => 'tt_edit_settings',
            'desktop_preferred' => true,
        ]);
        // Audit log is infra (no module owner). Gates on the specific
        // tt_view_audit_log sub-cap so the matrix entity audit_log
        // controls visibility 1:1 — clearing R for a persona on
        // audit_log in the matrix actually hides the tile.
        TileRegistry::register([
            'module_class' => null,
            'view_slug'    => 'audit-log',
            'entity'       => 'audit_log',
            'group'        => $admin_group,
            'kind'         => 'setup',
            'order'        => 30,
            'label'        => __( 'Audit log', 'talenttrack' ),
            'description'  => __( 'Who changed what, when. Filterable, paginated.', 'talenttrack' ),
            'icon'         => 'audit-log',
            'color'        => '#5b6e75',
            'cap'          => 'tt_view_audit_log',
            'desktop_preferred' => true,
        ]);
        TileRegistry::register([
            'module_class' => self::M_INVITATIONS,
            'view_slug'    => 'invitations-config',
            'entity'       => 'invitations_config',
            'group'        => $admin_group,
            'kind'         => 'setup',
            'order'        => 40,
            'label'        => __( 'Invitations', 'talenttrack' ),
            'description'  => __( 'Pending invites + WhatsApp message templates.', 'talenttrack' ),
            'icon'         => 'invitation',
            'color'        => '#5b6e75',
            'cap'          => 'tt_manage_invite_messages',
        ]);
        // "Open wp-admin" is a portal that points at the WP admin
        // dashboard rather than a tt_view route. v3.88.0 wired it to a
        // matrix entity; #0079 splits that entity from the broader
        // `frontend_admin` so granting wp-admin reach is independent of
        // the cap that gates Configuration / Migrations / KPIs tiles.
        TileRegistry::register([
            'module_class' => null,
            'view_slug'    => '',
            'slug'         => 'open-wp-admin',
            'entity'       => 'wp_admin_portal',
            'group'        => $admin_group,
            'kind'         => 'setup',
            'order'        => 100,
            'label'        => __( 'Open wp-admin', 'talenttrack' ),
            'description'  => __( 'Drop into the full WordPress admin dashboard.', 'talenttrack' ),
            'icon'         => 'external-link',
            'color'        => '#888',
            'url_callback' => static function (): string {
                return admin_url( 'admin.php?page=talenttrack' );
            },
        ]);

        // ── Staff development group (#0039) — visible to any user
        // who can view the staff-development surface. The HoD overview
        // tile is gated separately on the expiry-roll-up cap. ──
        $staff_dev_group = __( 'Staff development', 'talenttrack' );
        TileRegistry::register([
            'module_class' => self::M_STAFF_DEV,
            'view_slug'    => 'my-staff-pdp',
            'entity'       => 'my_staff_pdp',
            'group'        => $staff_dev_group,
            'kind'         => 'work',
            'order'        => 10,
            // v3.92.0 — was "My PDP" which collided with the player's
            // Me-group "My PDP" tile label. Coaches sat next to both on
            // their dashboard with no way to tell them apart. The label
            // is the only difference between the two; renamed here so
            // coaches see "My staff PDP" and players see "My PDP".
            'label'        => __( 'My staff PDP', 'talenttrack' ),
            'description'  => __( 'Your personal development plan.', 'talenttrack' ),
            // v3.72.5 — was `'pdp'` but no pdp.svg exists; tile rendered
            // without an icon. `methodology` fits the staff-development
            // theme and is distinct from regular PDP's `goals` icon.
            'icon'         => 'methodology',
            'color'        => '#0b3d2e',
            'cap'          => 'tt_view_staff_development',
        ]);
        TileRegistry::register([
            'module_class' => self::M_STAFF_DEV,
            'view_slug'    => 'my-staff-goals',
            'entity'       => 'my_staff_goals',
            'group'        => $staff_dev_group,
            'kind'         => 'work',
            'order'        => 20,
            'label'        => __( 'My staff goals', 'talenttrack' ),
            'description'  => __( 'Personal development goals — optionally linked to a certification.', 'talenttrack' ),
            'icon'         => 'goals',
            'color'        => '#1d7874',
            'cap'          => 'tt_view_staff_development',
        ]);
        TileRegistry::register([
            'module_class' => self::M_STAFF_DEV,
            'view_slug'    => 'my-staff-evaluations',
            'entity'       => 'my_staff_evaluations',
            'group'        => $staff_dev_group,
            'kind'         => 'work',
            'order'        => 30,
            'label'        => __( 'My staff evaluations', 'talenttrack' ),
            'description'  => __( 'Self and top-down staff evaluations.', 'talenttrack' ),
            'icon'         => 'evaluations',
            'color'        => '#7c3a9e',
            'cap'          => 'tt_view_staff_development',
        ]);
        TileRegistry::register([
            'module_class' => self::M_STAFF_DEV,
            'view_slug'    => 'my-staff-certifications',
            'entity'       => 'my_staff_certifications',
            'group'        => $staff_dev_group,
            'kind'         => 'work',
            'order'        => 40,
            'label'        => __( 'My certifications', 'talenttrack' ),
            'description'  => __( 'Coaching badges, first aid, GDPR and more — with expiry tracking.', 'talenttrack' ),
            'icon'         => 'rate-card',
            'color'        => '#c9962a',
            'cap'          => 'tt_view_staff_development',
        ]);
        TileRegistry::register([
            'module_class' => self::M_STAFF_DEV,
            'view_slug'    => 'staff-overview',
            'entity'       => 'staff_overview',
            'group'        => $staff_dev_group,
            'kind'         => 'admin',
            'order'        => 50,
            'label'        => __( 'Staff overview', 'talenttrack' ),
            'description'  => __( 'Open goals, overdue reviews, expiring certifications across the academy.', 'talenttrack' ),
            'icon'         => 'reports',
            'color'        => '#2271b1',
            'cap'          => 'tt_view_staff_certifications_expiry',
        ]);
    }

    /* ───────────────────────────────────────────────────────────
       wp-admin SUBMENU — TalentTrack sidebar entries.
       ─────────────────────────────────────────────────────────── */

    private static function registerAdminSubmenu(): void {
        $show_legacy = \TT\Shared\Admin\Menu::shouldShowLegacyMenus();
        $show_welcome = \TT\Shared\Admin\Menu::shouldShowWelcome();
        $parent       = $show_legacy ? 'talenttrack' : null;

        // #0063 — drop the explicit "Dashboard" submenu mirror.
        // WordPress automatically clones the top-level entry as the
        // first submenu when any submenu attaches; the explicit
        // duplicate registered here produced two visually identical
        // sidebar rows ("TalentTrack" and "Dashboard") that pointed
        // to the same callback. Removed; the auto-clone is renamed
        // to "Dashboard" by the menu_order tweak in
        // `Menu::register()` so the user-visible label is correct
        // without the dupe.

        // Welcome wizard — owner is Onboarding. Visible only while wizard
        // hasn't been dismissed; otherwise routed via parent=null per
        // #0038 so direct URLs still work.
        AdminMenuRegistry::register([
            'module_class' => self::M_ONBOARDING,
            'parent'       => $show_welcome ? 'talenttrack' : null,
            'title'        => __( 'Welcome', 'talenttrack' ),
            'cap'          => \TT\Modules\Onboarding\Admin\OnboardingPage::CAP,
            'slug'         => \TT\Modules\Onboarding\Admin\OnboardingPage::SLUG,
            'callback'     => [ \TT\Modules\Onboarding\Admin\OnboardingPage::class, 'render' ],
        ]);

        // License — Account submenu, always under top-level for billing
        // visibility. Cap relaxed to `read` (v3.90.0) because the page
        // now hosts a Plan & restrictions tab that's open to everyone.
        // The operator-only controls inside the Account tab still
        // self-gate on `tt_edit_settings`.
        AdminMenuRegistry::register([
            'module_class' => self::M_LICENSE,
            'parent'       => 'talenttrack',
            'title'        => __( 'Account', 'talenttrack' ),
            'cap'          => 'read',
            'slug'         => \TT\Modules\License\Admin\AccountPage::SLUG,
            'callback'     => [ \TT\Modules\License\Admin\AccountPage::class, 'render' ],
        ]);

        // v3.90.0 — top-level "TalentTrack" click now lands on Account.
        // Keep the legacy stats-and-tiles dashboard reachable via its
        // own submenu so admins can still get the at-a-glance view.
        AdminMenuRegistry::register([
            'module_class' => null,
            'parent'       => 'talenttrack',
            'title'        => __( 'Dashboard', 'talenttrack' ),
            'cap'          => 'read',
            'slug'         => 'tt-dashboard',
            'callback'     => [ \TT\Shared\Admin\Menu::class, 'renderDashboardTiles' ],
        ]);

        // v3.70.1 hotfix — Demo data submenu was registered only in
        // DemoDataPage::registerMenu (its own admin_menu callback),
        // which loses to AdminMenuRegistry's parent-slug bookkeeping
        // when the top-level menu gets reordered. Register it
        // declaratively here so it survives renames + module gating.
        AdminMenuRegistry::register([
            'module_class' => 'TT\\Modules\\DemoData\\DemoDataModule',
            'parent'       => 'talenttrack',
            'title'        => __( 'Demo data', 'talenttrack' ),
            'cap'          => 'manage_options',
            'slug'         => 'tt-demo-data',
            'callback'     => [ \TT\Modules\DemoData\Admin\DemoDataPage::class, 'render' ],
        ]);

        // ── People group ──
        if ( $show_legacy ) {
            AdminMenuRegistry::registerSeparator( 'tt-sep-people', __( 'People', 'talenttrack' ), 'tt_view_players', 'people' );
        }
        AdminMenuRegistry::register([
            'module_class' => self::M_TEAMS,
            'parent'       => $parent,
            'group'        => 'people',
            'title'        => __( 'Teams', 'talenttrack' ),
            'cap'          => 'tt_view_teams',
            'slug'         => 'tt-teams',
            'callback'     => [ \TT\Modules\Teams\Admin\TeamsPage::class, 'render_page' ],
        ]);
        AdminMenuRegistry::register([
            'module_class' => self::M_PLAYERS,
            'parent'       => $parent,
            'group'        => 'people',
            'title'        => __( 'Players', 'talenttrack' ),
            'cap'          => 'tt_view_players',
            'slug'         => 'tt-players',
            'callback'     => [ \TT\Modules\Players\Admin\PlayersPage::class, 'render_page' ],
        ]);
        AdminMenuRegistry::register([
            'module_class' => self::M_PEOPLE,
            'parent'       => $parent,
            'group'        => 'people',
            'title'        => __( 'People', 'talenttrack' ),
            'cap'          => 'tt_view_people',
            'slug'         => 'tt-people',
            'callback'     => [ \TT\Modules\People\Admin\PeoplePage::class, 'render' ],
        ]);

        // ── Performance group ──
        if ( $show_legacy ) {
            AdminMenuRegistry::registerSeparator( 'tt-sep-performance', __( 'Performance', 'talenttrack' ), 'tt_view_evaluations', 'performance' );
        }
        AdminMenuRegistry::register([
            'module_class' => self::M_EVALUATIONS,
            'parent'       => $parent,
            'group'        => 'performance',
            'title'        => __( 'Evaluations', 'talenttrack' ),
            'cap'          => 'tt_view_evaluations',
            'slug'         => 'tt-evaluations',
            'callback'     => [ \TT\Modules\Evaluations\Admin\EvaluationsPage::class, 'render_page' ],
        ]);
        AdminMenuRegistry::register([
            'module_class' => self::M_ACTIVITIES,
            'parent'       => $parent,
            'group'        => 'performance',
            'title'        => __( 'Activities', 'talenttrack' ),
            'cap'          => 'tt_view_activities',
            'slug'         => 'tt-activities',
            'callback'     => [ \TT\Modules\Activities\Admin\ActivitiesPage::class, 'render_page' ],
        ]);
        AdminMenuRegistry::register([
            'module_class' => self::M_GOALS,
            'parent'       => $parent,
            'group'        => 'performance',
            'title'        => __( 'Goals', 'talenttrack' ),
            'cap'          => 'tt_view_goals',
            'slug'         => 'tt-goals',
            'callback'     => [ \TT\Modules\Goals\Admin\GoalsPage::class, 'render_page' ],
        ]);
        AdminMenuRegistry::register([
            'module_class' => self::M_PDP,
            'parent'       => $parent,
            'group'        => 'performance',
            'title'        => __( 'Seasons', 'talenttrack' ),
            'cap'          => 'tt_edit_seasons',
            'slug'         => 'tt-seasons',
            'callback'     => [ \TT\Modules\Pdp\Admin\SeasonsPage::class, 'render' ],
        ]);

        // Methodology + its hidden edit subpages + football actions.
        AdminMenuRegistry::register([
            'module_class' => self::M_METHODOLOGY,
            'parent'       => $parent,
            'group'        => 'performance',
            'title'        => __( 'Methodology', 'talenttrack' ),
            'cap'          => \TT\Modules\Methodology\Admin\MethodologyPage::CAP_VIEW,
            'slug'         => \TT\Modules\Methodology\Admin\MethodologyPage::SLUG,
            'callback'     => [ \TT\Modules\Methodology\Admin\MethodologyPage::class, 'render' ],
        ]);
        foreach ( [
            [ \TT\Modules\Methodology\Admin\PrincipleEditPage::class,         __( 'Edit principle',         'talenttrack' ) ],
            [ \TT\Modules\Methodology\Admin\PositionEditPage::class,          __( 'Edit position',          'talenttrack' ) ],
            [ \TT\Modules\Methodology\Admin\SetPieceEditPage::class,          __( 'Edit set piece',         'talenttrack' ) ],
            [ \TT\Modules\Methodology\Admin\VisionEditPage::class,            __( 'Edit vision',            'talenttrack' ) ],
            [ \TT\Modules\Methodology\Admin\FrameworkPrimerEditPage::class,   __( 'Edit framework',         'talenttrack' ) ],
            [ \TT\Modules\Methodology\Admin\PhaseEditPage::class,             __( 'Edit phase',             'talenttrack' ) ],
            [ \TT\Modules\Methodology\Admin\LearningGoalEditPage::class,      __( 'Edit learning goal',     'talenttrack' ) ],
            [ \TT\Modules\Methodology\Admin\InfluenceFactorEditPage::class,   __( 'Edit influence factor',  'talenttrack' ) ],
        ] as [ $page_class, $title ] ) {
            AdminMenuRegistry::register([
                'module_class' => self::M_METHODOLOGY,
                'parent'       => null,
                'title'        => $title,
                'cap'          => $page_class::CAP,
                'slug'         => $page_class::SLUG,
                'callback'     => [ $page_class, 'render' ],
            ]);
        }
        AdminMenuRegistry::register([
            'module_class' => self::M_METHODOLOGY,
            'parent'       => $parent,
            'group'        => 'performance',
            'title'        => __( 'Voetbalhandelingen', 'talenttrack' ),
            'cap'          => \TT\Modules\Methodology\Admin\FootballActionsPage::CAP_VIEW,
            'slug'         => \TT\Modules\Methodology\Admin\FootballActionsPage::SLUG,
            'callback'     => [ \TT\Modules\Methodology\Admin\FootballActionsPage::class, 'render' ],
        ]);
        AdminMenuRegistry::register([
            'module_class' => self::M_METHODOLOGY,
            'parent'       => null,
            'title'        => __( 'Edit football action', 'talenttrack' ),
            'cap'          => \TT\Modules\Methodology\Admin\FootballActionEditPage::CAP,
            'slug'         => \TT\Modules\Methodology\Admin\FootballActionEditPage::SLUG,
            'callback'     => [ \TT\Modules\Methodology\Admin\FootballActionEditPage::class, 'render' ],
        ]);

        // ── Analytics group ──
        if ( $show_legacy ) {
            AdminMenuRegistry::registerSeparator( 'tt-sep-analytics', __( 'Analytics', 'talenttrack' ), 'tt_view_reports', 'analytics' );
        }
        AdminMenuRegistry::register([
            'module_class' => self::M_REPORTS,
            'parent'       => $parent,
            'group'        => 'analytics',
            'title'        => __( 'Reports', 'talenttrack' ),
            'cap'          => 'tt_view_reports',
            'slug'         => 'tt-reports',
            'callback'     => [ \TT\Modules\Reports\Admin\ReportsPage::class, 'render_page' ],
        ]);
        AdminMenuRegistry::register([
            'module_class' => self::M_STATS,
            'parent'       => $parent,
            'group'        => 'analytics',
            'title'        => __( 'Player Rate Cards', 'talenttrack' ),
            'cap'          => 'tt_view_reports',
            'slug'         => 'tt-rate-cards',
            'callback'     => [ \TT\Modules\Stats\Admin\PlayerRateCardsPage::class, 'render' ],
        ]);
        AdminMenuRegistry::register([
            'module_class' => self::M_STATS,
            'parent'       => $parent,
            'group'        => 'analytics',
            'title'        => __( 'Player Comparison', 'talenttrack' ),
            'cap'          => 'tt_view_reports',
            'slug'         => 'tt-compare',
            'callback'     => [ \TT\Modules\Stats\Admin\PlayerComparisonPage::class, 'render' ],
        ]);
        AdminMenuRegistry::register([
            'module_class' => self::M_STATS,
            'parent'       => $parent,
            'group'        => 'analytics',
            'title'        => __( 'Usage Statistics', 'talenttrack' ),
            'cap'          => 'tt_view_settings',
            'slug'         => 'tt-usage-stats',
            'callback'     => [ \TT\Modules\Stats\Admin\UsageStatsPage::class, 'render' ],
        ]);
        AdminMenuRegistry::register([
            'module_class' => self::M_STATS,
            'parent'       => null,
            'title'        => __( 'Usage Detail', 'talenttrack' ),
            'cap'          => 'tt_view_settings',
            'slug'         => 'tt-usage-stats-details',
            'callback'     => [ \TT\Modules\Stats\Admin\UsageStatsDetailsPage::class, 'render' ],
        ]);

        // ── Configuration group ──
        if ( $show_legacy ) {
            AdminMenuRegistry::registerSeparator( 'tt-sep-config', __( 'Configuration', 'talenttrack' ), 'tt_view_settings', 'config' );
        }
        AdminMenuRegistry::register([
            'module_class' => self::M_CONFIG,
            'parent'       => $parent,
            'group'        => 'config',
            'title'        => __( 'Configuration', 'talenttrack' ),
            'cap'          => 'tt_view_settings',
            'slug'         => 'tt-config',
            'callback'     => [ \TT\Modules\Configuration\Admin\ConfigurationPage::class, 'render_page' ],
        ]);
        AdminMenuRegistry::register([
            'module_class' => self::M_CONFIG,
            'parent'       => $parent,
            'group'        => 'config',
            'title'        => __( 'Custom Fields', 'talenttrack' ),
            'cap'          => 'tt_view_custom_fields',
            'slug'         => 'tt-custom-fields',
            'callback'     => [ \TT\Modules\Configuration\Admin\CustomFieldsPage::class, 'render' ],
        ]);
        AdminMenuRegistry::register([
            'module_class' => self::M_EVALUATIONS,
            'parent'       => $parent,
            'group'        => 'config',
            'title'        => __( 'Evaluation Categories', 'talenttrack' ),
            'cap'          => 'tt_view_evaluation_categories',
            'slug'         => 'tt-eval-categories',
            'callback'     => [ \TT\Modules\Evaluations\Admin\EvalCategoriesPage::class, 'render' ],
        ]);
        AdminMenuRegistry::register([
            'module_class' => self::M_EVALUATIONS,
            'parent'       => $parent,
            'group'        => 'config',
            'title'        => __( 'Category Weights', 'talenttrack' ),
            'cap'          => 'tt_view_category_weights',
            'slug'         => 'tt-category-weights',
            'callback'     => [ \TT\Modules\Evaluations\Admin\CategoryWeightsPage::class, 'render' ],
        ]);
        // v3.90.0 — Spond + Migrations were registered via direct
        // `add_submenu_page` calls at admin_menu priorities 30 / 20,
        // which left them visually trailing the Access Control group.
        // Folding them into the Configuration group puts each entry
        // where it conceptually belongs: an integration setting and
        // a database admin tool.
        AdminMenuRegistry::register([
            'module_class' => self::M_SPOND,
            'parent'       => $parent,
            'group'        => 'config',
            'title'        => __( 'Spond integration', 'talenttrack' ),
            'label'        => __( 'Spond', 'talenttrack' ),
            'cap'          => 'tt_edit_teams',
            'slug'         => \TT\Modules\Spond\Admin\SpondOverviewPage::SLUG,
            'callback'     => [ \TT\Modules\Spond\Admin\SpondOverviewPage::class, 'render' ],
        ]);
        AdminMenuRegistry::register([
            'module_class' => self::M_CONFIG,
            'parent'       => $parent,
            'group'        => 'config',
            'title'        => __( 'Database Migrations', 'talenttrack' ),
            'label'        => __( 'Migrations', 'talenttrack' ),
            'cap'          => 'tt_view_migrations',
            'slug'         => 'tt-migrations',
            'callback'     => [ \TT\Modules\Configuration\Admin\MigrationsPage::class, 'render_page' ],
        ]);

        // ── Access Control group (Authorization is always-on). ──
        if ( $show_legacy ) {
            AdminMenuRegistry::registerSeparator( 'tt-sep-access', __( 'Access Control', 'talenttrack' ), 'tt_view_settings', 'access' );
        }
        AdminMenuRegistry::register([
            'module_class' => self::M_AUTHORIZATION,
            'parent'       => $parent,
            'group'        => 'access',
            'title'        => __( 'Roles & rights', 'talenttrack' ),
            'cap'          => 'tt_view_settings',
            'slug'         => 'tt-roles',
            'callback'     => [ \TT\Modules\Authorization\Admin\RolesPage::class, 'render' ],
        ]);
        AdminMenuRegistry::register([
            'module_class' => self::M_AUTHORIZATION,
            'parent'       => $parent,
            'group'        => 'access',
            'title'        => __( 'Functional Roles', 'talenttrack' ),
            'cap'          => 'tt_view_functional_roles',
            'slug'         => 'tt-functional-roles',
            'callback'     => [ \TT\Modules\Authorization\Admin\FunctionalRolesPage::class, 'render' ],
        ]);
        AdminMenuRegistry::register([
            'module_class' => self::M_AUTHORIZATION,
            'parent'       => $parent,
            'group'        => 'access',
            'title'        => __( 'Permission Debug', 'talenttrack' ),
            'cap'          => 'tt_view_settings',
            'slug'         => 'tt-roles-debug',
            'callback'     => [ \TT\Modules\Authorization\Admin\DebugPage::class, 'render' ],
        ]);
        AdminMenuRegistry::register([
            'module_class' => self::M_AUTHORIZATION,
            'parent'       => $parent,
            'group'        => 'access',
            'title'        => __( 'Authorization Matrix', 'talenttrack' ),
            'cap'          => 'administrator',
            'slug'         => 'tt-matrix',
            'callback'     => [ \TT\Modules\Authorization\Admin\MatrixPage::class, 'render' ],
        ]);
        AdminMenuRegistry::register([
            'module_class' => self::M_AUTHORIZATION,
            'parent'       => $parent,
            'group'        => 'access',
            'title'        => __( 'Migration preview', 'talenttrack' ),
            'cap'          => 'administrator',
            'slug'         => 'tt-matrix-preview',
            'callback'     => [ \TT\Modules\Authorization\Admin\PreviewPage::class, 'render' ],
        ]);
        AdminMenuRegistry::register([
            'module_class' => self::M_AUTHORIZATION,
            'parent'       => $parent,
            'group'        => 'access',
            'title'        => __( 'Compare users', 'talenttrack' ),
            'cap'          => 'administrator',
            'slug'         => 'tt-user-compare',
            'callback'     => [ \TT\Modules\Authorization\Admin\UserComparisonPage::class, 'render' ],
        ]);
        AdminMenuRegistry::register([
            'module_class' => self::M_AUTHORIZATION,
            'parent'       => $parent,
            'group'        => 'access',
            'title'        => __( 'Modules', 'talenttrack' ),
            'cap'          => 'administrator',
            'slug'         => 'tt-modules',
            'callback'     => [ \TT\Modules\Authorization\Admin\ModulesPage::class, 'render' ],
        ]);

        // ── Help group ──
        // v3.90.0 — Help & Docs used to register without a group, which
        // meant it visually trailed the Access Control entries when
        // the legacy menu was on, looking like an Authorization item.
        // Its own group separator (when legacy menu is on) keeps that
        // confusion out.
        if ( $show_legacy ) {
            AdminMenuRegistry::registerSeparator( 'tt-sep-help', __( 'Help', 'talenttrack' ), 'read', 'help' );
        }
        AdminMenuRegistry::register([
            'module_class' => self::M_DOCUMENTATION,
            'parent'       => 'talenttrack',
            'group'        => 'help',
            'title'        => __( 'Help & Docs', 'talenttrack' ),
            'cap'          => 'read',
            'slug'         => 'tt-docs',
            'callback'     => [ \TT\Modules\Documentation\Admin\DocumentationPage::class, 'render_page' ],
        ]);
    }

    /* ───────────────────────────────────────────────────────────
       wp-admin DASHBOARD TILES — quick-link cards on the
       `?page=tt-dashboard` tile view. Stat cards stay in
       Menu::renderDashboardTiles() since they are bound to specific
       count + delta queries.
       ─────────────────────────────────────────────────────────── */

    private static function registerAdminDashboardTiles(): void {
        $admin = static fn ( string $slug ): string => admin_url( "admin.php?page={$slug}" );

        $people = __( 'People', 'talenttrack' );
        AdminMenuRegistry::registerDashboardTile([
            'module_class' => self::M_TEAMS,
            'group'        => $people, 'group_accent' => '#1d7874', 'group_order' => 10, 'order' => 10,
            'label' => __( 'Teams', 'talenttrack' ),
            'desc'  => __( 'Manage teams, staff assignments, and age groups.', 'talenttrack' ),
            'icon'  => 'teams',
            'url'   => $admin( 'tt-teams' ),
            'cap'   => 'tt_view_teams',
        ]);
        AdminMenuRegistry::registerDashboardTile([
            'module_class' => self::M_PLAYERS,
            'group'        => $people, 'group_accent' => '#1d7874', 'group_order' => 10, 'order' => 20,
            'label' => __( 'Players', 'talenttrack' ),
            'desc'  => __( 'Player roster, positions, photos, guardian info.', 'talenttrack' ),
            'icon'  => 'players',
            'url'   => $admin( 'tt-players' ),
            'cap'   => 'tt_view_players',
        ]);
        AdminMenuRegistry::registerDashboardTile([
            'module_class' => self::M_PEOPLE,
            'group'        => $people, 'group_accent' => '#1d7874', 'group_order' => 10, 'order' => 30,
            'label' => __( 'People', 'talenttrack' ),
            'desc'  => __( 'Coaches, assistants, medical staff, volunteers.', 'talenttrack' ),
            'icon'  => 'people',
            'url'   => $admin( 'tt-people' ),
            'cap'   => 'tt_view_people',
        ]);

        $performance = __( 'Performance', 'talenttrack' );
        AdminMenuRegistry::registerDashboardTile([
            'module_class' => self::M_EVALUATIONS,
            'group'        => $performance, 'group_accent' => '#7c3a9e', 'group_order' => 20, 'order' => 10,
            'label' => __( 'Evaluations', 'talenttrack' ),
            'desc'  => __( 'Rate players across training and match activities.', 'talenttrack' ),
            'icon'  => 'evaluations',
            'url'   => $admin( 'tt-evaluations' ),
            'cap'   => 'tt_view_evaluations',
        ]);
        AdminMenuRegistry::registerDashboardTile([
            'module_class' => self::M_ACTIVITIES,
            'group'        => $performance, 'group_accent' => '#7c3a9e', 'group_order' => 20, 'order' => 20,
            'label' => __( 'Activities', 'talenttrack' ),
            'desc'  => __( 'Record training activities and attendance.', 'talenttrack' ),
            'icon'  => 'activities',
            'url'   => $admin( 'tt-activities' ),
            'cap'   => 'tt_view_activities',
        ]);
        AdminMenuRegistry::registerDashboardTile([
            'module_class' => self::M_GOALS,
            'group'        => $performance, 'group_accent' => '#7c3a9e', 'group_order' => 20, 'order' => 30,
            'label' => __( 'Goals', 'talenttrack' ),
            'desc'  => __( 'Set and track development goals per player.', 'talenttrack' ),
            'icon'  => 'goals',
            'url'   => $admin( 'tt-goals' ),
            'cap'   => 'tt_view_goals',
        ]);

        $analytics = __( 'Analytics', 'talenttrack' );
        AdminMenuRegistry::registerDashboardTile([
            'module_class' => self::M_REPORTS,
            'group'        => $analytics, 'group_accent' => '#2271b1', 'group_order' => 30, 'order' => 10,
            'label' => __( 'Reports', 'talenttrack' ),
            'desc'  => __( 'Saved report presets and exports.', 'talenttrack' ),
            'icon'  => 'reports',
            'url'   => $admin( 'tt-reports' ),
            'cap'   => 'tt_view_reports',
        ]);
        AdminMenuRegistry::registerDashboardTile([
            'module_class' => self::M_STATS,
            'group'        => $analytics, 'group_accent' => '#2271b1', 'group_order' => 30, 'order' => 20,
            'label' => __( 'Player Rate Cards', 'talenttrack' ),
            'desc'  => __( 'Per-player rate cards with trends and charts.', 'talenttrack' ),
            'icon'  => 'rate-card',
            'url'   => $admin( 'tt-rate-cards' ),
            'cap'   => 'tt_view_reports',
        ]);
        AdminMenuRegistry::registerDashboardTile([
            'module_class' => self::M_STATS,
            'group'        => $analytics, 'group_accent' => '#2271b1', 'group_order' => 30, 'order' => 30,
            'label' => __( 'Player Comparison', 'talenttrack' ),
            'desc'  => __( 'Side-by-side comparison of up to 4 players.', 'talenttrack' ),
            'icon'  => 'compare',
            'url'   => $admin( 'tt-compare' ),
            'cap'   => 'tt_view_reports',
        ]);
        AdminMenuRegistry::registerDashboardTile([
            'module_class' => self::M_STATS,
            'group'        => $analytics, 'group_accent' => '#2271b1', 'group_order' => 30, 'order' => 40,
            'label' => __( 'Usage Statistics', 'talenttrack' ),
            'desc'  => __( 'Logins, active users, most-visited pages.', 'talenttrack' ),
            'icon'  => 'usage-stats',
            'url'   => $admin( 'tt-usage-stats' ),
            'cap'   => 'tt_view_settings',
        ]);

        $configuration = __( 'Configuration', 'talenttrack' );
        AdminMenuRegistry::registerDashboardTile([
            'module_class' => self::M_CONFIG,
            'group'        => $configuration, 'group_accent' => '#555', 'group_order' => 40, 'order' => 10,
            'label' => __( 'Configuration', 'talenttrack' ),
            'desc'  => __( 'Academy name, logo, rating scale, colors.', 'talenttrack' ),
            'icon'  => 'settings',
            'url'   => $admin( 'tt-config' ),
            'cap'   => 'tt_view_settings',
        ]);
        AdminMenuRegistry::registerDashboardTile([
            'module_class' => self::M_CONFIG,
            'group'        => $configuration, 'group_accent' => '#555', 'group_order' => 40, 'order' => 20,
            'label' => __( 'Custom Fields', 'talenttrack' ),
            'desc'  => __( 'Add club-specific fields to any entity.', 'talenttrack' ),
            'icon'  => 'custom-fields',
            'url'   => $admin( 'tt-custom-fields' ),
            'cap'   => 'tt_view_custom_fields',
        ]);
        AdminMenuRegistry::registerDashboardTile([
            'module_class' => self::M_EVALUATIONS,
            'group'        => $configuration, 'group_accent' => '#555', 'group_order' => 40, 'order' => 30,
            'label' => __( 'Evaluation Categories', 'talenttrack' ),
            'desc'  => __( 'Main + subcategories used in evaluations.', 'talenttrack' ),
            'icon'  => 'categories',
            'url'   => $admin( 'tt-eval-categories' ),
            'cap'   => 'tt_view_evaluation_categories',
        ]);
        AdminMenuRegistry::registerDashboardTile([
            'module_class' => self::M_EVALUATIONS,
            'group'        => $configuration, 'group_accent' => '#555', 'group_order' => 40, 'order' => 40,
            'label' => __( 'Category Weights', 'talenttrack' ),
            'desc'  => __( 'Per-age-group weighting for overall ratings.', 'talenttrack' ),
            'icon'  => 'weights',
            'url'   => $admin( 'tt-category-weights' ),
            'cap'   => 'tt_view_category_weights',
        ]);

        $access = __( 'Access Control', 'talenttrack' );
        AdminMenuRegistry::registerDashboardTile([
            'module_class' => self::M_AUTHORIZATION,
            'group'        => $access, 'group_accent' => '#b32d2e', 'group_order' => 50, 'order' => 10,
            'label' => __( 'Roles & Permissions', 'talenttrack' ),
            'desc'  => __( 'Who can do what — grant or revoke TalentTrack roles per user.', 'talenttrack' ),
            'icon'  => 'roles',
            'url'   => $admin( 'tt-roles' ),
            'cap'   => 'tt_view_settings',
        ]);
        AdminMenuRegistry::registerDashboardTile([
            'module_class' => self::M_AUTHORIZATION,
            'group'        => $access, 'group_accent' => '#b32d2e', 'group_order' => 50, 'order' => 20,
            'label' => __( 'Functional Roles', 'talenttrack' ),
            'desc'  => __( 'Head coach, assistant, physio — map club roles to permissions.', 'talenttrack' ),
            'icon'  => 'functional-roles',
            'url'   => $admin( 'tt-functional-roles' ),
            'cap'   => 'tt_view_functional_roles',
        ]);
        AdminMenuRegistry::registerDashboardTile([
            'module_class' => self::M_AUTHORIZATION,
            'group'        => $access, 'group_accent' => '#b32d2e', 'group_order' => 50, 'order' => 30,
            'label' => __( 'Permission Debug', 'talenttrack' ),
            'desc'  => __( 'Inspect any user\'s effective permissions.', 'talenttrack' ),
            'icon'  => 'permission-debug',
            'url'   => $admin( 'tt-roles-debug' ),
            'cap'   => 'tt_view_settings',
        ]);

        $help = __( 'Help', 'talenttrack' );
        AdminMenuRegistry::registerDashboardTile([
            'module_class' => self::M_DOCUMENTATION,
            'group'        => $help, 'group_accent' => '#888', 'group_order' => 60, 'order' => 10,
            'label' => __( 'Help & Docs', 'talenttrack' ),
            'desc'  => __( 'How to use TalentTrack.', 'talenttrack' ),
            'icon'  => 'docs',
            'url'   => $admin( 'tt-docs' ),
            'cap'   => 'read',
        ]);
    }
}
