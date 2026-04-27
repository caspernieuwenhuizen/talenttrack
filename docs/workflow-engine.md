<!-- audience: admin, dev -->

# Workflow & tasks engine

The workflow engine turns "we should evaluate after every match" from a wish into a scheduled, visible task that lands in someone's inbox with a deadline. It owns the orchestration layer that nudges coaches, players, and the head of development to do their part of systematic development on time.

This page is the high-level overview. Implementation details for cron reliability and email delivery live on their own pages once those surfaces ship.

## What it does (in one paragraph)

Templates describe a recurring or event-driven task ("a post-match coach evaluation, due 72 hours after the session, one task per player evaluated") plus how to find the right assignee ("the team's head coach") and what form they fill in. The engine fires the template on its schedule (cron, event, or manual button), creates one task per (assignee × affected entity), and routes it to the right person's inbox. Completing the task stores the response and runs any follow-up the template defines.

## What ships in Phase 1

All five sprints are now live:

- **Engine + schema** — `tt_workflow_tasks`, `tt_workflow_triggers`, `tt_workflow_template_config`, the `parent_user_id` column on `tt_players`, and the public PHP API (`WorkflowModule::engine()->dispatch(...)`).
- **Inbox + bell + email + self-diagnostic** — every user with `tt_view_own_tasks` sees their tasks at `?tt_view=my-tasks`; an unobtrusive bell shows the open count on the dashboard; the assignee gets an email when a task is created. A wp-admin banner warns when WP-cron has stopped firing reliably (links to [the cron setup guide](workflow-engine-cron-setup.md)).
- **Five shipped templates**
  - **Post-match coach evaluation** — manual trigger in v1 (an event hook will subscribe once `ActivitiesModule` fires `tt_activity_completed`). Fans out one task per active player on the team to the head coach, due in 72 hours.
  - **Player self-evaluation (weekly)** — cron `0 18 * * 0` (Sundays 18:00). One task per active rostered player, routed via the minors-assignment policy. Due in 7 days.
  - **Quarterly goal-setting** — cron `0 0 1 step-month wildcard` at start of each quarter. Player drafts up to three goals; on completion, automatically spawns a goal-approval task for the coach.
  - **Goal approval** — only spawned by the goal-setting template. Coach approves / amends / rejects each goal with optional notes. Reads the player's draft via `parent_task_id`.
  - **Quarterly Head of Development review** — same quarterly cadence. One task per HoD, 14-day deadline. Live-data form: shows the last 90 days of evaluations / sessions / goals / on-time task completion at render time.
- **HoD dashboard + admin config UI** — `?tt_view=tasks-dashboard` (HoD overview: per-template + per-coach completion rates + currently-overdue list); `?tt_view=workflow-config` (academy admin: enable/disable each template, override cadence and deadline, switch the minors-assignment policy).

## Permissions

Sprint 1 reserves four capabilities so subsequent sprints can land their views without churning role assignments:

| Capability | Default grant |
| --- | --- |
| `tt_view_own_tasks` | every TalentTrack role + administrator |
| `tt_view_tasks_dashboard` | administrator + Head of Development + Club Admin |
| `tt_configure_workflow_templates` | administrator + Club Admin |
| `tt_manage_workflow_templates` | administrator only |

## Minors-assignment policy

Many tasks (a weekly self-evaluation, a quarterly goal-setting form) target a player. Players under 16 may not have their own login; clubs need the choice of routing those tasks to the player, the parent, or both.

The engine reads `tt_workflow_minors_assignment_policy` from `tt_config` and supports four values:

- `direct_only` — task always goes to the player's WP user.
- `parent_proxy` — task always goes to the parent's WP user (`tt_players.parent_user_id`).
- `direct_with_parent_visibility` — task goes to the player; parent has read-only visibility (Sprint 2 inbox surface).
- `age_based` (default) — under 13: parent_proxy. 13-15: direct_with_parent_visibility. 16+: direct_only.

Sprint 1 ships the resolver + the seeded default. The admin UI to switch policy lands in Sprint 5.

## Reliability — cron and email

The engine relies on WP-cron for scheduled triggers and `wp_mail()` for notifications. Sprint 2 ships:

- A cron self-diagnostic banner that warns when scheduled tasks haven't fired on time.
- An email-confirmation flow on activation: a click-to-confirm test email, with a fallback banner if the admin doesn't click within 7 days.

Both link to dedicated setup guides for hosts where WP-cron or `wp_mail()` is broken.

## Phase 2 + 3 additions (v3.37.0)

The remaining phases of the epic landed in one release. The shape stays the same — same templates, same inbox, same admin UI — but four pieces got first-class status:

- **Chain steps** — declarative `spawns_on_complete`. A template can return one or more `ChainStep`s from `chainSteps()`; the engine walks them after the parent task is completed. The Quarterly goal-setting → Goal approval pair is now expressed this way (the old `onComplete` hand-roll is retired). Chain steps appear in the admin config view so you can see at a glance which completion will spawn what.
- **Inbox filters** — narrow the inbox by template, by status (open / in progress / overdue), by due window (24h / 3 days / 7 days). State persists in the URL so a filter view is bookmarkable.
- **Bulk actions + snooze** — checkboxes on every actionable row plus a bulk bar with **Skip selected**, **Snooze 1 day**, and **Snooze 7 days**. Per-row `1d` and `7d` buttons hide a single task without selecting it. Snoozed tasks reappear automatically once the snooze elapses; a *Show snoozed* checkbox brings them back early.
- **Event log + retry** — every event-typed trigger firing writes a row to `tt_workflow_event_log`. Successful dispatches transition `processed`; thrown errors land as `failed` with the message captured. The admin config view surfaces the last 25 entries with a **Retry** button on failed rows that re-runs the dispatch and increments a retry counter.

## What's still not shipped (Phase 4)

- **Form builder** — every form is still a PHP class shipped with the plugin or added by a developer.
- **Browser push notifications** — bell + email only until the PWA pass lands.
- **Non-development workflow types** (kit returns, medical check-ins, payment reminders) — the engine supports them in principle, but no shipped templates yet. Phase 4 was gated on Phase 1-3 usage data; revisit when academies start asking for it.

## See also

- [Roles and permissions](access-control.md) — for the capability slugs the engine uses.
- [Sessions](sessions.md) — match-type sessions are the trigger for post-match evaluations.
- [Goals](goals.md) — quarterly goal-setting is one of the shipped templates.
