<!-- type: epic -->

# #0022 — Workflow & Tasks Engine — epic overview

## Problem

TalentTrack stores evaluations, goals, sessions, and trial inputs but doesn't *orchestrate* them. Evaluations happen twice a season because no one is nudged. Goal-setting is ad-hoc. Trial-input varies by who remembers.

This epic introduces an orchestration layer: scheduled, visible, low-friction tasks landing in users' inboxes with deadlines. "Systematic development" becomes something the tool actively supports, not just stores evidence of.

Who feels it: HoD (consistency of development), parents (visible evidence of care), coaches (reminded to do what they want to do anyway), players (nudged to self-evaluate), and eventually anyone running recurring academy processes.

## Proposal

A Workflow & Tasks Engine shipped v1 as a narrow *cadence engine* bound to existing TalentTrack entities, architected to extend into a *generic workflow platform* as the roadmap progresses. Sequence placement: Phase 1 ships between #0019 and #0017, making #0017 the engine's first real consumer.

## Scope

Phase 1 = 5 sprints:

| Sprint | File | Focus | Effort |
| --- | --- | --- | --- |
| 1 | `specs/0022-sprint-1-engine-primitives.md` | Engine primitives, schema, task lifecycle | ~15h |
| 2 | `specs/0022-sprint-2-inbox-bell-email-diagnostics.md` | Inbox, bell, email with self-diagnostics | ~12h |
| 3 | `specs/0022-sprint-3-post-match-and-self-eval.md` | Two templates + assignee resolvers | ~13h |
| 4 | `specs/0022-sprint-4-goal-setting-and-hod-review.md` | Two more templates incl. tactical chain | ~12h |
| 5 | `specs/0022-sprint-5-dashboard-config-docs.md` | HoD dashboard, template config UI, docs | ~10h |

**Phase 1 total: ~62h** (driver time, before your personal multiplier).

**Phases 2-4** documented in the idea file (`ideas/0022-*.md`). Specs for those come after Phase 1 ships and real usage shapes the remaining design.

## Out of scope

- **Form builder in v1.** Forms are PHP classes.
- **Multi-step chains as a primitive.** Goal-setting uses a tactical `onComplete` hack in Phase 1; refactored in Phase 2.
- **Polymorphic entity links.** Typed FKs only.
- **External integrations.** No SMS, Slack, etc. in v1.
- **Push notifications.** Until #0019 Sprint 7's PWA.
- **Runtime template creation by academies.** Templates are developer-defined code.
- **Parent digest authoring.** Lives in #0014 (a workflow task triggers the report generation).
- **Task history on player profiles.** Audit-log only via #0021.

## Acceptance criteria

Epic Phase 1 is done when:

- [ ] 4 shipped templates work end-to-end: post-match coach eval, player self-eval, quarterly goal-setting with approval chain, quarterly HoD review.
- [ ] User inbox (`FrontendMyTasksView`) shows tasks with correct visibility per role.
- [ ] HoD dashboard shows aggregated task data.
- [ ] Template config UI lets HoD enable/disable and tune cadence per template.
- [ ] Email notifications work with self-diagnostic banner on failure.
- [ ] Cron self-diagnostic banner detects stalled tasks.
- [ ] Minors-assignment policy is configurable, defaults to age-based switch.
- [ ] Documentation shipped: cron setup, email setup, template library overview.

## Notes

### Depends on

- **#0019 Sprint 2** (FrontendListTable for the inbox).
- **#0019 Sprint 1** (REST patterns, components).
- **#0019 Sprint 4** (FunctionalRoles — assignee resolvers may consume this).

### Blocks

- **#0017 Phase 1** as revised. #0017 Sprint 3 (trial staff input with reminders) is rewritten to consume this engine instead of standing alone. #0017's overall sequencing shifts ~6-8 weeks later as a result.

### Key architectural decisions (all locked in the idea file)

See `ideas/0022-*.md` → "Decisions locked during shaping" for the full list. Summary:
- A-framing (cadence) in v1, architected for B-framing (generic platform).
- TT entities only, typed FK links.
- Developer-defined templates, not runtime creation.
- `AssigneeResolver` primitive handles configurable minors-assignment policy.
- Cron + email self-diagnostics, not infrastructure overhaul.

### Commercial relevance

This epic is a key Academy-tier feature for #0011 monetization. "Systematic development" orchestration is a differentiated pitch vs. competitors (Talento, VoetbalAssist) which store data without orchestrating it.
