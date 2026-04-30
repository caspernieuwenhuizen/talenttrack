<!-- type: feat -->

# #0022 Sprint 1 — Engine primitives + schema

## Problem

Foundation sprint. Before any template can ship, the task model itself must exist: primitives, interfaces, lifecycle methods, dispatchers, schema. No user-visible features this sprint — it builds the plumbing every subsequent sprint depends on.

## Proposal

Introduce the `TT\Workflow` namespace with interfaces (`TaskTemplateInterface`, `AssigneeResolver`, `FormInterface`), concrete primitives (`TaskEngine`, `TaskInstance`), three dispatchers (manual, cron, event), and the schema.

Also introduce the `tt_players.parent_user_id` column needed for minors-assignment policy.

## Scope

### Schema

One migration adding:

**`tt_workflow_tasks`** — task instances (full definition in the idea file).

**`tt_workflow_triggers`** — how templates become tasks (manual / cron / event).

**`tt_workflow_template_config`** — per-install configuration overrides.

**Column addition**: `tt_players.parent_user_id` (nullable FK to `wp_users`).

**Setting**: `tt_workflow_minors_assignment_policy` (`direct_only` / `parent_proxy` / `direct_with_parent_visibility` / `age_based`). Default `age_based` on new installs.

### Interfaces

**`TaskTemplateInterface`**:
```php
interface TaskTemplateInterface {
    public function key(): string;
    public function name(): string;
    public function description(): string;
    public function defaultSchedule(): ScheduleSpec;
    public function defaultAssignee(TaskContext $context): AssigneeResolver;
    public function defaultDeadlineOffset(): DeadlineOffsetSpec;
    public function formClass(): string;
    public function entityLinks(): array;
    public function expandTrigger(TriggerContext $context): array;  // default: returns single task
    public function onComplete(TaskInstance $task, array $response): void;  // default: no-op
}
```

**`AssigneeResolver`**:
```php
interface AssigneeResolver {
    public function resolve(TaskContext $context): array;  // array of user IDs
}
```

Plus concrete resolvers: `RoleBasedResolver`, `TeamHeadCoachResolver`, `PlayerOrParentResolver` (handles all four minors-assignment policies), `LambdaResolver`.

**`FormInterface`**:
```php
interface FormInterface {
    public function render(TaskInstance $task): string;  // returns HTML
    public function validate(array $submission): array;  // returns errors
    public function serialize(array $submission): array;  // returns response_json payload
    public function deserialize(array $response_json): array;  // for re-display
}
```

### TaskEngine class

Orchestrator:
```php
class TaskEngine {
    public function createTask(string $template_key, TaskContext $context): TaskInstance;
    public function createTasksFromTrigger(Trigger $trigger, TriggerContext $context): array;
    public function completeTask(int $task_id, array $response, int $actor_user_id): TaskInstance;
    public function markOverdue(): int;  // called by cron, flips status of overdue open tasks
    public function snoozeTask(int $task_id, DateTimeImmutable $until): void;  // Phase 2, stub in Phase 1
}
```

### Dispatchers

