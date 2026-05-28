# Player profile — design notes

## Target

The primary working surface in TalentTrack. Coach reaches for it daily; HoD on review days; parent on parent-portal access. Currently a single-column hero + 7 tabs (Profile / Goals / Evaluations / Activities / PDP / Trials / Notes) at `?tt_view=players&id=N`.

Pilot has consistently surfaced "I can't find X" feedback. The redesign keeps **every piece of data** that's there today but pulls the high-value-at-a-glance pieces above the fold, and gives each tab a cleaner shape.

## Inventory (must preserve)

From `src/Shared/Frontend/FrontendPlayerDetailView.php`:

### Hero (lines 328-401)
- Photo / initials avatar
- Player name (page title, line 217)
- Team link + age group (lines 345-349)
- Status pill (line 353)
- "Log behaviour" button — cap `tt_rate_player_behaviour` (lines 366-369)
- "Set potential" button — cap `tt_set_player_potential` (lines 371-374)
- Journey text — "X days in academy" / "Joined YYYY-MM-DD" (lines 379-386)
- Latest activity chip (lines 447-466)
- Latest evaluation chip (lines 469-480)
- Latest goal chip (lines 483-491)

### Page actions (lines 219-261)
- Edit button — primary FAB on mobile, cap `tt_edit_players` (lines 227-236)
- "Assign to team" button — only when no team (lines 241-250)
- Archive button — danger variant, REST DELETE (lines 252-260)

### Tabs (lines 281-300)
- Profile / Goals / Evaluations / Activities / PDP / Trials
- Notes — cap `tt_view_player_notes` (lines 169-170)
- Tab badges with non-zero counts via `PlayerFileCounts::for()` (line 276)
- Analytics tab REMOVED v3.110.187 — use `?tt_view=explore` instead (don't re-add)

### Profile tab (lines 514-582)
- **Identity table** (line 568): DOB · Position(s) · Preferred foot · Jersey number · Status
- **Academy table** (line 569): Team (or "Unassigned") · Age tier · Date joined
- Inline "Assign to team" form if unassigned and user has `tt_edit_players` (lines 573-574)
- "View status history" link → `?tt_view=player-status-capture&player_id=N` (lines 576-581, from #870)

### Goals tab (lines 649-688)
- List: Title (link) · Status pill · Deadline
- Empty state with "Add first goal" CTA

### Evaluations tab (lines 691-746)
- List: Date (link)
- Empty state with "Record first evaluation" CTA
- Inline row-level archive/delete REMOVED v3.110.148 — destructive actions only on detail surface

### Activities tab (lines 757-841)
- List: Date · Activity title (link) · Attendance status pill
- v3.110.185 (#789): includes both planned and completed
- Empty state varies by team-assigned-or-not

### PDP tab (lines 844-879)
- List: Status · Created date
- Empty state with "Start PDP cycle" CTA

### Trials tab (lines 882-931)
- List: Status (link) · Start date · End date
- Empty-state message varies; "Open trial case" CTA only when status='trial'

### Notes tab (lines 940-963)
- Threads module (#0085), cap-gated `tt_view_player_notes`

## Redesign moves

1. **Surface identity above the fold.** DOB / position / foot / jersey / status — coaches keep asking "where is X" because these are buried inside Profile tab. Mockup pulls them into a `Key facts` strip directly under the hero, before tabs.

2. **"At a glance" KPI strip.** Three living signals — latest evaluation, attendance % (30d), active goals count — replace the loose "latest chips" stack. KPI shape (number + label + tiny trend hint) reads at a glance.

3. **Compress hero, expand actions.** Action buttons (Log behaviour, Set potential, Edit, ⋯ overflow for Archive) collapse into a single action row under the hero. Each is cap-gated identically to today; the visual hierarchy is just tighter.

4. **Tabs as horizontally scrolling chips** — Profile / Goals / Evals / Activities / PDP / Trials / Notes. Each carries its count badge inline. Mobile-friendly (no overflow menu); desktop renders them in a single visible row.

5. **Profile tab gets parents + scouts** — currently parent contacts and scouts who logged the player exist as data but aren't surfaced on this page. Adding linked-records panels under the Identity / Academy tables (with caps respected) closes a discoverability gap pilot mentioned in 2026-05 conversations.

6. **Better empty states** — clean illustrations-as-text placeholders with primary CTA in the tab content area. Pattern reused across all 6 listing tabs.

## States the mockup picker toggles

- **Tab**: Profile (default) / Goals / Evaluations / Activities / PDP / Trials / Notes
- **Demo**: Full (default) / Empty player (none of: goals / evals / etc — show empty-state per tab) / Parent view (cap-restricted)
- **Cap profile**: Coach / HoD / Parent / Scout — toggles which buttons appear

## Responsive shape

The mockup is responsive at three breakpoints — resize the browser window to see each.

- **Mobile (≤719px)** — 360px column. Vertical stack: crumbs → back-pill → hero → actions → facts → glance → tabs (sticky, h-scroll) → tab content. Profile tab cards stacked.
- **Tablet (720-1023px)** — same column shape but max-width 720px; the tabs flow to a single visible row instead of horizontal scroll; hero gains breathing room (96px avatar). Profile tab cards become a 2-up grid.
- **Desktop (≥1024px)** — 1024px max-width, **two-column grid layout**:
  - **Left rail (320px)**: Key facts (1-up vertical) + At-a-glance KPIs (1-up vertical) — always visible, scrolls with the page.
  - **Right column**: Tabs (single row, no scroll) + tab content. Profile cards 2-up.
  - Hero, action row, and breadcrumb/back-pill span both columns at the top.

The desktop layout brings the "above-the-fold" data (facts + glance) into a permanent sidebar so an HoD reviewing the player keeps the key metrics visible while scrolling through evaluations or notes.

## Open questions

- The Notes tab — currently a Threads-module surface. Mockup just shows a placeholder block; the real Threads component renders different shapes per use case. Worth a follow-up tighten if pilot finds the notes surface specifically clunky.
- Status pill colours — current production uses a yellow `Trial`. Mockup keeps the same colour vocabulary so the port is mechanical.
- Photo upload UX — out of scope for this mockup. The avatar shape (circle, 72-96px) accepts a future upload affordance without rework.

## What's deliberately NOT in the mockup

- A redesigned Threads / Notes thread component (covered separately if/when pilot asks).
- Bulk actions across players (this is per-player, not list view).
- A "compare with another player" affordance (existing `?tt_view=compare` is its own surface).
- Analytics tab — removed v3.110.187 by pilot ask; don't re-add.
