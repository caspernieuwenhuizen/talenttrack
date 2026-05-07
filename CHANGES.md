# TalentTrack v3.110.3 — Player profile polish: profile-tab table + tab-list bugs + analytics 30-day card + notes wiring

Pilot polish round on the player profile (`?tt_view=players&id=N`). Eight items shipped:

## What landed

### Profile tab — proper table per section

Replaced the `<dl>` / `<dt>` / `<dd>` two-column layout with a real `<table>` per section (Identity / Academy). Each table has a section-name `<thead>` row spanning both columns, then `<tbody>` rows with `<th scope="row">` field labels and `<td>` values. Visually distinct (white card with subtle border + zebra-striped row separators), semantically correct, and easier to read at a glance. Mobile-first CSS keeps the field column at ~40% and the value column dominant.

### Goals + Evaluations CTA preserves player context

The "Add first goal" and "Record first evaluation" CTAs on the player profile's empty Goals / Evaluations tabs already passed `?player_id=N`, but the receiving forms ignored it and still rendered a player picker. Now both forms detect the URL parameter, pre-fill the player (and the team for evaluations, derived from `tt_players.team_id`), and **hide the picker** entirely. The eval form replaces the dropdowns with a small "Recording evaluation for *Name*" headline so the operator stays oriented. Same shape as v3.108.4's PDP-form preset path.

### Evaluations tab — list now matches the badge

The evaluations-tab query did `LEFT JOIN tt_eval_types et ON et.id = e.eval_type_id` to surface a type label, but `tt_eval_types` doesn't exist in the schema (eval typing moved to `tt_eval_categories` long ago). The JOIN errored silently and `wpdb->get_results()` returned empty — so the badge counted 1+ but the tab still rendered the "No evaluations yet" empty state. Dropped the JOIN and the type-label rendering; rows now show the eval date as the link label.

### Activities tab — only completed activities

Filtering the activities tab list (and the matching badge count in `PlayerFileCounts::for()`) on `a.plan_state = 'completed'`. Attendance rows for scheduled / in-progress activities default to 'Present' on insert (the form's roster pre-fills every roster player), which made the tab read like the player attended every upcoming activity — confusing pilots who clicked into "Present" rows that hadn't actually happened. Tab and badge now agree on "real attendance history" instead of "every row that happens to exist in `tt_attendance`".

### Analytics tab — 30-day attendance card now returns data

Two latent bugs in the Analytics fact registrations were silently zeroing out the 30-day attendance card on the player Analytics tab:

- **Wrong time-column name.** The attendance + activities Facts referenced `a.start_at` / `f.start_at`, but the `tt_activities` table stores its scheduling timestamp as `session_date`. The 30-day filter (`date_after = -30 days`) couldn't bind, so the SELECT errored or returned nothing.
- **Wrong status casing.** The `attendance_pct` measure compared `f.status = 'present'` (lowercase), but every write path stores capitalised values ('Present' / 'Absent' / 'Late' / 'Injured' / 'Excused' per the seeded `attendance_status` lookup). Even when the time filter worked, the AVG always returned 0.0%.

Fixed both: time column → `session_date`, comparison → `LOWER(f.status) = 'present'` (defensive: matches both seeded capitalised values and any legacy lowercase data from the v2.x present-int → status-string backfill). Same `LOWER()` defensive normalisation applied to `Modules\PersonaDashboard\Kpis\AttendancePctRolling`'s rolling-28-day SQL so the HoD KPI strip's *Attendance %* card stops reading 0% on installs that rely on capitalised status values. Also corrected the `activity_type` dimension's column reference from the phantom `session_type` to the actual `activity_type_key`.

### Notes — Save + Cancel after edit no longer dead

`ensureSelfActions()` short-circuited when the message element already had a `.tt-thread-msg-actions` container, leaving the Save / Cancel buttons in place after a successful save. Their click handlers referenced a textarea that had been replaced with a static body div, so the buttons rendered but did nothing. Now `ensureSelfActions()` always removes any existing actions container before recreating Edit + Delete — the buttons reset cleanly after every edit transition.

### Notes — Delete uses the in-app modal

`doDelete()` called `window.confirm()`, which felt out of place inside the otherwise app-styled thread surface. Now uses `window.ttConfirm()` (the existing in-app modal component, lazy-loaded by `FrontendThreadView::enqueueAssets()`) with a falls-back-to-`window.confirm` shim for installs that haven't enqueued `confirm.js` yet.

### Header card — age tier removed

The "Age tier" pill was duplicated in both the header card AND the profile-tab Academy section. Removed from the header — the profile tab is its canonical home. Stripped the unused `$age_tier` / `$tier_label` locals from `renderHero()`.

## Affected files

- `src/Shared/Frontend/FrontendPlayerDetailView.php` — profile-tab table conversion + activities-tab `plan_state` filter + evals-tab JOIN drop + hero age-tier removal
- `src/Infrastructure/Query/PlayerFileCounts.php` — activities count badge mirrors the tab's `plan_state` filter
- `src/Shared/Frontend/FrontendGoalsManageView.php` — goals form honours `?player_id=`
- `src/Shared/Frontend/FrontendEvaluationsView.php` — passes `?player_id=` through to `CoachForms::renderEvalForm`
- `src/Shared/Frontend/CoachForms.php` — `renderEvalForm` accepts `$preset_player_id`, hides team + player pickers when set
- `src/Modules/Analytics/AnalyticsModule.php` — attendance + activities Facts use `session_date` / `activity_type_key` and case-insensitive status comparison
- `src/Modules/PersonaDashboard/Kpis/AttendancePctRolling.php` — defensive `LOWER(status)='present'` for the rolling-28-day HoD card
- `src/Shared/Frontend/Components/FrontendThreadView.php` — enqueues `tt-confirm` for the in-app delete modal
- `assets/js/frontend-threads.js` — `ensureSelfActions` always resets; `doDelete` uses `ttConfirm`
- `assets/css/frontend-player-detail.css` — `.tt-profile-table` styles
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version + ship metadata
- `languages/talenttrack-nl_NL.po` — 1 new msgid ("Recording evaluation for %s.")