- **`ManualDispatcher`** — REST endpoint allowing authorized users to create tasks on demand.
- **`CronDispatcher`** — registered on WP-cron's `hourly` hook. Iterates enabled triggers with `trigger_type = 'cron'`, checks each `cron_expression` against current time, invokes the template's `expandTrigger()`.
- **`EventDispatcher`** — attaches to WordPress action hooks. One event supported in Phase 1: `tt_session_completed` (fired when a session transitions to completed plan-state, per #0006's session-unification work). Wires the post-match template.

### Capabilities

Four new capabilities, registered in `Activator.php`:

- `tt_view_own_tasks` — see one's own inbox. Granted to all TT roles (coach, head_dev, staff, player, administrator). Non-controversial.
- `tt_view_tasks_dashboard` — see HoD aggregation dashboard. Granted to `tt_head_dev`, `administrator`.
- `tt_configure_workflow_templates` — enable/disable templates, tune cadence. Granted to `tt_head_dev`, `administrator`.
- `tt_manage_workflow_templates` — deeper admin control (reset to defaults, force re-run). Granted to `administrator` only.

### Task lifecycle

State machine:

- `open` — newly created, no action taken.
- `in_progress` — user has opened the form and optionally saved a draft via `FrontendListTable`'s localStorage pattern. (Phase 1 treats in-progress as a UX distinction; the backend just tracks `open` + draft presence.)
- `completed` — form submitted, response stored.
- `overdue` — `due_at < NOW()` AND `status = 'open'`. Flipped by `TaskEngine::markOverdue()` on cron.
- `skipped` — explicitly marked by an admin (Phase 2).
- `cancelled` — explicitly cancelled, typically because the triggering entity was deleted (e.g. match cancelled).

### No user-visible features

This is plumbing. No inbox UI, no dashboard, no notifications — those start Sprint 2.

### Capability registration and minors-policy default

Activator logic adds:
1. The four new capabilities + role grants above.
2. The `tt_workflow_minors_assignment_policy` setting with default `age_based` if not already set (preserves value on upgrade).
3. Runs the schema migration.

## Out of scope

- Any template. Sprint 3+.
- Any UI. Sprint 2+.
- `spawns_on_complete` as first-class primitive. Phase 2.
- Event infrastructure beyond one `tt_session_completed` hook. Phase 3.
- `tt_player_parents` multi-parent table. Deferred to Phase 2 if needed.

## Acceptance criteria

- [ ] Migration creates all three workflow tables + player column on fresh install and upgrade.
- [ ] Four capabilities registered with correct role grants.
- [ ] `TaskTemplateInterface`, `AssigneeResolver`, `FormInterface` defined.
- [ ] `TaskEngine`, dispatchers implemented.
- [ ] `PlayerOrParentResolver` correctly applies all four minors-assignment policies.
- [ ] Unit tests: engine can create tasks manually, mark overdue, resolve assignees (via stub template).
- [ ] No regression anywhere else.

## Notes

- **Sizing**: ~15h. Breakdown: schema (2h), interfaces and value objects (2h), resolvers with policy logic (3h), TaskEngine (3h), dispatchers (3h), tests (2h).
- **Depends on**: #0019 Sprint 1 (REST patterns).
- **Blocks**: all other sprints in this epic.
- **Touches**: new module `src/Modules/Workflow/`, one migration, `includes/Activator.php` extension, `includes/REST/Workflow_Controller.php` (stubs).

---

# #0022 Sprint 2 — Inbox + bell + email + self-diagnostics

## Problem

The engine from Sprint 1 can create and manage tasks, but users have no way to see or complete them. This sprint ships the three user-facing concerns: an inbox showing each user's tasks, a notification bell highlighting new/overdue tasks, and email notifications for off-app reminders. Plus the two self-diagnostic banners (cron health, email health) that prevent silent failure.

## Proposal

Three deliverables:

1. **`FrontendMyTasksView`** — per-user inbox using `FrontendListTable` from #0019 Sprint 2.
2. **`NotificationBell`** — small frontend component showing open-task count and recent activity, embeddable in the tile grid.
3. **Email notifications** with the two-step self-diagnostic (activation test + click-to-confirm).

Plus **cron health banner** — detects stalled tasks and surfaces to HoD.

## Scope

### Inbox

`FrontendMyTasksView`:
- Uses `FrontendListTable`.
- Default view: open tasks sorted by `due_at` ascending.
- Filters: status, template, date range.
- Per-row action: "Open" → opens the task's form (the form class renders itself).
- Completed tasks collapse into a "Recently completed (30 days)" section below.

Task form rendering is invoked via `template->formClass()->render($task)`. Form submission posts to REST; `TaskEngine::completeTask()` stores the response.

Visibility: only shows tasks where `assignee_user_id = current_user`. Ensures no cross-user data leakage.

Entry point: a new tile "My tasks" on the dashboard tile grid, visible to any user with `tt_view_own_tasks`.

### Notification bell

Lightweight `NotificationBell` frontend component:
- Icon with badge showing open+overdue task count.
- Click → opens a small dropdown with: latest 5 tasks (title, template name, due time), "See all" link to inbox.
- Polls `/talenttrack/v1/workflow/tasks/mine/summary` every 5 minutes for updates. (Not WebSocket; polling is simpler and good enough.)
- Embedded in the frontend tile grid header for all users with `tt_view_own_tasks`.

### Email notifications

**When tasks are sent via email**:
- On task creation (an "new task assigned" email).
- On overdue transition (a "you have overdue tasks" digest, max once per day per user).

Template: simple HTML email with task title, description, due date, and a link to the inbox. No markdown or rich content — keeps delivery reliability high.

Sender: uses WP's `from_email` config. If club has a custom branded email from #0011, uses that instead.

Opt-out: per-user setting in WP profile ("Receive TalentTrack task emails: yes/no"). Default yes.

### Email self-diagnostic

Two-step as locked in shaping:

**Step 1 — Activation test** (runs on first Workflow Engine activation or upgrade):
- `TaskEngine` sends a test email to the current admin.
- Email subject: "TalentTrack Workflow Engine — mail test"
- Body: "This test email confirms TalentTrack can send emails from your site. Click the link below to confirm receipt. [Confirm receipt]"
- Link contains a signed token (HMAC with the WP AUTH_KEY, valid 30 days).

**Step 2 — Click-to-confirm**:
- Clicking the link hits `/talenttrack/v1/workflow/email-diagnostic/confirm?token=...`
- Token validated → sets site option `tt_workflow_email_confirmed_at = NOW()`. No banner appears.
- Token invalid/expired → friendly error page.

**Diagnostic banner logic**:
- On admin dashboard page load, checks: was the test email sent more than 7 days ago? AND is `tt_workflow_email_confirmed_at` null (never confirmed)?
- If yes: shows a dismissible banner: "We sent a test email but haven't seen you confirm receipt. Emails from your site may not be reaching recipients. [Send new test] [Learn how to fix →]"
- "Send new test" resets the timer and sends a fresh test.
- "Learn how to fix" → `docs/workflow-engine-email-setup.md` (new doc in Phase 1 deliverables).

### Cron self-diagnostic

**Detection**: on HoD dashboard (from Sprint 5) or any admin page load, run quick query: "Are there tasks with `due_at < NOW() - INTERVAL 24 HOUR` AND `status = 'open'`?"

**If yes**: show banner: "Scheduled tasks appear to be stalled. Your host's WordPress cron may need attention. [Run stuck tasks now] [Learn how to fix →]"

- "Run stuck tasks now" button (only if admin has `tt_manage_workflow_templates`) manually triggers `CronDispatcher::run()` once. Surfaces completion feedback.
- "Learn how to fix" → `docs/workflow-engine-cron-setup.md` (new doc).

Banner dismissible per-user for 24 hours. Returns if condition persists.

## Out of scope

- Template config UI. Sprint 5.
- HoD dashboard with aggregations. Sprint 5.
- Actual templates. Sprint 3+.
- Advanced notification preferences (only certain templates, digest frequency tuning). Phase 2.
- SMS, Slack, other channels. Not in v1.

## Acceptance criteria

- [ ] Inbox renders correctly with filters and sort.
- [ ] User sees only their own tasks.
- [ ] Opening a task shows the form rendered by its `formClass`.
- [ ] Submitting the form stores response + marks task completed.
- [ ] Notification bell polls and updates badge count.
- [ ] Task emails send with correct content and links.
- [ ] Per-user email opt-out respected.
- [ ] Email activation test runs; click-to-confirm works; diagnostic banner appears 7 days after unconfirmed test.
- [ ] Cron diagnostic banner appears when stalled tasks exist.

## Notes

- **Sizing**: ~12h. Inbox (4h), bell (2h), email notifications (3h), email diagnostic (1.5h), cron diagnostic (1.5h).
- **Depends on**: Sprint 1 (engine), #0019 Sprint 2 (FrontendListTable).
- **Touches**: new views, new REST endpoints, two new docs, bell component.

---

# #0022 Sprint 3 — Post-match + self-eval templates + resolvers

## Problem

With engine and UI in place, Sprint 3 ships the first two templates: **post-match coach evaluation** (event-triggered) and **player self-evaluation** (cron-triggered). These are the two most commonly-requested cadences and will prove the fan-out pattern plus the event-trigger infrastructure.

## Proposal

Two templates, two forms, plus the AssigneeResolver implementations needed. The session-completed event hook gets wired for the post-match trigger.

## Scope

### Post-match coach evaluation template

**Template class**: `PostMatchCoachEvaluationTemplate` implements `TaskTemplateInterface`.

- `key`: `post_match_coach_evaluation`
- `defaultSchedule`: event-based, `tt_session_completed`
- `defaultAssignee`: `TeamHeadCoachResolver` (dynamic per affected team)
- `defaultDeadlineOffset`: 72 hours from trigger
- `formClass`: `PostMatchEvalForm`
- `entityLinks`: `session_id`, `team_id`, `player_id`
- `expandTrigger(context)`: returns one task per player who was on the team's roster at session time

**Fan-out logic**: queries session's team roster at `session.date`. Generates one task per player. Each task has `player_id` set to that player, `team_id` to the team, `session_id` to the session.

**Form**: `PostMatchEvalForm` — shortened evaluation form. Uses existing eval categories (reads from `tt_eval_categories`). Three-question minimum:
- Overall rating (1-10).
- Key strength shown in match (free-text, ≤200 chars).
- Key growth area (free-text, ≤200 chars).
- Optional category ratings (coaches can expand if they want).

On submit, creates an `evaluation` row via the existing evaluation REST endpoint, sets `evaluation_id` on the task's response_json. This means the task's output becomes a real TT evaluation.

### Player self-evaluation template

**Template class**: `PlayerSelfEvaluationWeeklyTemplate`.

- `key`: `player_self_evaluation_weekly`
- `defaultSchedule`: cron, every Sunday 18:00 local time
- `defaultAssignee`: `PlayerOrParentResolver` per player (honors minors policy)
- `defaultDeadlineOffset`: 7 days
- `formClass`: `PlayerSelfEvalForm`
- `entityLinks`: `player_id`
- `expandTrigger(context)`: returns one task per rostered player (status = active)

**Form**: `PlayerSelfEvalForm` — 5-question reflection:
- "How did training go this week?" (1-5 emoji scale).
- "What did you do best?" (free-text, ≤300 chars).
- "What do you want to work on next week?" (free-text, ≤300 chars).
- "How do you feel physically?" (1-5 emoji scale).
- "Anything you want your coach to know?" (free-text, ≤500 chars).

On submit, creates a lightweight entry in the audit log with summary data, and writes `response_json` to the task. **Does not create a full `evaluation` row** — self-evals are a distinct signal from coach evals and shouldn't inflate the player's rating history.

### Assignee resolvers (completing what Sprint 1 stubbed)

**`TeamHeadCoachResolver`** (used by post-match template):
- Input: `team_id` from the task context.
- Query: `tt_teams.head_coach_id` → one WP user.
- Returns: `[head_coach_user_id]`.

**`PlayerOrParentResolver`** (used by self-eval + others):
- Input: `player_id` from context.
- Reads site option `tt_workflow_minors_assignment_policy`.
- Applies policy:
  - `direct_only` → returns `[player.wp_user_id]`.
  - `parent_proxy` → returns `[player.parent_user_id]` (if null, falls back to `player.wp_user_id` with warning log).
  - `direct_with_parent_visibility` → returns `[player.wp_user_id]` (visibility is a separate concern handled in Sprint 5's config UI).
  - `age_based` → computes player age from `player.birthdate`. <13: `parent_proxy` logic. 13-15: `direct_with_parent_visibility` logic. 16+: `direct_only` logic.
- Edge case: player has no `wp_user_id` AND no `parent_user_id` → skip task creation with audit log entry ("couldn't resolve assignee for player #N").

### Event hook wiring

`EventDispatcher::registerHook('tt_session_completed', function($session_id) { ... })`:
- Resolve the `tt_workflow_triggers` row where `event_hook = 'tt_session_completed'` AND `enabled = true`.
- If the session's `type = 'match'`, invoke the post-match template's `expandTrigger()`.
- Non-match sessions ignored.

The `tt_session_completed` event itself is fired by #0006's session transition code (Sprint 4 of that epic). If #0006 hasn't shipped, wire a fallback: a manual trigger button on the session's edit view ("Trigger post-match evaluations") that an HoD can press.

### Tests

Unit tests for:
- `TeamHeadCoachResolver` with various team states.
- `PlayerOrParentResolver` with each of the four policies, various player ages, missing wp_user_id / parent_user_id edge cases.
- Template `expandTrigger` for both templates.
- Form serialization/deserialization.

## Out of scope

- Remaining templates. Sprint 4.
- Dashboard. Sprint 5.
- Template config UI. Sprint 5.

## Acceptance criteria

- [ ] Session completion (type=match) fires the post-match template; one task per player created.
- [ ] Cron Sunday 18:00 fires the self-eval template; one task per rostered active player.
- [ ] Assignee resolvers correctly apply minors policies.
- [ ] Submitting the post-match form creates a real `evaluation` row.
- [ ] Submitting the self-eval form writes to audit log without polluting evaluation history.
- [ ] All edge cases handled (no coach assigned, player with no wp_user_id, etc.) log warnings rather than crashing.
- [ ] Manual fallback trigger for post-match works if `tt_session_completed` hook isn't available.

## Notes

- **Sizing**: ~13h. Post-match template + form (4h), self-eval template + form (3h), resolvers (2h), event wiring + fallback (2h), tests (2h).
- **Depends on**: Sprints 1-2.

---

# #0022 Sprint 4 — Goal-setting + HoD review templates

## Problem

Two more templates: **quarterly goal-setting** (with the tactical Phase 1 approval chain) and **quarterly HoD review** (org-level, no entity links). These complete the Phase 1 template set.

## Proposal

Goal-setting is the tactically-chained template the coverage check surfaced — player fills the first form, completion spawns a coach-approval task. HoD review is the straightforward quarterly reflection with live-queried aggregated data.

## Scope

### Quarterly goal-setting (chained)

**Template 1**: `QuarterlyGoalSettingTemplate` (player-side).

- `key`: `quarterly_goal_setting`
- `defaultSchedule`: cron, start of each quarter (Jan 1, Apr 1, Jul 1, Oct 1)
- `defaultAssignee`: `PlayerOrParentResolver` per active rostered player
- `defaultDeadlineOffset`: 14 days
- `formClass`: `GoalSettingForm`
- `entityLinks`: `player_id`, `goal_id` (populated when form creates goals)
- `expandTrigger`: one task per rostered active player
- `onComplete(task, response)`: **tactical Phase 1 chain** — creates second task using `QuarterlyGoalApprovalTemplate` with `parent_task_id = $task->id`, assignee = player's head coach.

**Form**: `GoalSettingForm`:
- "Pick 2-3 goals for this quarter."
- For each goal: title, category (dropdown), measurable target, target date (default end of quarter).
- On submit, creates rows in `tt_goals` via existing REST endpoint, attaches `goal_id`s to the task's response.

**Template 2**: `QuarterlyGoalApprovalTemplate` (coach-side).

- `key`: `quarterly_goal_approval`
- `defaultSchedule`: never directly triggered (only spawned via onComplete).
- `defaultAssignee`: resolves to the player's head coach at spawn time.
- `defaultDeadlineOffset`: 7 days from spawn.
- `formClass`: `GoalApprovalForm`
- `entityLinks`: `player_id`, (references `parent_task_id`)
- `expandTrigger`: returns no tasks (never triggered directly, only spawned).

**Form**: `GoalApprovalForm`:
- Shows the player's proposed goals (read-only).
- For each: approve / request changes.
- Free-text field for coach comment.
- On submit with "request changes": sets goal status back to draft, notifies player via another task (or just via email if we don't want to spawn yet another task).

**The Phase 1 tactical hack**, documented visibly:

```php
// Tactical Phase 1 — to be refactored with spawns_on_complete primitive in Phase 2.
// The proper architecture is: Phase 2 introduces `spawns_on_complete` as a first-class
// primitive on TaskTemplateInterface, and goal-setting's chain becomes declarative
// rather than imperative.
class QuarterlyGoalSettingTemplate implements TaskTemplateInterface {
    public function onComplete(TaskInstance $task, array $response): void {
        // Spawn approval task for coach
        $engine = tt_workflow_engine();
        $engine->createTask('quarterly_goal_approval', new TaskContext(
            player_id: $task->player_id,
            parent_task_id: $task->id,
            spawn_reason: 'goal_setting_complete',
        ));
    }
}
```

Marker comment appears in the class docblock and in a Phase 2 TODO file.

### Quarterly HoD review template

**Template**: `QuarterlyHoDReviewTemplate`.

- `key`: `quarterly_hod_review`
- `defaultSchedule`: cron, start of each quarter
- `defaultAssignee`: `RoleBasedResolver('tt_head_dev')` — all HoDs. If multiple exist, each gets their own task.
- `defaultDeadlineOffset`: 14 days
- `formClass`: `QuarterlyHoDReviewForm`
- `entityLinks`: none (org-level)
- `expandTrigger`: one task per HoD user

**Form**: `QuarterlyHoDReviewForm`:
- Header section: auto-queried aggregated data at render time (N evaluations completed this quarter, completion rate per team, top development areas by count).
- Free-text reflection sections:
  - "What went well this quarter?"
  - "What didn't work?"
  - "What changes for next quarter?"
- On submit, stores free-text in response, audits the completion, no side effects on other tables.

**Live-data querying**: form's `render()` method queries aggregated data via existing services (`PlayerStatsService` etc.) at render time. Data isn't frozen at trigger time — HoD sees *current* data when they open the task, even if it's days after the quarter started.

### Integration points

- Creates real `tt_goals` rows via existing endpoints.
- Reads from existing `tt_evaluations`, `tt_players`, `tt_teams`.
- `parent_task_id` column (from Sprint 1 schema) is now actually used.

## Out of scope

- Refactoring goal-setting to use `spawns_on_complete` primitive. Phase 2.
- Multi-person goal collaboration, goal libraries. Phase 2+.
- HoD review with attached report documents. Phase 2+ if needed.

## Acceptance criteria

- [ ] Start of quarter, goal-setting template fires; one task per player.
- [ ] Player can complete with 2-3 goals, goals created in `tt_goals`.
- [ ] Goal-setting completion spawns approval task for the player's coach (tactical chain).
- [ ] Coach can approve or request changes via the approval form.
- [ ] HoD review fires at start of quarter; one task per HoD.
- [ ] HoD review form shows live-queried data at render time.
- [ ] All three templates (+ approval spawn) visible in HoD dashboard once Sprint 5 lands.

## Notes

- **Sizing**: ~12h. Goal-setting template + form + chain (5h), goal-approval template + form (2h), HoD review template + form + live queries (3h), tests (2h).
- **Depends on**: Sprint 1-3. Goal-setting's chain depends on Sprint 3's patterns (task spawn mechanics).
- **Phase 2 TODO file** created with the list of things to refactor once `spawns_on_complete` primitive lands.

---

# #0022 Sprint 5 — Dashboard + template config UI + docs

## Problem

Engine works, templates fire, tasks get completed. HoDs have no aggregate visibility and no way to tune template behavior per their academy's preferences. Academy-wide configuration surfaces this sprint, plus the documentation that makes v1 actually deployable.

## Proposal

Three deliverables:

1. **`FrontendTasksDashboardView`** — HoD overview.
2. **`FrontendTaskTemplatesConfigView`** — enable/disable templates, tune cadences, set overrides.
3. **Documentation** for cron setup and email setup (linked from self-diagnostics).

## Scope

### HoD dashboard

**View**: `FrontendTasksDashboardView`, gated by `tt_view_tasks_dashboard`.

Sections:

**Open & overdue counters**:
- Open tasks: N
- Overdue tasks: N (prominent if > 0)
- Completed this week: N

**Completion rate per template**:
- Simple bar chart: each template with % of assigned tasks completed on time over last 30 days.
- Identifies which templates actually get used.

**Completion rate per coach**:
- Table: coach name, tasks assigned, completed on time, completion %.
- Identifies coaches who are behind.

**Completion rate per team**:
- Table: team, tasks assigned across all templates, completion rate.
- Identifies systemic issues.

**Recent completions**:
- Last 10 completed tasks with submitter, template, completion timestamp.

**Stalled tasks alert**:
- If cron self-diagnostic triggered, the banner from Sprint 2 appears at top.

### Template config UI

**View**: `FrontendTaskTemplatesConfigView`, gated by `tt_configure_workflow_templates`.

Per template:
- Enabled toggle (default: all enabled).
- Cadence override (for cron templates — drop-down: default / weekly / biweekly / monthly / custom cron expression).
- Deadline override (days from trigger).
- Assignee override: rarely needed, simple UI for "override default role" (intended Phase 3 but a minimal version here).
- **Dry-run preview**: "If enabled now, this template would create X tasks due on Y."

Persistence: writes to `tt_workflow_template_config`.

### Minors policy config

Same view or adjacent (under `tt_manage_workflow_templates`):
- Radio buttons for the four policies.
- Plain-language explanation of each.
- Current default: age-based switch.
- Save → writes `tt_workflow_minors_assignment_policy` site option.

### Documentation

Two new docs in the repo's `docs/` folder:

**`docs/workflow-engine-cron-setup.md`**:
- What WordPress cron is and why it's unreliable.
- How to add a real cron entry (crontab -e example).
- How to verify it's working (check a site transient, diagnostic output).
- Common hosting providers: specifics for cPanel, SiteGround, WP Engine, etc.

**`docs/workflow-engine-email-setup.md`**:
- Why `wp_mail` sometimes fails.
- Recommended SMTP plugins (WP Mail SMTP, Fluent SMTP).
- How to set up Mailgun / Postmark / Amazon SES.
- How to run the workflow engine's email diagnostic.

Both docs written in plain language suitable for a non-technical HoD or club admin.

### Template library doc

**`docs/workflow-engine-templates.md`** — one-page overview:
- What each of the four Phase 1 templates does.
- Default cadence.
- Who gets tasks.
- How to enable/configure them.
- "Request a new template" section pointing to #0009's development management for future template contributions.

## Out of scope

- Custom template creation in the UI. Templates are code; new ones come as PRs.
- Completion-trend graphs over time. Phase 2+.
- Drill-in to individual task detail from the dashboard (just counts in v1).
- Audit-log integration beyond what's automatic.

## Acceptance criteria

- [ ] HoD dashboard shows correct aggregated counts.
- [ ] Completion rate tables work and order correctly.
- [ ] Template config UI lets HoD enable/disable each template.
- [ ] Cadence and deadline overrides persist.
- [ ] Dry-run preview shows realistic numbers.
- [ ] Minors policy can be changed, applies to new tasks only.
- [ ] Both setup docs shipped and linked from self-diagnostic banners.
- [ ] Template library doc shipped.

## Notes

- **Sizing**: ~10h. Dashboard (4h), template config UI (3h), minors policy UI (1h), docs (2h).
- **Depends on**: Sprints 1-4.
- **Closes out Phase 1 of #0022.**
