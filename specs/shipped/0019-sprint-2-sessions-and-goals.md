<!-- type: feat -->

# #0019 Sprint 2 — Sessions + Goals full frontend

## Problem

Sessions and Goals are the two most-touched entities for coaches. Today:

- **Sessions**: can be created via `FrontendAjax::save_session` (being replaced in Sprint 1), but the list/search/filter/edit surface lives in wp-admin at `src/Modules/Sessions/Admin/SessionsPage.php`.
- **Goals**: similar — basic save/status/delete in `FrontendAjax`, but the full management surface (browsing, filtering by status/date, editing goal details) is wp-admin.

Coaches working at the pitch side cannot effectively manage either entity from their phone. They can log a new session mid-practice but can't scroll back through last month's sessions to check attendance patterns. They can change a goal's status but can't filter "show me all active goals for U14 ordered by deadline."

This sprint brings both entities to full frontend parity, with a reusable list component that becomes the foundation for Sprints 3, 4, and 5.

## Proposal

Three deliverables, in order of dependency:

1. **`FrontendListTable` component** — reusable list/search/filter/sort/paginate component. Generic enough to back any entity's list view. Serves Sessions + Goals in this sprint, then Players + Teams in Sprint 3, People in Sprint 4, admin-tier in Sprint 5.
2. **Sessions full frontend** — list view (using `FrontendListTable`), edit view, delete, full attendance UI with bulk "mark all present" mode.
3. **Goals full frontend** — list view, create/edit/delete, status transitions, filter by status/team/player/deadline.

The existing wp-admin pages stay intact during this sprint — they're removed from the menu in Sprint 6 (via the legacy-UI toggle), not here.

## Scope

### `FrontendListTable` component

A new component under `src/Shared/Frontend/Components/FrontendListTable.php`. Backed by REST — given an endpoint URL, it renders a list. Features:

- **Search**: text input, debounced, calls the REST endpoint with `?search=`.
- **Filters**: declarative. Each entity's list view declares filter dimensions (e.g. "team", "status", "date range"). Component renders them as dropdowns / date pickers / multi-select tags.
- **Sort**: column headers clickable, toggles asc/desc. Server-side sort via `?orderby=&order=`.
- **Pagination**: client-side controls, server-side cursor. Page size selector (10/25/50/100).
- **Row actions**: declarative per row (Edit, Delete, custom). Component handles the dispatch.
- **URL state**: filters, sort, page, page-size reflected in the URL querystring. Bookmarkable and shareable.
- **Empty state**: declarative message when no rows match.
- **Loading state**: skeleton rows or spinner while fetching.
- **Error state**: inline error if REST call fails, with retry.
- **Mobile behavior**: below 640px, the table reflows into stacked cards (one row = one card, headers become labels).

### Sessions frontend

Views under `src/Shared/Frontend/FrontendSessionsManageView.php`:

- **List view** (`[tt_sessions]` or via tile navigation):
  - Powered by `FrontendListTable`.
  - Filters: team, date range, attendance-completeness (complete / partial / none).
  - Columns: date, team, session type, player count, attendance %, actions.
  - Row actions: Edit, Delete (with confirm).
  - Below the table: "Create session" button.
- **Create / edit view**:
  - Form fields: date (DateInputComponent), team (PlayerPickerComponent equivalent for teams), session type (MultiSelectTagComponent against the session-type lookup), notes.
  - Attendance section: list of players on the selected team. For each: radio group or tap-chip for present/absent/late, with notes field.
  - **Bulk attendance mode**: a "Mark all present" button at the top of the attendance list. Sets all players to present in one action. Coach then toggles exceptions individually.
  - Save via REST `Sessions_Controller` (created in Sprint 1).
  - Draft persistence via localStorage (built in Sprint 1).
- **Delete**: soft-archive via the existing archived_at column (migration 0010).

### Goals frontend

Views under `src/Shared/Frontend/FrontendGoalsManageView.php`:

- **List view**:
  - Filters: team, player, status (active / achieved / missed / archived), deadline date range, priority.
  - Columns: player, title, status, priority, deadline, actions.
  - Row actions: Edit, Change status, Delete.
