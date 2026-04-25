<!-- type: plan -->

# #0019 Sprint 2 — Session-by-session plan

Companion to [`0019-sprint-2-sessions-and-goals.md`](0019-sprint-2-sessions-and-goals.md). Breaks the sprint's 22-hour scope into four reviewable PRs.

## At a glance

| # | Focus | Estimate | Files (new) | Depends on |
| - | - | - | - | - |
| 2.1 | REST list endpoints (sessions + goals) | ~3h | — (extends existing controllers) | Sprint 1 |
| 2.2 | `FrontendListTable` component | ~7h | `FrontendListTable.php`, `frontend-list-table.js` | 2.1 |
| 2.3 | Sessions full frontend | ~6h | `FrontendSessionsManageView.php` | 2.2 |
| 2.4 | Goals full frontend | ~4h | `FrontendGoalsManageView.php` | 2.2 |

**Total: ~20h.** Spec sized at 22h; the plan reclaims ~2h by folding mobile polish + draft wiring into each entity's session rather than treating it as separate work. Ship a release after each session merge (the running pattern from Sprint 1).

---

## Open questions — resolved 2026-04-25

Locked decisions for Sprint 2. (Originally seven open questions; all accepted as recommended.)

1. **Session-type filter** — dropped for Sprint 2 (schema has no `type` column; rely on free-text search across `title`).
2. **Attendance-completeness filter** — computed on the fly per row via correlated subqueries. Lists capped at 100/page; revisit if slow.
3. **Team picker** — separate `TeamPickerComponent` (different scoping rules from `PlayerPickerComponent`).
4. **Goal status transition** — inline dropdown on the row only (matches existing dashboard pattern).
5. **Bulk attendance** — "Mark all present" only for v1.
6. **URL state** — layered: `tt_view` for tile-route, `filter[*] / orderby / order / page / per_page` for the list-table. They don't collide.
7. **Mobile reflow** — CSS-only via `display: block` + `data-label` attributes. No JS branching.

---

## Session 2.1 — REST list endpoints (~3h)

The component in Session 2.2 needs a stable contract to consume. Build the server side first.

### Scope

Extend `SessionsRestController` and `GoalsRestController` with `GET` list endpoints. Both follow the same query-param shape so `FrontendListTable` doesn't need entity-specific knowledge:

- `?search=<text>` — free-text search across the obvious column(s) (sessions: `title` + `location` + team name; goals: `title` + `description` + player name).
- `?filter[<key>]=<value>` — entity-specific filters. Sessions: `team_id`, `date_from`, `date_to`, `attendance` (`complete|partial|none`). Goals: `team_id`, `player_id`, `status`, `priority`, `due_from`, `due_to`.
- `?orderby=<column>&order=<asc|desc>` — column whitelist enforced server-side (reject unknown columns with 400).
- `?page=<n>&per_page=<10|25|50|100>` — defaults page=1, per_page=25.
- Response envelope reuses `RestResponse::success` with `{ rows: [...], total: <int>, page, per_page }`.

Both endpoints respect the same authorization the existing create/update endpoints do (no widening). Demo-mode scope filter (`QueryHelpers::apply_demo_scope`) goes in the SQL.

### Files (modified, no new files)

- `src/Infrastructure/REST/SessionsRestController.php` — add `list_sessions` callback + register `GET /sessions`.
- `src/Infrastructure/REST/GoalsRestController.php` — add `list_goals` callback + register `GET /goals`.

### Acceptance

- [ ] `GET /talenttrack/v1/sessions?per_page=10` returns 10 rows + total.
- [ ] `GET /talenttrack/v1/sessions?filter[team_id]=5&orderby=session_date&order=desc` filters + sorts.
- [ ] `GET /talenttrack/v1/goals?filter[status]=active&filter[priority]=high` returns the expected slice.
- [ ] Unknown `orderby` value returns HTTP 400 with `bad_orderby` error code (no SQL injection surface).
- [ ] Coach without admin caps sees only sessions/goals for their accessible teams.

### Watchouts

- Existing `EvaluationsRestController::list_evals` has a different shape (no envelope, no pagination). Don't retrofit it — leave for a later cleanup. Sprint 2 only ships the two new list endpoints.
- `tt_attendance` has a status string column — `attendance` filter computes completeness via SQL `LEFT JOIN tt_attendance ... GROUP BY session_id HAVING COUNT(...) = roster_size`. Plan the query carefully; it's the only non-trivial bit.

---

## Session 2.2 — `FrontendListTable` component (~7h)

The keystone of the sprint. Spec calls it out: "Sessions + Goals are the test users. But the component must be clean enough that Sprints 3, 4, and 5 can build their list views by just declaring filters and columns."

### API shape (proposed)

```php
FrontendListTable::render([
    'rest_path' => 'sessions',                                   // relative to /talenttrack/v1/
    'columns'   => [
        'session_date' => [ 'label' => __('Date',   'talenttrack'), 'sortable' => true ],
        'team_name'    => [ 'label' => __('Team',   'talenttrack') ],
        'title'        => [ 'label' => __('Title',  'talenttrack') ],
        'attendance'   => [ 'label' => __('Att.%',  'talenttrack'), 'render' => 'percent' ],
    ],
    'filters' => [
        'team_id' => [ 'type' => 'team_picker', 'label' => __('Team', 'talenttrack') ],
        'date'    => [ 'type' => 'date_range',  'label' => __('Date range', 'talenttrack') ],
    ],
    'row_actions' => [
        'edit'   => [ 'label' => __('Edit',   'talenttrack'), 'href' => '?tt_view=sessions&edit={id}' ],
        'delete' => [ 'label' => __('Delete', 'talenttrack'), 'rest_method' => 'DELETE', 'rest_path' => 'sessions/{id}', 'confirm' => __('Delete this session?', 'talenttrack') ],
    ],
    'empty_state' => __('No sessions match your filters.', 'talenttrack'),
    'search'      => [ 'placeholder' => __('Search sessions…', 'talenttrack') ],
]);
```

