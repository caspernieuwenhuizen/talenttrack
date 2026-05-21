# TalentTrack v3.110.213 — MEDIUM-priority batch closes the #803 lookups audit (closes #845; #803)

## Why

Final ship from the #803 audit punch list. Five small vocabularies (3-4 values each, with 2-3 surfaces total) are too thin to justify their own ship but together close the audit:

| `lookup_type` | Values | Source class |
|---|---|---|
| `invitation_kind` | player, parent, staff | `InvitationKind` |
| `idea_type` | feat, bug, epic, needs-triage | `IdeaType` |
| `scouting_visit_status` | planned, completed, cancelled | `ScoutingVisitsRepository` |
| `scheduled_report_frequency` | weekly_monday, monthly_first, season_end | `ScheduledReportsRepository` |
| `scheduled_report_status` | active, paused, archived | `ScheduledReportsRepository` |

Total: 16 lookup rows + 80 `tt_translations` rows.

## Stored keys stay sacred

All 16 PHP constants stay. Contracts unchanged with `tt_invitations.kind`, `tt_ideas.type`, `tt_scouting_plan_visits.status`, `tt_scheduled_reports.frequency`, `tt_scheduled_reports.status`.

## Labels move

- **InvitationKind::label()** — delegate added.
- **IdeaType::label()** — delegate added.
- **ScoutingVisitsRepository::statusLabel()** — NEW (no canonical label existed; previously inlined in `FrontendScoutingPlanView::statusLabel()`).
- **ScheduledReportsRepository::frequencyLabel()** + **::statusLabel()** — NEW (previously inlined in `FrontendScheduledReportsView::frequencyLabel()`).

All five delegate to `LookupTranslator::byTypeAndName(<type>, $value)` with the canonical English switch retained as a pre-migration fallback. View-side delegates (`FrontendScoutingPlanView::statusLabel`, `FrontendScheduledReportsView::frequencyLabel`) call the canonical repository labels — single source of truth.

## Frontend admin tiles

Five new tiles on Configuration → Lookups. All `show_desc=false` (simple-label vocabularies; the constants names are self-explanatory). `show_color=true` only on the two lifecycle statuses (`scouting_visit_status`, `scheduled_report_status`) where pill colour-coding is useful; the rest get `show_color=false`.

## Migration 0117

Single migration that seeds all five lookup_types in one transaction (pattern mirrors `0098_tournament_lookups_seed.php` and `0116_seed_trial_case_lookups.php`). 16 lookup rows + 80 `tt_translations` rows (16 × 5 locales). Idempotent (`INSERT IGNORE`). Operator-edited rows preserved.

## How to test

1. Apply migrations — confirm `0117_seed_medium_batch_lookups` in `tt_migrations`; 16 lookup rows across 5 types + 80 translation rows exist.
2. Configuration → Lookups → 5 new tiles appear (Invitation kinds, Idea types, Scouting visit statuses, Scheduled report frequencies, Scheduled report statuses).
3. Edit Dutch label for any value → consuming surface renders the new label (e.g. invitations list shows "Speler" / "Ouder" / "Staf"; ideas board shows the translated type; scouting plan + scheduled reports likewise).
4. Pre-migration install renders the 16 English labels via the switch fallbacks.

## Closes the #803 audit

After this ships, `tt_lookups`-routed translations are the standard for every operator-extensible vocabulary in the codebase. Conversion summary:

- **v3.110.205 #836** — InvitationStatus (4)
- **v3.110.207 #841** — GoalApprovalForm decisions (3)
- **v3.110.208 #843** — PdpVerdicts decisions (4)
- **v3.110.209 #839** — TaskStatus (6)
- **v3.110.210 #844** — AudienceType (8 + descriptions)
- **v3.110.211 #840** — IdeaStatus (9)
- **v3.110.212 #842** — TrialCases statuses + decisions (4 + 6)
- **v3.110.213 #845 (this ship)** — MEDIUM batch (5 types × 3-4 values = 16)

Total over the audit: **62 values** in **15 lookup_types** moved from hardcoded PHP constants into operator-editable + translatable `tt_lookups` rows.

Excluded per the audit (LOW priority — structural / framework choices, not operator vocabularies): `CustomFieldsRepository::TYPE_*`, `MethodologyEnums::*`, `TeamBlueprintsRepository::STATUS_* / FLAVOUR_* / TIER_*`, `Comms/MessageType`. These remain code-side enums by design.
