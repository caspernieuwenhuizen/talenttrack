# TalentTrack v4.2.3 — localStorage draft prompt stripped from flat forms (closes #904)

## Pilot context

A coach opened `?tt_view=activities&action=new`, typed a single character, navigated away, then came back to the same URL. They were prompted:

> "You have unsaved changes from an earlier session — restore?"

They expected this not to happen. As far as the pilot was concerned, wizard autosave was retired in v3.110.86 (#385). Getting an "earlier session" prompt on a flat form they'd barely touched looked like the same autosave behaviour returning under a different name.

## Two mechanisms, one mental model

The pilot's confusion is reasonable. The plugin had two unrelated "remember what you were typing" mechanisms and v3.110.86 only retired one of them:

- **Server-side wizard drafts** (`tt_wizard_drafts` table). Resurrected wizard state after Cancel/Submit. **Retired in v3.110.86 / #385.** Correct fix, narrow scope.
- **Client-side localStorage drafts** (`assets/js/drafts.js`, shipped in #0019 Sprint 1 v3.x). Any form opts in by emitting a `data-draft-key="…"` attribute; the script debounce-saves field values to `localStorage` and offers to restore on next visit.

The new-activity flat form still opted in to the second mechanism, so the prompt still fired even though the wizard side was clean. Two different mechanisms, identical surface confusion to the user.

## What changed

Stripped `data-draft-key` from every production form in `src/`. The eight call sites:

- **`src/Shared/Frontend/FrontendActivitiesManageView.php:637`** — `$draft_key = $is_edit ? '' : 'activity-form'` (variable + attribute emission removed).
- **`src/Shared/Frontend/FrontendPlayersManageView.php:252`** — same shape (`player-form`).
- **`src/Shared/Frontend/FrontendPeopleManageView.php:137`** — same shape (`person-form`).
- **`src/Shared/Frontend/FrontendGoalsManageView.php:314`** — same shape (`goal-form`).
- **`src/Shared/Frontend/FrontendTeamsManageView.php:182`** — `data-draft-key="team-form"` on the create path.
- **`src/Shared/Frontend/CoachForms.php:108`** — eval form, both create and edit branches (`eval-form` / `eval-form-edit-N`).
- **`src/Shared/Frontend/CoachForms.php:478`** — legacy `renderActivityForm` reached via `CoachDashboardView`.
- **`src/Shared/Frontend/CoachForms.php:538`** — legacy `renderGoalForm` reached via `CoachDashboardView`.

`grep -r data-draft-key src/` returns zero hits after this ship.

## What didn't change

- **`assets/js/drafts.js` stays.** ~140 LOC, opt-in by attribute, zero cost when no form opts in. We may want explicit "Save as draft" affordances on long forms in the future (PDP conversation drafts, scout report drafts) — keeping the mechanism dormant is cheaper than reintroducing it later. The file's docblock gains a note recording that no production form opts in as of v4.2.3 so a future re-opt-in lands on documented ground.
- **No localStorage cleanup.** `drafts.js` line 29 (`MAX_AGE_MS = 14 * 24 * 60 * 60 * 1000`) auto-expires saved drafts after 14 days. Worst case a coach with a stale draft gets one final prompt within the next two weeks; after that the entries are gone. Forcing a cleanup is not worth the migration complexity.
- **No copy tweak.** Renaming the prompt to "Restore your local browser draft?" (the option-2 path in the pilot triage) would still surface unexpected autosave to a coach who isn't asking for it. The pilot's confusion isn't about the copy — it's about the behaviour existing at all on a flat form they didn't opt into. Strip the opt-in, not the wording.

## Validation

- Coach opens `?tt_view=activities&action=new` with localStorage manually cleared: no prompt, form is blank.
- Coach types one character, navigates away, comes back: no prompt, form is blank (the localStorage save never fires because the attribute is gone).
- Submit + save flow on every touched form still works (regression check — `drafts.js` was passive; stripping the attribute can't affect REST submission, but verified the create paths still POST correctly).
- The eval edit path (`CoachForms::renderEvalForm` with `$is_edit = true`) still loads the existing row's values from the server, as it always did — `data-draft-key` was unrelated to that flow.

## Why this is `patch`, not `minor`

UX cleanup completing a previously-shipped retirement (#385). No new behaviour, no new contract, no schema change, no REST change. The user-visible diff is "one annoying prompt stops appearing." Patch bump matches the SemVer table in `DEVOPS.md` § "When to bump what".

## Bumped

`talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.2.2` → `4.2.3`.