Server-rendered shell, JS-hydrated rows. No-JS users get the initial page of data + working filter form (full page reload on filter change). JS upgrades to inline filtering + pagination + URL state.

### Files (new)

- `src/Shared/Frontend/Components/FrontendListTable.php` — render + initial-page server-side fetch.
- `assets/js/components/frontend-list-table.js` — hydration: filter changes → re-fetch via REST → re-render rows + pagination, with URL state.
- Component-specific styles into `assets/css/frontend-admin.css` (under a `/* ─── FrontendListTable ─── */` section) — keep all the styling in the one stylesheet rather than fragmenting.

### Validator view

Don't try to ship Sessions or Goals views in this PR — that's session 2.3 / 2.4 work. To prove the component, drop a one-liner `FrontendListTable::render(['rest_path' => 'sessions', ... bare-minimum config ])` into a temporary test route or the existing `FrontendSessionsView::render` (which is a 23-line placeholder today). Delete the temporary wiring at the start of session 2.3.

### Acceptance

- [ ] Renders search + filters + table + pagination.
- [ ] Filter change → URL updates → re-fetches data without reload.
- [ ] Sort by clicking a column header → URL updates → re-fetches sorted.
- [ ] Pagination works; per-page selector changes default.
- [ ] Empty state renders cleanly when no rows match.
- [ ] Loading state visible during fetch.
- [ ] Mobile reflow at 375px: cards instead of table rows.
- [ ] Bookmarking a filtered URL and revisiting it shows the same view.

### Watchouts

- **The component is a foundation for 4 future sprints.** Spend the time on the API. If a feature feels custom-to-Sessions, push back and find a generic version.
- Don't write a giant JS module. Stick to the same pattern as `multitag.js` / `rating.js` (vanilla, ~200 LOC, no build step).
- URL state via `URLSearchParams` and `history.replaceState`. No router library.

---

## Session 2.3 — Sessions full frontend (~6h)

### Scope

- **List view** — `FrontendSessionsManageView::render()` consuming `FrontendListTable` with sessions-specific filters/columns/actions.
- **Create / edit form** — replace the existing `CoachForms::renderSessionForm` callsite with the new view's form, or have the view delegate to the existing form helper. Keep the bulk attendance ("Mark all present") button at the top of the attendance list, sticky on mobile.
- **Mobile attendance pagination** — show 15 players initially, "Show all" expander.
- **Delete** — soft-archive via `archived_at` (column exists; the wp-admin path already does this).
- **Tile entry** — add a "Sessions" coaching tile entry that points at the new view (or replace the existing `sessions` tile route).

### Files

- `src/Shared/Frontend/FrontendSessionsManageView.php` — new.
- `src/Shared/Frontend/Components/TeamPickerComponent.php` — new (per Q3 above).
- `src/Shared/Frontend/CoachForms.php` — minor wiring if needed.
- Tile router in `DashboardShortcode::dispatchCoachingView` — point `sessions` → `FrontendSessionsManageView` (existing `FrontendSessionsView` becomes obsolete, delete it in this PR).

### Acceptance — see spec.

---

## Session 2.4 — Goals full frontend (~4h)

### Scope

- **List view** — `FrontendGoalsManageView::render()` with goals-specific filters/columns. Inline status select on each row hits the existing `PATCH /goals/{id}/status`.
- **Create / edit form** — uses `PlayerPickerComponent`, `DateInputComponent`, `MultiSelectTagComponent` for priority. Saves via existing `Goals_Controller`. Drafts via the `data-draft-key` attribute pattern.
- **Delete** — soft-archive.

### Files

- `src/Shared/Frontend/FrontendGoalsManageView.php` — new.
- Existing `FrontendGoalsView.php` becomes obsolete — delete in this PR.
- `DashboardShortcode::dispatchCoachingView` — point `goals` → new view.

### Acceptance — see spec.

---

## Risks across the sprint

1. **Filter contract drift between server and client** — if Session 2.1 ships a contract that 2.2's component can't cleanly express, we pay rework cost. Mitigation: stub the JS hydration in 2.1 against curl'd JSON to validate the shape feels right before sealing it.

2. **TeamPicker scope rules** — admin / HoD / coach see different sets. Don't reinvent — there's already a working query in `QueryHelpers::get_teams_for_coach`. Wire the picker around that, don't add a new query path.

3. **Existing `CoachForms::renderSessionForm` is a fat method** — Sprint 2.3 has a choice: (a) keep using it from the new view, (b) extract its body into the new view and delete the helper. Recommend (a) for Sprint 2; defer the extraction to whichever sprint has time.

4. **No regression on wp-admin pages** — both `SessionsPage` and `GoalsPage` (each ~210 LOC) must keep working. Sprint 2 doesn't touch them. Smoke-test both before merging each session.

5. **Demo-mode scope filtering on list endpoints** — easy to forget. Both new list callbacks must apply `QueryHelpers::apply_demo_scope` or demo data leaks into real lists when demo mode is on.

---

## Notes

- Each session merge → release a patch (`v3.7.3`, `v3.7.4`, `v3.7.5`, `v3.7.6`). Keeps the release cadence consistent with Sprint 1.
- Update `SEQUENCE.md` after each session per the existing ship-along rule.
- `.po` updates per session per the existing ship-along rule.
- Session 2.2 is the largest reviewable chunk in the sprint. If it grows past ~9h, split the JS hydration into a follow-up PR rather than letting one PR balloon.
