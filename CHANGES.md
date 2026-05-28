# TalentTrack v4.7.0 — Activities list date-bucket redesign (closes #973)

Rewrites the `?tt_view=activities` list surface end-to-end as a faithful port of the design-of-record mockup committed to `.local-mockups/activity-list/`. Backend is untouched — the same `tt_activities` rows, the same `tt_view_activities` capability gate, the same entry-point URL — but the visual contract goes from a generic `FrontendListTable` with two filters to a date-bucketed card list with a Type filter and a persistent past-toggle.

## Friction the redesign addresses

| # | Friction in v4.6.x baseline | Redesign response |
|---|---|---|
| 1 | Flat list scrolls past months of training; coaches scan dates to find "what's tomorrow" | Date buckets make temporal context instant. |
| 2 | No type filter — coaches looking for the next match scroll past trainings | Type picker in the filter row, lookup-backed via `QueryHelpers::get_lookups('activity_type')`. |
| 3 | Cancelled / completed past activities create scroll noise | Past pinned to top, collapsed by default, one-tap reveal. |
| 4 | Past PLANNED (never marked completed/cancelled) is a TODO signal that gets buried | "Needs attention" pseudo-bucket above Today; date badges painted `--tt-warn`. |

## Layout

- **Filter row** — Team picker beside the new Type picker, side-by-side 2-column grid at every viewport (the mockup keeps both on one row at 360px too). Both honour the existing `tt-input` 48px floor; the Type select is built from `tt_lookups` so renamed / added activity types appear without code changes.
- **Past toggle** — single button pinned above the bucket list. Label switches `N past activities hidden · Show ▼` ⇄ `N past activities shown · Hide ▼`. URL state `?include_past=1` persists across refresh / shared links. Chevron rotates 180° via CSS transform when expanded.
- **Buckets, top→bottom** (empty buckets collapse to nothing):
  - ⚠ **Needs attention** — `session_date < today AND plan_state = 'planned'`. Header rendered in `--tt-warn`; each row's 44px date badge painted the same orange.
  - **Today** — `session_date = today`. Header carries day-of-week + date (e.g. "Today · Wed 28 May"); badge in `--tt-accent` blue.
  - **This week** — `today < session_date <= upcoming Sunday`.
  - **Next week** — next Mon → next Sun.
  - **Later this month** — beyond next week, up to end-of-month.
  - **Later** — beyond end-of-month.
- **Activity cards** — `grid-template-columns: 44px 1fr auto`: date badge | title + meta line (type pill, optional status pill, team + time) | chevron. The whole card is a link to the activity detail page.

## Bucket math

"Today" comes from `current_time('Y-m-d', true)` so the GMT-stored value is converted via `wp_timezone()`. Week bucket boundaries are computed in PHP with `DateTimeImmutable` anchored to `wp_timezone()`: end-of-this-week = the upcoming Sunday (`'this week'`'s definition in PHP starts on Monday by ISO-8601), next-week range = `(end-of-this-week + 1 day)` through `(end-of-this-week + 7 days)`, end-of-this-month from `'last day of this month'`. Buckets sort their rows by `session_date ASC` so the next-upcoming row sits at the top of each.

## Type / status pills

Colour-coded per the mockup:

| Type/status | Background | Text |
|---|---|---|
| Training | `#e1eef5` | `#0d4a7a` |
| Match / Game | `#fde6e2` | `#8a2a26` |
| Friendly | `#fff3d9` | `#8a5e0a` |
| Other | `--tt-mute` | `--tt-ink-soft` |
| Status: Completed | `#e0efe5` | `--tt-success` |
| Status: Cancelled | `#ffe0e0` | `#8a2a26` |

Future-bucket rows show only the type pill — the bucket position already conveys "planned". Past-bucket rows additionally carry a Completed / Cancelled status pill.

## What's untouched

- **Schema** — `tt_activities` is unchanged. No migration.
- **REST** — `/talenttrack/v1/activities` and `/activities/{id}` keep the same shape; this view does NOT consume them. The view now reads `tt_activities` directly via a dedicated server-side query that mirrors `ActivitiesRestController::list_sessions`'s WHERE / scope rules (club_id, demo scope, head-coach team scope, archived filter, team filter). The REST endpoint remains the contract for non-WordPress consumers per CLAUDE.md §4.
- **Capability gate** — `tt_view_activities` continues to gate the surface. Cross-entity links from the dashboard widget, team detail, and the activity detail page keep their existing URLs (`?tt_view=activities`, `?tt_view=activities&id=N`).
- **Other modes of the view** — `?action=new`, `?action=edit`, and the read-only detail (`?id=N` without `action`) render the same forms / detail pages as before. Only the default list mode is rewritten.

## Files touched

- `src/Shared/Frontend/FrontendActivitiesManageView.php` — `renderList()` rewritten; new `bucketize()`, `renderBucket()`, `renderActivityCard()`, `renderPastToggle()`, `loadActivitiesForList()`, `typeKeyForPill()` helpers; the existing `render()`, `renderDetail()`, `renderForm()`, and attendance / guest helpers are untouched.
- `assets/css/frontend-activities-manage.css` — mockup tokens + selectors added (`.tt-act-list`, `.tt-act-filters`, `.tt-act-past-toggle`, `.tt-act-bucket-head`, `.tt-act-card`, `.tt-act-date`, `.tt-act-meta`, etc.). The legacy attendance-table rules (still used by the edit form) are preserved at the bottom of the file.
- `talenttrack.php` Version + `TT_VERSION` + `readme.txt` Stable tag: `4.6.0` → `4.7.0`.
- `docs/activities.md` + `docs/nl_NL/activities.md` updated for the new list shape.
- `languages/talenttrack.pot` + `languages/talenttrack-nl_NL.po` updated for the new strings (no duplicate msgids).

## Why minor

New feature epic. Surface behaviour visible to every coach changes (new filter, new bucket grouping, new past-pinned toggle), but no operator-breaking removal: the URL, the cap, the data model are unchanged.