- **Create / edit view**:
  - Form: player (PlayerPicker), title, description, priority (MultiSelect), status, deadline (DateInput).
  - Save via REST `Goals_Controller`.
  - Draft persistence.
- **Status transition**: inline dropdown on the row or detail panel. Goes through the goal-status REST endpoint.
- **Delete**: archive via archived_at.

### Mobile UX specifics

- Bulk attendance mode: on mobile, the "Mark all present" button is sticky at the top of the scrolling attendance list so it's reachable with one thumb.
- Session edit form on mobile: attendance list paginates at 15 players so it's scannable; full team view behind a "Show all" expander.
- Goal list on mobile: card-mode from FrontendListTable handles this automatically.

## Out of scope

- **Replacing the wp-admin Sessions/Goals pages.** Those stay functional; menu removal is Sprint 6 (via the legacy toggle).
- **Any new attendance-tracking logic** — reuse existing attendance schema.
- **Goal category management** — assume existing goal lookups work; no new categorization.
- **Session templates or recurring sessions** — out of scope for this sprint and for this epic.
- **Exporting sessions or goals to CSV** — frontend CSV comes in Sprint 3 (for Players) and Sprint 5 (for admin-tier). Sessions/Goals export can be added later if needed.

## Acceptance criteria

### FrontendListTable

- [ ] A reusable component class exists at `src/Shared/Frontend/Components/FrontendListTable.php`.
- [ ] Given a REST endpoint and a filter declaration, it renders search + filters + table + pagination.
- [ ] URL querystring reflects state: loading `?filter[team]=5&orderby=date&order=desc&page=2` renders the correct filtered view.
- [ ] Mobile viewport (375px) reflows table to stacked-card layout.
- [ ] Empty state and error state render correctly.

### Sessions

- [ ] Coach can list sessions for their accessible teams with full filter/search/sort/paginate.
- [ ] Coach can create a session from the frontend: date, team, type, notes, attendance for each player.
- [ ] "Mark all present" button sets all players to present in one click; coach can then toggle exceptions.
- [ ] Edit session works including attendance changes.
- [ ] Delete session (archives it).
- [ ] All works on mobile (375px) without horizontal scrolling.
- [ ] Draft persistence: if I close the tab mid-entry, reopening the form prompts me to restore.

### Goals

- [ ] HoD/coach can list goals with filter by team/player/status/deadline/priority.
- [ ] Can create a goal from the frontend: player, title, description, status, priority, deadline.
- [ ] Can transition a goal's status inline from the list.
- [ ] Can edit or delete a goal.
- [ ] Mobile viewport works.
- [ ] Draft persistence on create/edit forms.

### No regression

- [ ] The existing wp-admin Sessions and Goals pages still work.
- [ ] Existing session-save and goal-save flows from previous versions work identically.
- [ ] Data written via frontend is indistinguishable from data written via wp-admin (same schema, same values).

## Notes

### The FrontendListTable is the real deliverable

Sessions + Goals are the test users. But the component must be clean enough that Sprints 3, 4, and 5 can build their list views by just declaring filters and columns. Spend extra time here — every subsequent sprint pays dividends.

### Sizing

~22 hours of driver time. Breakdown:

- FrontendListTable component: ~10 hours (this is most of the sprint)
- Sessions views: ~6 hours
- Goals views: ~4 hours
- Mobile polish + draft wiring + testing: ~2 hours

### Touches

- `src/Shared/Frontend/Components/FrontendListTable.php` (new)
- `src/Shared/Frontend/FrontendSessionsManageView.php` (new)
- `src/Shared/Frontend/FrontendGoalsManageView.php` (new)
- `assets/js/components/frontend-list-table.js` (new)
- `assets/css/components/frontend-list-table.css` (new)
- Existing tile grid: add entries for Sessions Manage, Goals Manage
- REST controllers: already created in Sprint 1; verify they support the filter query parameters this sprint needs

### Depends on

Sprint 1 (REST endpoints, shared components, CSS scaffold, drafts).

### Blocks

Sprint 3 (Players + Teams list views reuse `FrontendListTable`), Sprint 4 (People list view), Sprint 5 (admin-tier list views).
