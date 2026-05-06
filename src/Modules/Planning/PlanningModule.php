<?php
namespace TT\Modules\Planning;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;

/**
 * PlanningModule (#0006) — team planning calendar.
 *
 * The planner is a calendar view onto `tt_activities` that filters
 * by `plan_state` so coaches can see what's scheduled vs. what's
 * already logged. The data model is the existing activities table
 * (with three new columns added by migration 0072: `plan_state`,
 * `planned_at`, `planned_by`); the principle-tagging is the existing
 * methodology principle infrastructure (`tt_principles` from
 * migration 0015 + `tt_activity_principles` link).
 *
 * The module is a thin wrapper — no new repositories, no new REST
 * controllers. The frontend view (`FrontendTeamPlannerView`) renders
 * server-side off the existing `ActivitiesRestController` filter
 * surface (which gained a `plan_state` filter in this PR), and the
 * existing activity create/edit views handle the form-level CRUD
 * with `plan_state=scheduled` injected via a hidden field.
 *
 * Capabilities: `tt_view_plan` and `tt_manage_plan` are bridged to
 * the existing `activities` matrix entity (read / change) in
 * `LegacyCapMapper`, so coaches who can already see and edit
 * activities can use the planner without a parallel grant matrix.
 */
class PlanningModule implements ModuleInterface {

    public function getName(): string { return 'planning'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        // No live wiring — the view dispatcher and tile registration
        // are handled centrally in CoreSurfaceRegistration so the
        // module-disabled toggle hides the planner without touching
        // unrelated activities-module surfaces.
    }
}
