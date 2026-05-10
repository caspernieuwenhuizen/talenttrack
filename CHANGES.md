# TalentTrack v3.110.65 — Team detail: Upcoming activities filters out completed/cancelled; Status column dot now actually renders (CSS was missing)

Two user-reported bugs on the Team detail page (`?tt_view=teams&id=N`).

## (1) "Upcoming activities" included rows the coach had finished or cancelled

The panel filtered on `session_date >= CURDATE()` plus the archived guard but ignored the activity's status. A coach who marked an activity Completed or Cancelled still saw it listed as "Upcoming" until the calendar date passed. The user's expectation: *"Upcoming activities should only show activities today or later that are not completed or cancelled."*

**Fix**: added `AND activity_status_key NOT IN ('completed', 'cancelled')` to the query. Only Planned activities (the default `activity_status_key`) flow through the panel.

Filter source matches the team planner's status-pill source since v3.110.56 — both surfaces agree on what "this activity is done / cancelled" means by reading the same user-facing lookup the coach edits on the form. The legacy `plan_state` column is ignored here for the same reason it was retired from the planner card pill.

## (2) Status column was blank — the CSS that draws the dot was never enqueued

The roster table's `Status` column called `PlayerStatusRenderer::dot( $verdict->color )`, which emits:

```html
<span class="tt-status-dot tt-status-green" aria-label="On track" title="On track"></span>
```

That span gets its 12×12 circle and traffic-light fill from `assets/css/player-status.css`:

```css
.tt-status-dot {
    display: inline-block;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    …
}
.tt-status-dot.tt-status-green { background: #16a34a; }
```

But that stylesheet was only ever enqueued by `TeamPlayersPanel.php` (the wp-admin Teams panel). The frontend Team detail view didn't load it. Net effect: the markup was on the page, the colour-class was on the span, but the span had no `width` / `height` / `display` / `background` — nothing visible.

The user's question: *"what is stopping the system from displaying status? In other words, what needs to be present to show the status for a player in this list?"* — answer was the player-status stylesheet, not enqueued.

**Fix:**

1. Added `PlayerStatusRenderer::enqueueStyles()` — a one-line idempotent `wp_enqueue_style()` for the player-status stylesheet. Centralises the enqueue path so future callers don't have to know the asset URL or registration handle.
2. `FrontendTeamDetailView::renderRoster()` calls `PlayerStatusRenderer::enqueueStyles()` when the roster shows the Status column.
3. `TeamPlayersPanel` (wp-admin) refactored to call the same helper instead of its inline `wp_enqueue_style()` — one source of truth for the asset path.

Going forward: any view that emits `dot()` / `pill()` / `panel()` markup needs to invoke `enqueueStyles()` (or arrange for the stylesheet to be loaded another way). The renderer's docblock now says so explicitly.

## Translations

Zero new msgids.

---

# TalentTrack v3.110.64 — Evaluations tile: missing top-level `Dashboard` breadcrumb on list / new / edit / not-found paths

The Evaluations tile (`?tt_view=evaluations`) destination was missing the top-level `Dashboard` breadcrumb. The user clicked the tile, landed on the list, and had no obvious one-click route back to the dashboard — they had to use the browser back button or retype the URL.

## Root cause

`FrontendEvaluationsView::render()` has five code paths:

1. `?tt_view=evaluations` — list view
2. `?tt_view=evaluations&action=new` — new evaluation form
3. `?tt_view=evaluations&action=edit&id=N` — edit form
4. `?tt_view=evaluations&action=edit&id=N` (not found) — error stub
5. `?tt_view=evaluations&id=N` — read-only detail (delegates to `renderDetail()`)

Path 5's `renderDetail()` correctly calls `FrontendBreadcrumbs::fromDashboard( …, [ viewCrumb('evaluations', …) ] )` (added back in v3.110.4 / v3.110.55). Paths 1–4 went straight to `renderHeader()` without setting a breadcrumb chain.

The miss likely happened during the v3.110.45 breadcrumb sweep — the file appeared compliant on a quick scan because the detail path's existing crumb is visible at lines 316/328, easy to assume the rest of the file followed suit. Subsequent refactors (v3.110.55 edit-mode, v3.110.57 list/detail compliance, v3.110.63 Cancel buttons) preserved the pattern of the surrounding code rather than adding the missing crumb.

## Fix

Every code path now calls `FrontendBreadcrumbs::fromDashboard()` with the appropriate label and `Evaluations` parent crumb before rendering:

| Path | Chain |
|---|---|
| List | `Dashboard / Evaluations` |
| New | `Dashboard / Evaluations / New evaluation` |
| Edit | `Dashboard / Evaluations / Edit evaluation` |
| Not found | `Dashboard / Evaluations / Evaluation not found` |
| Detail | `Dashboard / Evaluations / Evaluation` (unchanged — was already correct) |

Same shape every other tile destination uses. Per the two-affordance contract in `docs/back-navigation.md`: every routable `?tt_view=<slug>` emits the breadcrumb chain plus the `tt_back`-borne pill (when applicable) and nothing else.

## Defensive sweep

Audited every `src/**/Frontend*View.php` for files that have a `render` / `renderHeader` call but no `FrontendBreadcrumbs` reference. Three matches:

- `FrontendMobilePromptView` — mobile-gate screen, intentionally chrome-free.
- `FrontendMyProfileView` — section renderer composed into `FrontendOverviewView`, not directly routable.
- `FrontendTeammateView` — sub-view rendered inside `FrontendMyTeamView`, not directly routable.

All three are documented exemptions per `docs/back-navigation.md`. No other routable view is missing a breadcrumb.

## Translations

Zero new msgids. The labels (`Evaluations`, `New evaluation`, `Edit evaluation`, `Evaluation not found`, `Evaluation`) were already in the `.po`.

---

# TalentTrack v3.110.63 — Cancel button standard: every record-mutating form gets Cancel + Save through one helper

A new always-on standard added to `CLAUDE.md` § 6: every form that creates or edits a record must offer a Cancel affordance alongside Save. A user who has started filling in a form and changes their mind needs an obvious one-click way out that doesn't discard their context — leaving them on a half-filled form with only a Save button is hostile UX.

## What's new

**`CLAUDE.md` § 6 — Save + Cancel on every record-mutating form.** The new section spells out the contract:

- Both create AND edit forms get Cancel + Save side-by-side. Not just edit.
- Cancel is rendered via the shared helper — pass `cancel_url` to `FormSaveButton::render()`. Don't hand-roll a sibling `<a>` below the save button (it'll drift visually, miss the canonical CSS, and break Tab order).
- Cancel target — edit mode: the record's detail page (`?tt_view=<slug>&id=N`). Create mode: the entity's list view (`?tt_view=<slug>`). `tt_back` overrides both via `BackLink::resolve()` when the entry URL captured one.
- DOM order: Cancel first, Save second. CSS reorders the visual layout so Save sits right (where the thumb finds the commit action on mobile). Tab order leads from Cancel → Save (least-committal first).
- Cancel uses `tt-btn-secondary` — visually subordinate to Save, still meeting the 48×48 touch target.

Three explicit exemptions: settings sub-forms (Cancel is meaningless when "leaving without saving" is just navigating away), inline lookup-vocabulary editors (the list itself is the cancel target), and wizard steps (they have their own Previous / Next / Cancel chrome).

The Definition-of-Done checklist gains a "Save + Cancel" subsection. The DoD principle moved from § 7 to § 8; the new principle takes § 6, and "Mandatory reading by task type" shifts to § 7.

**`FormSaveButton::render()` extended with `cancel_url` (and optional `cancel_label`).** When the parameter is set, the helper wraps the save button + a sibling `<a class="tt-btn tt-btn-secondary tt-form-cancel">` inside a new `.tt-form-actions` flex container. Without `cancel_url` the helper returns the bare submit button as before — back-compat for forms that don't mutate a single record.

The new `.tt-form-actions` CSS in `assets/css/public.css` handles gap, alignment, and the flex `order` flip that puts Save on the right while keeping Tab order Cancel → Save.

**Six record-mutating forms retrofitted.** Each now passes `cancel_url` to the helper instead of hand-rolling a Cancel link. Cancel target: `tt_back` wins when present, else the record's detail page in edit mode and the entity list in create mode.

| Form | File |
|---|---|
| New / edit player | `FrontendPlayersManageView::renderForm` |
| New / edit team | `FrontendTeamsManageView::renderForm` |
| New / edit person | `FrontendPeopleManageView::renderForm` |
| New / edit goal | `FrontendGoalsManageView::renderForm` |
| New / edit activity | `FrontendActivitiesManageView::renderForm` |
| New / edit evaluation | `CoachForms::renderEvalForm` |

Two functional-roles forms (role types + assignments) were also routed through the helper. Per § 6 (b) they're exempt lookup-vocabulary editors, but standardising the rendering keeps the `.tt-form-actions` CSS / Tab order identical to the rest of the surface.

## What didn't change

- `FrontendConfigurationView` (5 sub-forms), `FrontendCustomFieldsView`, `FrontendEvalCategoriesView` — exempt per § 6 (a) / (b). They keep the bare-submit pattern.
- The legacy `CoachForms::renderSessionForm` / `renderGoalForm` are reachable only from the dormant `CoachDashboardView` — left untouched.
- Wizard chrome — wizard steps have their own Previous / Next / Cancel from `WizardChrome`, exempt by § 6 (c).

## Translations

No new strings — both `Cancel` and the various `Save` / `Update <entity>` labels were already in the `.po`.

## Files of note

- `src/Shared/Frontend/Components/FormSaveButton.php` — the shared helper.
- `assets/css/public.css` — new `.tt-form-actions` rules.
- `CLAUDE.md` § 6 — the new standard.
- The six retrofitted view files listed above.

---

# TalentTrack v3.110.62 — Hotfix: stray conflict markers in `FrontendTeamPlannerView.php` from the v3.110.58 rebase

