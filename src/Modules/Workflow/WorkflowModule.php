<?php
namespace TT\Modules\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\Workflow\Diagnostics\CronHealthNotice;
use TT\Modules\Workflow\Dispatchers\CronDispatcher;
use TT\Modules\Workflow\Dispatchers\EventDispatcher;
use TT\Modules\Workflow\Frontend\NotificationBell;
use TT\Modules\Workflow\Notifications\TaskMailer;
use TT\Modules\Workflow\Repositories\TasksRepository;
use TT\Modules\Workflow\Repositories\TemplateConfigRepository;
use TT\Modules\Workflow\Repositories\TriggersRepository;
use TT\Modules\Workflow\Templates\GoalApprovalTemplate;
use TT\Modules\Workflow\Templates\InviteToTestTrainingTemplate;
use TT\Modules\Workflow\Templates\LogProspectTemplate;
use TT\Modules\Workflow\Templates\PlayerSelfEvaluationTemplate;
use TT\Modules\Workflow\Templates\PostGameEvaluationTemplate;
use TT\Modules\Workflow\Templates\QuarterlyGoalSettingTemplate;
use TT\Modules\Workflow\Templates\QuarterlyHoDReviewTemplate;

/**
 * WorkflowModule (#0022 Sprint 1) — workflow & tasks engine.
 *
 * Owns:
 *   - Schema (migration 0021): tt_workflow_tasks, tt_workflow_triggers,
 *     tt_workflow_template_config + tt_players.parent_user_id column.
 *   - Engine primitives: TaskEngine, TaskContext, TaskStatus, TaskTemplate
 *     (abstract), TemplateRegistry.
 *   - Contracts under Contracts/: TaskTemplateInterface, FormInterface,
 *     AssigneeResolver.
 *   - Resolvers under Resolvers/: RoleBased, TeamHeadCoach,
 *     PlayerOrParent, Lambda.
 *   - Repositories under Repositories/: Tasks, Triggers, TemplateConfig.
 *   - Capabilities: tt_view_own_tasks, tt_view_tasks_dashboard,
 *     tt_configure_workflow_templates, tt_manage_workflow_templates.
 *
 * Sprint 1 ships the foundation only — no live cron / event dispatchers,
 * no inbox view, no admin pages. Templates and the surfaces that
 * consume them land in Sprints 2-5.
 */
class WorkflowModule implements ModuleInterface {

    private static ?TemplateRegistry $registry = null;
    private static ?TaskEngine $engine = null;

    public function getName(): string { return 'workflow'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        add_action( 'init', [ self::class, 'ensureCapabilities' ] );

        // Sprint 3 — register Phase 1 templates with the registry on
        // every request so dispatchers + the inbox + the detail view
        // can resolve them. Cheap (one map insert per template).
        add_action( 'init', [ self::class, 'registerShippedTemplates' ], 5 );

        // Sprint 2 — live wiring. Each component subscribes to its own
        // hooks; ordering between them doesn't matter here because the
        // hooks fire on different events.
        CronDispatcher::init();
        EventDispatcher::init();
        TaskMailer::init();

        if ( ! is_admin() ) {
            NotificationBell::init();
        } else {
            CronHealthNotice::init();
        }
    }

    /**
     * Register the Phase 1 templates. Called from boot() on init priority 5
     * so dispatchers (priority 20) see them. Idempotent — TemplateRegistry
     * keys by template->key() so repeated registration just overwrites.
     */
    public static function registerShippedTemplates(): void {
        $registry = self::registry();
        $registry->register( new PostGameEvaluationTemplate() );
        $registry->register( new PlayerSelfEvaluationTemplate() );
        $registry->register( new QuarterlyGoalSettingTemplate() );
        $registry->register( new GoalApprovalTemplate() );
        $registry->register( new QuarterlyHoDReviewTemplate() );
        // #0081 child 2 — onboarding-pipeline templates (links 1+2 of 5).
        // Links 3-5 (ConfirmTestTraining / RecordTestTrainingOutcome /
        // ReviewTrialGroupMembership) ship in PR 2b alongside the
        // public parent-confirmation REST endpoint.
        $registry->register( new LogProspectTemplate() );
        $registry->register( new InviteToTestTrainingTemplate() );
    }

    /**
     * Process-wide template registry. Concrete templates register
     * themselves here when their module loads (e.g. the Phase 1
     * templates ship in Sprint 3).
     */
    public static function registry(): TemplateRegistry {
        if ( self::$registry === null ) {
            self::$registry = new TemplateRegistry();
        }
        return self::$registry;
    }

    /**
     * Process-wide TaskEngine. Lazily wired against the shared registry
     * + fresh repository instances (repositories are stateless).
     */
    public static function engine(): TaskEngine {
        if ( self::$engine === null ) {
            self::$engine = new TaskEngine(
                self::registry(),
                new TasksRepository(),
                new TemplateConfigRepository()
            );
        }
        return self::$engine;
    }

    /**
     * Idempotent capability assignment. Cap names are reserved up front
     * so the four sprints that follow this one can land their views
     * without each one churning Activator.
     *
     *   tt_view_own_tasks                  — every TT role + administrator.
     *                                        Players + parents (#0032 will
     *                                        add `tt_parent`) land in the
     *                                        view-roles list once that role
     *                                        exists; Sprint 1 only grants
     *                                        the cap to roles that already
     *                                        exist on the install.
     *   tt_view_tasks_dashboard            — administrator + tt_head_dev
     *                                        + tt_club_admin.
     *   tt_configure_workflow_templates    — administrator + tt_club_admin.
     *   tt_manage_workflow_templates       — administrator only.
     *                                        ("Manage" = add/remove
     *                                        templates and triggers, not
     *                                        edit per-install config.)
     */
    public static function ensureCapabilities(): void {
        $own_view  = 'tt_view_own_tasks';
        $dash_view = 'tt_view_tasks_dashboard';
        $configure = 'tt_configure_workflow_templates';
        $manage    = 'tt_manage_workflow_templates';

        $own_view_roles  = [ 'administrator', 'tt_head_dev', 'tt_club_admin', 'tt_coach', 'tt_readonly_observer', 'tt_player', 'tt_parent' ];
        $dash_view_roles = [ 'administrator', 'tt_head_dev', 'tt_club_admin' ];
        $configure_roles = [ 'administrator', 'tt_club_admin' ];
        $manage_roles    = [ 'administrator' ];

        foreach ( $own_view_roles as $role_slug ) {
            $role = get_role( $role_slug );
            if ( $role && ! $role->has_cap( $own_view ) ) $role->add_cap( $own_view );
        }
        foreach ( $dash_view_roles as $role_slug ) {
            $role = get_role( $role_slug );
            if ( $role && ! $role->has_cap( $dash_view ) ) $role->add_cap( $dash_view );
        }
        foreach ( $configure_roles as $role_slug ) {
            $role = get_role( $role_slug );
            if ( $role && ! $role->has_cap( $configure ) ) $role->add_cap( $configure );
        }
        foreach ( $manage_roles as $role_slug ) {
            $role = get_role( $role_slug );
            if ( $role && ! $role->has_cap( $manage ) ) $role->add_cap( $manage );
        }
    }
}
