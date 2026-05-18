# TalentTrack v3.110.170 — Row-link standard rolled out to 7 more list views

## Pilot ask

Chat 2026-05-18:

> it works, apply to other, similar table lists

v3.110.169 landed the row-link standard + first consumer (PDP files). Pilot validated it on the PDP list, now wants every comparable list view to pick the standard up.

## Audit

Surveyed every `FrontendListTable::render([...])` call site (14 files). Each view falls into one of three buckets:

| Bucket | Views | Action |
| --- | --- | --- |
| **Wired in v3.110.169** | PDP files | None |
| **REST already emits `detail_url`** | Tournaments | View-side opt-in only |
| **REST builds detail URL internally but doesn't expose it** | Evaluations | Add `detail_url` field + view opt-in |
| **No detail URL anywhere yet** | Activities, My Activities, Goals, Players, Teams, People | Add `detail_url` field + view opt-in |
| **Skip** (settings / inline-edit / out-of-scope) | Functional Roles, Custom Fields, Prospects Overview | None |

## Each REST controller emits `detail_url` the same way

The pattern is one variable extraction + one return-array key. Example from `GoalsRestController::format_row()`:

```php
// Before
$title_link_html = \TT\Shared\Frontend\Components\RecordLink::inline(
    $title,
    \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'goals', $goal_id )
);

// After
$title_url = \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack( 'goals', $goal_id );
$title_link_html = \TT\Shared\Frontend\Components\RecordLink::inline( $title, $title_url );
// …and in the returned array:
'detail_url' => $title_url,
```

Reusing the variable keeps the URL definition single-sourced — the inline-cell link and the whole-row link always point at the same place, even when the URL builder changes.

Six controllers received this change:

| REST controller | Method | Slug |
| --- | --- | --- |
| `ActivitiesRestController` | `format_row` | `activities` |
| `EvaluationsRestController` | `format_row` | `evaluations` |
| `GoalsRestController` | `format_row` | `goals` |
| `PlayersRestController` | `fmtRow` | `players` |
| `TeamsRestController` | `fmtTeamRow` | `teams` |
| `PeopleRestController` | `format_row` | `people` |

Tournaments' REST controller (`TournamentsRestController::fmtTournamentRow`) already emitted `detail_url` since the planner-foundation ship — no change needed there.

## Each view declares `row_url_key`

One config line in each `FrontendListTable::render()` call:

```php
'row_url_key' => 'detail_url',
```

Eight views opted in:

| View | File | Slug |
| --- | --- | --- |
| Activities | `FrontendActivitiesManageView.php` | `activities` |
| My Activities | `FrontendMyActivitiesView.php` | `my-activities` (REST → `activities`) |
| Tournaments | `FrontendTournamentsManageView.php` | `tournaments` |
| Evaluations | `FrontendEvaluationsView.php` | `evaluations` |
| Goals | `FrontendGoalsManageView.php` | `goals` |
| Players | `FrontendPlayersManageView.php` | `players` |
| Teams | `FrontendTeamsManageView.php` | `teams` |
| People | `FrontendPeopleManageView.php` | `people` |

## What's skipped and why

**`FrontendFunctionalRolesView`** — the assignments tab is an inline-edit list, not a "list of records with detail pages". Rows are read-only and editing happens inline on the same view; row-click navigation doesn't apply.

**`FrontendCustomFieldsView`** — same shape as functional roles. Settings/admin list with edit-on-same-view via `?id=N` query param. No separate detail page.

**`FrontendProspectsOverviewView`** — prospects DO have a detail page, but the REST list doesn't yet emit a navigation URL. Adding it touches the prospect data model + the scout-facing view scoping, which is tracked separately (out-of-scope for this row-link rollout).

## Per-column links keep working — the standard's design holds

Important: every list now has multiple click destinations from one row.

On the Goals list, for example:
- Click the **player name cell** → goes to that player's detail page
- Click the **goal title cell** → goes to that goal's detail page
- Click **dead space** (priority column, status pill, due date, padding) → goes to that goal's detail page

The JS hydrator's `bindRowLinks()` interactive-target detection (`A`, `BUTTON`, `INPUT`, `SELECT`, `TEXTAREA`, `LABEL`, `role=button`) skips the row-link when the click target is itself a link or button. Per-column cross-entity navigation is preserved exactly as it was.

On every list view the *whole row* picks the same target as the **primary identifier cell** (Title for activities/tournaments/goals, Name for players/teams/people, Date for evaluations) — that's what a user would expect.

## Try it on the pilot install

After upgrading to v3.110.170, every list view supports:
- Click anywhere on row dead space → that row's detail page
- Click a cross-entity link cell (player name on a goal row, team name on an activity row, etc.) → that entity's detail page
- Middle-click → new tab
- Cmd-click (Mac) / Ctrl-click (Windows) → new tab
- Keyboard: Tab to row, Enter or Space → navigate

Existing column header sorts, filters, pagination, per-page selector — all unchanged.

## Files touched

REST controllers (6):
- `src/Infrastructure/REST/ActivitiesRestController.php`
- `src/Infrastructure/REST/EvaluationsRestController.php`
- `src/Infrastructure/REST/GoalsRestController.php`
- `src/Infrastructure/REST/PlayersRestController.php`
- `src/Infrastructure/REST/TeamsRestController.php`
- `src/Infrastructure/REST/PeopleRestController.php`

Views (8):
- `src/Shared/Frontend/FrontendActivitiesManageView.php`
- `src/Shared/Frontend/FrontendMyActivitiesView.php`
- `src/Shared/Frontend/FrontendTournamentsManageView.php`
- `src/Shared/Frontend/FrontendEvaluationsView.php`
- `src/Shared/Frontend/FrontendGoalsManageView.php`
- `src/Shared/Frontend/FrontendPlayersManageView.php`
- `src/Shared/Frontend/FrontendTeamsManageView.php`
- `src/Shared/Frontend/FrontendPeopleManageView.php`

Version + changelog:
- `talenttrack.php` — 3.110.169 → 3.110.170
- `readme.txt` — Stable tag + changelog entry
- `CHANGES.md` — this file

No DB migration, no REST shape break (all changes are additive — `detail_url` is a new field; existing fields untouched), no new i18n strings, no auth change.
