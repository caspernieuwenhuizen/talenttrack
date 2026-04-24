<!-- type: epic -->

# #0022 — Workflow & Tasks Engine

## Problem

Youth academies promise *systematic development* to paying parents, but every current tool in the market stores evaluations rather than orchestrating them. Evaluations happen twice a season because no one is nudged to do them. Goal-setting is ad-hoc. Trial-player input varies by who remembers to submit it. Post-match reflection is rare, and when it happens it's on paper that gets lost.

TalentTrack already has the *data plumbing* for continuous development: evaluation categories with hierarchy and weights, goal tracking, session logging, trial case handling. What's missing is the *orchestration layer* — the thing that turns "we should evaluate after every match" from a wish into a scheduled, visible, low-friction task landing in someone's inbox with a deadline.

This epic introduces that orchestration layer as a first-class capability.

Who feels it: head of development (wants the academy to actually *do* development consistently), parents (want visible evidence that their money buys systematic attention to their kid), coaches (genuinely want to reflect but forget), players (benefit from being nudged to self-evaluate), and eventually — in the B-framing — anyone running any recurring academy process.

## Proposal

A **Workflow & Tasks Engine** — a generic task orchestration system within the plugin, shipped v1 as a narrow *cadence engine* bound to existing TalentTrack entities, architected from day one to extend into a broader *generic workflow platform* as the roadmap progresses.

## Decisions locked during shaping

- **Start A, plan for B.** v1 is a narrow cadence engine bound to existing TT entities; architecture supports B-framing without committing to it.
- **TT entities only in v1.** Task-to-entity links are foreign keys to specific tables. Extensible later by adding new entity types in code.
- **No form builder in v1.** Forms are PHP classes defined by developers. Academies configure schedule/assignment/cadence but don't design forms.
- **Library of shipped templates.** Phase 1 ships 4 templates. Phase 2 adds the 5th (trial-input migration). New templates are PRs, not runtime configuration.
- **Bell + email notifications in v1.** No browser push until #0019 Sprint 7 PWA is available.
- **Sequence position: Option C.** Phase 1 ships between #0019 and #0017. #0017 becomes the first real consumer of the engine.
- **Minors assignment: configurable per academy.** Club admin picks from four models. Default for new installs: age-based switch (most defensible regulatory posture).
- **Task history: audit-log only.** Completed task data lives in `tt_audit_log`. Creates soft dependency on #0021.
- **Parent digest: moves to #0014.** Workflow Engine just triggers a task on the HoD to run #0014's report wizard.
- **Cron reliability: hope for the best + self-diagnostic banner.**
- **Email reliability: `wp_mail()` + two-step diagnostic** (activation test + click-to-confirm).

## Template library

### Phase 1 templates (4, ship with engine)

1. **Post-match coach evaluation** — event-triggered on session-completed with type `match`; assignees fan out to head coach with one task per player evaluated; 72-hour deadline.
2. **Player self-evaluation (weekly)** — cron-triggered Sundays 18:00; fans out to every rostered player via minors-policy resolver; 7-day deadline.
3. **Quarterly goal-setting with approval chain** — cron-triggered start of each quarter; player fills `GoalSettingForm`; on completion, spawns a `GoalApprovalForm` task for the coach. **Tactically special-cased in Phase 1** — the template's `onComplete` handler manually creates the second task. Refactored in Phase 2 when `spawns_on_complete` becomes a first-class primitive.
4. **Quarterly HoD review** — cron-triggered start of each quarter; assignee is HoD by role; 14-day deadline; no entity links; form queries live aggregated data at render time.

### Phase 2 templates