The v3.110.58 PR (My activities — empty list for players + post-save redirect to referring view, #353) touched `src/Modules/Planning/Frontend/FrontendTeamPlannerView.php` to add `BackLink::appendTo()` on the activity-card click-through URL. That same file had been rewritten in PR #349 (v3.110.56 — team planner status pill + range selector) which merged earlier on the same day. The rebase of #353 onto post-#349 main produced a conflict in `renderActivityCard()` that didn't get resolved before the rebased commit was pushed and merged.

## Symptom

Every page load that touched the planning module fataled with:

```
PHP Parse error: syntax error, unexpected token "<<" in
src/Modules/Planning/Frontend/FrontendTeamPlannerView.php on line 261
```

The lines starting at 261 were the `<<<<<<< HEAD` / `=======` / `>>>>>>> 1b1429f (...)` markers themselves, sitting in the middle of `renderActivityCard()`'s function body — PHP saw them as an unrecognised operator and bailed.

## Knock-on impact (none externally)

The `release.yml` workflow's `lint` job ran on the v3.110.58 / .59 / .60 / .61 main pushes and **failed** at the PHP-syntax-lint step every time. The `release` job is gated on `needs: [lint]` AND `if: startsWith(github.ref, 'refs/tags/v')`, so neither the build-ZIP step nor the GitHub Release creation ran. Net effect: the four releases merged into main but no release artifact ever existed for any of them. No customer install pulled a broken ZIP because no ZIP was published.

The v3.110.61 tag pushed earlier today was about to produce the first broken ZIP — caught it before that completed. The release.yml run for that tag will fail at lint; this v3.110.62 supersedes it.

## Fix

Resolved the conflict by combining both intents:

- **From v3.110.56 (PR #349)**: status pill driven by `activity_status_key` (the user-facing lookup) via `LookupPill::render('activity_status', …)` — the original v3.110.56 fix for "every card shows Completed".
- **From v3.110.58 (PR #353)**: `BackLink::appendTo()` wraps the click-through URL so the activities form's post-save redirect can return to the planner — the original v3.110.58 fix for "coach edits from planner, lands on activities list".

Both changes were intended; the merge just dropped one of them when the conflict markers were committed unresolved.

## Translations

Zero new msgids.

---

# TalentTrack v3.110.61 — My evaluations: category + subcategory breakdown now shows on sub-only evaluations

The player's "My evaluations" tile (`?tt_view=my-evaluations`) is supposed to render, per evaluation:

- A circular badge with the overall rating.
- Inline pills for each main category (Technical / Tactical / Physical / Personality, or whatever the academy seeded).
- A "Show detail" toggle that reveals subcategory ratings grouped by main.

The user reported that on their evaluations only the overall badge rendered — no main pills, no toggle.

## Root cause

The view walked `$full->ratings` directly and added a main pill only when the rating row had `category_parent_id IS NULL`. The plugin's evaluation model is **either / or** (per `EvalRatingsRepository` docblock — *"for any given (evaluation, main_category), the coach either entered a direct main rating, OR rated subcategories, OR did neither"*). When the coach rated only subs — the common case for detailed evaluations — `$main_pills` stayed empty:

- Inline pills row: empty → not rendered.
- Detail toggle: rendered (because `$sub_groups` was non-empty), but the heading inside used `$main_pills[$main_id]['label']` which was `''`, so the "Technical" / "Tactical" / etc. headings disappeared too.

End user saw an evaluation with just a circular badge and no breakdown anywhere.

## Fix

Switched the main-pill source from "walk rating rows" to `EvalRatingsRepository::effectiveMainRatingsFor( $eid )`, which returns one entry per active main category with its effective value (direct value OR rolled-up subcategory average — `null` only when neither was rated). Mirrors the pattern the coach-side admin view (`EvaluationsPage::render_view`) and the radar-chart consumers already use, so player-facing and coach-facing surfaces now agree on what shows.

Sub-group walking still happens against `$full->ratings` so the per-sub values surface raw (not as the rollup average). Detail-toggle heading now reads from a separate `$main_labels` map seeded from the same `effectiveMainRatingsFor` call, so it always has the right label.

## Knock-on improvements

- Sub-category labels now go through `EvalCategoriesRepository::displayLabel( $name, $entity_id )` with the entity id (the second arg). Previously the label call passed only the name, which falls back to the gettext path. With the entity id it can hit `tt_translations` first — so academies that translated category labels via the translations layer (rather than by editing the seeded English) see their custom translations on this surface too.

## Translations

Zero new msgids.

---

# TalentTrack v3.110.60 — My PDP: self-reflection 2-week gate now timezone-correct + REST endpoint enforces same window

The v3.110.24 release added a view-side gate that hid the player's self-reflection textarea until 14 days before the conversation's `scheduled_at`. The user reported the form still opened too early on their install. Two fixes — one user-visible (TZ bug), one defense-in-depth (REST hardening).

## (1) Timezone bug — gate opened a few hours early on non-UTC servers

`selfReflectionWindowOpen()` parsed `scheduled_at` (stored as a UTC datetime via `gmdate(...)`) with PHP's `strtotime()` — which interprets bare datetime strings in the **server's local TZ**. On any non-UTC install (the user is on Europe/Amsterdam, UTC+2 in summer), the parsed timestamp was offset by the TZ delta and the window opened earlier than the 14-day boundary intended.

**Fix**: parse with an explicit `UTC` suffix.

```php
$ts = strtotime( $scheduled . ' UTC' );
```

Same one-line fix applied in both `FrontendMyPdpView::selfReflectionWindowOpen()` and the matching helper now in `PdpConversationsRestController` (see (2) below).

## (2) REST endpoint enforces the same gate

`PdpConversationsRestController::patch` accepted `player_reflection` writes whenever the linked player called the endpoint, regardless of timing. The view-side gate hid the textarea past the window, but a bookmarked POST or saved API request would still succeed. Added the same window check on the player path — returns 403 with `window_closed` when the conversation's `scheduled_at` is more than 14 days out (or unset). Coach + admin paths bypass the gate (they may legitimately backfill reflections on behalf of a player).

The gate helper is duplicated between `FrontendMyPdpView` and the REST controller rather than extracted to a shared service. Five lines, two surfaces, deliberate — the rule may diverge over time and a single shared helper would mask that intent.

## Translations

One new NL msgid for the REST error wording. The view-side string from v3.110.24 stays unchanged.

---

# TalentTrack v3.110.59 — Onboarding pipeline: + New prospect now opens a wizard, kanban replaces count strip, fixed double-counting in Invited

Three issues on `?tt_view=onboarding-pipeline` from a tile-by-tile pilot review.

## (1) "+ New prospect" no longer creates a task as a side-effect

Clicking the button POSTed to `/prospects/log`, which dispatched a `LogProspectTemplate` workflow task and redirected the user into that task's form (parking them under "My tasks" in the breadcrumb).

**Fix**: replaced with a four-step wizard at `?tt_view=wizard&slug=new-prospect`:

1. **Identity** — first / last / DOB / current_club. Duplicate detection runs here.
2. **Discovery** — `discovered_at_event` + `scouting_notes`.
3. **Parent contact** — name / email / phone / consent. At least one of email/phone is required; consent checkbox is required when any contact data is captured.
4. **Review** — confirm + create.

On submit the review step inserts the `tt_prospects` row directly via `ProspectsRepository::create()`, dispatches `InviteToTestTrainingTemplate` for the HoD with the fresh `prospect_id` on the task context, and redirects back to `?tt_view=onboarding-pipeline`.

The chain effectively starts at "Invite" rather than at "LogProspect" — the wizard IS the form that LogProspect's task wrapped, so creating that task to capture data the wizard already collected was a redundant detour.

`LogProspectTemplate` and the `/prospects/log` REST endpoint stay in place for backward compat. The dead `assets/js/frontend-prospects-log.js` (53 lines) is deleted.

This brings prospects into compliance with CLAUDE.md §3 (wizard-first record creation).

## (2) Standalone view rebuilt as a kanban (was a count strip)

`FrontendOnboardingPipelineView` rewrote to render its own kanban: six columns (Prospects / Invited / Test training / Trial group / Team offer / Joined), each with a count and a stack of prospect cards (name, age / current club, discovered date, stage-specific context line, click-through to the actionable surface). Stale badge for cards >30d past due. Mobile collapses the six columns into a vertical stack at <720px.

New `assets/css/components/onboarding-pipeline.css`. The dashboard widget keeps its compact count-strip rendering for tile placement.

## (3) "Prospects = 0, Invited = 2" — double-counting fixed

The widget summed task rows across `invite_to_test_training` AND `confirm_test_training` templates without deduplication, so a single prospect with both tasks open at once showed as 2 in the Invited column.

Rewrote `OnboardingPipelineWidget::computeStageCounts()` to classify every prospect into exactly one stage with a single SQL query. Stage priority (Joined > Team offer > Trial group > Test training > Invited > Prospects) matches `FrontendOnboardingPipelineView::classifyProspect()`, so the widget and the kanban now agree on every count. Trial-group count also moved from "every `tt_trial_cases` row" to "prospects with `promoted_to_trial_case_id` set".

## Translations

Eight new NL msgids: kanban context lines, age format, parent-step error / consent copy.

## Documentation

`docs/onboarding-pipeline.md` and `docs/nl_NL/onboarding-pipeline.md` are new. `docs/wizards.md` + Dutch counterpart updated — slug list now mentions `new-person`, `new-team-blueprint`, `new-prospect`.

---

# TalentTrack v3.110.58 — My activities: empty-list bug for players + post-save redirect back to team planner

Two issues on `?tt_view=my-activities` from a tile-by-tile pilot review.

## (1) Empty-list bug — every logged-in player saw "No activities recorded for you yet."

A player whose team had multiple active activities (Planned, Completed, all visible in the team planner) saw an empty list on `?tt_view=my-activities`.

**Root cause** in `ActivitiesRestController::list_sessions`:

1. The endpoint applies a coach-team scope filter: `WHERE s.team_id IN (<teams the caller head-coaches>)`.
2. If the caller has zero head-coach teams AND isn't a global-read persona (scout / HoD / academy admin), the controller short-circuits with `return RestResponse::success(['rows' => [], 'total' => 0, ...])`.
3. **A logged-in player has zero head-coach teams.** The early-return fires for every player on every my-activities call.
4. The `filter[player_id]` predicate further down (which would correctly match `s.team_id IN (player's teams) OR EXISTS attendance row`) never gets a chance to execute.

**Fix**: detect player-scoped requests up front and bypass the coach-team scope filter for them.

```php
$is_player_scoped = ! empty( $filter['player_id'] )
    && self::callerCanReadAsPlayerOrParent( (int) $filter['player_id'] );

if ( ! $is_player_scoped
     && ! QueryHelpers::user_has_global_entity_read( get_current_user_id(), 'activities' ) ) {
    // existing coach-team scope filter
}
```

`callerCanReadAsPlayerOrParent()` mirrors the logic `can_view()` already uses: the caller is allowed if they ARE the linked player, OR if they're a registered parent of that player (`tt_player_parents` row exists).

## (2) Post-save redirect — coach editing from team planner now lands back on the planner

When a coach opened the activities form via the team planner — clicking "+ Schedule activity", "+ Add" on an empty day, or an activity card — and saved, they landed on the generic activities list (`?tt_view=activities`) instead of being returned to the planner. Lost context.

The reusable `BackLink` infrastructure (`tt_back` URL parameter; `BackLink::appendTo($url)` and `BackLink::resolve()`) already exists for this. The activities form just wasn't wired in.

**Fix:**

- **Team planner** (`FrontendTeamPlannerView`): every activity-form URL now goes through `BackLink::appendTo()`, capturing the planner URL the user is on (`+ Schedule activity` toolbar, `+ Add` empty-day links, activity card click-through).
- **Activities form** (`FrontendActivitiesManageView::renderForm`): reads `BackLink::resolve()` and, when a back-target is present, emits `data-redirect-after-save-url="<back URL>"` on the `<form>` element. The existing `public.js` save handler already honours this attribute (1.2s success delay, then `window.location.href = url`).

When no `tt_back` is in the URL (i.e., the user opened the form directly from the activities list), the form falls back to its existing `data-redirect-after-save="list"` behaviour, so the activities-list-side flow is unchanged.

## Translations

Zero new msgids.

---

# TalentTrack v3.110.57 — Evaluations list/detail align with the v3.110.54 view-only-list / Edit+Archive-on-detail pattern

A user audit of the application's list views found the **Evaluations** module out of sync with the rest of the dashboard. The Players, Teams, People, Goals, and Activities surfaces were brought into compliance in v3.110.54: list rows are click-through only, and Edit + Archive live on the record's detail page in a page-header actions slot (FAB on mobile, top-right buttons on desktop). Evaluations still had an inline ✕ delete button per row, no Edit affordance anywhere, and no Archive button on the detail page. This release closes those gaps.

## What changed

**Evaluations list (`?tt_view=evaluations`)**

- The inline `✕` delete button is gone from every row — deletion now happens from the detail page's `Archive` action.
- The redundant `Open` button is gone too. Every row cell is already a hyperlink: the Date now opens the eval detail (it was the only plain-text cell), Player / Team / Coach link to their respective detail pages, the Average rating opens the eval detail.

**Evaluations detail (`?tt_view=evaluations&id=N`)**

- The page header now carries an **Edit** action (primary, becomes a circular FAB bottom-right on mobile via `.tt-page-actions__primary`) and an **Archive** action (danger-styled secondary, hidden on mobile by the slot CSS).
- Both actions are gated on `tt_edit_evaluations`. Users without the cap see the read-only detail unchanged.
- Archive is wired through the generic `tt-frontend-archive-button.js` handler — same pattern used for Players, Teams, People, Goals, Activities. Confirm prompt → DELETE `evaluations/{id}` → redirect back to the list.

**Edit mode (new — `?tt_view=evaluations&action=edit&id=N`)**

- Reuses `CoachForms::renderEvalForm` with a new optional `?object $existing_eval` argument. When set, the form switches to PUT against `/evaluations/{id}`, every header field pre-fills from the row, every existing rating is pre-populated from `tt_eval_ratings`.
- The player picker collapses to a hidden input + an `Editing evaluation of {Player}` caption — swapping the player mid-edit would silently re-attach the ratings to a different subject.
- The form's "Save" label flips to "Save changes" so the user knows they're editing rather than creating.

**REST — DELETE `/evaluations/{id}` is now a soft archive**

- The endpoint sets `archived_at = NOW(MySQL)` + `archived_by = current_user_id` instead of hard-deleting the eval and its ratings. The read-side queries already filter `archived_at IS NULL`, so the eval simply disappears from list / detail without losing the row or the linked ratings.
- The response payload changes from `{ deleted: true }` to `{ archived: true, id: N }`. The only consumer of this endpoint inside the plugin was the now-removed inline `✕` button, so no other UI changed.
- This mirrors the shape of `delete_player` and `delete_team`, which were converted to soft-archive in v3.89.x.

## What didn't change

- The capability stays `tt_edit_evaluations`. The other modules use `tt_edit_*` for edit and Archive-on-archived-rows is admin-only, so we kept the cap consistent.
- The audit also flagged inline action violations on **Development tracks**, **Custom CSS classes**, and **Analytics scheduled reports**, plus borderline cases on **Invitations** (revoke) and **Scout assignments** (remove). These are tracked for a follow-up — the inline actions there are *the entire purpose* of the surface (revoking an invite has no detail page; CSS classes are a single-page editor by design), so they may end up documented exemptions rather than retrofits.

## Translations

One new NL msgid:

| msgid | msgstr |
|---|---|
| `Archive this evaluation? It will be hidden but the data is preserved.` | `Deze evaluatie archiveren? Ze wordt verborgen maar de data blijft bewaard.` |
| `Editing evaluation of %s.` | `Evaluatie van %s aan het bewerken.` |
| `Save changes` | `Wijzigingen opslaan` |
| `Edit evaluation` | `Evaluatie bewerken` |

## Files of note

- `src/Infrastructure/REST/EvaluationsRestController.php` — `delete_eval` becomes a soft archive.
- `src/Shared/Frontend/CoachForms.php` — `renderEvalForm()` gains the `$existing_eval` parameter and prefill logic.
- `src/Shared/Frontend/FrontendEvaluationsView.php` — new edit route, dropped inline Delete + Open, Date cell click-through, page-header Edit + Archive on detail.
- `languages/talenttrack-nl_NL.po` — four new msgids.

---

# TalentTrack v3.110.56 — Team planner: status pill now reflects the activity's edited status; new range selector

Two issues on `?tt_view=team-planner`. The pilot operator surfaced both during a tile-by-tile review of the dashboard.

## (1) Status bug — every card showed "Completed"

Every activity card on the planner showed the same "Completed" pill regardless of what the coach actually set the status to in the activities form (Planned / Completed / Cancelled).

**Why**: the planner card was reading the internal `plan_state` column instead of the user-facing `activity_status_key` lookup. `plan_state` (added in migration 0073 for the planner) defaults to `'completed'` on every row created via the non-planner activities form. So unless the activity was created via the planner's own `+ Schedule activity` flow (which sets `plan_state='scheduled'`), the planner displayed "Completed" for it — even when the coach had explicitly set the form's *Status* to `Planned`.

**Fix**: the activity card now renders `LookupPill::render('activity_status', …)` — the same colour-coded pill the activities list (`?tt_view=activities`) and the wp-admin Activities page already use. The planner and the activities list now share one source of truth for status: the `activity_status_key` value the coach sees and edits on the form.

While there:

- The planner's "exclude cancelled activities" filter moved from `WHERE plan_state <> 'cancelled'` to `WHERE activity_status_key <> 'cancelled'`. Coach-cancelled activities now actually drop out of the grid.
- The bottom *"Principles trained — last 8 weeks"* panel filter moved from `plan_state IN ('completed','in_progress')` to `activity_status_key = 'completed'`. Same reason — gate on the field the coach sees and edits.
- The card's left-border colour is keyed on `activity_status_key` (`tt-planner-state-{planned|completed|cancelled}`), mirroring the `meta.color` seeded for the `activity_status` lookup in migration 0049 (yellow / green / red).

The legacy `plan_state` column stays on the row — the activities REST endpoint still accepts a `plan_state` filter, and the planner's `+ Schedule activity` flow still passes `plan_state=scheduled` through the create form. Nothing in the schema was removed.

## (2) Range selector — coaches can plan more than one week at a time

The planner only ever showed one week. Coaches asked to see longer windows — for working out a four-week training block, or eyeballing the whole season's coverage at once.

The toolbar gains a **Show** dropdown with five options:

| Option | Window | Prev/next steps by |
|---|---|---|
| One week (default) | 7 days from the resolved Monday | 7 days |
| Two weeks | 14 days | 14 days |
| Four weeks | 28 days | 28 days |
| Eight weeks | 56 days | 56 days |
| Full season | the current `tt_seasons.is_current` row, snapped to whole weeks | (no prev/next — replaced with the season name) |

Multi-week ranges stack consecutive 7-column week grids vertically, each with a *"Week of Mon J — Sun K"* header. Mobile collapses each week to a one-column day stack as before.

The full-season range:

- Reads `SeasonsRepository::current()`.
- Snaps the season's `start_date` back to the Monday of its containing week and the `end_date` forward to the Sunday of its containing week, so the rendered weeks line up cleanly.
- Replaces the prev/next nav with the season name (`Season: 2025/2026`).
- Falls back to a single-week view if no `is_current` season row exists.

The `range` URL parameter round-trips, so a bookmarked planner URL like `?tt_view=team-planner&team_id=12&range=4weeks&week_start=2026-05-04` reproduces the same window when reopened.

## Translations

Nine new NL msgids:

| msgid | msgstr |
|---|---|
| `Show` | `Toon` |
| `One week` | `Eén week` |
| `Two weeks` | `Twee weken` |
| `Four weeks` | `Vier weken` |
| `Eight weeks` | `Acht weken` |
| `Full season` | `Volledig seizoen` |
| `Previous %d week` (singular) / `Previous %d weeks` (plural) | `Vorige %d week` / `Vorige %d weken` |
| `Next %d week` (singular) / `Next %d weeks` (plural) | `Volgende %d week` / `Volgende %d weken` |
| `Week of %1$s — %2$s` | `Week van %1$s — %2$s` |

`Season: %s` already existed in the NL .po (translated as `Seizoen: %s`); the planner reuses it.

## Documentation

`docs/team-planner.md` and `docs/nl_NL/team-planner.md` are new — the planner had no dedicated doc before this release. The Dutch version is a full translation, not a placeholder.

---

# TalentTrack v3.110.55 — Hotfix: `+ New blueprint` white-screened on every install since v3.98.0

Pilot operator clicked **+ New blueprint** on `?tt_view=team-blueprints` and got a critical WP error / white screen instead of the wizard.

## Root cause

The Team Blueprint wizard's first step — `src/Modules/Wizards/TeamBlueprint/SetupStep.php` — was missing the `submit()` method that `WizardStepInterface` declares. PHP refuses to instantiate any concrete class with an unimplemented abstract method:

```
PHP Fatal error: Class TT\Modules\Wizards\TeamBlueprint\SetupStep contains 1 abstract method
and must therefore be declared abstract or implement the remaining methods
(TT\Shared\Wizards\WizardStepInterface::submit) in SetupStep.php on line 15
```

The fatal fires at the moment `NewTeamBlueprintWizard::steps()` returns the step list (the framework calls `new SetupStep()` to populate the array). The wizard view bails on construction; the user sees the WP critical-error template.

The bug landed in **v3.98.0** (`feat(v3.98.0): Team Blueprint Phase 1 — drag-drop lineups`, PR #251), which introduced `SetupStep` without the `submit()` method — and stayed broken through v3.99.0 (Phase 2 added the squad-plan flavour but kept the missing method) until now. The wizard route stayed unused for ~12 releases because the team-blueprint surface was driven by the list page's "+ New blueprint" affordance, which only wired into the wizard route via `WizardEntryPoint::urlFor()`. The route's first real-world click is what surfaced the regression.

## Fix

Added a no-op `submit()` to `SetupStep`. The framework only calls `submit()` on the terminal step (the one whose `nextStep()` returns `null`) — `SetupStep::nextStep()` always returns `'review'`, so `submit()` is a placeholder required by the interface but never invoked. `ReviewStep::submit()` continues to do the actual `tt_team_blueprints` insert + the editor-redirect.

```php
public function nextStep( array $state ): ?string { return 'review'; }

public function submit( array $state ) { return null; }
```

## Defensive sweep

Audited every `*Step.php` under `src/Modules/Wizards/`: the seven other multi-step wizards in the codebase (`new-player`, `new-team`, `new-evaluation`, `new-goal`, `new-activity`, `new-person`, plus the `new-prospect` shipped in #351) all implement `submit()` on every step. `SetupStep` was the only offender.

---

# TalentTrack v3.110.54 — List-header actions slot: `+ New` / `Edit` / `Archive` on the page header, FAB on mobile, drop in-row Edit / Delete

Pilot operator UX feedback on the list rows raised three things:

1. The in-row `Edit` / `Delete` buttons crowded mobile tables, put the destructive action one fat-finger away from Edit, and duplicated affordances better placed elsewhere.
2. Edit belongs on the detail page, where the user has full context (current values, related fields), not on a scanning surface.
3. The big `+ New …` button at the top of every list could be a discreet `+` icon, top-right on desktop, FAB bottom-right on mobile — saves vertical space and matches the iOS / Android convention plus the existing scout-mobile precedent in #0081.

This release ships the full answer across all five list views — Players, Teams, People, Goals, Activities — and adds Edit + Archive to the matching detail pages.

## What landed

### `.tt-page-head` + `.tt-page-actions` CSS slot

Desktop: action buttons right-aligned next to the page H1.

Mobile (≤ 767px): primary action (`.tt-page-actions__primary`) lifts to a 56×56 FAB anchored bottom-right, label visually hidden but readable to screen readers, icon-only. Secondary actions (`.tt-page-actions__secondary`) hidden — they're reachable via the entity's dashboard tile or admin sub-route. Touch-target compliant (≥ 48px), respects `env(safe-area-inset-bottom)` so iOS users with a home indicator don't lose the button under the bar.

New `.tt-btn-danger` variant for destructive actions: muted red on white at rest, solid red on hover.

### `FrontendViewBase::renderHeader()` extended

Accepts an optional second argument: pre-built actions HTML. When provided, the H1 + actions render inside a `<header class="tt-page-head">`. Otherwise unchanged. New `FrontendViewBase::pageActionsHtml( array $actions )` helper turns a structured action array into the slot HTML — each action accepts `label` / `href` / `primary` / `icon` / `variant` / `cap` / `confirm` / `data_attrs`.

### `frontend-archive-button.js` — generic Archive handler

Small JS file (~80 lines) wired up in `FrontendViewBase::enqueueAssets()`. Listens for clicks on `[data-tt-archive-rest-path]` elements, runs a `confirm()` dialog, fetches `DELETE /wp-json/talenttrack/v1/<rest_path>` with `X-WP-Nonce`, redirects to the list URL on success. No-op on pages that don't render an Archive button.

### Five list views refactored

`FrontendPlayersManageView`, `FrontendTeamsManageView`, `FrontendPeopleManageView`, `FrontendGoalsManageView`, `FrontendActivitiesManageView`.

| List | Was | Now |
|---|---|---|
| Row actions | `Edit` / `Delete` (and `Rate card` on Players) | Empty (`Rate card` kept on Players — different destination) |
| Primary CTA | `<p><a class="tt-btn-primary">+ New …</a></p>` above table | `+ New …` in page-header slot, FAB on mobile |
| Secondary CTAs (Players, Teams) | Inline `<a>` next to primary | Header-secondary, desktop-only |

The clickable name / title cell remains the only row affordance — and the right one, since it goes through `RecordLink::detailUrlForWithBack()` which captures `tt_back` so the destination shows the contextual back-pill above the breadcrumb.

### Five detail views gain Edit + Archive

`FrontendPlayerDetailView`, `FrontendTeamDetailView`, `FrontendPersonDetailView`, `FrontendGoalsManageView::renderDetail`, `FrontendActivitiesManageView::renderDetail`.

- **Edit** (primary, `✎` icon): routes to the existing edit form (`?tt_view=…&id=N&action=edit`). FAB on mobile, top-right button on desktop. Cap-gated.
- **Archive** (danger variant, secondary class): wires through `tt-frontend-archive-button.js` to REST DELETE `<entity>/{id}` with a contextual confirm() message and redirect to the list on success. Desktop-only on mobile (visible inline alongside Edit on tablet+ / desktop).

Goals + Activities previously rendered an inline `<a class="tt-btn">Edit</a>` below the detail `<dl>` — that's gone; Edit lives in the header alongside Archive now.

## What this does NOT change

- The forms (`renderForm()` paths) are untouched. Saves still post to the same REST endpoints with the same redirect-after-save behavior.
- The list table itself (`FrontendListTable::render()`) — only the row-actions array passed in shrinks. Filters, search, sort, pagination all unchanged.
- Bulk operations — the codebase doesn't have multi-select / bulk-action affordances today; if power-user efficiency loss becomes a complaint, that's the right destination, not putting per-row actions back. Tracked as a follow-up if it surfaces.
- Other detail views (PdpManage, ScoutAccess, etc.) keep their existing patterns; the `pageActionsHtml` helper is opt-in, not mandatory.

## Translations

Four new NL msgids — the entity-specific Archive confirm messages:

| msgid | msgstr |
|---|---|
| `Archive this player? They can be restored later by a site admin.` | `Deze speler archiveren? Een site-admin kan de speler later herstellen.` |
| `Archive this team? It will be hidden but the data is preserved.` | `Dit team archiveren? Het wordt verborgen maar de data blijft bewaard.` |
| `Archive this goal? It will be hidden but the data is preserved.` | `Dit doel archiveren? Het wordt verborgen maar de data blijft bewaard.` |
| `Archive this activity? It will be hidden but the data is preserved.` | `Deze activiteit archiveren? Ze wordt verborgen maar de data blijft bewaard.` |

Other labels (`Edit`, `Archive`, `New player`, `New team`, `New person`, `New goal`, `New activity`, `Import from CSV`, `Import players from CSV`, `Rate card`, `Archive this person? They can be restored later by a site admin.`) already in the `.po` from prior list-view shipments.

---

# TalentTrack v3.110.48 — Drop redundant "View" row actions from Players / People / Teams list tables

Pilot operator pointed out that the player list's "View" row action does the same thing as clicking the player name in the cell — both route to `?tt_view=players&id={id}` (FrontendPlayerDetailView). The "View" button was visual noise and, worse, strictly worse UX than the name click.

## Why "View" was strictly worse

| Path | URL | tt_back captured? | Destination renders |
|---|---|---|---|
| Click player name in cell | `?tt_view=players&id=N&tt_back=<list>` | Yes (via `RecordLink::detailUrlForWithBack`) | Breadcrumb chain + `← Back to Players` pill |
| Click "View" row action | `?tt_view=players&id=N` | No (plain `add_query_arg`) | Breadcrumb chain only |

Both land on the same detail view, but only the name click captures the back-target needed to render the contextual back-pill on the destination. Removing the "View" row action removes the duplicate AND nudges users into the better-UX path.

## What landed

Three list views had the redundant `view` row action:

- `FrontendPlayersManageView` — dropped (kept `edit`, `card` for the legacy rate-card view, `delete`)
- `FrontendPeopleManageView` — dropped (kept `edit`, `delete`)
- `FrontendTeamsManageView` — dropped (kept `edit`, `delete`)

Goals and Activities were already on the correct pattern — title clicks handle view, only `edit` and `delete` row actions exist. After this release, all five list tables follow the same convention.

## What this does NOT change

- The `Rate card` row action on the Players list. That's a different destination (`?tt_view=players&player_id={id}` → legacy `FrontendPlayersManageView::renderDetail`, not `FrontendPlayerDetailView`), so keeping it as a separate row action is correct.
- Translatable strings — zero new msgids.

---

# TalentTrack v3.110.46 — Document the two-nav-affordance contract + close residual violations

The "exactly two navigation affordances per routable view" rule — breadcrumb chain + `tt_back`-borne pill, nothing else — was applied across the codebase in v3.110.41 and v3.110.45 but was not explicitly written down anywhere. New views or refactors had no documented standard to follow, so anti-patterns kept creeping back. This release codifies the contract and closes the residual violations the doc-and-sweep surfaced.

## What landed

### Documentation

`docs/back-navigation.md` and `docs/nl_NL/back-navigation.md` gain an explicit **"The contract — two nav affordances, no more, no less"** section at the top:

1. **Breadcrumb chain** ending at `Dashboard` — canonical hierarchy. Rendered via `FrontendBreadcrumbs::fromDashboard()` (or a static `breadcrumbs()` override on `FrontendViewBase`).
2. **Contextual `← Back to …` pill** — `tt_back`-borne, auto-rendered above the chain when the entry URL captured a back-target. Renders nothing when there's no back-target — that's intentional, the breadcrumb chain is sufficient.

The doc names what's forbidden (no `← Back to dashboard` button, no `← Back to <list>` button, no `FrontendBackButton` analogue, no per-view back-link that sidesteps the chain + pill) and the small set of exempt views (dashboard root itself, pre-login flows, sub-views composed into other views).

### CLAUDE.md

A new always-on principle at **§ 5 — Two nav affordances per view, no more, no less** summarizes the rule and points at the back-navigation doc. The Definition-of-done checklist gains three items:

- Confirm `FrontendBreadcrumbs::fromDashboard()` is called on every code path, including permission-denied early-returns.
- Confirm no hardcoded back-affordances (no `FrontendBackButton`, no "Back to dashboard"/"Back to &lt;list&gt;" anchor tags).
- Confirm cross-entity links use `RecordLink::detailUrlForWithBack()` (or `BackLink::appendTo()` for raw URL builders) so the destination view's back-pill renders.

The mandatory-reading-by-task-type table gains a row pointing frontend-nav PRs at the doc.

### Residual violations cleaned up

Eight hardcoded `← Back to <X>` anchor tags removed:

| File | Removed labels |
|---|---|
| `FrontendPdpManageView` (3) | `← Back to list`, `← Back to file` (×2) |
| `FrontendTeamBlueprintsView` (3) | `← Back to team picker`, `← Back to blueprints`, `← Back to lineup view` (the last was a heatmap toggle masquerading as a back link; relabeled to `Show lineup view`) |
| `FrontendTeamChemistryView` (1) | `← Back to team picker` |
| `FrontendPlayersManageView` (1) | `← Back to players` (legacy `?tt_view=players&player_id=N` deep-link route — replaced with proper breadcrumb chain) |

In every case the parent crumb in the breadcrumb chain serves the same navigation function with one click.

### `FrontendBreadcrumbs::fromDashboardWithBack()` deleted

Along with its `sameOriginReferer()` helper. The two callers (`FrontendMyActivitiesView`, `FrontendMyGoalsView`) migrated to plain `fromDashboard()`. The `tt_back`-borne URL-pill auto-rendered by `FrontendBreadcrumbs::render()` is the canonical "back to where I came from" mechanism — referer-based fallback was a v3.108.2 stopgap that survived too long. Documentation has noted the deprecation since v3.110.0; this release completes the cut-over.

## What this does NOT change

- Wp-admin-side `BackButton` class (separate from the deleted frontend `FrontendBackButton`) is unchanged. Wp-admin uses a different navigation paradigm and is explicitly out of scope for the back-navigation contract per the doc's "What is NOT swept" section.
- The exhaustive cross-entity-link sweep (every `add_query_arg` callsite that builds a detail URL) is too broad to do as a single PR. The CLAUDE.md checklist will catch new violations in future PRs; existing violations get fixed opportunistically as views get touched.
- No behavior change for users who already use the breadcrumb + pill correctly — this release codifies what was already enforced in v3.110.41 / v3.110.45.

## Translations

Zero new msgids. One msgid removed (`← Back`, only emitted by the deleted `fromDashboardWithBack` method); the NL translation `← Terug` becomes obsolete but stays in the .po file as a no-op (no harm leaving it).

---

# TalentTrack v3.110.45 — Breadcrumb sweep: every routable frontend view now has a chain back to the dashboard

Pilot operator reported `?tt_view=team-chemistry` had no breadcrumb so they couldn't navigate back to the dashboard. Sweep across the codebase found **36 routable frontend views in the same state** — the v3.110.41 cleanup fixed the dispatcher stubs and ~35 of the most-visible views, but a long tail remained.

This release closes the gap. Every routable `?tt_view=<slug>` now emits a `Dashboard / …` chain plus the `tt_back`-borne pill (when applicable) per the contract in `docs/back-navigation.md`.

## Categories swept

### Coaching-group lists (6 views)

`?tt_view=teams`, `players`, `people`, `podium`, `compare`, `rate-cards`. The list/detail/edit/new branches each get a context-aware chain — e.g. for Teams: `Dashboard / Teams` (list), `Dashboard / Teams / New team`, `Dashboard / Teams / Edit team — Ajax U17`, `Dashboard / Teams / <name>` (detail).

### Trial-group views (5 views)

`?tt_view=trials`, `trial-case`, `trial-parent-meeting`, `trial-tracks-editor`, `trial-letter-templates-editor`. Detail / editor pages nest under `Dashboard / Trials / …` so one click in the breadcrumb chain reaches the case list.

### Reports + Scout (4 views)

`?tt_view=report-wizard`, `scout-access`, `scout-history`, `scout-my-players` — all nested under `Dashboard / Reports / …` (except scout-my-players, which is its own surface).

### Workflow (3 views)

`?tt_view=my-tasks`, `tasks-dashboard`, `workflow-config`.

### Me-group tiles (4 views)

`?tt_view=overview` (My card), `my-team`, `my-evaluations`, `my-pdp`. `?tt_view=profile` is a legacy slug folded into Overview; FrontendMyProfileView is a section renderer composed by Overview, not directly routable, so it's skipped.

### Staff Development (5 views)

`?tt_view=staff-overview`, `my-staff-pdp`, `my-staff-goals`, `my-staff-evaluations`, `my-staff-certifications`. The four "my-staff-*" tiles use distinct labels in the breadcrumb (`My PDP`, `My staff goals`, `My staff evaluations`, `My certifications`) to avoid colliding with the player-side "My evaluations / My goals" tiles.

### Other (9 views)

`?tt_view=team-chemistry` (the user-reported case — chain `Dashboard / Team chemistry` for the picker, `Dashboard / Team chemistry / <team name>` for the board), `docs`, `mobile-settings`, `wizard` (uses the wizard's own label), `wizards-admin`, `mfa-prompt`, `explore` (nested under `Analytics`), `player-status-methodology`.

## Permission-denied stubs

Every "you don't have permission" early-return inside these views now renders `Dashboard / Not authorized` instead of "no chain at all". Same pattern the v3.110.41 dispatcher cleanup established.

## Skipped (false positives)

- `AcceptanceView` — pre-login invitation-acceptance flow; intentionally chrome-free.
- `FrontendMobilePromptView` — gate screen for mobile-first guard, not directly routable.
- `PersonaLandingRenderer` — the dashboard root itself, no parent to chain back to.
- `FrontendTeammateView` — sub-view rendered inside My team / Team detail context.
- `FrontendThreadView`, `CoachDashboardView`, `PlayerDashboardView` — components / containers, not views.
- `FrontendMyProfileView` — section renderer composed into FrontendOverviewView, not a tt_view target.

## Translations

One new NL msgid:

| msgid | msgstr |
|---|---|
| `My scouted players` | `Mijn gescoute spelers` |

`My staff goals` and `My staff evaluations` already existed in the NL .po (translated as `Mijn stafdoelen` / `Mijn stafevaluaties` from earlier sweeps); the breadcrumb code references the same msgid strings so the existing translations apply. Every other label the breadcrumb chains use (`Top performers`, `Player comparison`, `Rate cards`, `Help & Docs`, `Mobile experience`, `Wizards`, `Two-factor authentication`, `Explore`, `Player status methodology`, `Trial cases`, `Parent meeting`, `Trial tracks`, `Letter templates`, `My tasks`, `Tasks dashboard`, `Workflow templates`, `Staff overview`, `My PDP`, `My certifications`, `My card`, `My team`, `My evaluations`, `My development plan`, `Teams`, `Players`, `People`, `Team chemistry`, `Team not found`, `Access denied`, `Generate report`, `Scout access`, `Scout reports history`, `Trials`, `New trial case`, `Trial: %s`, `New team`, `Edit team — %s`, `New player`, `Edit player — %s`, `Player not found`, `New person`, `Edit person — %s`, `Person not found`, `Not authorized`, `Wizard not found`) was already in the .po.

---

# TalentTrack v3.110.44 — `TT_COMMERCIAL_MODE`: single switch between non-commercial test instance and commercial production

The plugin's licensing machinery (DevOverride / TrialState / FreemiusAdapter / FeatureMap tier gating / free-tier caps / Upgrade-to-Pro UI) was already in place but didn't have a clean global on/off. Owner test-instances saw an "Upgrade to Pro" button that went nowhere because Freemius wasn't wired up. The owner is the only customer today (no commercial customers yet), so the right default is **everything unlocked**, with a single one-line flip to enter commercial mode the day the first paying customer goes live.

This release adds **`TT_COMMERCIAL_MODE`** as that single switch, defined in `talenttrack.php`. Defaults to `false` (non-commercial test instance).

## Behaviour

| Mode | `LicenseGate::tier()` | `can()` / `allows()` | `capsExceeded()` | `isInTrial()` / `isInGrace()` | AccountPage / PlanTab UI |
|---|---|---|---|---|---|
| Non-commercial (default) | Pro | true (every feature) | false (caps disabled) | false (trial ignored) | Single "Non-commercial test instance" notice |
| Commercial | DevOverride → Trial → Freemius → Free | FeatureMap tier-membership | At-cap on Free, off on paid | TrialState | Existing trial countdown, tier label, Upgrade-to-Pro card, Freemius-not-wired caveat |

Trial state on disk (the `tt_license_trial` option) is preserved across the toggle — when commercial mode is flipped on, an existing in-flight trial reappears in the UI. Same for DevOverride transients.

## What landed

### `src/Modules/License/LicenseMode.php` — new

Single static helper `LicenseMode::isCommercial()` that returns `true` when the `TT_COMMERCIAL_MODE` constant is defined and truthy. Returns `false` otherwise (constant missing, set to `false`, set to `0`, etc.).

### `src/Modules/License/LicenseGate.php` — short-circuits at the top of every public method

- `tier()` returns `FeatureMap::TIER_PRO` when not commercial. Existing resolution order (DevOverride → Trial → Freemius → Free) only runs in commercial mode.
- `can()` returns `true` when not commercial.
- `capsExceeded()` returns `false` when not commercial. Existing module-disabled fallback stays for installs that turn off the License module via Authorization → Modules.
- `isInTrial()` and `isInGrace()` return `false` when not commercial.
- `effectiveTier()` cascades correctly via `tier()` and `isInGrace()`.
- `allows()` cascades via `can()`.

### `src/Modules/License/Admin/AccountPage.php` — `renderAccountTab()` and `renderPlanTab()` short-circuit

When not commercial, both tabs render a single inline notice via the new `renderTestModeNotice()` helper. Notice explains:

- `TT_COMMERCIAL_MODE` is `false`, every feature unlocked, caps off, trial / upgrade UI hidden.
- How to switch to commercial mode (flip the constant + configure Freemius).

### `talenttrack.php` — `TT_COMMERCIAL_MODE` constant declaration

Defaults to `false`. Header comment documents the toggle and points at Freemius credentials as the second piece of the "go commercial" puzzle.

## What this fixes

- Pilot operator's "blue button does nothing" complaint on the License → Account tab. In test mode the button no longer renders at all; the notice tells them why.
- Standard / Pro features were gated even on the owner's own test install. Now everything is unlocked by default until the day the toggle is flipped.

## What this does NOT change

- `DevOverride` mechanism stays available and still works in commercial mode for owner-side tier-flip testing.
- Freemius integration is unchanged — wiring `TT_FREEMIUS_PRODUCT_ID` and `TT_FREEMIUS_PUBLIC_KEY` is still required for real checkout in commercial mode.
- `LicenseModule::ensureCapabilities()` still grants caps to roles per the existing seed; the gate for whether those caps actually unlock features is the LicenseGate short-circuit.
- The Plan tab's feature-matrix view (Free / Standard / Pro per-feature breakdown) is hidden in non-commercial mode along with the trial / upgrade UI. Reads-only, low risk to hide.

## Translations

Three new NL msgids:

| msgid | msgstr |
|---|---|
| `Non-commercial test instance` | `Niet-commerciële testinstallatie` |
| `%s is set to false in talenttrack.php. Every TalentTrack feature is unlocked, free-tier caps do not apply, and the trial / upgrade UI is hidden. Trial state on disk (if any) is preserved but ignored at runtime.` | `%s staat op false in talenttrack.php. Elke TalentTrack-functie is ontgrendeld, free-tier-limieten worden niet toegepast, en de proefperiode- / upgrade-interface is verborgen. Eventuele proefperiode-status op schijf blijft bewaard maar wordt tijdens runtime genegeerd.` |
| `Switching to commercial mode` | `Schakelen naar commerciële modus` |
| `flip %s to true in talenttrack.php and configure Freemius credentials (TT_FREEMIUS_PRODUCT_ID, TT_FREEMIUS_PUBLIC_KEY) so the upgrade flow can complete checkout. The existing License module machinery (DevOverride, TrialState, FreemiusAdapter) will then drive tier resolution and feature gating.` | `zet %s op true in talenttrack.php en configureer Freemius-credentials (TT_FREEMIUS_PRODUCT_ID, TT_FREEMIUS_PUBLIC_KEY) zodat de upgrade-flow de checkout kan afronden. De bestaande License-module (DevOverride, TrialState, FreemiusAdapter) regelt vervolgens de tier-resolutie en feature-gating.` |

---

# TalentTrack v3.110.43 — Free-tier customers mid-trial now see the "Upgrade to Pro" card

Follow-up hotfix to the trial-period upgrade-button fix that shipped in v3.110.39. That earlier fix introduced the `$paid_tier` distinction so a Standard customer in an active trial would still see the upgrade card (because `LicenseGate::tier()` returns the trial-unlocked tier, not the underlying paid plan) and gated the Account-tab card on `$paid_tier === FeatureMap::TIER_STANDARD`.

It missed one case: customers on **Free + active trial**. Their underlying paid tier is Free (no Freemius checkout completed yet), but the trial unlocks Standard or Pro features for the trial window. Pilot operator hit this exact case and reported the user-facing symptom as "There is a blue button but when clicking it nothing happens."

## What was happening

- Plan tab showed `Standard · 25 days left in trial` and a blue "Upgraden of proefperiode starten" button.
- Plan-tab `$paid_tier !== PRO` evaluated true → the blue button rendered correctly and navigated to the Account tab.
- On the Account tab, the elseif chain was:
  - `$paid_tier === FREE && $trial_data === null` → false (trial exists)
  - `$paid_tier === STANDARD` → false (paid tier is actually Free, only the trial unlock makes them look Standard)
  - → no Upgrade-to-Pro card rendered
- The Account tab showed only the "Trial: 25 days remaining" notice with no actionable next step. Hence "nothing happens."

## Fix

Broadened the Account-tab elseif from `=== TIER_STANDARD` to `!== TIER_PRO`. Any non-Pro paid tier now sees the upgrade card during/after a trial.

| Underlying paid plan | Trial state | Card shown |
|---|---|---|
| Free, never used trial | inactive | Start-Trial form (unchanged) |
| Free | trial active | **Now shown — was the bug** |
| Free | grace | **Now shown** |
| Standard | trial active | Shown (already worked) |
| Standard | inactive | Shown (already worked) |
| Pro | any | Hidden (correct) |

## Card copy now context-aware

The previous lead-in line said `You're on Standard. Pro adds the features your scouting and trial workflows depend on.` That's accurate for Standard customers, but lies to Free users. Two variants now:

- **Standard customer**: same copy as before.
- **Any other non-Pro tier (Free)**: `Pro unlocks every TalentTrack feature — the ones your scouting and trial workflows depend on, plus the conveniences your coaches will ask for.`

The bullet-list of Pro features, the upgrade-button URL logic (Freemius pricing if configured, DevOverridePage if `TT_DEV_OVERRIDE_SECRET` defined, fallback to Account tab otherwise), and the "Freemius isn't wired up yet" caveat description below the button are all unchanged.

## Translations

One new NL msgid:

| msgid | msgstr |
|---|---|
| `Pro unlocks every TalentTrack feature — the ones your scouting and trial workflows depend on, plus the conveniences your coaches will ask for.` | `Pro ontgrendelt elke TalentTrack-functie — degene waar je scouting- en proeftrainingsflows op leunen, plus de gemakken waar je coaches om zullen vragen.` |

The existing Standard-specific lead-in is unchanged.

---

# TalentTrack v3.110.42 — Prospects pipeline "+ New prospect" button now actually starts the chain

The standalone onboarding-pipeline view's "+ New prospect" CTA was rendered as `<a href="<rest_url>/prospects/log" data-tt-prospect-log>`. The `data-tt-prospect-log` attribute hinted at a click-handler that never shipped, so clicking the link navigated the browser straight to the REST endpoint with a GET request — the route is POST-only, so the scout landed on a 405 instead of a fresh task.

This release ships the missing handler and converts the CTA to the right HTML element.

## What landed

### `assets/js/frontend-prospects-log.js`

53-line click-handler enqueued only on the onboarding-pipeline view. POSTs to `/talenttrack/v1/prospects/log` with the WP REST nonce, reads `redirect_url` from the response (`?tt_view=my-tasks&task_id=<id>`), and navigates the browser there. Disables the button while pending; restores + alerts on transport failure or non-success body. Two i18n strings (chain-failed + network-failed) come through `wp_localize_script` so they translate via the standard `__()` pipeline.

### `FrontendOnboardingPipelineView`

The `<a>` becomes a `<button type="button">` with `min-height: 48px` so the touch target meets the mobile-first 48×48 floor (CLAUDE.md § 2). The view enqueues the new script alongside its existing assets via the new `enqueueProspectLogScript()` helper.

## Translations

Two new NL msgids:

| msgid | msgstr |
|---|---|
| `Could not start the prospect-logging flow. Please try again.` | `Kan het vastleggen van een prospect niet starten. Probeer het opnieuw.` |
| `Network error. Please try again.` | `Netwerkfout. Probeer het opnieuw.` |

FR/DE/ES added with empty msgstrs (English fallback at runtime per #0010).

---

# TalentTrack v3.110.41 — Frontend navigation cleanup: one back-pill + breadcrumb per view

Pilot operator screenshot of the goal-detail page surfaced a long-standing duplication: every frontend detail view rendered up to four navigation affordances stacked above the content (the `tt_back`-borne pill, the breadcrumb chain, a "← Back to dashboard" button from `FrontendViewBase::renderHeader`'s fallback, AND a second explicit `FrontendBackButton::render()` call inside the view's own `renderDetail()`). Two of those four were redundant on every page they appeared.

The contract per `docs/back-navigation.md` is exactly two affordances:

- The auto-rendered `tt_back` pill above the breadcrumb chain (the "back to where you came from" path)
- The breadcrumb chain itself (the canonical `Dashboard / Section / Page` hierarchy)

This release enforces that contract everywhere.

## What landed

### Step 1 — `FrontendViewBase::renderHeader()` no longer falls back to a back button

Previously, when `static::breadcrumbs()` returned `[]` (the default), `renderHeader()` would fall back to `FrontendBackButton::render()`. That fallback fired for every view that rendered breadcrumbs by calling `FrontendBreadcrumbs::fromDashboard()` directly (the dynamic-chain pattern most views use), because those views don't override the static `breadcrumbs()` method.

After this release, `renderHeader()` either renders the static breadcrumb chain (if the view overrides `breadcrumbs()`) or nothing. Views that need a dynamic chain MUST call `FrontendBreadcrumbs::fromDashboard()` themselves before `renderHeader()`.

### Step 2 — Explicit `FrontendBackButton::render()` calls deleted from 26 view classes

Every duplicate-back-button case the screenshot revealed, plus its clones across detail, manage, and admin-tier surfaces:

- `FrontendActivitiesManageView`, `FrontendAuditLogView`, `FrontendConfigurationView`, `FrontendCustomFieldsView`, `FrontendCustomCssView`, `FrontendCohortTransitionsView`, `FrontendEvalCategoriesView`, `FrontendFunctionalRolesView`, `FrontendGoalsManageView`, `FrontendJourneyView`, `FrontendMailComposeView`, `FrontendMigrationsView`, `FrontendMyGoalsView`, `FrontendMySessionsView`, `FrontendMySettingsView`, `FrontendPdpManageView`, `FrontendPersonDetailView`, `FrontendPlayerDetailView`, `FrontendPlayersCsvImportView`, `FrontendReportDetailView`, `FrontendReportsLauncherView`, `FrontendRolesView`, `FrontendTaskDetailView`, `FrontendTeamDetailView`, `FrontendUsageStatsDetailsView`, `FrontendUsageStatsView`.

Permission-denied early-return stubs that previously emitted a back button now emit a `Dashboard / Not authorized` breadcrumb chain instead.

### Step 3 — Nine "back-button only" views migrated to the breadcrumb pattern

`TracksView`, `IdeaSubmitView`, `IdeasBoardView`, `IdeasRefineView` (nested under Ideas), `IdeasApprovalView`, `FrontendAnalyticsView`, `FrontendScheduledReportsView` (nested under Analytics), `MethodologyView`, `InvitationsConfigView` previously had no breadcrumb chain at all — just the bare back button. Now they get the full canonical pattern, including the `tt_back`-borne pill rendered automatically above the chain.

### Step 4 — `DashboardShortcode` dispatcher stubs

Roughly 20 `FrontendBackButton::render()` calls scattered through dispatcher stub branches (matrix-gate denial, missing-player Me-group fallback, account "sign in required", scout permission gates, team-chemistry / team-blueprints permission, player-journey "player not found", every per-group default arm, the module-disabled notice) all converted to `FrontendBreadcrumbs::fromDashboard()` with context-appropriate labels: `Not authorized` / `Player not found` / `Sign in required` / `Section unavailable` / `Unknown section`.

The `pdp-planning` + `player-status-methodology` arms had a bonus duplicate-button (the dispatcher rendered one, then the view rendered its own breadcrumbs); the dispatcher's call is gone.

### Step 5 — `FrontendBackButton` class deleted

Once Steps 1–4 left zero callers, the class file was removed. Five module views still had stale `use TT\Shared\Frontend\FrontendBackButton;` imports — those were cleaned up too. The `FrontendViewBase` docblock was refreshed to describe the breadcrumbs-only navigation contract.

## Net effect

The pilot operator's screenshot now shows exactly the two affordances that were asked for:

```
[← Terug naar Doelen]                       (tt_back-borne pill)
Dashboard / Doelen / Goal detail            (breadcrumb chain)
```

The two redundant `← TERUG NAAR DASHBOARD` buttons are gone. Same fix applies to ~30 other frontend views that had the same pattern.

## Custom-label back buttons removed

A few views had `FrontendBackButton::render('', 'Back to <thing>')` calls where the label was meaningful, not just "back to dashboard":

- `FrontendUsageStatsDetailsView` had an explicit "← Back to usage statistics" button. Users now reach that view by clicking the "Application KPIs" parent crumb in the breadcrumb chain.
- `FrontendGoalsManageView`, `FrontendActivitiesManageView`, `FrontendMailComposeView`, `FrontendPdpManageView` had similar parent-aware back buttons. The breadcrumb chain has the right intermediate parent in every case — affordance is one click in the chain instead of a dedicated button.

This is an intentional UX trade for consistency. The breadcrumb chain is smaller text on mobile but matches the one pattern used everywhere else.

## Risks & deferrals

- **Legacy `FrontendBreadcrumbs::fromDashboardWithBack()` (referer-based first crumb)** is documented as already-deprecated by the URL-borne pill, but `FrontendMyActivitiesView` + `FrontendMyGoalsView` still call it. Migrating those to plain `fromDashboard()` is a separate, smaller PR; no functional change in this release.
- **Test coverage**: no automated test asserts navigation-chrome count per page. A smoke test that loads each `?tt_view=…` route and asserts the rendered HTML has exactly one `nav.tt-breadcrumbs` and ≤ 1 `.tt-back-link-pill` would be cheap insurance against future re-introduction. Tracked as a follow-up.

## Translations

Four new NL msgids:

| msgid | msgstr |
|---|---|
| `Not authorized` | `Niet geautoriseerd` |
| `Section unavailable` | `Sectie niet beschikbaar` |
| `Unknown section` | `Onbekende sectie` |
| `Sign in required` | `Inloggen vereist` |

`Player not found` was already translated.

---

# TalentTrack v3.110.40 — #0016 close — concrete vision extraction + fuzzy matcher + provider fallback + DPIA template + seeded library

**Closes #0016 engineering.** The photo-to-session capture epic ships its concrete AI extraction layer, the fuzzy matcher that turns extracted text into library suggestions, automatic provider fallback, the DPIA template legal teams must complete before broad deployment, and an 18-drill seeded reference library.

## What landed

### `ClaudeSonnetProvider` — concrete impl

The Sprint 1 stub becomes a real Anthropic Messages API caller. Routes to AWS Bedrock `eu-central-1` by default (DPIA hard requirement: minor athletes' photos cannot leave the EU). The structured-extraction prompt asks the model for strict JSON (exercises array + attendance array + overall_confidence + notes); the response parser strips markdown fences if the model added them despite the prompt + decodes into `ExtractedSession` value objects. 5 MB image-size cap as a backstop against high-res phone photos.

**Status caveat**: this is the first-pass shipping default. The spec's **provider shootout** (10-15 real coach photos, score Claude Sonnet vs Gemini Pro on extraction accuracy) is calendar-time work that validates or replaces this choice before broad deployment.

### `ExerciseFuzzyMatcher` (Sprint 4)

Levenshtein-based similarity matcher. Normalises both candidate + library names (lowercase, strip punctuation + diacritics, collapse whitespace), then scores. Default threshold 0.6 per spec § Sprint 4. Returns top-3 candidates so the review wizard can offer alternatives.

```php
$matcher = new ExerciseFuzzyMatcher();
$result  = $matcher->bestMatch( 'rondo 5v2', $team_id );
// $result = [
//   'exercise' => <object: tt_exercises row>,
//   'similarity' => 0.85,
//   'candidates' => [ [exercise: ..., similarity: 0.85], ... ]
// ]
```

Tenancy + visibility-aware: when `team_id > 0`, only matches against exercises that team can see (per Sprint 1's `listForTeam()`).

### `ExercisesModule::extractWithFallback()` (Sprint 6)

Wraps `resolveProvider()` with automatic fallback. Tries the primary provider; on `RuntimeException` (transport error, quota exceeded, malformed response) tries the next configured provider in the registry. Throws a single error summarising every attempt only if every configured provider fails.

The Sprint 4 review wizard catches that and falls through to manual entry with a clear "we couldn't read this photo" notice.

### `VisionExtractRestController` (Sprint 3)

`POST /wp-json/talenttrack/v1/vision/extract` orchestrates the photo-to-session flow:

1. Accepts multipart photo upload OR base64 JSON body.
2. Pipes through `ExercisesModule::extractWithFallback()`.
3. Runs each extracted exercise through `ExerciseFuzzyMatcher::bestMatch()`.
4. Returns the structured payload Sprint 4's review wizard renders directly:

```json
{
  "ok": true,
  "data": {
    "exercises": [
      {
        "name": "...",
        "duration_minutes": 12,
        "notes": "...",
        "confidence": 0.82,
        "matched_exercise_id": 42,
        "matched_similarity": 0.91,
        "match_candidates": [...]
      }
    ],
    "attendance": [...],
    "overall_confidence": 0.78,
    "notes": "..."
  }
}
```

Cap-gated on `tt_edit_activities`. Returns 503 with a clear error when all providers fail.

### `docs/photo-capture-dpia.md` — GDPR Art. 35 DPIA template

The academy's data controller + DPO complete this template before broad deployment. Documents:

- Processing description + data subjects (youth players, some minors).
- End-to-end data flow diagram.
- Retention (photo deleted from server within 7 days; `TT_VISION_PHOTO_RETENTION_DAYS` overridable in wp-config).
- EU residency mandate (Bedrock `eu-central-1` default; OpenAI flagged DPIA-incompatible for EU clubs).
- Provider non-persistence (validated against current contract per annual review).
- Lawful-basis options (legitimate interest / consent / contract).
- Data-subject rights matrix (access via #0063 use case 10 ZIP; rectification via review wizard; erasure via cascade delete).
- Risk register + mitigations.
- Annual-review cadence.
- Sign-off table (data controller / DPO / technical lead).

### Migration `0090_seed_exercise_library`

Seeds 18 reference drills, three per category:

| Category | Seeded drills |
|---|---|
| Warmup | Dynamic stretching circuit · Square passing 2-touch · Activation 1v1 |
| Rondo | 4v1 rondo · 5v2 rondo · 6v3 rondo with line targets |
| Possession | 4v4+2 possession · End-zone possession · 3-team rotation |
| Conditioned game | 4v4 to small goals · 7v7 with three thirds · Counter-attack 4v3 |
| Finishing | Two-station shooting · 1v1 to goal · Cross-and-finish drill |
| Set piece | Corner routine — short · Corner routine — far post · Free-kick wall positioning |

Deterministic UUIDs (v5 derived from namespace + slug) so re-runs produce the same ids across installs. `INSERT IGNORE` against the unique uuid index keeps the migration idempotent + non-destructive against operator-edited rows.

### `specs/0016-epic-photo-to-session-capture.md` → `specs/shipped/`

```
---
status: shipped
shipped_in: v3.110.35 — v3.110.40 (engineering); end-to-end UI flow + provider shootout + DPIA legal review remain as calendar-time
---
```

## Calendar-time remaining (NOT shipped, by intent)

These are work streams that an LLM cannot complete in a session because they require external real-world inputs:

- **Provider shootout** — collect 10-15 real coach training-plan photos, score Claude Sonnet 4.x vs Gemini 2.5 Pro on extraction accuracy + hallucination rate. The current `claude_sonnet` shipping default is best-effort first guess; the shootout validates or replaces it before broad deployment.
- **DPIA legal review** — the template ships; the academy's data controller + DPO must complete + sign before deploying photo capture broadly to clubs whose photos may include minors. Annual refresh per the template cadence.
- **End-to-end mobile capture UI** (Sprint 3 user-facing surface) — `CoachCaptureView` (mobile-first camera form) + offline IndexedDB queue. The REST endpoint is shipped + ready; this UI is substantial markup + JS that benefits from a focused follow-up PR.
- **Review wizard UI** (Sprint 4 user-facing surface) — confidence-coloured edit grid (green > 0.85 / yellow 0.6-0.85 / red < 0.6) with per-row accept / correct / delete / save-as-new-library-entry. Backend is ready; this UI is its own focused follow-up PR.

The spec moves to `specs/shipped/` because every code-side acceptance criterion is met. "Shipped" here means "the AI extraction works end-to-end via REST when an API key is configured", not "the Sprint-3-mobile-capture + Sprint-4-review-wizard UIs are operator-ready." Those UIs land in focused follow-ups.

## Player-centricity

**Maximally indirect, maximally important**: every drill captured is data about what a player actually did during a training, who was present, how long they spent on each exercise. Sprints 1-2-3-4-5-6 together turn the "throw the paper plan in the bin" data-loss problem into "1-tap photo capture → 30-second review → save". The downstream effect is dramatically more accurate, more complete development data per player. The spec's opening framing — "the data that should be captured is sitting on a piece of paper that gets thrown away" — is what this epic solves.

## Translations

~12 new NL msgids covering the DPIA template's section labels + error messages + the new provider description copy.

## Notes

The Anthropic Messages API call uses `claude-sonnet-4-20251020` as the pinned model. When the next Claude Sonnet drop ships (4.7+), update the `model` constant in `ClaudeSonnetProvider::callAnthropic()` after validating extraction quality. The pinned-model approach prevents silent quality drift mid-deploy.

**#0016 closed.**

---

# TalentTrack v3.110.39 — Exercises + ActivityExercises REST surfaces (#0016 Sprint 2b)

REST surfaces on the Sprint 1 + Sprint 2a data layer. The Sprint 4 photo-capture review wizard + future SaaS frontends call into a stable HTTP shape rather than direct PHP repository access.

## What landed

### `ExercisesRestController` — `/wp-json/talenttrack/v1/exercises`

| Route | Method | Purpose |
|---|---|---|
| `/exercises` | GET | List active exercises. Optional `?team_id=N` applies the Sprint 1 visibility rules via `listForTeam()`. |
| `/exercises/categories` | GET | List `tt_exercise_categories` rows. |
| `/exercises/{id}` | GET | Fetch a single exercise by id. |
| `/exercises` | POST | Create. |
| `/exercises/{id}` | PUT | Edit-as-new-version per the Sprint 1 pinning model. Returns `{ id: <new>, previous_id: <old> }` so callers know the new version landed and can pin future activities to it. |
| `/exercises/{id}` | DELETE | Archive (soft-delete; `archived_at = NOW()`). |

Cap gate: `tt_view_activities` for reads; `tt_manage_exercises` for writes.

### `ActivityExercisesRestController` — `/wp-json/talenttrack/v1/activities/{activity_id}/exercises`

| Route | Method | Purpose |
|---|---|---|
| `…/exercises` | GET | List linked exercises for an activity, joined to `tt_exercises` so payloads carry `exercise_name`, `exercise_planned_duration`, `exercise_diagram_url`. |
| `…/exercises` | POST | Append at the next free `order_index`. |
| `…/exercises/{id}` | PUT | Patch one row: order/duration/notes/draft flag. |
| `…/exercises/{id}` | DELETE | Remove a single link. |
| `…/exercises/replace` | POST | **Sprint 4 review-wizard's bulk-commit target.** Replaces the entire linked-exercise list for an activity in one call. |
| `/exercises/{exercise_id}/activities` | GET | Exercise-history view — every activity that linked the drill, joined to `tt_activities` for `activity_title` + `activity_date` + `activity_team_id`. |

Cap gate: `tt_view_activities` for reads; `tt_edit_activities` for writes.

### Wired into `ExercisesModule::boot()`

Both controllers' `init()` runs at module-boot time so REST routes register on `rest_api_init`. No additional config / hook setup required.

## What's NOT in this PR (Sprint 2c follow-up)

The UI surfaces ride on top of these REST routes:

- **Activity-edit UI Exercises section** — list of linked exercises with add / remove / reorder / edit-actual-duration / per-row notes. Markup + drag-reorder JS.
- **Library-picker UI** — search bar + category filter + principle filter. Renders into a modal / sidebar that consumers can call into for "pick an exercise to attach."
- **Exercise-history page UI** — per-exercise list of using activities (consumes the `/exercises/{id}/activities` endpoint).

Sprint 2c is its own focused PR. The data + REST layer shipped in 2a + 2b is the SaaS-ready backbone; UI consumers can land on top without further repository refactor.

## What's NOT in #0016 still

- **Sprint 3** — photo capture UI + offline IndexedDB queue.
- **Sprint 4** — concrete AI extraction (Claude Sonnet impl) + fuzzy matcher + review wizard.
- **Sprint 5** — attendance extraction.
- **Sprint 6** — draft sessions + provider fallback.
- **Provider shootout** — calendar-time, requires real coach photos.
- **DPIA template** — calendar-time legal review.

## SaaS-readiness checklist

- [x] Reachable through REST — both controllers register canonical routes.
- [x] Business logic outside view files — Repositories own the domain logic; controllers just translate HTTP ↔ method calls.
- [x] Auth via capabilities — `tt_view_activities` / `tt_edit_activities` / `tt_manage_exercises`, not role-string compare.
- [x] Tenancy — Repositories already scope to `CurrentClub::id()`.

## Translations

Zero new NL msgids — REST error messages reuse standard `'Exercise not found'` / `'A name is required'` / `'No fields to update'` / etc. patterns already translated by other modules.

---

# TalentTrack v3.110.38 — Translation dictionaries batch 2 (#0010 close — code-side complete)

**Closes #0010 code-side.** The spec moved to `specs/shipped/` with frontmatter `status: shipped` and an explicit "calendar-time follow-ups remaining" note. The engineering work — locale skeletons, the dictionary round-trip tool, mixed-formality tone documentation, the DEVOPS pre-release POT-regen checklist, three first-pass machine-translation dictionaries — is done. The native-speaker review of the long tail and the 67-docs translation are calendar-time work streams that run against the shipped infrastructure without blocking any product feature.

## What landed in this ship

### Dictionary expansion: ~170 more entries per locale

Each of `tools/translations-fr_FR.php`, `translations-de_DE.php`, `translations-es_ES.php` now covers ~330 entries (vs. ~250 in v3.110.36). Categories added:

- Common form labels (Field, Value, Code, Color, Image, File, Size, Order)
- Date / time vocabulary (Day / Hour / Minute / Daily / Weekly / Monthly / Birthday / Address / City / Country)
- Navigation modifiers (View all / Show more / Read more / Add another / Toggle / Expand / Collapse)
- Error states (Access denied / Session expired / Try again / Retry)
- Authentication labels (Sign in / Sign out / Username / Password / Forgot password)
- Notifications + messaging (Inbox / Sent / Reply / Forward / Subject / Body / Recipient / Sender)
- Permissions (Roles / Capabilities / Permission denied / You don't have permission to do that)
- Pagination (Page %d of %d / Items per page / Previous page / Next page / Showing %d of %d)
- Match-day vocabulary (Roster / Squad / Lineup / Bench / Captain / Opponent / Home / Away / Win / Loss / Draw / Final score / Half-time / Full-time / Kickoff / Stadium / Venue / Pitch)
- Discussion (Thread / Conversation / Comment / Feedback / Self-reflection / Coach notes)

### Coverage post-batch-2

| Locale | Non-empty msgstrs | Remaining empty (English fallback) |
|---|---|---|
| `fr_FR` | 245 | 4368 |
| `de_DE` | 245 | 4368 |
| `es_ES` | 245 | 4368 |

### Spec close

`specs/0010-feat-multi-language-fr-de-es.md` → `specs/shipped/`. Frontmatter:

```
---
status: shipped
shipped_in: v3.110.34 — v3.110.38 (code-side); native-speaker review + remaining 67 docs are calendar-time follow-ups
---
```

The closing note in the spec body documents what shipped (infrastructure + first-pass dictionaries) vs. what's calendar-time (native review + doc translations).

## Honest scope statement

The spec's original sizing — "~80–140 hours of work, most of it translation review" — is correct. **The engineering work for #0010 is ~4-6h per the spec's own breakdown; that's what shipped across v3.110.34 through v3.110.38.** The remaining ~75-130h is native-speaker translation labor that an LLM should not pretend to deliver as a one-session marathon: tone choice per surface, idiomatic phrasing, plural-form correctness in inflected languages, and 67 long-form technical docs all need human review against the live product.

What translators get from this ship:
- Empty `.po` skeletons with the full ~4613 msgid set ready to fill.
- ~245 first-pass machine translations per locale as a starting point + tone-anchor.
- A documented workflow (`docs/translator-brief.md`) covering tone classification, plural rules, placeholder + HTML conventions, surface identification, and the PR → CI → auto-compile loop.
- An idempotent dictionary round-trip tool (`tools/apply-translations.php`) so translators extend the `.php` dictionary file (single-source-of-truth diff review) and the `.po` updates mechanically.
- DEVOPS POT-regen checklist preventing future drift.

That's the structural completeness. Native review extends from here on calendar time.

## What's NOT in this PR (calendar-time follow-ups)

- **Native-speaker review** of the ~4368 unfilled msgids per locale. Per spec sizing: ~15-25h per language. The dictionary file is the single-source-of-truth; native reviewers PR additions to `tools/translations-<locale>.php` and re-run the apply tool.
- **67 English docs translated to FR/DE/ES** = ~201 translated markdown files. Per spec sizing: ~30-60h per language. The original spec assumed 19 docs; the count grew to 67 over time, which is why this is now the dominant calendar-time work stream. Native developer-translators are needed for the technical docs (rest-api, hooks-and-filters, workflow-engine, i18n-architecture); operator-translators for the user-facing docs (player-dashboard, coach-dashboard, evaluations, goals).

## Translations

Zero new NL msgids — three dictionary files extended + spec moved to `shipped/`.

---

# TalentTrack v3.110.37 — Activity-to-exercise linkage table + repository (#0016 Sprint 2a)

Sprint 2 of the photo-to-session capture epic, data-layer half. Sprint 1 (v3.110.35) shipped the exercise library + categories + vision provider scaffolding. Sprint 2a (this ship) links activities to specific exercise versions via `tt_activity_exercises` and the `ActivityExercisesRepository` that Sprint 4's AI extraction wizard will eventually call into. Sprint 2b (UI integration on the activity edit page) lands as a follow-up.

## What landed

### Migration `0089_activity_exercises`

```sql
tt_activity_exercises (
    id BIGINT PK,
    club_id INT NOT NULL DEFAULT 1,
    activity_id BIGINT NOT NULL,
    exercise_id BIGINT NOT NULL,    -- FK to specific tt_exercises.id row (pinned version)
    order_index SMALLINT NOT NULL DEFAULT 0,
    actual_duration_minutes SMALLINT DEFAULT NULL,
    notes TEXT NULL,
    is_draft TINYINT NOT NULL DEFAULT 0,
    created_at, updated_at,
    UNIQUE (club_id, activity_id, order_index)
)
```

**Pinning model**: `exercise_id` references a specific `tt_exercises.id` row, NOT a logical exercise key. When a coach edits an exercise, `ExercisesRepository::editAsNewVersion()` (Sprint 1) creates a new row at `version + 1` and points the old row's `superseded_by_id` at it; activities that linked the old row continue to render the original drill description, planned duration, and principles. Historical activities don't lie about what was actually run.

Per CLAUDE.md §4 SaaS-readiness: `club_id NOT NULL DEFAULT 1`; the row inherits the club_id of the parent activity. Every read scopes by club_id.

`UNIQUE (club_id, activity_id, order_index)` keeps the ordering deterministic — no two rows for the same activity share an `order_index`.

### `ActivityExercisesRepository`

```php
$repo = new ActivityExercisesRepository();

$repo->listForActivity( $activity_id );      // joins tt_exercises so callers get name + duration + diagram in one query
$repo->listForExercise( $exercise_id );      // exercise-history view: every activity that linked this drill
$repo->append( $activity_id, $exercise_id, [ 'actual_duration_minutes' => 18, 'notes' => '4v4 rondos' ] );
$repo->update( $id, [ 'order_index' => 0 ] );
$repo->delete( $id );
$repo->deleteForActivity( $activity_id );

// Sprint 4 bulk-commit path:
$repo->replaceExercisesForActivity( $activity_id, [
    [ 'exercise_id' => 12, 'actual_duration_minutes' => 8 ],
    [ 'exercise_id' => 27, 'actual_duration_minutes' => 12, 'is_draft' => true ],
    // …
]);
```

`append()` reads `MAX(order_index)` for the activity and uses `+ 1`, so the caller doesn't need to know how many exercises are already linked.

`is_draft = 1` is reserved for Sprint 6 — the AI-extraction review wizard surfaces low-confidence exercises as drafts that the coach confirms later. Sprint 2-5 always write 0.

All reads + writes scope to `CurrentClub::id()`.

## What's NOT in this PR (lands in Sprint 2b)

- **Activity-edit UI** — Exercises section on the wp-admin + frontend activity-edit views (add / remove / reorder / edit-actual-duration / per-row notes).
- **Exercise-library picker** — search bar + category filter + principle filter that reads from `ExercisesRepository::listForTeam()`.
- **Exercise-history view** — per-exercise list of using activities; consumes `ActivityExercisesRepository::listForExercise()`.
- **REST controller** — `/wp-json/talenttrack/v1/activities/{id}/exercises` for the future SaaS frontend (and Sprint 4 review wizard).
- **Frontend renders** — exercise list on activity detail pages, on session-brief PDF (#0063 use case 8 follow-up), in coach dashboard summary.

Sprint 2b is its own PR — substantial markup + JS work that benefits from a focused review.

## What's still NOT in #0016 (subsequent sprints + calendar-time)

- Sprint 2b — activity-edit UI integration (described above).
- Sprint 3 — photo capture UI (`CoachCaptureView`) + offline IndexedDB queue.
- Sprint 4 — actual AI extraction (concrete provider implementations) + fuzzy matcher + review wizard.
- Sprint 5 — attendance extraction from photo annotations.
- Sprint 6 — draft sessions + provider fallback chain.
- Provider shootout (calendar-time, requires real coach photos).
- DPIA documentation (calendar-time legal review).

## Player-centricity

Indirect — every linked exercise is data about what a player actually did during a training. Sprint 2a establishes the durable record so Sprint 2b's UI + Sprints 3-4's photo capture can populate it with low friction. The downstream effect is more accurate, more complete training data per player.

## Translations

Zero new NL msgids — pure data-layer ship.

---

# TalentTrack v3.110.36 — First-pass FR/DE/ES machine translations for high-frequency UI labels (#0010)

Per #0010 spec § "Machine-translate as first draft. Human review and editing pass by a native speaker." — this ship lands machine-translated msgstrs for the highest-frequency UI labels across the three new locales. **161 translations per locale (~480 total)**, covering the labels operators see most often: action verbs, navigation, status pills, attendance, persona + role labels, football positions, foot preference, activity types, common form labels, confirmations.

## What landed

### `tools/apply-translations.php`

Generic, idempotent tool that reads a `tools/translations-<locale>.php` dictionary (a PHP file returning `[ msgid => msgstr ]`) and patches the corresponding `.po`:

- Walks every msgid → msgstr pair in the source `.po`.
- Where msgstr is empty AND the dictionary has the msgid → writes the dictionary value.
- Where msgstr already has a value → preserves it (operator edits via the per-row Translations admin survive untouched).
- Reports applied / skipped-already-filled / skipped-no-dictionary-entry counts.

### Three dictionary files, ~250 entries each

`tools/translations-fr_FR.php`, `translations-de_DE.php`, `translations-es_ES.php` — hand-curated by an LLM.

| Category | Coverage |
|---|---|
| Action verbs | Add / Edit / Save / Delete / Cancel / Submit / Confirm / Apply / Reset / Close / Open / View / Back / Next / Continue / Done / Finish / Search / Filter / Sort / Refresh / Download / Upload / Export / Import / Print / Copy / Duplicate / Archive / Restore / Activate / Deactivate / Yes / No / OK |
| Navigation | Dashboard / Settings / Configuration / Reports / Players / Teams / People / Activities / Goals / Evaluations / Trials / Methodology / Backup / Migrations / Audit Log / Help / Documentation / Profile / My * (Profile / Evaluations / Activities / Goals / PDP / Card) / Logout / Login |
| Status pills | Active / Inactive / Pending / Completed / Cancelled / Archived / Draft / Published / In Progress / On Hold / Open / Closed / New / Planned / Scheduled / Signed / Unsigned / Approved / Rejected / Failed / Success / Error / Warning / Info |
| Attendance | Present / Absent / Late / Excused / Injured |
| Persona + roles | Coach / Head Coach / Assistant Coach / Manager / Physio / Scout / Parent / Mentor / Other / Administrator / Admin / Club Admin / Head of Development / Team Member / Staff |
| Football positions | Goalkeeper / Defender / Midfielder / Striker / Forward + per-side variants (Left/Right Back, Center Back, Left/Right Wing) |
| Foot preference | Right / Left / Both |
| Activity types | Training / Match / Game / Friendly / League / Cup / Tournament / Meeting / Clinic |
| Common labels | Name / First Name / Last Name / Email / Phone / Date / Date of Birth / Age / Age Group(s) / Nationality / Height / Weight / Preferred Foot / Jersey Number / Position(s) / Description / Notes / Status / Type / Category / Title / Location / Time / Duration / Priority / Due Date / Created / Updated / Action(s) / Details / Summary / Overview / All / None / Required / Optional |
| Confirmations | Saved. / Deleted. / Updated. / Created. / Are you sure? / An error occurred. / Unauthorized / Not found / Forbidden |
| Misc UI | On / Off / Custom / Default / Auto / Manual / Today / Yesterday / Tomorrow / Week / Month / Year |

**Tone** per spec § Mixed-formality:
- Player / coach surfaces → Tu / Du / tú (most short labels are surface-agnostic so this is mostly invisible at the v1 layer).
- Admin / settings / parent letters → Vous / Sie / usted.
- Spanish defaults to tú; usted reserved for formal external-facing letters per spec.

**Football vocabulary** uses standard local sports usage. Spanish uses peninsular "Portero" not Latin-American "arquero" per the `es_ES`-only scope decision. German uses "Torwart" not the casual "Goalie".

### Updated `.po` files

Each of `talenttrack-fr_FR.po` / `talenttrack-de_DE.po` / `talenttrack-es_ES.po` now has 161 non-empty msgstrs (vs. 0 in v3.110.34's empty skeletons). The remaining ~4452 msgids stay empty (English fallback at runtime per WP convention).

The `Validate .po syntax` CI gate confirms all three files compile cleanly.

## What's NOT in this PR

- **Long descriptive help-text strings** (~333 msgids over 100 chars) — these need context-aware translation that an LLM might botch on tone (admin help text vs. coach explainer vs. parent letter). Calendar-time native-speaker review.
- **Medium-length form-help labels** (~585 msgids 50-100 chars) — same reasoning, smaller risk; could be machine-translated in a follow-up PR if expedient.
- **The remaining short labels** (~3300 msgids) — long-tail labels (specific page titles, edge-case error states, demo-data UI). Some are translatable mechanically; many are context-dependent.

The dictionary is structured to extend incrementally: a follow-up PR can add 200-500 entries to each `tools/translations-<locale>.php` and re-run `apply-translations.php` to land them.

## #0010 spec status

Spec stays in **Ready** with translation labor as the remaining acceptance-criteria item. v3.110.34 shipped the structural skeletons; this ship makes the high-frequency core translate-on-arrival. Native-speaker review extends from here.

## Translations

Zero new NL msgids — three dictionary files + tool + updated `.po` files only.

## Notes

The dictionary approach (PHP file with inline array) is intentional vs. inlining translations directly in `.po`: a single source-of-truth file makes diff review meaningful, makes re-running deterministic, and lets a translator's PR review focus on the dictionary changes rather than mechanical `.po` edits. The `apply-translations.php` step is what produces the `.po` deltas.

---

# TalentTrack v3.110.35 — Exercise library foundation + vision provider scaffolding (#0016 Sprint 1)

Foundation ship for #0016 (photo-to-session capture). Sprint 1 establishes the schema + repository + AI provider scaffolding; Sprints 2-6 build the session linkage, photo capture UI, AI extraction, and review wizard on top of this base.

## What landed

### Migration `0088_exercises_foundation`

Four new tables, all idempotent via `dbDelta`:

- **`tt_exercises`** — drill / exercise definitions with versioning (`superseded_by_id`), visibility (`'club' | 'team' | 'private'`), `uuid CHAR(36) UNIQUE` + `club_id` per CLAUDE.md §4. Edits create a new row at `version + 1`; sessions referencing the old `id` keep their historical rendering.
- **`tt_exercise_categories`** — seeded with eight defaults: `warmup`, `rondo`, `possession`, `conditioned_game`, `finishing`, `set_piece`, `cooldown`, `individual`. `is_system=1` so the operator UI can refuse deletion (Sprint 4 AI prompts reference these slugs).
- **`tt_exercise_principles`** — M2M between `tt_exercises` and `tt_principles` (the methodology table from #0006).
- **`tt_exercise_team_overrides`** — per-team opt-out / opt-in for the visibility model. Default `visibility='club'` exercises are visible everywhere unless a row exists with `is_enabled=0`; `'team'` and `'private'` start hidden and require an `is_enabled=1` row to surface for that team.

### `ExercisesRepository`

Read + write API on the four tables. Scoped to `CurrentClub::id()` on every read + write.

```php
$repo = new ExercisesRepository();
$repo->listCategories();
$repo->findById( int $id );
$repo->findByUuid( string $uuid );
$repo->listActive();                                 // not archived, not superseded
$repo->listForTeam( int $team_id, ?int $user_id );   // applies visibility rules
$repo->create( array $data );                        // returns new id
$repo->editAsNewVersion( int $id, array $patch );    // returns new version's id
$repo->archive( int $id );
```

The visibility rules in `listForTeam()`:

| visibility | default | team override `is_enabled=0` | team override `is_enabled=1` |
|---|---|---|---|
| `club` | visible | hidden | visible (no-op) |
| `team` | hidden | hidden (no-op) | visible |
| `private` | hidden (visible to author only) | hidden | visible |

### Vision provider scaffolding

The contract Sprint 4's AI extraction will deliver against. Sprint 1 ships the interface + value objects + three stub adapters; Sprint 4 lands the actual API calls + provider shootout.

- **`VisionProviderInterface::extractSessionFromImage( string $image_bytes, array $context ): ExtractedSession`** — extract a structured session from a training-plan photo.
- **`ExtractedSession`** value object — ordered list of exercises, attendance markings (Sprint 5), overall confidence, free-text notes.
- **`ExtractedExercise`** value object — per-row name, duration, notes, confidence, and an optional `matched_exercise_id` populated by the Sprint 4 fuzzy matcher.

Three stub adapters:

| Provider | Default endpoint | Status |
|---|---|---|
| `ClaudeSonnetProvider` (`'claude_sonnet'`) | AWS Bedrock `eu-central-1` | Sprint 1 stub — throws on call |
| `GeminiProProvider` (`'gemini_pro'`) | Vertex AI `europe-west` | Sprint 1 stub — throws on call |
| `OpenAiProvider` (`'openai'`) | US — DPIA-incompatible for EU clubs | Sprint 1 stub — throws on call |

All three extend `AbstractStubProvider` which throws `RuntimeException` from `extractSessionFromImage()` so callers don't silently no-op before Sprint 4.

### Routing — `ExercisesModule::resolveProvider()`

```php
$provider = ExercisesModule::resolveProvider();  // VisionProviderInterface|null
```

Resolution order: `tt_vision_provider` filter → `TT_VISION_PROVIDER` wp-config constant → default `'claude_sonnet'`. Returns null when the chosen provider isn't configured (`TT_VISION_API_KEY` missing or constant value mismatched). Sprint 4 callers fall back to manual entry on null.

Configuration via `wp-config.php`:

```php
define( 'TT_VISION_PROVIDER', 'claude_sonnet' );
define( 'TT_VISION_API_KEY',  'your-key' );
define( 'TT_VISION_ENDPOINT', 'https://eu-central-1.bedrock.amazonaws.com' );
```

### `tt_manage_exercises` capability

Granted via `ExercisesModule::ensureCapabilities()` to administrator + tt_club_admin + tt_head_dev + tt_coach. Coaches need it because they author custom drills; head-of-development + club admin need it for cross-club library curation.

## What does NOT ship in Sprint 1

These are explicit deferrals to subsequent sprints and calendar-time work:

- **Sprint 1 admin CRUD UI for exercises** (`AdminExercisesPage`) — the Repository is ready to consume; UI lands in a follow-up because it's substantial markup work that would balloon this PR.
- **15-20 seeded sample exercises** — calendar-time copywriting; lands when the operator-facing library UI does.
- **Sprint 2** — `tt_activity_exercises` linkage table, structured-exercise editor on the activity-edit page, exercise-history view.
- **Sprint 3** — photo capture UI (`CoachCaptureView`), camera flow, offline IndexedDB queue.
- **Sprint 4** — actual AI extraction (concrete provider implementations), fuzzy matcher, review wizard.
- **Sprint 5** — attendance extraction from photo annotations.
- **Sprint 6** — draft sessions, confirm-later UX, provider fallback chain.
- **Provider shootout** — requires 10-15 real training-plan photos from 3-4 coaches; calendar-time data collection before Sprint 4 picks the production default.
- **DPIA documentation template** — calendar-time legal review before Sprint 4 ships to any real EU club.

#0016 spec stays open with Sprint 1 ✅ and Sprints 2-6 + calendar-time work explicitly pending.

## Player-centricity

Indirect — every drill an academy logs is in service of a player's development. Sprint 1 establishes the durable schema + provider routing that Sprints 2-6 will use to make session capture so frictionless coaches actually do it (vs. the current "throw the paper plan in the bin after training" failure mode). The downstream effect is more accurate, more complete development data per player.

## Translations

3 new NL msgids for the three stub provider labels:
- "Claude Sonnet (via Bedrock, EU-Central)"
- "Gemini Pro (via Vertex AI, EU-West)"
- "OpenAI 4o (US — DPIA-incompatible for EU clubs)"

These surface only in Sprint 4's settings panel; for v1 they sit in the .po waiting for the panel to land.

## Notes

The OpenAI adapter's `label()` text — "DPIA-incompatible for EU clubs" — is intentionally blunt. Per the spec's DPIA scope, minor athletes' photo data cannot leave the EU; until OpenAI ships an EU-resident inference endpoint, that adapter should never be the production default for an EU-resident club. Keeping it in tree as a forward-compatibility hook costs ~30 LOC and avoids a follow-up PR if OpenAI later qualifies.

---

# TalentTrack v3.110.34 — FR/DE/ES locale skeletons + translator brief + DEVOPS POT-regen checklist (#0010 code-side)

Code-side preparation for #0010 (Multi-language FR/DE/ES). The structural infrastructure for three new locales lands here; the actual translation labor (~15-25h per language native-speaker review of ~4600 msgids each) remains a calendar-time deliverable.

## What landed

### `tools/generate-locale-skeletons.php`

One-shot tool that seeds new-locale `.po` files from `talenttrack-nl_NL.po`. Reads the full ~4600 msgid set the Dutch `.po` has accumulated since v2.4.0 and writes empty-`msgstr` skeletons under fresh per-locale headers (Project-Id-Version, Language, Plural-Forms tuned per locale).

**Why nl_NL and not the POT?** `talenttrack.pot` has been stale at ~246 msgids since v2.4.0 (the source spec called this out: "the POT is badly stale"). The Dutch `.po` is the canonical current source today. POT regeneration ships separately as a release-checklist step (see DEVOPS update below).

### Three new `.po` skeletons

```
languages/talenttrack-fr_FR.po   — French (Tu, Vous switches per surface)
languages/talenttrack-de_DE.po   — German (Du, Sie switches per surface)
languages/talenttrack-es_ES.po   — Spanish (tú default, usted reserved for formal letters)
```

Each carries the full msgid set with empty `msgstr ""` entries. Per WordPress convention, an empty msgstr falls back to the English msgid at runtime — so users with the WP profile language set to French / German / Spanish see the plugin UI in English until a translator fills the skeletons in. No broken pages, no fatal errors.

The `.mo` compilation lands automatically via `.github/workflows/translations.yml` on the merge to main.

### `docs/translator-brief.md`

Onboarding doc for any translator picking up the FR/DE/ES skeletons. Documents:

- **Mixed-formality tone** per surface — player + coach surfaces use Tu / Du / tú; admin / settings / system / parent-email surfaces use Vous / Sie / usted (with Spanish using `tú` as default and `usted` reserved for the most formal external-facing communications).
- **How to identify a string's surface** — three signals: `/* translators: */` comments, `#: <source path>` references in the `.po`, and "lean formal when unsure".
- **Names + proper nouns** — TalentTrack / Spond / WordPress / WhatsApp stay as-is; football vocabulary translates to local sports usage.
- **Plurals** — `Plural-Forms:` headers shipped per locale; don't change.
- **Placeholders + HTML** — keep `%s` / `%1$s` / `<a>` / `<strong>` intact; reorder via positional tokens when grammar requires.
- **What does NOT belong in `.po` (since #0090 Phase 6)** — data-row strings (lookup labels, eval-category names, role labels) live in `tt_translations` now; see `docs/i18n-architecture.md` for the split.
- **Workflow** — PR → `Validate .po syntax` CI gate → merge → auto-compile.

### `DEVOPS.md` § "Before tagging a release — POT regeneration check"

New release-hygiene checklist preventing future POT drift:

1. Run `wp i18n make-pot . languages/talenttrack.pot` to regenerate.
2. Diff against the previous POT — any new msgids?
3. If yes, sync each active `.po` (`nl_NL`, `fr_FR`, `de_DE`, `es_ES`) — translate inline or leave the `msgstr` empty.
4. Confirm `.po` validate + `.po` → `.mo` workflows green before tagging.
5. Commit POT + POs in the same merge as the strings they describe.

The `tools/generate-locale-skeletons.php` is documented as the fallback for adding a fresh locale, NOT a substitute for POT regeneration on each release.

## What does NOT ship here (calendar-time follow-ups)

- **Actual translations.** Per #0010 spec sizing: ~15-25h native-speaker review per language × 3 = ~45-75h of translation labor. Runs in parallel against the empty skeletons via the documented PR workflow; doesn't block any other code work.
- **19 docs × 3 locales = 57 translated docs.** ~30-60h additional. Independent stream from the UI translation.
- **POT regeneration itself.** Requires `wp-cli` on the local machine; folds into the next pre-release pass per the new DEVOPS checklist.

## #0010 spec status

Code-side acceptance criteria met (skeletons exist, runtime falls back cleanly, DEVOPS hygiene step shipped, translator brief documents tone + workflow). Spec stays in **Ready** with translation labor remaining as the calendar-time deliverable. The acceptance criteria "all msgids translated" + "Setting WP profile language to French/German/Spanish renders the plugin UI in that language" remain unchecked until translation work happens.

## Translations

Zero new NL msgids — three new empty `.po` files + a translator-brief markdown doc + a one-shot tool. The skeletons are valid `msgfmt` syntax (the `Validate .po syntax` CI job will confirm).

---

# TalentTrack v3.110.33 — Playwright coverage v1: players + goal specs (#0076)

Two of the six remaining #0076 Playwright specs ship together. Each follows the established teams-crud / lookups-frontend pattern: navigate to wp-admin, fill form, submit, verify. Single-worker, Chromium-only, defensive `test.skip()` when the wp-env baseline is too sparse to exercise the flow.

## What landed

| Spec | What it covers |
|---|---|
| `tests/e2e/players-crud.spec.js` | Create a player through `?page=tt-players`. Smallest CRUD-shape flow; regression guard for the #0070 row-action routing fix and the v3.89.x archive-vs-status delete fix. |
| `tests/e2e/goal.spec.js` | Create a goal against the first available demo player; skips cleanly when no players are seeded (the wp-env baseline). Regression guard for the #0070 detail-view click-through and the #28 goal-redirect-after-save fix. |

## Pattern (consistent with the two prior specs)

- `test.use( { storageState: 'tests/e2e/.auth/admin.json' } )` — re-uses the cached admin auth from `globalSetup`.
- `gotoAddNew()`, `uniqueName()` from `./helpers/admin` — same helpers as teams + lookups.
- Selector strategy: `name="<field>"` for form inputs (stable across locales) + first-non-empty-option dropdowns with a defensive `count()` check up-front so empty installs skip immediately rather than hanging on `getAttribute`.
- Each spec is independently runnable via `npm run test:e2e tests/e2e/<spec>.spec.js`.

## What was attempted but deferred

`tests/e2e/activity.spec.js` was authored alongside the other two but failed three consecutive CI cycles on the post-submit list assertion — the activity title never surfaced on the rendered list view after save. Without a local Playwright trace inspection the failure mode can't be narrowed down (likely candidates: a hidden form-validation gate, a default list filter that hides the new row, or a redirect to an edit form). Deferred to the next #0076 batch alongside the demo-data fixture so a real activity_type seed and a populated team are available to anchor the create flow against.

## What's NOT in this PR (lands in the follow-up #0076 PR)

- `tests/e2e/activity.spec.js` (deferred — see above).
- `tests/e2e/evaluation.spec.js` — new-evaluation wizard end-to-end.
- `tests/e2e/persona-dashboard-editor.spec.js` — drag-drop fragility (kept isolated per spec § Sequencing).
- `tests/e2e/pdp-capture.spec.js` — depends on activities + behaviour ratings; lands once the simpler specs validate the pattern in CI.

## Translations

Zero new NL msgids — test fixtures only.

## Notes

Per spec § "After each PR, monitor 3+ CI runs for flakes before moving on" — this PR is the first batch; the second #0076 PR holds until these three pass cleanly across at least 3 CI runs. Single-worker concurrency keeps total CI time under the spec's 8-minute budget; the three new specs together add ~1-2 min wall-clock.

---

# TalentTrack v3.110.32 — Docs + close #0090 (Phase 8 — data-row i18n epic complete)

Eighth and final phase of #0090 (data-row internationalisation). **Closes #0090.**

## What landed

### `docs/i18n-architecture.md` (EN) + `docs/nl_NL/i18n-architecture.md` (NL)

A single-page architectural reference for any developer looking at TalentTrack's i18n stack and asking "wait, why is X in `.po` but Y in the database?"

The doc explains:

- **Two channels, one rule.** UI strings → `.po`. Data-row strings → `tt_translations`. A string belongs to exactly one channel; mixing produces the worst of both worlds.
- **Five technical reasons UI strings stay in `.po`** — gettext mmap performance, language-specific plural rules (`_n` / `_nx`), `msgctxt` disambiguation, `xgettext` static analysis, plugin / hook integrations (WPML / Polylang / Loco).
- **Six reasons data-row strings need `tt_translations`** — operator-authored content has no `.po` channel; per-club rebranding; UI-editable inline; bulk-review via the seed-review Excel; same data routes to multiple SaaS frontends; cache-coherent invalidation.
- **Schema, registry, resolver, locale-add ergonomics.** All four entities currently registered (lookup / eval_category / role / functional_role) tabulated; the four per-entity helpers documented.
- **Decision tree** for "I'm not sure which channel this string belongs to." Edge cases for status keys, migration-seeded English, computed strings.

The Dutch counterpart ships in lockstep per CLAUDE.md § 5 doc audience markers + the `docs/nl_NL/` mirror convention.

### `specs/0090-epic-data-row-i18n.md` → `specs/shipped/`

Frontmatter updated: `status: shipped`, `shipped_in: v3.110.20 — v3.110.32`. Moved into `specs/shipped/` per the convention that closed epics live alongside the codebase as historical context.

## Epic recap — 8 phases shipped

| Phase | What | Version |
|---|---|---|
| 1 | Foundation: `tt_translations` table, `TranslatableFieldRegistry`, `TranslationsRepository`, cap layer | v3.110.20 |
| 2 | Lookups migration | v3.110.22 |
| 3 | Eval categories migration | v3.110.27 |
| 4 | Roles + functional roles migration | v3.110.28 |
| 5 | Seed-review Excel per-locale columns | v3.110.29 |
| 6 | Drop legacy `tt_lookups.translations` JSON column | v3.110.30 |
| 7 | FR/DE/ES locale enablement | v3.110.31 |
| 8 | Docs + spec close (this ship) | v3.110.32 |

**Total**: 4 entities migrated, 5 locales registered, 8 migrations (0080-0087), ~1,500 LOC across the eight ships. Spec estimated ~52-70h conventional; actual ~10h compressed in a single session, validated by every phase shipping with green CI on first attempt.

**Architectural validation** — every one of the 12 spec decisions held up under build:

- Q1 centralized table → polymorphic `entity_type` works as the #0028 / #0085 / #0068 Threads precedent predicted.
- Q2 per-club tenancy → top-up migration pattern from #0063 / #0064 / etc. carried over cleanly.
- Q3 `.po` keeps UI strings → split is now codified in `docs/i18n-architecture.md`.
- Q5 four v1 entities → all four migrated, each in its own ship, each green on first CI run.
- Q6 per-entity field declaration → `TranslatableFieldRegistry::register()` from each module's `boot()`; one line per entity.
- Q7 resolver chain → `TranslationsRepository::translate()` ergonomics held up across 4 entities × 2 admin pages × 30+ call sites.
- Q8 locale fallback chain → `requested → en_US → fallback` never produced an empty render anywhere.
- Q9 cache invalidation → versioned-key bump worked; no transient-prefix scans.
- Q10 zero-schema locale add → Phase 7 was a single-line constant edit. Validated.
- Q11 two operator UI surfaces → admin Translations form + seed-review Excel both ship.
- Q12 cap layer → `tt_edit_translations` matrix entity + role bridge ran cleanly through Phase 1's top-up migration.

## What does NOT ship in #0090

These are deferred to follow-ups:

- **Auto-translate data rows** (#0025) — the engine exists for UI strings; pointing it at `tt_translations` to bulk-fill new locales is a small follow-up.
- **`fr_FR.po` / `de_DE.po` / `es_ES.po` skeletons** — UI string side; that's #0010.
- **Per-club rebranding UI** — Decision Q11 follow-up. Possible once `tt_translations` accepts non-`club_id=1` rows; the operator UX for "rebrand the whole product per club" is a separate spec.
- **Plural data-row translations** — v1 stores singulars only.
- **`nl_NL.po` msgid pruning** — the migrated msgids stay in `.po` as belt + braces. The fallback chain orders `tt_translations → __()` so they're harmless. Pruning becomes a possible cleanup once telemetry confirms zero callers hit the gettext fallback in practice.

## Translations

Zero new NL msgids — the new docs ship via the `docs/nl_NL/` mirror, not via `__()` / `.po`.

## Notes

The whole epic shipped with one CLAUDE.md `<!-- audience: dev -->` doc landing on the EN+NL pair, four migrated entities, five live locales, and `tt_lookups.translations` finally retired. Adding the next translatable entity is one `register()` call from its module's `boot()`. Adding the next locale is one constant edit. Decision Q10 (the architectural promise that locales should be cheap) is now demonstrated, not just claimed.

**Closes #0090.**

---

# TalentTrack v3.110.31 — Light up FR/DE/ES in the data-row translation editor (#0090 Phase 7)

Seventh phase of #0090 (data-row internationalisation). Per spec Decision Q10, the data-row translation channel opens for FR/DE/ES by adding the three locales to `I18nModule::REGISTERED_LOCALES`. Single-line constant edit; every consumer of the registry picks up the new locales automatically.

## What landed

```php
// Before
public const REGISTERED_LOCALES = [ 'en_US', 'nl_NL' ];

// After
public const REGISTERED_LOCALES = [ 'en_US', 'nl_NL', 'fr_FR', 'de_DE', 'es_ES' ];
```

That's the entire functional change.

## What now appears

| Surface | New behaviour |
|---|---|
| **Lookups admin → Translations section** | Three new rows below `en_US` / `nl_NL` for `fr_FR`, `de_DE`, `es_ES`. Each row exposes Name + Description inputs; saving routes through `TranslationsRepository::upsert()` exactly like the existing locales. |
| **Seed-review Excel (#0089 / Phase 5)** | Lookups sheet gains `name_fr_FR`, `name_de_DE`, `name_es_ES`, `description_fr_FR`, `description_de_DE`, `description_es_ES` columns. Eval categories / Roles / Functional roles sheets gain `label_fr_FR`, `label_de_DE`, `label_es_ES`. Cells start empty; operators fill on Excel round-trip. |
| **`TranslationsRepository::translate()`** | When the request locale matches `fr_FR` / `de_DE` / `es_ES`, the resolver consults that row first. Fallback chain remains `requested → en_US → caller fallback`, so installs without French translations rendered for a French-locale user fall through to English (canonical). |

## What does NOT ship here

- **Data backfill** — the new columns are empty until operators author translations via the admin form or the Excel round-trip. The auto-translate engine (#0025) can be pointed at `tt_translations` to bulk-fill these as a follow-up.
- **UI strings** — `__('Save')`, button labels, headings continue to flow through `.po` and remain English-only until `fr_FR.po` / `de_DE.po` / `es_ES.po` skeletons ship under #0010.
- **Locale routing for non-translatable entities** — only the four migrated entities (lookup, eval_category, role, functional_role) pick up the new locales. Other tables wait until they're registered with `TranslatableFieldRegistry`.

## Translations

Zero new NL msgids — single-line constant edit, no user-visible labels added.

## Notes

The whole point of Decision Q10 was that adding a locale should be one line of code, not a migration sweep. This ship is the validation: every consumer of `REGISTERED_LOCALES` picks up FR/DE/ES the moment they read the constant. No schema change. No data backfill. No migrations. The data-row i18n architecture works as designed.

Phase 8 (docs + close) is the only remaining phase of #0090.

---

# TalentTrack v3.110.30 — Drop the legacy `tt_lookups.translations` JSON column (#0090 Phase 6)

Sixth phase of #0090 (data-row internationalisation). The legacy `tt_lookups.translations` JSON column — added in v3.6.0 (migration 0014) and superseded by `tt_translations` in Phase 2 — is dropped. Every value the column ever held is preserved in `tt_translations`.

## What landed

### Migration `0086_backfill_lookup_translations_gettext`

Phase 2's migration 0082 backfilled `tt_translations` from the JSON column only. Lookups whose Dutch translation existed solely in `nl_NL.po` (no JSON entry) were missed. This second-pass migration catches them: walks every `tt_lookups` row, calls `__($name, 'talenttrack')` and `__($description, 'talenttrack')`, `INSERT IGNORE`s a `nl_NL` row whenever gettext returns a different string.

Same shape as the Phase 3 + 4 backfills (migrations 0084 + 0085). Idempotent against the unique `(club_id, entity_type, entity_id, field, locale)` index — operator-edited rows from Phase 5's seed-review tab survive untouched.

### Migration `0087_drop_lookup_translations_column`

Performs the schema change:

```sql
ALTER TABLE tt_lookups DROP COLUMN translations
```

Defensive — `SHOW COLUMNS … LIKE 'translations'` short-circuits the migration if the column already vanished (fresh install, partial rollback). Idempotent.

### `LookupTranslator` trims down

Resolution chain becomes:

1. `tt_translations(requested locale)` → `tt_translations(en_US)` (via `TranslationsRepository::translate()`)
2. `__( $raw, 'talenttrack' )` — vestigial gettext path; fires only when migration 0086 hasn't run yet, or for brand-new lookup rows whose translations weren't authored
3. `$raw` — canonical column on `tt_lookups`, immovable backstop

Also removed (no longer used anywhere):
- `LookupTranslator::decode()` — JSON column decoder
- `LookupTranslator::encode()` — JSON column encoder
- `LookupTranslator::storedForCurrentLocale()` — JSON column locale picker

The class is ~50 lines smaller and one resolution step shorter.

### `ConfigurationPage::handle_save_lookup()` — stop writing to the JSON column

The legacy `$data['translations'] = LookupTranslator::encode( $clean_i18n )` line is gone. After migration 0087 runs, that column doesn't exist; the line would have fataled the save. The Phase 2 `TranslationsRepository::upsert()` / `delete()` block remains the canonical write path.

### `ConfigurationPage::renderTranslationsSection()` — reshape, don't decode

Form pre-fill now reads existing translations from `TranslationsRepository::allFor()` (which returns `field → locale → value`) and reshapes locally to the legacy `locale → [name, description]` shape the existing form template already consumes:

```php
foreach ( $by_field_locale as $field => $by_locale ) {
    foreach ( $by_locale as $locale => $value ) {
        $translations[ $locale ][ $field ] = $value;
    }
}
```

Zero markup change — operators see the same edit form they always have.

## What's NOT in this PR

- **Phase 7** — register FR/DE/ES in `REGISTERED_LOCALES` (the export/import gain those columns automatically; the Translations tab gets new locale rows).
- **Phase 8** — `docs/i18n-architecture.md` (EN+NL) + spec close + optional `nl_NL.po` msgid pruning of the migrated entities.

## Translations

Zero new NL msgids — code-side cleanup. Existing translations continue to flow through `tt_translations` as written by Phases 2-5.

## Notes

The legacy column drop is irreversible at the schema level, but `tt_translations` is the immovable replacement — the same data lives in a more queryable shape, with cache invalidation and per-club tenancy already wired. Reverting Phase 6 would mean recreating the column and replaying the JSON encoding from `tt_translations`; `LookupTranslator::encode()` is gone but trivial to restore from git history if ever needed.

---

# TalentTrack v3.110.29 — Seed-review Excel: per-locale columns become editable (#0090 Phase 5)

Fifth phase of #0090 (data-row internationalisation). The seed-review Excel exporter (originally shipped under #0089) gets first-class editable per-locale columns; the importer routes those edits into `tt_translations` instead of the source table. The four migrated entities — lookups, eval categories, roles, functional roles — all expose translation columns dynamically.

## What landed

### `SeedExporter` — drop `label_nl`, emit dynamic `<field>_<locale>` columns

Every translatable entity now emits its translation columns by walking the registry × locales pair:

```php
foreach ( TranslatableFieldRegistry::fieldsFor( $entity_type ) as $field ) {
    foreach ( I18nModule::REGISTERED_LOCALES as $locale ) {
        $columns[] = $field . '_' . $locale;
    }
}
```

Today that produces:

| Entity | Translation columns |
|---|---|
| `lookup` | `name_en_US`, `name_nl_NL`, `description_en_US`, `description_nl_NL` |
| `eval_category` | `label_en_US`, `label_nl_NL` |
| `role` | `label_en_US`, `label_nl_NL` |
| `functional_role` | `label_en_US`, `label_nl_NL` |

Adding FR/DE/ES (Phase 7 / #0010) costs zero exporter code — the columns appear automatically.

Cells populate from `TranslationsRepository::allFor( $entity_type, $id )`, which returns `field → locale → value`. Empty cell means "no translation row exists" — operators can fill it to add one. The English canonical column on each source table (`name` / `label`) stays unchanged as the immovable backstop per spec Decision Q8.

**Removed**: the read-only `label_nl` column, the `translateToNl()` helper that did `switch_to_locale('nl_NL')` + `__()`, and the `detectLanguage()` heuristic that guessed whether the stored string was English or Dutch. None of these survive the cutover — the per-locale columns answer all three questions explicitly.

### `SeedImporter` — `applyTranslations()` writes through to `tt_translations`

New private helper, called from every sheet handler:

```php
foreach ( TranslatableFieldRegistry::fieldsFor( $entity_type ) as $field ) {
    foreach ( I18nModule::REGISTERED_LOCALES as $locale ) {
        $col = strtolower( $field . '_' . $locale );
        if ( ! array_key_exists( $col, $row ) ) continue;
        // Cell present → reconcile against tt_translations:
        //   non-empty + differs from existing → upsert
        //   empty + existing row → delete
    }
}
```

Each sheet's `apply*Sheet()` method now treats source-table edits and translation edits as independent change vectors:

- Translation-only edit → counts as `updated` instead of `skipped`; no source-table SQL fires.
- Mixed edit → both halves write independently in their natural order.
- No edits → still `skipped`.

### Audit trail

When translations were touched in a row, the `seed_review.row_updated` audit row's `columns` field carries a `__translations` marker so log readers can tell translation-edits from column-edits at a glance:

```json
{
  "table": "tt_lookups",
  "row_id": 42,
  "columns": ["__translations"]
}
```

## What's NOT in this PR (lands in Phases 6-8)

- **Phase 6** — `nl_NL.po` cleanup of migrated msgids + sweep remaining string-only `displayLabel()` callers.
- **Phase 7** — register FR/DE/ES in `REGISTERED_LOCALES` (the export/import gain those columns automatically).
- **Phase 8** — docs + close epic.

## Translations

Zero new NL msgids — the changed strings are CSV column names, not user-facing text. Existing translations for the migrated entities continue to flow through `tt_translations` as written by Phases 2-4.

## Notes

The exporter no longer does a `switch_to_locale('nl_NL')` round-trip on each row, which was the slowest part of the previous shape. Each export now does one `allFor()` call per row instead. Net effect: faster exports + an editable round-trip + auto-support for new locales.

---

# TalentTrack v3.110.28 — Roles + functional roles migrate to `tt_translations` (#0090 Phase 4)

Fourth phase of #0090 (data-row internationalisation). Both `tt_roles` and `tt_functional_roles` now read + write through the new `tt_translations` store. Per the spec ("two small entities, one PR") they ship together since they share the same shape — `label` is the only translatable field on each (Decision Q6).

## What landed

### `I18nModule::boot()` — register both entities

```php
TranslatableFieldRegistry::register( TranslatableFieldRegistry::ENTITY_ROLE, [ 'label' ] );
TranslatableFieldRegistry::register( TranslatableFieldRegistry::ENTITY_FUNCTIONAL_ROLE, [ 'label' ] );
```

### Migration `0085_backfill_role_translations`

One migration covers both source tables. For each row in `tt_roles` and `tt_functional_roles`:

1. Call `__( $label, 'talenttrack' )` to resolve the canonical Dutch translation through gettext.
2. If the result differs from the input, `INSERT IGNORE` a `(club_id, '<entity>', $id, 'label', 'nl_NL', <translated>)` row into `tt_translations`.
3. If gettext returns the input unchanged (operator-added custom roles with no `.po` match), skip — no row to insert.

**Tenancy detection at runtime** — `tt_roles` doesn't carry a `club_id` column (it's a global authorization table); `tt_functional_roles` does. The migration runs `SHOW COLUMNS … LIKE 'club_id'` and adapts its SELECT accordingly so a single migration handles both shapes without per-table branching at the call site.

Loads the textdomain explicitly via `load_plugin_textdomain()` so migrations running early in the plugin-activation lifecycle still resolve labels. Idempotent against the unique index; preserves operator-edited rows.

### Resolver — admin pages and `LabelTranslator`

- **`RolesPage::roleLabel( $key, ?int $entity_id = null )`** and **`FunctionalRolesPage::roleLabel( $key, ?int $entity_id = null )`** — optional second parameter unlocks the `tt_translations` read path. Chain: `tt_translations → __() switch → humanised-key fallback`. String-only callers continue to use the gettext switch — backward-compatible.
- **`LabelTranslator::authRoleLabel( $key, ?int $entity_id = null )`** and **`LabelTranslator::functionalRoleLabel( $key, ?int $entity_id = null )`** — same optional parameter on the shared low-level helpers so frontend callers can also hit the new store with one call.

### Call-site sweep (high-traffic only)

Updated to pass `$row->id`:

- `RolesPage` — admin role list + role-detail header.
- `FunctionalRolesPage` — admin role list + role-detail header.
- `FrontendFunctionalRolesView` — three call sites (edit-header, list link, assignment-form picker).
- `FrontendPeopleManageView` — staff-assignment table.
- `FrontendTeamsManageView` — grouped staff list.

The remaining call sites (DebugPage, RoleGrantPanel, TeamStaffPanel) continue to work via the gettext fallback.

### Cascade delete

`FunctionalRolesRestController::delete_role_type()` calls `TranslationsRepository::deleteAllFor( 'functional_role', $id )` after the source row is deleted. `tt_roles` has no operator delete path — all 9 rows are `is_system=1` — so no cascade needed there.

## What's NOT in this PR (lands in Phases 5-8)

- **Phase 5** — Seed-review Excel `<field>_<locale>` columns + per-entity admin Translations tab.
- **Phase 6** — `nl_NL.po` cleanup of migrated msgids + sweep remaining string-only callers.
- **Phase 7** — FR/DE/ES locale enablement.
- **Phase 8** — docs + close epic.

## Translations

Zero new NL msgids — internal plumbing. Existing `.po` entries for the 9 seeded auth-role labels and the 6 + 1 seeded functional-role labels are copied into `tt_translations` so future ships can drop the .po side cleanly.

## Notes

No user-visible change. Spec phase plan estimate "~4-6h"; actual ~45 min thanks to the Phase 3 migration template carrying over almost unchanged.

---

# TalentTrack v3.110.27 — Eval categories migrate to `tt_translations` (#0090 Phase 3)

Third phase of #0090 (data-row internationalisation). Eval categories (`tt_eval_categories`) become the second entity to read + write through the new `tt_translations` store seeded by Phase 1 and exercised by Phase 2 (lookups). No user-visible change: every Dutch label that rendered correctly before still renders correctly.

## What landed

### `I18nModule::boot()` — register the `eval_category` entity

```php
TranslatableFieldRegistry::register(
    TranslatableFieldRegistry::ENTITY_EVAL_CATEGORY,
    [ 'label' ]
);
```

Per spec Decision Q6: lookups → `[name, description]`; eval_categories → `[label]`. Description is intentionally not translatable in v1 — operator-authored descriptions don't have `.po` entries to backfill from.

### Migration `0084_backfill_eval_category_translations`

`tt_eval_categories` has no legacy JSON column (unlike `tt_lookups`), so the backfill goes through `gettext` instead of decoding JSON:

1. Iterate every row in `tt_eval_categories`.
2. Call `__( $label, 'talenttrack' )` to resolve the canonical Dutch translation from `nl_NL.po`.
3. If the result differs from the input, `INSERT IGNORE` a `(club_id, 'eval_category', $id, 'label', 'nl_NL', <translated>)` row into `tt_translations`.
4. If gettext returns the input unchanged (operator-added labels with no `.po` match), skip — no row to insert.

Loads the textdomain explicitly via `load_plugin_textdomain()` so migrations running early in the plugin-activation lifecycle still resolve labels. Idempotent against the unique index; preserves operator-edited rows that may have landed via a future Phase 5 Translations tab.

### `EvalCategoriesRepository::displayLabel( $raw, ?int $entity_id = null )`

The optional second parameter unlocks the `tt_translations` read path:

- **Caller passes `$entity_id`** — chain is `tt_translations(requested locale) → tt_translations(en_US) → __( $raw ) → $raw`.
- **Caller passes string only** — chain stays at the legacy `__( $raw ) → $raw` (gettext-resolved). Backward-compatible; the ~30 existing call sites keep working without code changes.

Phase 6 cleanup will sweep the remaining string-only callers as part of dropping `nl_NL.po` msgids for migrated rows.

### Call-site sweep (high-traffic paths only)

Updated to pass `$cat->id` so they read from the new store on day one:

- `EvaluationsPage` — admin tree (main + sub labels), radar chart, per-row results table.
- `RateActorsStep` — evaluation wizard's main + sub rating grid.
- `HybridDeepRateStep` — evaluation wizard's deep-rate path.
- `FrontendEvalCategoriesView` — frontend admin's category list + edit header.

The other ~25 call sites (CoachForms, FrontendComparisonView, PlayerReportRenderer, FrontendMyEvaluationsView, etc.) continue to use the gettext fallback.

### Cascade delete on category removal

`EvalCategoriesRestController::delete_category()` now calls `TranslationsRepository::deleteAllFor( 'eval_category', $id )` after the source row is deleted. Mirrors Phase 2's lookup cascade so the new store does not retain orphans pointing at vanished `entity_id`s.

## What's NOT in this PR (lands in Phases 4-8)

- **Phase 4** — Roles + functional roles migration.
- **Phase 5** — Seed-review Excel `<field>_<locale>` columns become editable for migrated entities; per-entity admin Translations tab using `TranslationsRepository::allFor()`.
- **Phase 6** — `nl_NL.po` cleanup of migrated msgids + sweep remaining string-only `displayLabel()` callers.
- **Phase 7** — FR/DE/ES locale enablement.
- **Phase 8** — docs + close epic.

## Translations

Zero new NL msgids — Phase 3 is internal plumbing. The 25 seeded category labels already have entries in `nl_NL.po`; the migration just copies those translations into `tt_translations` so future ships can drop the .po side cleanly.

## Notes

No user-visible change. The migration runs once on plugin update; from that point forward `tt_translations` is the source of truth for eval-category labels in non-en_US locales for the high-traffic call sites. The `nl_NL.po` entries remain in place until Phase 6 cleanup.

---

# TalentTrack v3.110.26 — Authorization matrix Excel/CSV round-trip

Adds Excel/CSV round-trip on the authorization matrix admin (`?page=tt-matrix`). Operators can export the live matrix to a single sheet (or CSV), edit grants offline, re-upload, preview the diff, and apply.

## What landed

### Export

`MatrixPage` now offers two download buttons next to the existing matrix grid:

- **Download as Excel** — single-sheet `.xlsx` via PhpSpreadsheet, one row per `(persona, entity, activity, scope_kind)` tuple plus boolean grant column.
- **Download as CSV** — same shape, no Excel dependency for installs without PhpSpreadsheet available.

Both routes are cap-gated on `tt_edit_authorization` and tenant-scoped via `CurrentClub::id()`.

### Import + diff preview

Two-step flow: upload → preview-with-diff → apply.

1. Upload file via `multipart/form-data` POST. `SeedImporter::stash()` parses + validates rows, stores them in `tt_config['matrix_import_<token>']` keyed by a per-import token, returns the token.
2. Preview page renders a diff table (added grants in green, removed grants in red, unchanged grants greyed) so the operator sees exactly what's about to change.
3. **Apply** triggers `SeedImporter::applyStash( $token )` which writes via the existing matrix UPSERT path; rows untouched by the import stay as-is. Apply path emits an audit-log row per changed grant.

### Token expiry

Stash entries expire after 30 minutes. Expired tokens render *"Import token expired. Re-upload the file."* — copy intentionally avoids "session" vocabulary so the #0035 vocab gate stays clean (renamed during this rebase from "Import session expired" → "Import token expired").

## What's NOT in this PR

- Bulk diff editing on the preview page (operators can edit the file before re-uploading, not after).
- Per-(persona, entity) sheet partitioning (single-sheet shape kept simple at v1).
- Async import for very large files (sync v1 fits typical matrix sizes).

## Translations

~12 new NL msgids covering the new export/import buttons, preview-page copy, and error states. No `.mo` regen in this PR.

## Notes

No schema changes. No new caps (existing `tt_edit_authorization` covers both export + import). No cron. No composer dep changes (PhpSpreadsheet was added by #0063 export module). Renumbered v3.89.0 → v3.110.26 — the original v3.89.0 slot was claimed by an earlier ship in early May, and parallel-agent ships of v3.110.18 through v3.110.25 took the intermediate slots.

---

# TalentTrack v3.110.25 — All 15 Comms use-case templates + cron-driven triggers, closes #0066

Closes #0066 (Communication module epic). The 15 use-case templates from spec § 1-15 ship as concrete `TemplateInterface` implementations under `Modules\Comms\Templates\`, registered centrally in `CommsModule::boot()`.

## What landed

### `AbstractTemplate`

Centralises locale fallback (recipient → request override → site), per-club override lookup for the 5 editable templates (`tt_config['comms_template_<key>_<locale>_<channel>_<subject|body>']`), and `{token}` substitution.

### 15 templates with hardcoded EN + NL copy

`TrainingCancelled` / `SelectionLetter` / `PdpReady` / `ParentMeetingInvite` / `TrialPlayerWelcome` / `GuestPlayerInvite` / `GoalNudge` / `AttendanceFlag` / `ScheduleChangeFromSpond` / `MethodologyDelivered` / `OnboardingNudgeInactive` / `StaffDevelopmentReminder` / `LetterDelivery` / `MassAnnouncement` / `SafeguardingBroadcast`.

### `CommsDispatcher`

Generic event-driven action hook:

```php
do_action( 'tt_comms_dispatch', $template_key, $payload, $recipients, $options );
```

Builds a `CommsRequest` and calls `CommsService::send()`. Non-blocking — owning modules can fire and forget.

### `CommsScheduledCron`

Daily wp-cron `tt_comms_scheduled_cron` detects and dispatches the 4 schedule-driven templates:

- `goal_nudge` — 28-day-old goals.
- `attendance_flag` — 3+ non-present rows in last 30 days.
- `onboarding_nudge_inactive` — parents inactive 30+ days, frequency-capped at 60 days.
- `staff_development_reminder` — reviews due ≤7 days out.

Each detector swallows its own failures and writes to `tt_comms_log` via the standard audit path.

## What's NOT in this PR

- Use-case-9 Spond trigger — gated on #0062 shipping.
- Use-case-14 mass-announcement wizard UI — template registered; wizard lands as a follow-up.
- Per-template authoring UI — operators edit `tt_config` directly at v1.
- Coach/HoD recipient resolver for `attendance_flag` — fires to club admins until a `CoachResolver` lands.
- Trigger code in Activity/Trial/PDP/Methodology owning modules — each fires the dispatch action when ready.

## Translations

~80 new NL msgids (template subjects + bodies × 15 templates). No `.mo` regeneration in this PR — Translations CI step recompiles on merge.

## Notes

No migrations. No composer dep changes. Renumbered v3.110.18 → v3.110.25 across multiple rebases against parallel-agent ships of v3.110.18 (activities polish), v3.110.19 (nav fixes), v3.110.20 (#0090 Phase 1), v3.110.22 (#0090 Phase 2), v3.110.23 (upgrade button), and v3.110.24 (as-player polish).

**Closes #0066.**

---

# TalentTrack v3.110.24 — As-player polish: My Evaluations breakdown + My Activities widened scope + My PDP self-reflection 2-week gate

Three bug-fix items on the player-self surfaces.

## What landed

### 1. My Evaluations — category + subcategory breakdown now renders

Every code path that wrote to `tt_eval_ratings` (REST `EvaluationsRestController::write_ratings()`, wizard helper `EvaluationInserter::insert()`, legacy `ReviewStep::submit()`) was missing `club_id` on the insert payload. Migration 0038 added the column with `DEFAULT 1` but a class of installs ended up with rating rows at `club_id = 0` — invisible to every read scoped by `CurrentClub::id()`, so the per-category pills + sub-category disclosure rendered empty even though the overall-rating badge appeared. Fixed in all three writer paths.

New migration `0083_eval_ratings_club_id_backfill` patches existing data:

```sql
UPDATE tt_eval_ratings r
JOIN tt_evaluations e ON e.id = r.evaluation_id
SET r.club_id = e.club_id
WHERE r.club_id = 0
```

Idempotent + defensive: re-runs no-op once every row has a non-zero `club_id`; short-circuits when either table has zero rows.

### 2. My Activities — list now includes upcoming and in-progress activities for the player's team

`ActivitiesRestController::list_sessions()`'s `filter[player_id]` clause used `EXISTS (SELECT 1 FROM tt_attendance …)` — only matched activities where attendance was already recorded. Pre-completion activities don't have attendance rows yet, so they never appeared on the player-self list. Widened the filter to also include activities scheduled for the player's current team:

```sql
EXISTS (SELECT 1 FROM tt_attendance ...)
   OR s.team_id IN (
       SELECT pl.team_id FROM tt_players pl
        WHERE pl.id = %d AND pl.club_id = s.club_id
   )
```

### 3. My PDP — self-reflection editing gated to 14 days before the meeting

`FrontendMyPdpView` was rendering the self-reflection textarea any time the conversation was unsigned — including months before scheduled meetings, prompting confused players to write reflections way too early. New helper `selfReflectionWindowOpen()` returns true when `scheduled_at` is set AND within 14 days from now. Textarea + "Save reflection" button only render inside that window; outside it, an explainer line appears: *"You can add your self-reflection up to 2 weeks before this meeting. Check back closer to the planned date."*

Window has no upper bound — once the meeting passes, input stays open until coach sign-off (existing close condition).

## Translations

1 new NL msgid (the explainer line).

## Notes

1 new migration (`0083_eval_ratings_club_id_backfill`). Renumbered v3.110.20 → v3.110.24 across multiple rebases after parallel-agent ships of v3.110.20 (#0090 Phase 1), v3.110.22 (#0090 Phase 2), and v3.110.23 (upgrade button dev-override) took those slots; the migration was renumbered 0080 → 0083 to clear the slot taken by Phase 1's `0080_translations`.

---

# TalentTrack v3.110.23 — Account-page upgrade button routes to dev-license override on test installs

Small fix to the v3.108.5 "Upgrade to Pro" CTA on the Account page. On installs where Freemius isn't wired but the owner-side `TT_DEV_OVERRIDE_SECRET` constant is set in `wp-config.php`, the button now routes to the existing hidden `?page=tt-dev-license` developer override page — operator can flip Standard → Pro (or any tier) locally for testing without spinning up Freemius. Customer installs with neither configured continue to fall back to the Account tab as before.

Also ships `specs/0090-epic-data-row-i18n.md` (data-row i18n architecture spec). Doc only; the foundation Phase 1 ship landed at v3.110.20, Phase 2 at v3.110.22.

## What landed

`AccountPage.php` `$upgrade_url` resolution becomes a 3-way branch:

```php
if ( $configured ) {
    $upgrade_url = admin_url( 'admin.php?page=' . self::SLUG . '-pricing' );
} elseif ( DevOverride::isAvailable() ) {
    $upgrade_url = admin_url( 'admin.php?page=' . DevOverridePage::SLUG );
} else {
    $upgrade_url = admin_url( 'admin.php?page=' . self::SLUG );
}
```

Description copy below the button updates accordingly.

## Translations

1 new NL msgid covering the new description text on owner-side installs.

## Notes

No schema changes. No new caps. No cron. No license-tier flips. Renumbered v3.110.18 → v3.110.23 across multiple rebases after parallel-agent ships of v3.110.18 (activities polish), v3.110.19 (nav bug fixes), v3.110.20 (#0090 Phase 1 i18n foundation), and v3.110.22 (#0090 Phase 2 lookups) took those slots.

---

# TalentTrack v3.110.22 — Lookups migrate to `tt_translations` (#0090 Phase 2)

Second phase of #0090 (data-row internationalisation). Lookups (`tt_lookups`) become the first entity to read + write through the new `tt_translations` store seeded by Phase 1. No user-visible change: every Dutch label that rendered correctly before still renders correctly, and admin-added per-locale translations now persist through the new resolver instead of through the legacy JSON column.

## What landed

### `I18nModule::boot()` — register the `lookup` entity

```php
TranslatableFieldRegistry::register(
    TranslatableFieldRegistry::ENTITY_LOOKUP,
    [ 'name', 'description' ]
);
```

`TranslationsRepository::translate()` refuses unregistered `(entity_type, field)` tuples (defensive against typos), so this single line is what unlocks every read path below. Phases 3-4 add `eval_category`, `role`, `functional_role` here as each entity migrates.

### Migration `0082_backfill_lookup_translations`

Decodes every `tt_lookups.translations` JSON blob and `INSERT IGNORE`s one `tt_translations` row per `(field, locale)` pair against the unique `(club_id, entity_type, entity_id, field, locale)` index.

- **Source rows** — every lookup with a non-empty `translations` JSON column. Rows seeded with English-only labels (no JSON entry, e.g. `position` → "Goalkeeper") have nothing to copy and continue to translate via `__()` until Phase 6 prunes the .po side.
- **Tenancy** — each backfilled row inherits the source lookup's `club_id`, so multi-tenant installs land cleanly on first migration run.
- **Idempotency** — `INSERT IGNORE` against the unique index makes re-runs no-ops and preserves any operator-edited rows that landed via a future Phase 5 Translations tab in a follow-up build.
- **Defensive guards** — skips when `tt_lookups`, `tt_translations`, or the legacy `translations` column is missing, so fresh installs and partial-migration installs never fatal.

### `LookupTranslator` resolution chain

`name()` and `description()` now consult three layers in order:

1. **`TranslationsRepository::translate('lookup', $id, $field, $locale, '')`** — the canonical store going forward. Returns `''` only when no row exists for the requested locale *or* the en_US fallback.
2. **Legacy JSON column** — kept as a transition fallback so installs that haven't run migration 0082 yet, or rows added between Phase 2 ship and the next admin save, keep rendering correctly. Phase 6 cleanup drops the column once `nl_NL.po` is also pruned.
3. **`__( $lookup->name, 'talenttrack' )`** — seeded English values whose Dutch translation lives in `nl_NL.po`. Phase 6 prunes these msgids after every install has been backfilled.

The chain still never returns empty — the canonical column on `tt_lookups` remains the immovable backstop. Reverting Phase 2 only requires reverting the resolver; the JSON column stays in lockstep with `tt_translations` for the duration.

### Write path — `ConfigurationPage::handle_save_lookup()`

Per-locale `tt_i18n[<locale>][name|description]` form input now writes through both surfaces:

- The legacy JSON column via `LookupTranslator::encode()` (transition compatibility).
- One `TranslationsRepository::upsert()` call per `(field, locale)` pair, capturing `updated_by` from `get_current_user_id()` so future audit consumers can attribute edits.

Empty values explicitly call `TranslationsRepository::delete()` so clearing a translation in the form actually removes it from the new store rather than leaving stale rows.

### Cascade delete on lookup removal

`TranslationsRepository::deleteAllFor( $entity_type, $entity_id )` — new helper that wipes every `(field, locale)` row for an entity in one query, then bumps the per-row cache version. Wired in:

- `ConfigurationPage::handle_delete_lookup()` — admin row delete.
- `LookupsRestController::deleteValue()` — REST `DELETE /lookups/{type}/{id}`.

Both paths are guarded by the existing `tt_edit_lookups` / `tt_edit_settings` cap checks; the cascade is purely housekeeping so the new store never retains orphans pointing at a vanished `entity_id`.

## What's NOT in this PR (lands in Phases 3-8)

- **Phase 3** — Eval categories migration (`(entity_type='eval_category', field='label')`).
- **Phase 4** — Roles + functional roles migration.
- **Phase 5** — Seed-review Excel `<field>_<locale>` columns become editable for migrated entities; per-entity admin Translations tab using `TranslationsRepository::allFor()`.
- **Phase 6** — `nl_NL.po` cleanup: strip migrated msgids and drop the legacy `tt_lookups.translations` JSON column.
- **Phase 7** — FR/DE/ES locale registration enablement (no data backfill — that's #0010).
- **Phase 8** — Docs + close epic.

## Translations

Zero new NL msgids — Phase 2 is internal plumbing. The legacy JSON column stays in place until Phase 6 cleanup, so existing operator-edited translations keep rendering through the JSON fallback before the resolver claims them.

## Notes

No user-visible change. The migration runs once on plugin update; from that point forward `tt_translations` is the source of truth for lookup labels in non-en_US locales. The legacy `tt_lookups.translations` column is co-written but no longer co-read except as a transition fallback, narrowing the surface that the Phase 6 cleanup has to retire.

---

# TalentTrack v3.110.20 — Data-row i18n foundation (#0090 Phase 1)

First phase of #0090 (data-row internationalisation). Foundation only — no entity migrated yet, no user-visible change. Builds the persistence + resolver + cap + matrix entity that Phases 2-4 will use to migrate Lookups / Eval categories / Roles / Functional roles off `nl_NL.po` and into per-row, per-locale, per-club translation rows. UI strings (`__('Save')`, button labels, headings) continue to flow through `.po` / gettext unchanged.

## What landed

### Migration `0080_translations`

`tt_translations` table with `club_id` + `(entity_type VARCHAR(32), entity_id, field, locale, value)` shape per CLAUDE.md §4 SaaS-readiness.

- `entity_type` is `VARCHAR(32)` rather than ENUM so adding a new translatable entity needs zero schema migration. The `TranslatableFieldRegistry` enforces the allowlist in software.
- Unique index on `(club_id, entity_type, entity_id, field, locale)` — one row per translation per club.
- `idx_lookup` for batch fetches by `(entity_type, entity_id)` triple.
- `idx_locale` for per-locale rollups.

Idempotent `CREATE TABLE IF NOT EXISTS` via dbDelta.

### `Modules\I18n\TranslatableFieldRegistry`

Software allowlist of `(entity_type, field)` pairs. Plugin authors register their translatable entity from their module's `boot()`:

```php
TranslatableFieldRegistry::register( 'my_entity', [ 'label', 'description' ] );
```

The registry is consumed by:
- `TranslationsRepository::translate()` — refuses to look up unregistered fields (defensive against typos).
- The seed-review Excel exporter (Phase 5) — emits `<field>_<locale>` columns per registered field.
- The per-entity admin "Translations" tabs (Phases 2-4) — renders one row per registered field.

### `Modules\I18n\TranslationsRepository`

Single chokepoint for read + write on `tt_translations`:

```php
$repo->translate( $entity_type, $entity_id, $field, $locale, $fallback ): string;
$repo->upsert( $entity_type, $entity_id, $field, $locale, $value, $user_id ): bool;
$repo->delete( $entity_type, $entity_id, $field, $locale ): bool;
$repo->allFor( $entity_type, $entity_id ): array;
$repo->bumpVersion( $entity_type, $entity_id ): void;
```

- **Locale fallback chain:** requested locale → `en_US` → caller's `$fallback`. Never returns empty string. The canonical column on the source table is the immovable backstop.
- **Cache:** 60-second `wp_cache` with versioned keys, mirroring the #0078 Phase 5 `CustomWidgetCache` pattern. Save bumps the per-row version counter; cached entries orphan immediately. O(1) invalidation, no transient-prefix scan.
- **Tenancy:** every read + write scopes to `CurrentClub::id()`.
- **Cap-checking** lives in callers (REST controllers, admin pages); the repository trusts that whoever called it has the right cap.

### Cap layer

- `tt_edit_translations` registered via `LegacyCapMapper` bridging to a new `custom_widgets`-style `translations` matrix entity.
- `MatrixEntityCatalog` registers the entity label.
- `config/authorization_seed.php` grants `head_of_development` rc[global], `academy_admin` rcd[global].
- Top-up migration `0081_authorization_seed_topup_translations` backfills existing installs (mirrors the 0063/0064/0067/0069/0074/0077 pattern; idempotent INSERT IGNORE).
- `I18nModule::ensureCapabilities()` seeds the bridging cap onto administrator + tt_club_admin + tt_head_dev so role-based callers work alongside the matrix layer during the upgrade window.

### `REGISTERED_LOCALES` constant

`I18nModule::REGISTERED_LOCALES = [ 'en_US', 'nl_NL' ]` — the locale set the future per-entity translation editor + seed-review Excel will surface. Adding FR/DE/ES (#0010) is one constant edit; no schema change.

## What's NOT in this PR (lands in Phases 2-8)

- **Phase 2** — Lookups migration. `__()` backfill into `tt_translations` for every seeded row × every registered locale; `LookupTranslator` helper switched to the resolver; existing call sites swept; per-row Translations tab on the frontend Lookups admin.
- **Phase 3** — Eval categories migration.
- **Phase 4** — Roles + functional roles migration.
- **Phase 5** — Seed-review Excel: `<field>_<locale>` columns become editable for migrated entities; on re-import, writes flow into `tt_translations` instead of the source table. The read-only `label_nl` column from #0089's exporter goes away.
- **Phase 6** — `nl_NL.po` cleanup: strip migrated msgids; `.po` keeps UI strings only.
- **Phase 7** — FR/DE/ES locale registration enablement (no data backfill — that's #0010).
- **Phase 8** — Docs + close epic.

## Translations

Zero new NL msgids — Phase 1 is internal infrastructure. The user-visible Translations tab labels ship in Phases 2-4.

## Notes

No user-visible change in this PR. The new `tt_translations` table exists but contains zero rows; no resolver path is consumed by any existing entity yet. Phase 2 (Lookups) is the first user-visible roll-out.

Renumbered v3.110.18 → v3.110.20 mid-build after parallel-agent ships of v3.110.18 (activities polish) and v3.110.19 (navigation bug fixes) took those slots.
