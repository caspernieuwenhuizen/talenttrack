<!-- type: feat -->

# #0019 Sprint 3 — Players + Teams frontend with CSV bulk import

## Problem

Players and Teams are the two largest entity surfaces in TalentTrack. Both live entirely in wp-admin today:

- **Players**: single-record CRUD on `PlayersPage`, rate-card tab, CSV bulk import, media uploader for photos. Used heavily by both coaches (single-record edits) and club admins (CSV, bulk operations).
- **Teams**: CRUD on `TeamsPage`, roster management, head coach assignment. Used by HoD and admins.

Getting these onto the frontend unblocks most of the remaining day-to-day work. It's also the sprint with the most historically admin-tier features (CSV, media upload) — so it's where "admin can do everything on frontend" meets its hardest test.

This sprint is explicitly larger than Sprint 2. Budget honestly for ~30–35 hours.

## Proposal

Four deliverables:

1. **Players frontend surface** — list (via `FrontendListTable` from Sprint 2), single-record create/edit/delete, photo upload via WP media uploader (enqueued on frontend).
2. **Teams frontend surface** — list, create/edit/delete, roster management (add/remove players), head coach assignment, formation placeholder (routes to "Coming with #0018").
3. **CSV bulk import on frontend** — full parity with the existing wp-admin CSV import. Upload, row-by-row preview, validation errors per row, dupe detection strategy, async processing with progress polling, transactional behavior.
4. **Player rate-card view on frontend** — the FIFA-card rendering already works both sides; this sprint ensures it's cleanly accessible from the player edit surface.

Existing wp-admin pages remain intact.

## Scope

### Players frontend

Views under `src/Shared/Frontend/FrontendPlayersManageView.php`:

- **List view**:
  - Powered by `FrontendListTable` from Sprint 2.
  - Filters: team, position, active/archived, preferred foot, age group.
  - Columns: photo thumb, name, team, position, age, actions.
  - Search: by name.
  - Row actions: Edit, View rate card, Archive, Delete.
- **Create/edit view**:
  - Form fields: first name, last name, birth date (DateInput), team (PlayerPicker equivalent for teams), jersey number, position, preferred foot, height, weight, notes.
  - **Photo upload**: uses WP media uploader (`wp_enqueue_media()` — confirmed frontend-compatible during epic shaping). Shows current photo, button to upload new or pick from library.
  - Custom fields rendered via existing `CustomFieldRenderer`.
  - Save via REST `Players_Controller` (expand existing).
  - Draft persistence.
- **Player rate card view**:
  - The FIFA-style card already renders via `PlayerCardView` which works both admin and frontend.
  - Accessible from the edit view ("View rate card") and as its own tile.
  - No rebuild — just clean accessibility from the frontend surface.

### Teams frontend

Views under `src/Shared/Frontend/FrontendTeamsManageView.php`:

- **List view**: via FrontendListTable. Filters: age group, season, active/archived. Columns: team name, age group, coach, player count.
- **Create/edit view**:
  - Form: team name, age group, season, head coach (dropdown of users with `tt_coach` role or higher).
  - **Roster section**: list of current players on the team, add/remove players. Autocomplete search to add a player from the pool.
  - **Formation section**: a boxed area with placeholder text: "Team formation coming with #0018 — team development." Link to roadmap. No functional UI, just the placeholder.
- **Delete**: archive.

### CSV bulk import (frontend)

New view: `src/Shared/Frontend/FrontendPlayersCsvImportView.php`.

Flow:

