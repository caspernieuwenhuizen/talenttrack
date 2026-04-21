# TalentTrack v2.18.0 — Usage Statistics + Dashboard as Workspace

## Summary

Two items:

1. **Usage Statistics** — new admin page with login / active-user metrics, DAU + evaluations-created charts, top-pages breakdown, inactive-user nudge list. 90-day rolling retention, privacy-first (no IPs / no user agents).
2. **Admin Dashboard overhaul** — the formerly-inert 5-card stat page becomes a proper workspace: Overview (clickable gradient stat cards) + grouped tile navigation (People / Performance / Analytics / Configuration / Help) mirroring the menu structure. Cap-gated. Preparation for front-end admin work to come.

## Item 1 — Usage Statistics

### What gets tracked

Two event sources, kept lean:

- **Login events** — captured on `wp_login`. One row per login, per user, timestamped.
- **Admin page views** — on every TalentTrack admin page load. Skips separator slugs (`tt-sep-*`) so the menu separators don't inflate counters.

That's it. No IPs, no user agents, no fingerprinting. A dedicated `record($user_id, $type, $target)` method exists on `UsageTracker` for future instrumentation (e.g. "evaluation_saved" hooks could go here) but no other events are captured in this release.

### Retention

Fixed 90-day window. Events older than 90 days are deleted by a daily WP-Cron job (`tt_usage_prune_daily`). The cron is scheduled on activation and unscheduled on deactivation.

### Schema

Migration 0011 creates `tt_usage_events`:
- `id BIGINT UNSIGNED AUTO_INCREMENT`
- `user_id BIGINT UNSIGNED NOT NULL`
- `event_type VARCHAR(50) NOT NULL`
- `event_target VARCHAR(100) NULL`
- `created_at DATETIME NOT NULL`

Indexes on `(user_id, created_at)`, `(event_type, created_at)`, and `(created_at)` alone for the prune job.

### Admin page: TalentTrack → Usage Statistics

Admin-only (`tt_manage_settings`). Lives under the Analytics group in the admin menu.

**Headline row (6 tiles):**
- Logins over 7 / 30 / 90 days
- Unique active users over 7 / 30 / 90 days

Logins are blue-accented; active-user tiles green-accented. Big numbers, compact layout.

**Daily active users (90 days):**
Full-width filled line chart. Zero-filled days so sparse early weeks still render as a continuous series. Chart.js, ~260px tall.

**Evaluations created per day (90 days):**
Full-width bar chart. Reads directly from `tt_evaluations.created_at` rather than an event, so existing historical data is visible immediately (no "post-install only" gap).

**Two-column row:**
- **Active users by role (30 days)** — breakdown into Admins / Coaches / Players / Other via capability checks + player-link lookup. Horizontal bars with counts.
- **Most-visited admin pages (30 days)** — top 10, human-readable labels, progress-bar visualization of relative traffic.

**Inactive users table:**
Users who've logged in at any point within the 90-day window but haven't logged in the last 30+ days. Shows display name + last-login timestamp. Up to 20 rows. When empty: green "everyone's active" message.

### Privacy posture

- Admin-only visibility
- No identifying info beyond user_id
- No IPs, no user agents, no geolocation
- Clear banner on the page stating the retention + what's captured
- Events are auto-deleted at 90 days

If a regulator ever asks, the answer is: "we log which authenticated user loaded which admin page and when; nothing else. Everything is purged after 90 days."

## Item 2 — Admin Dashboard as a workspace

### The shift

**Before:** 5 non-clickable stat cards showing counts. Dead-end page — admins had to go back to the menu to navigate anywhere.

**After:** Dashboard is a proper workspace. Two sections:

**A. Overview (top):** 5 clickable gradient stat cards — Players, Teams, Evaluations, Sessions, Goals. Each:
- Shows active count (now properly filtered on `archived_at IS NULL`)
- Uses a per-entity gradient tint for visual identity (Players teal, Teams blue, Evaluations purple, Sessions gold, Goals red)
- Links directly to its list page on click
- Lifts on hover with shadow elevation

**B. Grouped tiles (below):** One section per menu group with its corresponding entries as tiles:
- **People** — Teams, Players, People
- **Performance** — Evaluations, Sessions, Goals
- **Analytics** — Reports, Player Rate Cards, Usage Statistics
- **Configuration** — Configuration, Custom Fields, Evaluation Categories, Category Weights
- **Help** — Help & Docs

Each tile shows:
- Icon (colored per group accent, gradient background)
- Label (matches menu label)
- One-line description (what you do on that page)

Tiles lift on hover. Section labels have a muted-gray uppercase treatment with a hairline divider extending right. Mobile-responsive: at 640px the tile grid collapses to 1 column and stat cards shrink.

### Design decisions (confirmed last sprint)

