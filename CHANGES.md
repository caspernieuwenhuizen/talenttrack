# TalentTrack v2.19.0 — Drag-reorder Lookups + Back Button + Clickable KPIs + Compact Stat Cards

## Summary

Four UX fixes and polish items in one release:

1. **Lookup tables now have drag-to-reorder** — the "sort_order" column existed but had no UI to set values. Fixed with SortableJS drag handles.
2. **Back button on every edit/detail admin page** — referer-based, never takes you out of the plugin.
3. **Clickable KPIs on Usage Statistics** — every card, bar, and chart point drills into a detail page.
4. **Dashboard stat cards redesigned** — compact horizontal layout, "+N this week" delta per card, tighter and more informative.

Plus the usual translations + polish.

## Item 1 — Lookup drag-to-reorder

### The bug

Lookup tables (`tt_lookups`, e.g. position, age group, foot preference, eval type) had a `sort_order` column since way back. The Configuration page's list view showed "Volgorde / Order" column but all values read `0` because the **form to set them never exposed a usable input** — every new entry saved with the default. Reordering was effectively impossible without direct SQL.

### The fix

New shared `DragReorder` helper (`src/Shared/Admin/DragReorder.php`) that:

- Loads SortableJS 1.15.2 from jsDelivr (~15KB, cached across admin pages)
- Adds a drag handle (`⋮⋮`) as the first column on sortable tables
- Triggers an AJAX POST to `admin-ajax.php?action=tt_drag_reorder` with the new id order
- Server validates nonce + cap (`tt_manage_settings`) + that all ids belong to the same scope (lookup_type), then assigns sequential 0..N values to `sort_order`
- Shows a toast ("Order saved." / "Save failed.") so the user knows it worked
- Updates the visible "Order" cell values live so you can see the new sort_order without refreshing

### Where it's active

- Configuration → Positions / Foot Options / Age Groups / Goal Status / Goal Priority / Attendance Status (the `tab_lookup` tabs)
- Configuration → Evaluation Types (the `tab_eval_types` tab — same table, separate code path)

### Where it's deferred

- Evaluation Categories & Subcategories — that page renders mains and their subs in a single interleaved table. Dragging across main/sub boundaries would be ambiguous. Leaving this one for a future sprint (the form's `display_order` input still works manually). Stated in `DragReorder::TABLES` config for when we implement.

### Read side — verified

`QueryHelpers::get_lookups()` already does `ORDER BY sort_order ASC, name ASC` — correct since an earlier sprint. All consumers route through this helper (`get_lookup_names`, `get_eval_types`), so every dropdown in the plugin respects the new ordering the moment an admin drags rows around. No read-query fixes needed.

## Item 2 — Back button

### The need

Admin pages like player edit, evaluation view, session edit were reached from multiple places (list page, dashboard tile, cross-entity link). Users had no reliable way to get back to where they came from except the browser back button or the menu sidebar.

### Implementation

New `BackButton` helper (`src/Shared/Admin/BackButton.php`) with a single static `render( $fallback_url )` method. Strategy:

- Reads `wp_get_referer()` server-side — no client-side history fuzziness
- Only trusts referers within `/wp-admin/`
- Checks for self-referer (page refresh / form resubmit) and falls back instead of looping
- If referer is missing, external, or self, uses the caller's explicit fallback (typically the entity's list page)
- If no fallback given, falls back to the TalentTrack dashboard

Rendered as a subtle link above the `<h1>`, not a bold button — understated.

### Applied to

10 detail/edit render methods across 8 files:

- Players — edit form, detail view
- Teams — edit form
- Evaluations — edit form, detail view
- Sessions — edit form
- Goals — edit form
- People — edit form
- Custom Fields — edit form
- Evaluation Categories — edit form

List pages don't get a back button — the sidebar menu serves that role, and list pages typically ARE where you go back to.

### Redundant links removed

Two pages had their own inline "← Back" `page-title-action` buttons. These were removed in favor of the new BackButton, since having two back links side by side looks weird.

## Item 3 — Clickable KPIs on Usage Statistics

### The need

You noted: "whenever there are KPI cards a user expects them to be clickable to at least some more details." Agreed. The 2.18.0 Usage Statistics page had information-dense visuals but the numbers were dead-ends — seeing "42 logins this week" and having no way to click through to see which logins is a promise broken.

### The fix

New hidden admin page: `UsageStatsDetailsPage` at `?page=tt-usage-stats-details`. Registered via `add_submenu_page( null, ... )` so it doesn't clutter the menu but routes correctly. Admin-only.