1. **Upload step**: file input (`<input type="file" accept=".csv">`). Not WP's media uploader — plain file upload. Client-side validation: file size ≤ 5MB, extension `.csv`, MIME `text/csv` or `application/vnd.ms-excel`.
2. **Preview step**: after upload, server parses the CSV and returns a preview of the first 20 rows with per-row validation status (valid / warning / error). Each row shows which column failed and why.
3. **Dupe handling**: for each row whose `first_name + last_name + birth_date` matches an existing player, offer three options per-row: **Skip** (default), **Update existing**, or **Create new anyway**. Bulk-apply buttons at the top ("Skip all dupes", "Update all dupes").
4. **Commit step**: "Import 47 rows" button. Backend processes in batches via an async job pattern (same as #0020's demo generator). Progress-polling UI shows "Processed 23 of 47…".
5. **Result step**: summary — how many imported, how many skipped, how many errored. Link to re-download error rows as a corrected-input CSV for retry.

Reuses existing wp-admin CSV parsing logic — new surface, same backend. Expose via REST endpoint `POST talenttrack/v1/players/import` (multipart/form-data).

Transactional behavior: **accept-what-worked with clear report** (per earlier shaping decisions for #0020's philosophy). If row 47 of 100 fails, rows 1–46 are committed; rows 48+ continue; row 47 surfaces in the error report. No rollback. Reason: rollback on a large import is expensive and the error report gives the user clear remediation.

### Player access from Teams

- On the Teams edit view, roster lists are clickable — link straight to the player edit view (same convention as wp-admin).

## Out of scope

- **Replacing the wp-admin Players/Teams pages.** Menu removal is Sprint 6.
- **Bulk ops other than CSV import** (bulk delete, bulk archive, bulk move-between-teams). Out of scope for this sprint; can be a later sprint or a separate idea if needed.
- **Player photo generation or integration with an external photo service.** Flag from #0020: generated demo players don't have photos, and that stays out of #0019 too.
- **Team formation board** — #0018 owns this entirely. Sprint 3 has a placeholder only.
- **Rate-card rebuild** — the card rendering exists and stays as-is. Only its accessibility from the frontend surface is in scope.
- **CSV export** of players — import only in this sprint. Export is separate.

## Acceptance criteria

### Players

- [ ] Coach/HoD/admin can browse all accessible players on the frontend with filters + search + pagination.
- [ ] Create a new player via the frontend with all fields including photo upload. Photo uploader opens the WP media library.
- [ ] Edit an existing player via the frontend.
- [ ] Archive/delete a player.
- [ ] The FIFA-style rate card is accessible from the player edit surface.
- [ ] Mobile viewport (375px) works without horizontal scroll.
- [ ] Custom fields (if configured) render correctly on the frontend form.

### Teams

- [ ] Browse, create, edit, delete teams on the frontend.
- [ ] Roster management: add or remove players from a team via the frontend.
- [ ] Head coach assignment works (dropdown of eligible users).
- [ ] Formation placeholder renders with link to #0018 roadmap.
- [ ] Mobile works.

### CSV import

- [ ] Club admin can upload a CSV of players from the frontend.
- [ ] Preview step shows first 20 rows with per-row validation.
- [ ] Dupes are detected and offer skip/update/create options.
- [ ] Full import runs asynchronously with progress polling.
- [ ] Result step clearly shows imports/skips/errors.
- [ ] Errored rows are exportable as a corrected-input CSV for retry.
- [ ] A 500-row CSV imports successfully without timing out.

### No regression

- [ ] wp-admin Players and Teams pages still work.
- [ ] wp-admin CSV import still works.
- [ ] Data written via frontend and data written via wp-admin is indistinguishable in the database.

## Notes

### Sizing

~30–35 hours, the largest sprint in the epic. Breakdown:

- Players frontend (including media uploader): ~8 hours
- Teams frontend (including roster management): ~7 hours
- CSV import on frontend: ~10 hours (this is the expensive one)
- Rate card accessibility + placeholder for formations: ~2 hours
- Mobile polish + draft wiring + testing: ~5 hours
- Buffer: ~3 hours

### Media uploader on frontend

`wp_enqueue_media()` works on frontend — confirmed during the epic-shaping technical audit. Small CSS reset needed because the modal default styling inherits some wp-admin styles. The plugin already calls `wp_enqueue_media()` in `PlayersPage.php` and `ConfigurationPage.php`; the frontend call is structurally identical.

### CSV parsing

The existing wp-admin CSV import code is the source of truth. Do not rewrite — refactor to be callable from a REST endpoint, and wire the frontend UI to that endpoint. Reuse the parsing, dupe detection, and validation logic. Only the entry point and UI layer are new.

### Transactional philosophy

Per shaping, async CSV accepts-what-worked without rollback. Document this clearly in the UI ("Row 47 failed. Rows 1–46 were imported. Rows 48–100 continue. See error report below."). No surprising "everything or nothing" semantics.

### Touches

- `src/Shared/Frontend/FrontendPlayersManageView.php` (new)
- `src/Shared/Frontend/FrontendTeamsManageView.php` (new)
- `src/Shared/Frontend/FrontendPlayersCsvImportView.php` (new)
- `includes/REST/Players_Controller.php` (expand — add create/update/delete if not there, add import endpoint)
- `includes/REST/Teams_Controller.php` (new or expand)
- `src/Modules/Players/CsvImporter.php` (refactor to be REST-callable)
- Existing tile grid: add Players Manage, Teams Manage, CSV Import tiles

### Depends on

Sprint 1 (REST, components, CSS), Sprint 2 (FrontendListTable).

### Blocks

Sprint 4 can start in parallel with this if needed (different surfaces, different developers). Sprint 5 doesn't depend on this.
