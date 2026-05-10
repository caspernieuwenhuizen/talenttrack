# 0091 — List-view compliance follow-up: remaining inline-action violations

## Problem

The v3.110.54 / v3.110.57 work brought the six top-level frontend list/detail surfaces (Players, Teams, People, Goals, Activities, Evaluations) into compliance with the standard documented in `CLAUDE.md` § 5 + § 7:

- List rows are click-through only — no inline `Edit` / `Delete` / `Archive`.
- Detail views carry `Edit` (primary, FAB on mobile) + `Archive` (danger, secondary) in the page-header actions slot.
- Both detail-page actions are cap-gated.

A second-pass audit of every `src/Modules/*/Frontend/` surface (run 2026-05-10 in conversation `2792aa4b`) found a long tail of secondary surfaces that still ship inline mutating actions on list rows. They were left out of v3.110.54 / v3.110.57 because they're either lower-traffic, or the inline action arguably *is* the surface's purpose. This spec captures what's left and the disposition for each.

## Proposal

For each surface below: either retrofit to the v3.110.54 pattern (drop inline actions, add a detail page with `Edit` + `Archive`), or document an exemption. The user's standard is "remove any exceptions unless explicitly documented as a business requirement" — this spec is where the explicit documentation lives.

Group by disposition:

### Group A — Retrofit (inline actions belong on a detail page)