Each Usage Statistics surface now drills into this page with a metric parameter:

| Metric | Parameter shape | Detail shown |
|---|---|---|
| `logins` | `?metric=logins&days=7|30|90` | Every login event in the window: user, role, timestamp |
| `active_users` | `?metric=active_users&days=...` | Every active user: name, role, login count, total events, last-seen, → timeline link |
| `dau_day` | `?metric=dau_day&date=YYYY-MM-DD` | Users active on that specific date with event counts and first/last activity times |
| `evals_day` | `?metric=evals_day&date=...` | Every evaluation created that day with player, type, coach, → view link |
| `active_by_role` | `?metric=active_by_role&role=admin|coach|player|other&days=...` | Users of that role active in the window |
| `top_page` | `?metric=top_page&slug=tt-players&days=...` | Per-user visit counts to a specific admin page |
| `user_timeline` | `?metric=user_timeline&uid=N` | Full event timeline for one user within the 90-day retention window |

### UX affordances

- **Headline tiles** are now wrapped as `<a>` with hover lift + "See details →" hint line
- **Role breakdown bars** are wrapped as links with a subtle hover background
- **Top-pages table rows** get a hover tint and cursor:pointer; entire row navigates
- **Inactive-users table rows** likewise clickable → user's timeline
- **DAU line chart** — Chart.js `onClick` handler navigates to `dau_day` for the clicked data point; cursor changes to pointer on hover
- **Evaluations bar chart** — same, to `evals_day`

Each detail view uses the new BackButton so you always return to the Usage Statistics dashboard cleanly.

### Privacy revisited

Previous release's Usage Statistics page was "admin-only aggregate metrics, no drill-down" out of abundance of caution on GDPR. Drilling into per-user data is **fine** in this context because:

- You're the data controller
- Users are your own club members with a direct relationship
- The data is already in WordPress (login times, admin URLs visited)
- No IPs, no user agents, no third-party sharing
- Still admin-only (`tt_manage_settings`) — only the club administrator sees this

## Item 4 — Stat cards redesign

### The complaint

The 5 stat cards on the dashboard (Players / Teams / Evaluations / Sessions / Goals) were visually heavy — 220px wide, ~130px tall, lots of empty space per card, and only showing a count. Low data density.

### The new design

**Horizontal layout, compact:**
- ~58px tall (down from ~130px)
- ~200px min-width (down from 220px, fits more per row)
- Icon left (34×34), body right — takes less vertical space
- Border-left 3px accent stripe instead of full gradient background (cleaner, less noisy)

**Weekly delta indicator:**
- Small pill next to the count shows row additions in the last 7 days: "+3 this week"
- Green pill if delta > 0, muted gray if 0
- Delta computed server-side from `created_at`

**Simpler visual identity:**
- Dropped the background gradient in favor of a clean white card with colored accent stripe
- Kept the per-entity color (teal Players, blue Teams, etc.) so each card still has its identity

### Result

Same information density × more cards per row + new delta data = actually looks like a dashboard. Less dead space per card.

## Files in this release

### New
- `src/Shared/Admin/BackButton.php` — referer-based back navigation helper
- `src/Shared/Admin/DragReorder.php` — SortableJS-backed drag-reorder + AJAX handler, supports multiple tables
- `src/Modules/Stats/Admin/UsageStatsDetailsPage.php` — drill-down views for all Usage Statistics KPIs

### Modified
- `talenttrack.php` — version 2.19.0
- `src/Shared/Admin/Menu.php` — register DragReorder hook, register hidden `tt-usage-stats-details` page, redesigned stat-card render path with compact horizontal + delta + color accent
- `src/Modules/Configuration/Admin/ConfigurationPage.php` — drag-handle column + SortableJS wiring in `tab_lookup` and `tab_eval_types`
- `src/Modules/Stats/Admin/UsageStatsPage.php` — headline tiles now linked, role bars linked, top-pages rows linked, inactive-users rows linked, chart click handlers for day drill-downs, new CSS for hover affordances
- `src/Modules/Players/Admin/PlayersPage.php` — BackButton on edit form + detail view, cleanup of redundant back-link
- `src/Modules/Teams/Admin/TeamsPage.php` — BackButton on edit form
- `src/Modules/Evaluations/Admin/EvaluationsPage.php` — BackButton on edit form + detail view, cleanup
- `src/Modules/Sessions/Admin/SessionsPage.php` — BackButton on edit form
- `src/Modules/Goals/Admin/GoalsPage.php` — BackButton on edit form
- `src/Modules/People/Admin/PeoplePage.php` — BackButton on edit form
- `src/Modules/Configuration/Admin/CustomFieldsPage.php` — BackButton on edit form
- `src/Modules/Evaluations/Admin/EvalCategoriesPage.php` — BackButton on edit form
- `languages/talenttrack-nl_NL.po` + `.mo` — 38 new strings

