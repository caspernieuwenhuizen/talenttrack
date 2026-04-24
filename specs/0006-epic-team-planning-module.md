<!-- type: epic -->

# #0006 — Team planning module

## Problem

Coaches plan training activities week by week: "this Tuesday we work on pressing triggers, Thursday we work on finishing." Today this planning lives in:

- Paper notebooks.
- WhatsApp messages to the team.
- Google Calendar events with cryptic titles.
- The coach's head.

None of it connects to the plugin's existing structures: the playing principles that the club tries to teach, the individual players whose development this is meant to support, or the sessions that log what actually happened.

What's missing: a planning tool that:
1. Lets coaches schedule training activities in advance on a calendar.
2. Connects each activity to a **principle** (a coaching-philosophy item — a concept distinct from evaluation categories).
3. Unifies with `tt_sessions` — a planned activity *becomes* a session when the date arrives and attendance is logged.
4. Is lightweight enough that coaches actually use it (no heavy calendar library bloating the frontend).

## Proposal

A five-sprint epic that introduces a **Principles** concept as a new domain, a planning calendar UI built from custom lightweight components, and unification of `tt_sessions` with planned activities. Sessions gain plan-state (draft → scheduled → completed) rather than staying a pure log.

Core decisions locked during shaping:
1. **"Principles" is a new concept, distinct from evaluation categories.** Evaluation categories measure *players*; principles describe *coaching philosophy*. New table `tt_principles`, seeded with a set of standard youth-football principles.
2. **Sessions ARE planned activities.** `tt_sessions` gains plan-state columns. The planner writes to `tt_sessions`; the existing session-edit flow reads/edits the same rows. One source of truth.
3. **Custom calendar UI, no external library.** Aligns with progressive-enhancement ethos. Adds ~2–3 days of UI work over using FullCalendar but avoids a 200KB dependency.

## Scope

Five sprints:

| Sprint | File | Focus | Effort |
| --- | --- | --- | --- |
| 1 | `specs/0006-sprint-1-principles-and-schema.md` | `tt_principles` + session plan-state columns | ~6–8h |
| 2 | `specs/0006-sprint-2-calendar-ui.md` | Custom calendar component, week/month view, drag-drop | ~18–22h |
| 3 | `specs/0006-sprint-3-activity-creation.md` | Schedule activity (a "planned session") with principle linkage | ~8–10h |
| 4 | `specs/0006-sprint-4-session-unification.md` | Wire planned-session to the Sessions module; plan-state transitions | ~8–10h |
| 5 | `specs/0006-sprint-5-coach-summary-and-principle-reporting.md` | Coach weekly summary, principle-coverage report per team | ~8–10h |

**Total: ~48–60 hours of driver time.**

## Out of scope

- **Public parent-facing calendar.** The planner is coach-internal. A "what's on this week" parent-facing view is a separate future idea.
- **Multi-team plan coordination.** Each team's planner is independent. Resolving venue conflicts across teams is out of scope.
- **Venue/facility booking integration.** Field availability is whatever the club tracks elsewhere.
- **Automatic plan generation** ("given these principles, auto-suggest this week's activities"). Human-planned; tool surfaces structure.
- **iCal subscription feeds.** Possible future enhancement; not v1.
- **Principle-based automatic coaching content library.** Clubs bring their own content; the planner is the skeleton.

## Acceptance criteria

The epic is done when:

- [ ] Coach can browse a weekly calendar view of their team's activities.
- [ ] Coach can add a planned activity with principle linkage, time, and notes.
- [ ] Planned activity appears as a draft session in the Sessions module.
- [ ] On the activity date, coach logs attendance; activity transitions to completed.
- [ ] A principle-coverage report shows which principles the team has trained over the last N weeks.
- [ ] All UI works on mobile without horizontal scroll.

## Notes

### Cross-epic interactions

- **#0019** — the calendar UI consumes #0019 Sprint 1's CSS and component conventions.
- **#0017** — trial players benefit from the principle-coverage report (can trial players be evaluated on principles they've been exposed to?).
- **#0018** — separate module, no direct integration. Coexist.
- **#0014** — player profile could eventually show "principles practiced recently" in the "Recent activity" section. Flag for post-v1 enhancement; not in this epic's scope.

### Depends on

- #0019 Sprint 1 (REST foundation, CSS scaffold).
- #0019 Sprint 2 (FrontendListTable for the principle-library admin).
- Ideally #0019 Sprints 2–3 so the Sessions frontend is already in place when this epic's Sprint 4 unifies the models.
