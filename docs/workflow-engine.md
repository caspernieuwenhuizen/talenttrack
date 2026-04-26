<!-- audience: admin, dev -->

# Workflow & tasks engine

The workflow engine turns "we should evaluate after every match" from a wish into a scheduled, visible task that lands in someone's inbox with a deadline. It owns the orchestration layer that nudges coaches, players, and the head of development to do their part of systematic development on time.

This page is the high-level overview. Implementation details for cron reliability and email delivery live on their own pages once those surfaces ship.

## What it does (in one paragraph)

Templates describe a recurring or event-driven task ("a post-match coach evaluation, due 72 hours after the session, one task per player evaluated") plus how to find the right assignee ("the team's head coach") and what form they fill in. The engine fires the template on its schedule (cron, event, or manual button), creates one task per (assignee × affected entity), and routes it to the right person's inbox. Completing the task stores the response and runs any follow-up the template defines.

## What it ships in v1

- **Engine + schema (Sprint 1, this release)** — the foundation tables (`tt_workflow_tasks`, `tt_workflow_triggers`, `tt_workflow_template_config`), the `parent_user_id` column on `tt_players`, and the PHP API. No live tasks yet — templates that create them land in Sprint 3.
- **Inbox + bell + email + self-diagnostics (Sprint 2)** — every user with a TalentTrack role gets a "My tasks" surface; an unobtrusive bell shows the open count; emails fire when a task is created or about to be overdue. A self-diagnostic banner flags hosts where WP-cron isn't running reliably.
- **Four shipped templates (Sprints 3-4)**
  - **Post-match coach evaluation** — fires when a match-type session is completed, fans out one task per player evaluated, due in 72 hours.
  - **Player self-evaluation (weekly)** — Sundays 18:00, one task per rostered player, due in 7 days.
  - **Quarterly goal-setting (with approval chain)** — start of each quarter; the player's submission spawns an approval task for their coach.
  - **Quarterly HoD review** — start of each quarter; one task to every Head of Development; 14-day deadline.
- **Dashboard + template config (Sprint 5)** — the HoD overview (completion rate per coach/team, per-template usage) and an academy-admin page to enable/disable templates, override cadence, and override deadlines.

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

## What's not in v1

- No form builder — every form is a PHP class shipped with the plugin or added by a developer.
- No browser push notifications — bell + email only until the PWA pass (separate epic) lands.
- No multi-step chains as a first-class primitive — Phase 1 special-cases goal-setting with a manual `onComplete` hook; Phase 2 generalises this.

## See also

- [Roles and permissions](access-control.md) — for the capability slugs the engine uses.
- [Sessions](sessions.md) — match-type sessions are the trigger for post-match evaluations.
- [Goals](goals.md) — quarterly goal-setting is one of the shipped templates.