## Install

Extract `talenttrack-v2_19_0.zip`. Move `talenttrack-v2.19.0/` contents into your `talenttrack/` plugin folder. Deactivate + reactivate.

**No schema migrations.** All changes are UI + service layer. `sort_order` columns already exist; `tt_usage_events` already exists from 2.18.0.

## Verify

### Drag-reorder
1. Configuration → Positions tab. Hover any row — drag handle (⋮⋮) visible on the left.
2. Drag a row up or down. Release. Toast in bottom-right: "Order saved." The numeric Order column updates live.
3. Go to any player edit form — Position dropdown now reflects the new order immediately (no cache, no refresh).
4. Same on Configuration → Age Groups, Foot Options, Evaluation Types, etc.

### Back button
5. Dashboard → click the Players stat card → Players list → click any player's Edit → you're on the edit form. Top of the page: "← Back" link.
6. Click it — you're back on the Players list.
7. Try from a different origin: open the edit URL directly (copy-paste). Click Back — falls back to the Players list (since there's no meaningful referer).

### Clickable KPIs
8. Analytics → Usage Statistics.
9. Click "Logins (7 days)" tile → new page listing every login event in the last 7 days. Back button returns.
10. Click the Role "Coaches" bar → list of active coaches. Back button returns.
11. Click any data point on the DAU line chart → list of users active that specific day. Chart cursor is pointer on hover.
12. Click any bar on the Evaluations chart → evaluations created that day.
13. Click an Inactive user row → that user's event timeline.

### Stat cards
14. Dashboard top. Cards are compact horizontal, icon left, count + delta pill on top row, label below.
15. Delta shows "+N this week" if any entity was created in the last 7 days. Green pill for positive, gray pill for zero.
16. Cards fit more per row than before — 5 cards easily on a 1400px-wide screen, wrap gracefully on narrower.

## Known caveats

- **Eval Categories drag deferred.** The Eval Categories page renders mains + subs interleaved. Drag-reorder there requires either separating into two sortable scopes (per main) or a full UI redesign. Scheduled for a future sprint. The form's `display_order` input still works manually.
- **Drag requires SortableJS from CDN.** If the admin's network blocks jsDelivr, drag won't work and admins fall back to editing `sort_order` via the edit form. No error surfaced (graceful degradation — SortableJS `if ( typeof Sortable === 'undefined' ) return` guard).
- **Delta logic is additive only.** "+5 this week" counts rows created in the last 7 days. It doesn't count archives, deletions, or restores. Good enough for a dashboard signal; if a club wants churn metrics we can add that later.
- **Clickable charts don't indicate the exact point on hover beyond the native Chart.js tooltip.** The cursor changes to pointer and a hint line says "click any day" — matches the intent without adding visual noise.

## Design notes

- **Why SortableJS over HTML5 drag API.** Native HTML5 drag is verbose, fragile across browsers, and doesn't give you smooth animations out of the box. SortableJS is 15KB minified, mature, and handles all the edge cases (auto-scroll on long lists, touch support for mobile admins, accessibility).
- **Why hidden submenu for drill-downs rather than a separate top-level menu.** The drill-downs aren't a standalone feature — they're navigation depth from Usage Statistics. Cluttering the menu with "Usage Statistics — Logins", "Usage Statistics — Active Users" etc. would be a disaster. Hidden-page pattern via `add_submenu_page( null, ... )` routes correctly without menu pollution.
- **Why BackButton over history.back().** `history.back()` can take users out of the plugin entirely if they arrived via bookmark or external link. Server-side referer check with fallback keeps navigation inside TalentTrack boundaries, matching WordPress's own list-action patterns.
- **Why delta = 7-day window specifically.** Week is the shortest meaningful period for youth football activity (one training cycle). Monthly is too long to feel actionable. Daily would fluctuate too much with weekends. 7-day is right.
- **Compact card redesign preserves the "lift on hover" affordance.** The interaction signal (these cards are clickable) is the same as before; only the visual size changed. Existing muscle memory transfers.

## v2.20.0 preview

- Eval Categories drag-reorder (deferred from this sprint)
- People page archive-view filter refactor (deferred from 2.17.0)
- Front-end admin work (the large arc prepared by the 2.18.0 dashboard)
- Possibly: cascade handling for bulk-delete of entities with dependents