- **A=ii** — Two sections (Overview stats at top, grouped tiles below) instead of one unified grid
- **B=p** — Tile sections mirror the menu groups exactly
- **C** — Icon + label + description + count (count only on Overview, where it's meaningful)
- **D=x** — Primary entities appear in both Overview AND their group tiles (redundancy intentional; different purposes — survey vs navigate)
- **E** — Tiles are cap-gated; users only see what they can access
- **F=r** — More visual — gradients, colored icons per group, hover lift
- **G** — Tiles are navigation only; no new admin functionality is introduced by this sprint

### Why

You asked for this specifically as "preparing for front end admin work options." That lands. A well-designed dashboard is the natural foundation for a front-end admin experience: the same tile-based navigation concept can port to the user-facing shortcode, with different tile sets per role. The admin dashboard establishes the visual language.

### Scope boundary (confirmed)

Tiles link to existing admin pages. This sprint adds no new functionality behind tiles — no "quick-add evaluation from dashboard," no "recent activity feed," no "starred pages." Those are future work.

## Files in this release

### New
- `database/migrations/0011_usage_events.php` — `tt_usage_events` table
- `src/Infrastructure/Usage/UsageTracker.php` — event capture service + query helpers
- `src/Infrastructure/Usage/index.php`
- `src/Modules/Stats/Admin/UsageStatsPage.php` — admin dashboard page

### Modified
- `talenttrack.php` — version 2.18.0
- `src/Core/Activator.php` — `ensureSchema` creates `tt_usage_events` for fresh installs
- `src/Modules/Stats/StatsModule.php` — wires `UsageTracker::init()`
- `src/Shared/Admin/Menu.php` — dashboard fully rewritten (Overview + grouped tiles with scoped CSS and `lighten()` helper); Usage Statistics submenu re-added in Analytics group
- `languages/talenttrack-nl_NL.po` + `.mo` — 36 new strings

### Deleted
(none)

## Install

Extract `talenttrack-v2_18_0.zip`. The folder inside is `talenttrack-v2.18.0/`. Move contents into your `talenttrack/` plugin directory preserving structure. Deactivate + reactivate.

**On activation:**
- Migration 0011 creates `tt_usage_events` (idempotent — skipped if already present)
- `ensureSchema` creates the same table on fresh installs
- WP-Cron daily prune job (`tt_usage_prune_daily`) scheduled
- No data migration needed

**On deactivation:**
- Cron job unscheduled
- Events retained (reactivation picks up where things left off)

## Verify

### Usage Statistics
1. TalentTrack menu → Analytics → Usage Statistics. Page loads.
2. Headline tiles show 7/30/90-day login + active-user counts. Immediately after install these are all zero; they grow as you and other users log in and browse.
3. Daily-active-users chart renders as a filled line across 90 days (mostly zero initially).
4. Evaluations-per-day chart renders as bars, populated from existing `tt_evaluations.created_at` — you'll see historical evaluation activity right away.
5. Role breakdown shows your WP admin counted under "Admins."
6. Most-visited pages populates after ≥2 page views on TalentTrack admin pages.
7. Inactive users table is likely empty for a new install.

### Dashboard
8. Click the "Dashboard" menu entry (or the top-level TalentTrack item). New dashboard renders.
9. Overview section shows 5 gradient stat cards. Count numbers reflect current active (non-archived) rows. Each card is clickable — lifts on hover, navigates to the corresponding list page.
10. Below, grouped tiles appear: People (3 tiles), Performance (3 tiles), Analytics (3 tiles — Reports, Rate Cards, Usage Statistics), Configuration (4 tiles), Help (1 tile).
11. Each tile shows an icon with gradient background + label + one-line description. Click any tile — goes to the corresponding page.
12. Test with a non-admin user (coach): Configuration section is hidden entirely; only Performance + limited People tiles appear per capability.
13. On a phone / narrow viewport, stat cards shrink and tiles stack 1-wide.

### Menu separator rows (regression check)
14. The People / Performance / Analytics / Configuration separator rows in the submenu still render as muted uppercase headings and aren't clickable. Unchanged from 2.17.0.

## Known caveats

- **Usage stats historical data is empty after first install.** Only events captured from this release forward are visible on the dashboard. Evaluations chart is the exception — it reads the evaluations table directly, so existing data appears immediately.
- **WP-Cron reliability.** If the host has `DISABLE_WP_CRON` set, the daily prune won't fire automatically. The events table will grow unbounded until a cron run (manual or scheduled) trips it. For a typical club of 30 users this isn't urgent — table stays small — but worth knowing.
- **Dashboard visual style is fixed.** No theming options yet. If a club wants a different color palette, it's a CSS edit in `Menu.php`. Dedicated theme configuration slated for later.
- **Dashboard stat cards show active+non-archived.** Matches the list views. Inactive players (status≠'active') aren't counted; archived entities aren't counted.

## Design notes

- **Why a separate events table, not user meta.** User meta gives you one value per user at a time. We want a historical series — every login captured, queryable by time. The events table is the right shape. User-meta approach wouldn't answer "how many logins last week?"
- **Why evaluations chart sources from `tt_evaluations` instead of events.** Historical data. If we sourced only from `evaluation_saved` events, the chart would show zero before today even though the club has been using the plugin for months. Reading `created_at` from the entity table gives correct historical counts.
- **Why 90 days, not 180 or 365.** A practical privacy / storage / usefulness balance. 90 days is long enough to spot trends ("coaches stopped logging in 2 months ago") without becoming a long-term surveillance database. Can be adjusted (`UsageTracker::RETENTION_DAYS`) if a club has different needs.
- **Why cap-gated tiles, not menu-mirroring (WP already hides menu items by cap).** WP hides menu items but still shows them in the secondary nav on certain themes. Dashboard tiles need their own `current_user_can()` check to guarantee parity. Cheap — one check per tile, sub-millisecond impact.
- **Why gradient stat cards + plain gradient-icon tiles.** Stat cards need to stand out as the "top of the page" — so they get full gradient backgrounds, bigger numbers. Tiles are navigation — smaller, subtler, gradient only on the icon itself. The visual hierarchy communicates "these are different things."

## v2.19.0 preview

- People page archive-view filter refactor (still pending from v2.17.0)
- Optional bulk-delete cascade handling
- Possibly: dashboard customization (pin favorite tiles, show/hide sections)
- Front-end admin work (the actual feature this sprint prepared for)
