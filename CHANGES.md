# TalentTrack v2.17.0 — Admin Menu Overhaul + Bulk Archive/Delete + Isolated Print

## Summary

This release bundles four changes that make the admin experience cleaner and more robust for daily club use:

1. **Admin menu overhauled** — flat 13-item menu grouped into logical sections with visual separators
2. **Bulk archive & delete** across all list views — select many rows, archive them (reversible) or permanently delete (admins only)
3. **Teams list** — Head Coach column removed from display (field still editable on the team form)
4. **Printable report fixed** — print route is now fully isolated; no more WP admin chrome leaking into printed output. Visible Print button + Download PDF button. No more auto-fire.

The usage-statistics item originally planned for this sprint has been **deferred to v2.18.0** — that one grew into enough scope to deserve its own release with proper implementation.

## Item 1 — Admin menu overhaul

The TalentTrack admin menu was a flat list of ~13 submenu entries accumulated across sprints. It's now grouped:

```
Dashboard
─── People ─────────────────
  Teams
  Players
─── Performance ────────────
  Evaluations
  Sessions
  Goals
─── Analytics ──────────────
  Reports
  Player Rate Cards
─── Configuration ──────────
  Configuration
  Custom Fields
  Evaluation Categories
  Category Weights
Help & Docs
```

Separators are non-clickable heading rows styled via inline admin CSS (small caps, muted gray, thin top border). Clicking one does nothing visible; keyboard-nav accidentally landing on one redirects silently to the dashboard.

**Implementation note**: WordPress has no native grouped-submenu support. Separators are faked with `add_submenu_page` entries whose slug begins with `tt-sep-` and whose display is transformed entirely by CSS. The approach is resilient — plays well with WP's existing capability checks, bookmarks to real submenu pages keep working, admin themes that override the submenu list don't break.

## Item 2 — Bulk archive and delete

Every major entity list view now supports bulk actions:

**Surfaces covered:**
- Players
- Teams
- Evaluations
- Sessions
- Goals
- People (bulk archive/delete only — full archive-view filtering on People deferred to v2.18.0 since it uses its own repository layer)

**Actions available:**
- **Archive** — soft-delete; row retained with `archived_at` timestamp + `archived_by` user id. Reversible.
- **Restore** — clear archive stamp; row returns to active list. Only offered from the Archived view.
- **Delete permanently** — hard delete from DB. Irreversible. Admin-only (requires `tt_manage_settings`).

**UI shape:**
- Checkbox column (30px wide) at left of every row
- Master select-all checkbox in the table header
- Bulk action dropdown + Apply button, rendered above AND below the table (standard WordPress list-table convention)
- Status tab-bar above the table: **Active (N) | Archived (N) | All (N)**, each link counted. URL-driven (`?tt_view=active|archived|all`).
- Archived rows in the "All" or "Archived" view render with 60% opacity + gray "Archived" badge next to the name