1. **Development tracks** — `src/Modules/Development/Frontend/TracksView.php`
   - Inline `Delete` on each track. Tracks have enough fields (slug, label, description, is_internal, sort) to warrant a detail/edit view. Retrofit to: list rows click-through to `?tt_view=development-tracks&id=N` (build the detail), detail carries `Edit` + `Archive`.
   - Also: per-track ideas show inline status pills with no click-through. The pill stays read-only (it's a status indicator, not an action), but tapping an idea on a track should go to the IdeasRefineView.

2. **Development ideas (board view)** — `src/Modules/Development/Frontend/IdeasBoardView.php`
   - Inline status dropdown on each kanban card mutates state in place. Move the status change onto `IdeasRefineView` (the existing detail page) — board cards become click-through only.
   - The `Refine` link on each card is fine (it's navigation, not Edit).

3. **Custom CSS classes** — `src/Modules/CustomCss/Frontend/FrontendCustomCssView.php`
   - Inline `Edit` + `Delete` on each CSS-class row. Build a detail view at `?tt_view=custom-css&id=N` showing the class definition, with `Edit` + `Archive` in the header. The list becomes a list of clickable class names.
   - Note: this surface is admin-tier only, but the standard applies regardless of tier.

4. **Analytics scheduled reports** — `src/Modules/Analytics/Frontend/FrontendScheduledReportsView.php`
   - Inline `Pause` / `Resume` / `Archive` on each schedule row. Build a schedule detail page with the run history, next-run preview, and the `Edit` (paused/active toggle is part of the form) + `Archive` actions on the header.

### Group B — Documented exemptions (the inline action is the surface's purpose)

5. **Invitations** — `src/Modules/Invitations/Frontend/InvitationsConfigView.php`
   - Inline `Copy link` + `Revoke` on each pending invitation. **Exemption.** Reason: an invitation has no meaningful detail page beyond what the row already shows (the link, the recipient email, the status, the expiry). The whole *point* of the surface is to copy or revoke; forcing a detail-page round-trip for revoke would be friction with no information benefit. Same shape as a "share link" management UI.
   - Caveat: keep the cap gate (`tt_edit_settings`).

6. **Scout assignments** — `src/Modules/Reports/Frontend/FrontendScoutAccessView.php`
   - Inline `Remove` on each assigned player + `Assign` form. **Exemption.** Reason: a scout assignment is a relationship, not a record. The "detail page" of the relationship is the player's profile (which already exists at `?tt_view=players&id=N`). The HoD assigning / unassigning is doing relationship management, not record editing.
   - Same precedent: WordPress's "Add User" / "Remove from team" admin screens — relationship management is inline by convention.

7. **Methodology, Calendar / Planner, Journey, Team chemistry** — already compliant or n/a (read-only visualisations, not record collections).

### Group C — Workflow surfaces (separate UX rules)

8. **Workflow tasks** — `src/Modules/Workflow/Frontend/FrontendMyTasksView.php` + `FrontendTaskDetailView.php`
   - Inline `Snooze 1d` / `Snooze 7d` on each task row. **Exemption.** Reason: tasks are work items in an inbox, not records. The inbox UX (Asana, Todoist, every email client) is inline-action by design. Forcing a detail round-trip for "snooze 1 day" would actively make the surface worse.
   - Detail page (`FrontendTaskDetailView`) has `Submit` (complete the task) — which is the correct primary action for a work item. No `Edit` or `Archive` because tasks aren't records the user maintains; they're work items the workflow engine maintains.

9. **Idea submission queue** — `src/Modules/Development/Frontend/IdeasApprovalView.php`
   - Inline `Approve & promote` / `Reject with note` on each card. **Exemption.** Reason: this is an approval queue (same pattern as a code-review queue or a moderation queue). The whole point of the surface is to dispatch approval decisions in bulk. Detail-per-idea exists separately at `IdeasRefineView` for the cases when the reviewer wants to drill down.

## Wizard plan

**Exemption** — this spec doesn't introduce new record-creation flows. The Group A retrofits add detail/edit views but reuse the existing flat-form path (same as v3.110.54 / v3.110.57 did for Players / Teams / Evaluations). New record-creation work, if any of these surfaces ever gets a wizard, is out of scope here.

## Scope

In:

- Group A retrofits: TracksView, IdeasBoardView (status-on-card), CustomCss, ScheduledReports.
- For each: remove inline mutating actions from list rows, build/extend a detail view at `?tt_view=<slug>&id=N`, place `Edit` + `Archive` (or domain equivalent) in the page-header actions slot via `FrontendViewBase::pageActionsHtml()`.
- For each: REST `DELETE /<resource>/{id}` becomes a soft archive (`archived_at` + `archived_by`). Mirror the shape of `delete_player` / `delete_team` / `delete_eval`.
- Group B + C: write the exemption rationale into `docs/back-navigation.md` (or a new `docs/list-view-actions.md` if the existing doc gets too crowded), so a future audit can grep for exemptions and find the documented reason.

Out:

- New record-creation wizards for any of these surfaces.
- Any UX redesign of the kanban or approval queue beyond removing the inline status mutator.
- Mobile-first migration of any of these views' CSS (tracked separately under #0056).

## Acceptance criteria

- TracksView: list view has no inline `Delete`. New `?tt_view=development-tracks&id=N` detail with `Edit` + `Archive` in the page header. `tt_edit_settings`-gated. `DELETE /development-tracks/{id}` soft-archives.
- IdeasBoardView: kanban card has no inline status mutator. Status changes happen on the IdeasRefineView form. Card is click-through to refine.
- CustomCssView: list view has no inline `Edit` / `Delete`. New `?tt_view=custom-css&id=N` detail with `Edit` + `Archive` in the page header. Cap gate as today.
- ScheduledReportsView: list view has no inline `Pause` / `Resume` / `Archive`. New schedule-detail view with the same actions in the page header.
- Group B + C: rationale written into the back-navigation doc (or new doc) with a clear "this is an exemption because …" section per surface.
- Audit script (manual or scripted) lands clean: every list view in `src/Modules/*/Frontend/` either has no inline mutating row actions, or appears in the documented exemptions list.

## Notes

- The audit that produced this spec lives in conversation `2792aa4b` (2026-05-10). The full inventory is reproducible by re-running the Explore agent prompts there.
- The user's framing in the original ask: "remove any exceptions unless explicitly documented as a business requirement." This spec IS that documentation for Group B + C, and a retrofit punch list for Group A.
- v3.110.54 (#356) and v3.110.57 (#357) are the precedent patterns. Look there for the CSS slot, the JS Archive handler, the `pageActionsHtml()` helper, the soft-archive REST shape.
- Order of work: Group A items are independent and can ship one PR each. Suggested cadence: TracksView + CustomCss are smallest, do those first; ScheduledReports is biggest (build a detail view + run history); IdeasBoardView is in between.
- Do NOT bundle Group A into one giant PR — each surface is a clean, isolated change with its own test surface, and the v3.110.54 / v3.110.57 commits already prove the per-module pattern.
