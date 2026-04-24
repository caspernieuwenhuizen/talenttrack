<!-- type: epic -->

# #0017 — Trial player module — epic overview

## Problem

Youth football academies regularly run **trial periods** for prospective players: 2-6 weeks during which the club evaluates whether to offer the kid a place. Today the plugin's only acknowledgment of this is a player status value of `trial` (in `PlayersPage.php:126`) — but there's no structure around it. Coaches and HoDs manage trial cases in spreadsheets, shared emails, and Google Docs. The result: inconsistent evaluation, decisions made on recency bias, letters written from scratch every time, parents getting different experiences, no audit trail.

What's missing:
- **Trial case structure**: who is trialing, what track (standard / scout / goalkeeper), start/end dates, status.
- **Multi-staff input aggregation**: several coaches see a trial player; their evaluations should combine into a single case synthesis for the decision.
- **Decision workflow**: admit / deny-with-encouragement / deny-final, with letter generation.
- **Parent-meeting mode**: a sanitized single-screen view for the decision conversation.
- **Retention / GDPR**: denied players' data retained 2 years, then archivable for deletion requests.

Who feels it: HoD (runs the trials), coaches (contribute to evaluation), prospective players and their parents (experience the process), operations staff (compliance with retention policy).

## Proposal

Six-sprint epic adding a new `src/Modules/Trials/` module with its own schema, admin surfaces, and integration with #0014's report renderer for letter generation.

Key design principles (locked during shaping):
1. **Reuse existing eval categories** — trial input uses the normal evaluation dimensions. Simpler. Data flows naturally into post-admission evaluations.
2. **Unlimited trial extensions with mandatory justification note** — accountability via audit trail rather than arbitrary limits.
3. **Three letter templates** (admit / deny-final / deny-with-encouragement) — each substantively different in tone and content.
4. **Optional acceptance-slip page** per club (toggle in letter template settings).
5. **Retention: 2 years, then archive** (not delete by default) — supports GDPR deletion requests via a separate endpoint.
6. **No new WordPress role for trial staff** — reuse existing Functional Roles module for per-case staff assignment.

## Scope

Six sprints:

| Sprint | File | Focus | Effort |
| --- | --- | --- | --- |
| 1 | `specs/0017-sprint-1-schema-and-case-crud.md` | Schema, case CRUD, track templates basic scaffolding | ~12–15h |
| 2 | `specs/0017-sprint-2-execution-view.md` | Aggregated case view (sessions/evaluations/goals for trial period) | ~8–10h |
| 3 | `specs/0017-sprint-3-staff-input.md` | Staff input flow, visibility rules, aggregation UI, reminders | ~12–14h |
| 4 | `specs/0017-sprint-4-decision-and-letters.md` | Decision panel, 3 letter templates via #0014's renderer | ~14–16h |
| 5 | `specs/0017-sprint-5-parent-meeting-mode.md` | Sanitized fullscreen view for decision conversation | ~5–7h |
| 6 | `specs/0017-sprint-6-template-editor.md` | Track template editor + letter template editor in admin | ~8–10h |

**Total: ~59–72 hours of driver time.**

v1 is Sprints 1–4. Sprints 5–6 are completeness/polish that can ship in a later release.

## Out of scope

- **Public-facing trial application form** (parent fills a form on the club website, creates a player + case). Separate future idea — requires spam protection, email verification, a whole new surface.
- **Multi-academy / multi-location clubs.** Current schema has no location model. Out of scope.
- **Automatic trial-to-admission data migration** (e.g. copy trial evaluations to the player's normal evaluation history on admission). All evaluations sit in the same tables regardless of trial status — no copying needed.
- **Trial-player billing or fee collection.** Monetization is #0011's concern.
- **Scout-specific trial flow** — scouts (introduced by #0014 Sprint 5) can evaluate trial players via normal evaluation input, but don't get dedicated trial workflow.
- **Multi-concurrent trials per player** — model supports it (multiple rows) but no dedicated UX for "field + goalkeeper trial for the same kid." Trial history is shown on the player profile; that's enough.

## Acceptance criteria

The epic is done when:

- [ ] HoD can create a trial case for a player with a track (standard / scout / goalkeeper), start/end dates, assigned staff.
- [ ] Coaches assigned to a trial case can submit their input via the normal evaluation flow; the case view aggregates them.
- [ ] HoD can view the aggregated case view with all sessions/evaluations/goals during the trial window.
- [ ] HoD can record a decision (admit / deny-final / deny-with-encouragement) with justification.
- [ ] Decision triggers generation of the appropriate letter via `PlayerReportRenderer` (#0014 Sprint 3).
- [ ] Parent-meeting mode shows a sanitized single-screen view for the conversation.
- [ ] Tracks and letter templates are editable per club.
- [ ] Retention: denied players' case files retained 2 years, archivable via a GDPR deletion endpoint.

## Notes

### Depends on

- **#0014 Sprint 3** — `PlayerReportRenderer` + `ReportConfig` generalization. Without this, letter generation has nowhere to plug in. Hard dependency.
- **#0019 Sprint 1** — REST foundation, shared components, CSS scaffold.
- **#0019 Sprint 4** — Functional Roles module is consumed here for per-case staff assignment.

This epic cannot start until #0014 Sprint 3 ships. In the SEQUENCE.md ordering, #0014 Part B (which includes Sprint 3) is in Phase 4, and #0017 follows it.

### Cross-epic interactions

- **#0014 Sprint 4** — when the wizard + audience templates ship, trial letters get first-class audience entries. This epic's Sprint 4 adds `TrialEndReportAudience`, `TrialAdmittanceLetterAudience`, `TrialDenialLetterAudience` to the renderer's audience registry.
- **#0013 (Backup + DR)** — backup retention for denied trial cases. This epic's retention logic intersects with backups.
- **#0010 (Multi-language)** — letter templates need translation. Default templates ship in English and Dutch.
- **#0011 (Monetization + privacy)** — privacy statement update to mention trial data handling.

### Hard decisions locked during shaping

- Reuse existing eval categories (no trial-specific dimensions).
- Unlimited extensions with justification note (no arbitrary cap).
- Three letter templates, not toggles.
- Optional acceptance slip per club.
- 2-year retention + archive-not-delete default.