**UX details:**
- Select-all checkbox toggles every row checkbox in the same form
- "Delete permanently" triggers a JS confirm with the selected count before submitting
- Empty-selection submit shows a nudge ("No items selected.")
- Non-admin user accidentally submitting "Delete permanently" (shouldn't appear in their dropdown but belt-and-braces) hits a server-side permission error
- After any bulk action, a success notice shows at the top of the list: "3 items archived." etc.

**Schema:**
- Migration 0010 adds `archived_at DATETIME NULL` + `archived_by BIGINT UNSIGNED NULL` + `idx_archived_at` index to `tt_players`, `tt_teams`, `tt_evaluations`, `tt_sessions`, `tt_goals`, `tt_people`
- Idempotent — skips tables/columns that already have the columns
- `ensureSchema` updated for fresh installs
- Default list queries automatically append `AND archived_at IS NULL` so archived rows don't leak into the main views

**Permission model:**
- Archive/restore — uses each entity's existing management cap (`tt_manage_players` for player/team/person, `tt_evaluate_players` for evaluation/session/goal)
- Delete permanently — always requires `tt_manage_settings` (full admin), regardless of entity
- Rationale: archiving is cheap + reversible, should flow naturally; permanent deletion is rare + destructive, belongs in admin hands only

**Cascade behavior:** archiving a team does NOT archive its players, sessions, or goals. Each entity is archived independently. An archived team's active players remain visible and editable; they just lose access to the team badge. Explicit independence avoids the "I archived one team and everything vanished" footgun.

**Future work (v2.18.0):**
- People page archive-view filtering (currently shows active-only in the Active tab, empty in Archived due to repo layer not yet supporting the filter)
- Dependency warnings when archiving an entity with many active dependents
- Cascade-on-delete for permanent deletes (currently orphans dependents)

## Item 3 — Head Coach field removed from Teams display

The Teams admin list no longer shows the Head Coach column. The underlying `head_coach_id` column on `tt_teams` is retained; the field is still editable on the team form. This is a pure display change — no schema migration, no data changes.

## Item 4 — Isolated print route

The v2.16.0 print report leaked admin shell / theme chrome into printed output because the report rendered inside the regular admin/theme layout and relied on `@media print { visibility: hidden }` CSS tricks to hide surrounding elements. This didn't always work: admin notices, theme footers, and specific theme rules with high CSS specificity slipped through.

**v2.17.0 approach:** A new `PrintRouter` intercepts print requests at `admin_init` (for admin URLs) and `template_redirect` (for frontend URLs), **before** WordPress assembles any admin or theme chrome. It emits a completely standalone `<html>...</html>` document containing:

1. A small fixed action bar at the top with three buttons:
   - **🖨 Print this report** — triggers `window.print()` on a clean document
   - **📄 Download PDF** — uses html2canvas + jsPDF (loaded from CDN) to generate a raster PDF and trigger browser download
   - **← Back** — returns to the previous page

2. The actual report content (club header, FIFA card, headline tiles, breakdown, charts, signature footer)

3. Print-only CSS that hides the action bar during printing

**Key changes from v2.16.0:**
- No more auto-firing `window.print()` on page load — user sees the preview first, clicks Print when ready
- No admin shell or theme chrome on the print page — what you see is what prints
- Download PDF button generates an A4 portrait PDF named `TalentTrack-Report-<player>-<date>.pdf`. Output is raster (text not selectable in the PDF) — acceptable for a 1-page visual report. Library fails? Falls back to browser print dialog.
- Works from every surface that had the 🖨 Print report button — just routes through the isolated page now

**Request shapes accepted by `PrintRouter`:**
- `?tt_report=1&player_id=N` (preferred, new)
- `?tt_print=N` (legacy frontend URL, still works)
- `?print=1&player_id=N&page=tt-rate-cards` (legacy admin URL, still works)

**Permission checks on the isolated route:**
- Admin entry (in wp-admin URLs): requires `tt_view_reports`
- Frontend entry (on shortcode pages): admin any player, coach only players on coached teams, player own only
- Unauthorized requests get `wp_die` with a localized message instead of rendering

**Why raster PDF (html2canvas + jsPDF) rather than a server-side PDF library:**
- html2canvas captures the already-rendered Chart.js canvases correctly; a server-side library like dompdf can't execute JS and would produce empty chart boxes
- No server dependencies, no composer install needed, no PHP memory issues
- ~500KB of JS loaded only on the print page (not globally)
- Acceptable trade-off: PDFs aren't text-selectable, but the use case is "save a report to share," not "search through archives"

## Files in this release

### New
- `src/Infrastructure/Archive/ArchiveRepository.php` — archive/restore/delete + counts + filter clauses
- `src/Infrastructure/Archive/index.php`
- `src/Shared/Admin/BulkActionsHelper.php` — shared UI + post handler for bulk actions
- `src/Modules/Stats/PrintRouter.php` — isolated print route handler
- `database/migrations/0010_archive_support.php` — archive columns for 6 tables

### Modified
- `talenttrack.php` — version 2.17.0
- `src/Core/Activator.php` — `ensureSchema` gets archive columns on 6 tables
- `src/Shared/Admin/Menu.php` — grouped submenus with CSS-styled separators; BulkActionsHelper wired
- `src/Modules/Teams/Admin/TeamsPage.php` — Head Coach column removed; archive tabs + bulk actions
- `src/Modules/Players/Admin/PlayersPage.php` — archive tabs + bulk actions
- `src/Modules/Evaluations/Admin/EvaluationsPage.php` — archive tabs + bulk actions
- `src/Modules/Sessions/Admin/SessionsPage.php` — archive tabs + bulk actions
- `src/Modules/Goals/Admin/GoalsPage.php` — archive tabs + bulk actions
- `src/Modules/People/Admin/PeoplePage.php` — bulk actions (archive filter deferred)
- `src/Modules/Stats/StatsModule.php` — wire PrintRouter
- `src/Modules/Stats/Admin/PlayerRateCardView.php` — print button URL points at PrintRouter; old print=1 short-circuit removed
- `src/Modules/Stats/Admin/PlayerReportView.php` — auto-fire print logic removed; charts render without post-render print trigger
- `src/Shared/Frontend/DashboardShortcode.php` — old `?tt_print=N` short-circuit removed; PrintRouter handles it
- `languages/talenttrack-nl_NL.po` + `.mo` — 30 new strings

### Deleted
(none)

## Install

Extract the ZIP. Folder inside is `talenttrack-v2.17.0/` — separate from your live `talenttrack/` for review. Move contents into your `talenttrack/` plugin directory preserving tree structure. Deactivate + reactivate.

**Activation runs:**
- Migration 0010 → adds archive columns to 6 tables (idempotent — skipped if already present)
- `ensureSchema` creates those columns on any fresh install
- No data migration needed — existing rows get `archived_at = NULL` by default, meaning active

## Verify

### Menu
1. Open any TalentTrack admin page. Side menu is grouped with visual separators ("PEOPLE", "PERFORMANCE", "ANALYTICS", "CONFIGURATION") between related entries.
2. Hover a separator — no link interaction, cursor stays default.

### Bulk actions
3. Open TalentTrack → Players. At the top of the list: status tabs "Active (N) | Archived (0) | All (N)".
4. Select 2-3 players via their row checkboxes. Pick "Archive" from the bulk actions dropdown above the table. Click Apply. Get a success notice "3 items archived." The archived rows disappear from the Active view.
5. Click the "Archived" tab. The archived rows appear, rendered at 60% opacity with an "Archived" badge.
6. Select them. Bulk action dropdown now offers "Restore" + "Delete permanently" (the latter only if you're an admin).
7. Try "Delete permanently" with something selected — confirm dialog appears with the count. Cancel, then try "Restore" — rows return to Active.
8. Repeat on Teams, Evaluations, Sessions, Goals to confirm the pattern works uniformly.

### Teams — head coach removed
9. Teams list: no "Head Coach" column. Edit any team: head-coach dropdown still present on the form.

### Print report
10. Go to TalentTrack → Player Rate Cards, pick a player, click "🖨 Print report". New tab opens.
11. The page is clean — no WP admin menu, no theme header, just: action bar at top with [🖨 Print] [📄 Download PDF] [← Back], then the report below.
12. Click the Print button — browser print dialog opens, preview shows ONLY the report (no ambient UI).
13. Click Download PDF — a file named `TalentTrack-Report-<player>-<date>.pdf` downloads.
14. Try the same from a player's frontend dashboard (Overview "🖨 Print report" button) and coach Player Detail button. Same clean output.

## Known caveats

- **People page archive view**: the Active tab works as expected; the Archived and All views query the table directly (bypassing `PeopleRepository::list()`) and don't yet support the repo's search/only-staff filters in combination with the archive view. Bulk actions still work in all views. Full repo refactor slated for v2.18.0.
- **PDF output is raster, not vector**: text in the generated PDF isn't selectable. Use browser print-to-PDF (Ctrl+P → Save as PDF) if you need selectable text.
- **Bulk delete doesn't cascade**: if you permanently delete a team, its player rows keep `team_id` pointing at the now-missing team (shown as "—" in the list). Dependent-row handling was deemed out of scope for this release.

## Design notes

- **Separators as fake menu entries**: Chosen over CSS-injected DOM manipulation because WP's admin menu is dynamically rendered and manipulating it post-render fights the framework. Fake entries live inside WP's model, get the right keyboard-nav treatment, and degrade gracefully in themes that style the menu differently.
- **Archive as column, not join table**: `archived_at` on the entity table itself keeps list queries simple (single `WHERE` clause, no extra JOIN). The archive pattern composes naturally with existing filters.
- **Positional permission check on print route**: Checked before any rendering, by `PrintRouter`, rather than scattered across the old short-circuit code paths in admin + frontend. Single source of truth for "who can print what."
- **Why defer usage stats**: It turned into a full new module — events table, capture tracker, daily prune cron, admin dashboard with multiple Chart.js visualizations, privacy documentation. Scoping it inside a sprint that already had four big items risked shipping something half-baked. Better to ship what's solid and take another run at stats in v2.18.0.

## v2.18.0 preview

- Usage statistics (the deferred item)
- People page archive-view filtering to match the other 5 entities
- Optional: bulk-delete cascade handling for entities with dependents
- Whatever else accumulates