5. **Trial staff input (migrated from #0017 Sprint 3)** — migration sprint. The existing `tt_trial_case_staff_inputs` table gets replaced; responses canonically live in `tt_workflow_tasks.response_json`. Data migration included.

### Post-Phase-2 templates (opportunistic)

- Log attendance for past planned session (consumes #0006 Sprint 3's activity→session transition).
- Send monthly parent digest (triggers #0014's parent-monthly report wizard).
- Backup health review (consumes #0013's health indicator).
- Generic academy templates for Phase 4: kit returns, medical check-ins, payment reminders, onboarding checklists.

## Core model (primitives)

### Task template

A PHP class implementing `TT\Workflow\TaskTemplateInterface`. Shipped templates live in `src/Modules/Workflow/Templates/`. Each template defines:

- `key()` — unique slug
- `name()` and `description()` — for the library UI
- `defaultSchedule()` — default cadence config
- `defaultAssignee()` — returns an **`AssigneeResolver`**, not a raw user ID. Resolver is called at task creation and handles dynamic cases like "head coach of the affected team" or the minors-assignment policy
- `defaultDeadlineOffset()` — e.g. "72 hours from trigger"
- `formClass()` — PHP class name of the form to render
- `entityLinks()` — which TT entity types this template links to
- `expandTrigger(trigger_context)` — **for fan-out templates.** Returns an array of task instances. Default returns a single task. Overridden by fan-out templates.
- `onComplete(task, response)` — **optional.** Called when a task is completed. Goal-setting uses it to spawn the approval task (tactical Phase 1 hack).

### Task instance

```sql
CREATE TABLE tt_workflow_tasks (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_key VARCHAR(64) NOT NULL,
  assignee_user_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'open',
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  due_at DATETIME NOT NULL,
  completed_at DATETIME DEFAULT NULL,
  -- Typed entity links (each nullable)
  player_id BIGINT UNSIGNED DEFAULT NULL,
  team_id BIGINT UNSIGNED DEFAULT NULL,
  session_id BIGINT UNSIGNED DEFAULT NULL,
  evaluation_id BIGINT UNSIGNED DEFAULT NULL,
  goal_id BIGINT UNSIGNED DEFAULT NULL,
  trial_case_id BIGINT UNSIGNED DEFAULT NULL,
  -- Chain tracking
  parent_task_id BIGINT UNSIGNED DEFAULT NULL,
  -- Response storage
  response_json TEXT DEFAULT NULL,
  KEY idx_assignee_status (assignee_user_id, status),
  KEY idx_template (template_key),
  KEY idx_due (due_at),
  KEY idx_parent (parent_task_id)
);
```

Status values: `open`, `in_progress`, `completed`, `overdue`, `skipped`, `cancelled`.

### Trigger

```sql
CREATE TABLE tt_workflow_triggers (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_key VARCHAR(64) NOT NULL,
  trigger_type VARCHAR(32) NOT NULL,  -- 'manual', 'cron', 'event'
  cron_expression VARCHAR(64) DEFAULT NULL,
  event_hook VARCHAR(128) DEFAULT NULL,
  enabled BOOLEAN DEFAULT TRUE,
  config_json TEXT DEFAULT NULL,
  KEY idx_template (template_key)
);
```

v1 supports `manual`, `cron`, and one `event` type (session-completed for the post-match template). More events in Phase 3.

### Template config (per-install overrides)

```sql
CREATE TABLE tt_workflow_template_config (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_key VARCHAR(64) NOT NULL UNIQUE,
  enabled BOOLEAN DEFAULT TRUE,
  cadence_override VARCHAR(64) DEFAULT NULL,
  deadline_offset_override VARCHAR(32) DEFAULT NULL,
  assignee_override_json TEXT DEFAULT NULL,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_by BIGINT UNSIGNED NOT NULL
);
```

### Form response

Stored inline in `response_json` on the task instance. Form classes serialize/deserialize their own schema.

**Live-data forms**: form classes may query live data at render time (used by Quarterly HoD review). Data isn't frozen at trigger time.

### AssigneeResolver primitive

```php
interface AssigneeResolver {
    public function resolve(TaskContext $context): array;  // array<int> of user IDs
}
```

Implementations:
- `RoleBasedResolver` — returns all users with a role (e.g. HoD).
- `TeamHeadCoachResolver($team_id)` — head coach of a specific team.
- `PlayerOrParentResolver($player_id)` — respects the minors-assignment policy.
- `TrialCaseStaffResolver($case_id)` — assigned staff on a trial case (Phase 2).
- `LambdaResolver(Closure $fn)` — escape hatch for one-off resolution.

The mechanism that lets configurable minors-assignment policy plug in at task-creation time without templates knowing about it.

## Cron + email reliability safeguards

### Cron self-diagnostic banner

On HoD dashboard page load: "Any tasks with `due_at < NOW() - 24 hours` AND `status = 'open'`?" If yes:

> "Scheduled tasks don't appear to be running reliably on this install. Your host's WordPress cron may need attention. [Learn how to fix →]"

Link goes to `docs/workflow-engine-cron-setup.md` (ships in Phase 1). Banner dismissible per-user, returns if condition persists > 7 days.

### Email self-diagnostic

On Workflow Engine activation:
1. Send test email to current admin.
2. Email contains click-to-confirm link with signed token.
3. Admin clicks within 7 days → confirmed working, no banner.
4. 7 days with no click → banner: "We sent a test email but haven't seen you confirm receipt. [Test again] [Learn how to fix →]"

Link goes to `docs/workflow-engine-email-setup.md` (SMTP plugin recommendations, Mailgun/Postmark setup).

## Minors-assignment policy

Setting: `tt_workflow_minors_assignment_policy`. Four models:

- **`direct_only`** — tasks always go to `player.wp_user_id`. Parents see nothing.
- **`parent_proxy`** — tasks always go to `player.parent_user_id`.
- **`direct_with_parent_visibility`** — tasks go to player; parent has read-only view of child's task inbox.
- **`age_based`** (default) — <13: parent_proxy. 13-15: direct_with_parent_visibility. 16+: direct_only.

Schema addition: `tt_players.parent_user_id` (nullable FK to wp_users). Multi-parent support deferred to Phase 2 via `tt_player_parents` if needed.

Switching policy mid-install: applies to new tasks only. Existing open tasks retain original assignee.

## Dashboard + inbox surfaces

### `FrontendMyTasksView` (per-user inbox)

Any user with `tt_view_own_tasks`. Shows open tasks sorted by `due_at`, in-progress (drafts), and recently completed. Uses `FrontendListTable` from #0019 Sprint 2.

### `FrontendTasksDashboardView` (HoD overview)

`tt_view_tasks_dashboard`. Aggregated counts, completion rate per coach/team, per-template usage, drill-in to individual tasks.

### `FrontendTaskTemplatesConfigView` (academy admin)

`tt_configure_workflow_templates`. Enable/disable toggles, per-template configuration, dry-run preview.

## Phased rollout

### Phase 1 — Engine + 4 templates (~50-65h)

Five sprints:

| Sprint | Focus | Effort |
| --- | --- | --- |
| 1 | Engine primitives + schema | ~15h |
| 2 | Inbox + bell + email + self-diagnostics | ~12h |
| 3 | Post-match + self-eval templates + AssigneeResolvers | ~13h |
| 4 | Goal-setting (tactical chain) + Quarterly HoD templates | ~12h |
| 5 | Dashboard + template config UI + docs | ~10h |

### Phase 2 — Trial migration + chain primitive + dashboards (~35-45h)

- Migrate #0017 Sprint 3 to a template. Deprecate `tt_trial_case_staff_inputs`.
- Refactor goal-setting to use new `spawns_on_complete` first-class primitive.
- Deeper dashboards; inbox filters; bulk actions; snooze.

### Phase 3 — Event infrastructure + deeper configurability (~30-40h)

- Proper event bus with retry/replay.
- Assignee override UIs, cadence variations, template inheritance.
- Multi-step chains as first-class primitive.

### Phase 4 — B-framing extension (~60-100h)

- Minimal form builder (5 field types).
- Non-development workflow types (kit returns, medical, payments).
- **Decision point**: is B-framing justified by Phase 1-3 usage data?

## Cross-epic interactions

- **#0017 (Trial)** — Sprint 3's flow migrates to a template in Phase 2 of this epic.
- **#0014 (Reports)** — monthly parent digest becomes a task that triggers #0014's report wizard. Wizard gets "launch from task" entry point.
- **#0006 (Team planning)** — activity-to-session transition uses a task in Phase 2+.
- **#0013 (Backup)** — health review as a template (Phase 2+, nice-to-have).
- **#0019 Sprint 7 (PWA)** — browser push on bell, once PWA ships.
- **#0011 (Monetization)** — major Academy-tier pitch. "Systematic development" narrative.
- **#0021 (Audit log viewer)** — task completion data canonically lives here. Soft dependency: #0022 ships first, but #0021 should follow within a release.

## SEQUENCE.md impact

Revised phase structure:

- Phase 3 (#0019 Sprints 4-6) — frontend migration ends.
- **New inserted phase: #0022 Phase 1** (5 sprints, ~50-65h). Adds ~6-8 calendar weeks at 2hr/day.
- Phase 4 — interleaves #0022 Phase 2 with #0017 (which consumes the engine).
- Phase 5+ — #0022 Phases 3-4.

SEQUENCE.md update is part of this epic's delivery.

## Success signals

- % of triggered tasks completed before deadline (>70% target within first quarter)
- Time between match and evaluation submission decreases over season
- Number of active task templates per academy
- Qualitative: does HoD check the dashboard weekly without being prompted?
- Reduction in bespoke workflow code: Phase 2 deletes at least 400 lines across #0017 Sprint 3

## Open questions for Phase 2 shaping

- How does `spawns_on_complete` generalize beyond goal-setting? Linear chain vs DAG?
- Trial-input data migration: preserve historical rows as-is or forward-migrate?
- Tasks-specific audit view or wait for #0021?
- Parent-proxy attribution: response attributed to parent or child? (Likely both; audit preserves actor, display attributes to child.)

## Touches (indicative)

- New module: `src/Modules/Workflow/`
  - Core: `TaskTemplate.php`, `TaskInstance.php`, `Trigger.php`, `TaskEngine.php`
  - Interfaces: `TaskTemplateInterface.php`, `FormInterface.php`, `AssigneeResolver.php`
  - Resolvers: `RoleBasedResolver.php`, `TeamHeadCoachResolver.php`, `PlayerOrParentResolver.php`, `LambdaResolver.php`
  - Templates/: four Phase 1 templates
  - Forms/: four corresponding form classes
  - Dispatchers: `CronDispatcher.php`, `EventDispatcher.php`, `ManualDispatcher.php`
  - Diagnostics: `CronHealthCheck.php`, `EmailHealthCheck.php`
- New schema: `tt_workflow_tasks`, `tt_workflow_triggers`, `tt_workflow_template_config`, column `tt_players.parent_user_id`
- Notifications: `NotificationBell` frontend component
- Frontend surfaces: `FrontendMyTasksView`, `FrontendTasksDashboardView`, `FrontendTaskTemplatesConfigView`
- Capabilities: `tt_view_own_tasks`, `tt_view_tasks_dashboard`, `tt_configure_workflow_templates`, `tt_manage_workflow_templates`
- Documentation: cron setup guide, email setup guide, template library overview
