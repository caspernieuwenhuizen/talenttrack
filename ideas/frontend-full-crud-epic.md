<!-- type: epic -->

# Full CRUD frontend admin — epic

Raw idea:

Front end pages like evaluations, when entered I want a searchable, filterable list of existing evaluations with all fancy typical web based display tools (number of items to display, pagination, etc..). This needs to happen to all pages. Then I want to have a add new button so I can add a new one. Of course, I should only see records relevant for my role. Basically this is moving the admin back-end to the front-end more, something I want for a long time already.

## Why this is an epic

Touches 6+ entities (evaluations, sessions, goals, teams, players, people), each needs: list view with search/filter/pagination/page-size, inline add-new, role-scoped data, edit/delete with cap gates. That's essentially rebuilding wp-admin list tables on the frontend, entity by entity. Multi-sprint.

## Decomposition (rough — will refine during shaping)

1. Sprint A — shared frontend list-table component (searchable, sortable, paginated, page-size selectable). One reference implementation, probably on evaluations since the current view is the worst.
2. Sprint B — port sessions + goals to the new component.
3. Sprint C — port teams + players + people.
4. Sprint D — inline add-new + inline edit flow (currently forms are separate sub-pages).
5. Sprint E — advanced filters (date range, type, status) per entity.

## Open design questions

- Vanilla JS or a small framework (Alpine, Preact)? The plugin currently avoids build tooling; keeping that constraint means vanilla or a single tiny library via CDN.
- URL state — filters in querystring so results are shareable/bookmarkable?
- Does this replace the wp-admin list pages entirely, or live alongside? My lean: alongside. wp-admin stays for power-admin ops.

## Touches

Most of src/Shared/Frontend/Frontend*View.php
New: src/Shared/Frontend/FrontendListTable.php (or similar) — the shared component
