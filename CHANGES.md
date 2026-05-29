# TalentTrack v4.7.1 — Surface "Evaluation Categories" tile on the frontend Configuration → Lookups grid (closes #982)

## Friction this fixes

Admins navigating to `?tt_view=configuration` to edit evaluation subcategories couldn't find a tile for them. The Lookups grid only enumerates `tt_lookups`-backed vocabularies, but evaluation categories (and their child subcategories) live in `tt_eval_categories` — a hierarchical table with a `parent_id` chain (`parent_id IS NULL` → main category; `parent_id = N` → subcategory). The dedicated tree editor at `?tt_view=eval-categories` (`FrontendEvalCategoriesView`) is the right tool; it just had no entry point from where operators actually look.

The wp-admin Configuration page (`src/Modules/Configuration/Admin/ConfigurationPage.php:269-271`) already carries an "Evaluation Categories" tile in the "Lookups & vocabularies" group linking to the wp-admin tree editor. The frontend Configuration grid was missing the parallel tile.

## Architecture call: navigation, not data-model rework

Evaluation subcategories are a tree, not a flat list. They can't be migrated into `tt_lookups` without losing the parent/child semantics that the radar chart, evaluation templates, and category weights all depend on. The fix is purely additive at the navigation layer — surface the existing editor from where operators look for it.

## The fix (single-file edit)

`src/Shared/Frontend/FrontendConfigurationView.php` — one new entry appended to the `renderLookupsIndex()` `$cards` array, plus one new `elseif` branch in the surrounding `foreach` loop. The slug `__eval_categories` mirrors the existing `__rating` pattern (rating scale also lives outside `tt_lookups` — it's in `tt_config`), so the tile sits in the lookups grid alongside its siblings but click-throughs to the standalone tree editor at `?tt_view=eval-categories` instead of the per-category `tt_lookups` CRUD surface.

```php
[ __( 'Evaluation Categories', 'talenttrack' ),
  __( 'Hierarchy of evaluation categories (Technical, Tactical, …).', 'talenttrack' ),
  '__eval_categories', 'evaluations' ],
```

URL branch:

```php
} elseif ( $slug === '__eval_categories' ) {
    $url = add_query_arg( [ 'tt_view' => 'eval-categories' ], home_url( '/' ) );
}
```

## Label / description / icon choices

- **Label**: "Evaluation Categories" — verbatim copy of the wp-admin tile (`ConfigurationPage.php:269`). Same vocabulary on both surfaces. Existing translation in `talenttrack-nl_NL.po` ("Evaluatie-categorieën").
- **Description**: "Hierarchy of evaluation categories (Technical, Tactical, …)." — verbatim copy of the wp-admin tile (`ConfigurationPage.php:270`). Existing translation in `talenttrack-nl_NL.po`. **No new msgids introduced** — duplicate-msgid CI gate passes.
- **Icon**: `evaluations` SVG (existing in `IconRenderer`). Matches the icon used by the "Evaluation types" tile two rows above so the visual grouping reads as expected.

## What's untouched

- No schema change. `tt_eval_categories` continues to back the editor unchanged.
- No REST contract change. The existing `?tt_view=eval-categories` route is the same one wp-admin links to; it carries its own capability gate.
- No new view, no new form, no new wizard. The fix is one tile + one URL branch.
- The other 30+ tiles on the Lookups grid are unchanged.

## Capability gate

The Configuration view requires `tt_access_frontend_admin` for the surrounding page (`FrontendConfigurationView::render()` line 28); the eval-categories tree editor enforces its own gate when reached. Operators without permission see neither surface.

## Version: 4.7.1

Patch bump. Single-file discoverability fix within the 4.7 minor — no operator-breaking change, no new feature, no new vocabulary, no new translations required.

---

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
