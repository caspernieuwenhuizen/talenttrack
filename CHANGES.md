# TalentTrack v2.16.0 — Epic 2 Sprint 2C: Neutral Tier, Printable Report, Mobile Polish

## Three items in this release

This sprint bundles three related improvements under "how the product actually reaches the users' hands":

1. **Tier-by-position** — medals on the podium are now positional, not rating-driven
2. **Printable A4 player report** — a proper paper deliverable that goes home with a player
3. **Mobile readiness** — front-end surfaces work on a phone, which is where players actually use them

## Item 1: Tier-by-position + neutral colorway

### The change in one sentence

Gold/silver/bronze are **podium awards**, not rating tiers. A new neutral colorway covers every card outside a ranking context.

### Why

Previously, a player's card tier was determined by their rolling-average overall (≥4.0 gold, ≥3.0 silver, <3.0 bronze). That conflated two different ideas: "your current level" and "your ranking among peers." On a podium, the #1 player might appear in a silver or bronze card even though they ranked first — confusing and visually deflating. On a player's own dashboard, the tier carried achievement connotations that felt arbitrary ("why am I bronze? what does that mean?").

We now separate the two:

- **Podium rank → medal tier.** 1st place always gets a gold card, 2nd silver, 3rd bronze, regardless of any player's actual rolling average. Matches how real podiums work: you don't repaint the athlete gold because they won, they stand on the gold platform.
- **Non-podium context → neutral tier.** The default card colorway is now a premium dark-navy design (platinum-on-navy). Feels like a product card without implying an achievement that wasn't earned.
- **Zero rated evaluations → unrated (muted gray).** Unchanged; distinct visual signal for players with no data.

### Visual neutral design

The default neutral colorway is **dark navy** (`#243858` → `#4a6690` → `#152238`) with soft platinum highlights. Still uses the same faceted geometry, metallic shine, Oswald + Manrope typography as the tier variants — just in a color space that doesn't claim a medal. Feels appropriate for every player's "here is my card" moment regardless of performance.

An alternative **chrome** palette (off-white / light platinum) is included in the CSS as a commented-out block. To swap, edit `assets/css/player-card.css` — remove the navy `.tt-pc--neutral` block and uncomment the chrome one. One file, one block swap. Takes 30 seconds.

### Where this appears

Neutral now renders in:
- Player dashboard — Overview tab, the own-card on the right
- Player dashboard — Mijn team tab, the own-card above the podium
- Coach dashboard — Player Detail tab, the FIFA-style card
- Admin rate card page — Card view toggle
- Printable report — the embedded card at the top

Gold/silver/bronze only render inside `renderPodium()`, where the position-to-tier mapping is hardcoded and explicit.

### API change

`PlayerCardView::renderCard()` now accepts an optional 4th parameter `$tier_override`. When set to `'gold'`, `'silver'`, or `'bronze'`, forces that tier regardless of the player's rating. When null (the default), renders neutral (or `'none'` if no rated evaluations). `renderPodium()` passes explicit tier per slot — callers outside the podium don't need to think about tier at all.

## Item 2: Printable A4 player report

### What it is

A one-sheet A4 portrait report suitable for printing or saving as PDF, containing:

- **Header**: club logo + academy name (both from `TalentTrack → Configuration`), report title with player name, generation date, period covered by the filters
- **FIFA-style player card** (medium size, neutral colorway) — anchors the top of the page as the visual summary
- **Three headline tiles**: Most recent, Rolling average, All-time average
- **Main category breakdown table** with all subcategories expanded (no accordion behavior on paper)
- **Trend line chart** + **radar chart** side by side, rendered by Chart.js, captured as raster in the printout
- **Signature footer**: coach signature line + date line

### How to trigger

A **"🖨 Print report"** button appears in three places:

- Admin rate card page (`TalentTrack → Player Rate Cards` and the Players edit "Rate card" tab) — button sits in the view-mode toggle bar, top-right
- Player front-end dashboard Overview tab — button above the card on the right
- Coach front-end dashboard Player Detail tab — button above the FIFA card

All three open a new tab, render the report, and **auto-invoke `window.print()`** once Chart.js has finished rendering (~400ms delay to let fonts and animations settle). The browser's native print dialog handles PDF export.

### Branding configuration

Uses existing `TalentTrack → Configuration → Academy` settings:
- **Academy Name** → shown as the header club line
- **Logo URL** → shown as the header logo (max 90×90)

Both are optional — the report omits the corresponding element gracefully if unset. No new configuration fields introduced.

### Permissions

- **WP admin** (`tt_manage_settings`): any player's report
- **Coach** (`tt_evaluate_players`): reports for players on their coached teams only
- **Player** (no coach/admin caps): their own report only
- Checked on the front-end print route (`?tt_print=<player_id>` on the dashboard shortcode); admin route inherits the surrounding `tt_view_reports` cap of `PlayerRateCardsPage`

### Filter handling

The print button on any page preserves the filters active at button-click time. If you had "Date from 2025-01-01, Type = Match" set on the rate card page, the printed report covers exactly that period and evaluation type, with the period shown in the header. If no filters were active, the header reads "All evaluations."

### Chart rendering on paper

Chart.js renders to canvas, which prints as raster graphics. Expect slightly pixelated charts on print — acceptable for an A4 report, the data is still legible. High-DPI printers produce very clean results; low-DPI output is noticeably rasterized.

If Chart.js fails to load (CDN blocked), the report still prints — just without the charts. Text tables still carry all the information.

### Why browser print, not a PDF library

A PDF library (dompdf, mPDF) adds ~1MB of dependencies, complicates WordPress deployments, struggles with Chart.js output, and produces a result that's only marginally better than browser print-to-PDF. Browser-native print was the right level of complexity for this release. If customers report needing proper PDF generation later, it's a self-contained follow-up.

## Item 3: Mobile readiness

### Scope

Front-end surfaces only. Admin pages got a light survival check (no horizontal-scroll overflow on tablet) but remain optimized for desktop — club staff use admin from laptops.

### Player card mobile behavior

- `< 820px`: `lg` cards shrink to `md`-size metrics. Podium still horizontal.
- `< 640px`: all cards (regardless of variant) render at `sm`-size (200×286). Podium still horizontal with reduced stagger.
- `< 480px`: podium **stacks vertically**, 1st on top, 2nd middle, 3rd bottom. DOM order in the 2|1|3 arrangement is overridden by CSS `order` so visual order matches semantic order.

### Rate card page mobile

Inline responsive `<style>` block scoped to the rate card surface:
- **Filter bar**: stacks vertically on tablet, inputs become full-width with touch-friendly heights
- **Headline 3-card row**: stacks vertically on tablet
- **Charts row**: stacks vertically on tablet (was already `flex-wrap`, now forces single column)
- **Main breakdown table**: on phone (< 640px), collapses to stacked mini-cards. Each row becomes a card with the category name on top and "All-time: X" / "Recent: Y" labels in the cells. Headers hidden since the inline labels make them redundant.

### Frontend dashboards mobile

New stylesheet `assets/css/frontend-mobile.css` enqueued by both `PlayerDashboardView::render()` and `CoachDashboardView::render()`. Covers:
- **Tabs**: horizontal scroll with a custom slim scrollbar on narrow viewports so 7 tabs still fit on a phone
- **Roster grid** (`.tt-grid`): 2-column on phone, 1-column on very small phones
- **Tables** (`.tt-table`): collapse to stacked card layout on phone — rows become bordered mini-cards, headers hidden, cells wrap naturally
- **Forms** (`.tt-form-row`): labels stack above inputs, inputs full-width, 38-44px touch-friendly heights, buttons full-width at 44px
- **Radar chart wrapper**: horizontal scroll guard + `max-width:100%` SVG
- **Teammate avatars**: shrink from 72px to 60px on very narrow screens

### What's unchanged

- Admin tables (evaluations list, players list, custom fields table): still desktop-optimized. They're heavy data tables that don't collapse well; admins work on laptops.
- Other admin pages (Configuration, Custom Fields, Category Weights, Evaluation Categories): tablet-survivable but not phone-optimized. Same reasoning.

## Schema / migrations

**None.** Entirely additive UI + CSS + new view class. No activation changes.

## Files in this release

### New
- `assets/css/frontend-mobile.css` — frontend mobile responsive layer (~200 lines)
- `src/Modules/Stats/Admin/PlayerReportView.php` — printable A4 report view (~500 lines)

### Modified
- `talenttrack.php` — version 2.16.0
- `assets/css/player-card.css` — neutral colorway (navy default, chrome alternative), mobile breakpoints at 820/640/480px, podium stacking behavior
- `src/Modules/Stats/Admin/PlayerCardView.php` — `$tier_override` parameter on `renderCard()`, neutral default, podium passes explicit positional tiers
- `src/Modules/Stats/Admin/PlayerRateCardView.php` — `?print=1` short-circuit, print button in toggle bar, inline responsive styles, filter-bar/headline/charts mobile markup classes
- `src/Shared/Frontend/DashboardShortcode.php` — `?tt_print=<player_id>` route with permission check (admin/coach/player) and filter passthrough
- `src/Shared/Frontend/PlayerDashboardView.php` — enqueue frontend-mobile.css, print button above own card on Overview
- `src/Shared/Frontend/CoachDashboardView.php` — enqueue frontend-mobile.css, print button on Player Detail tab above FIFA card
- `languages/talenttrack-nl_NL.po` + `.mo` — 14 new strings

### Deleted
(none)

## Install

Extract `talenttrack-v2_16_0.zip`. The folder inside is `talenttrack-v2.16.0/` — separate from your live `talenttrack/` for easy review. Move contents into your `talenttrack/` plugin directory preserving the tree. Deactivate + reactivate. No migrations.

## Verify

### Tier-by-position
1. Open any team's podium (coach dashboard Roster tab, or player dashboard Mijn team tab). Top-left card (2nd slot) is silver, center-elevated (1st) is gold, right (3rd) is bronze — **regardless** of each player's actual rolling average.
2. Open your own Overview card. Card is **neutral navy** — no medal implied.
3. Open a player with zero rated evaluations. Card is **muted gray** ("unrated").
4. Admin rate card Card view: card renders neutral.

### Printable report
1. Go to Admin rate card page. Click 🖨 Print report button. New tab opens, report renders, print dialog fires automatically within ~1 second. Report shows header with club name/logo (if configured), FIFA card, three headline tiles, breakdown table, two charts, signature footer.
2. Apply filters first (date range + type), then print. Report header shows "Period: 2025-01-01 — 2025-06-30" reflecting your filter.
3. From player front-end Overview, click 🖨 Print report. Same result, restricted to own player.
4. From coach front-end Player Detail, click 🖨 Print report. Same result for the selected player. Coach cannot print reports for players outside their coached teams (enforced by `DashboardShortcode` permission check — test by changing the URL `?tt_print=<id>` to a player not in coached teams; get "You do not have access" message).

### Mobile
1. Open the player dashboard on a 375px-wide phone (or DevTools responsive mode). Tabs scroll horizontally. Overview card drops below player info. Mijn team podium stacks vertically.
2. Open the rate card page on same narrow viewport. Filter bar stacks. Headline tiles stack. Breakdown table collapses to stacked mini-cards.
3. Tables on Evaluations / Goals / Attendance tabs collapse to stacked mini-cards on phone. Forms are touch-friendly (big inputs, full-width buttons).

## Out of scope (slated for later)

- Admin tables responsive — laptop-only remains acceptable for this release
- Proper PDF generation library (browser print-to-PDF is sufficient for now)
- Custom report templates / multi-page reports
- Team-level report (this release is player-only)
- Configurable podium tier thresholds (permanently positional by design — intentional)
- Admin-configurable neutral palette switcher (CSS-edit swap is good enough for now; revisit if multiple clubs want different looks)
- Historical print archive — reports always re-render from current data; no saved-PDF audit trail
- Comparative reports (player A vs B) — next sprint candidate

## Design notes

- **Positional tiers are a hard design rule.** Tier mapping (1→gold, 2→silver, 3→bronze) is hardcoded in `renderPodium()` and not configurable. This is intentional — any other mapping would reintroduce the "what does my tier mean?" ambiguity the neutral default was introduced to solve.
- **Print uses native browser tooling deliberately.** No dependencies, no additional payload, works everywhere modern browsers run, produces PDF output via the OS print dialog's "Save as PDF" option. Result quality is "good enough for A4" — which is the bar for this release.
- **Mobile table collapse uses generic CSS selectors**, not per-table `data-label` attributes. Means every `.tt-table` on the front-end collapses the same way — pro: zero markup changes, con: less ideal per-column labels. Can be upgraded per-table later if specific tables need richer mobile UX.
- **Frontend mobile CSS is a separate stylesheet** (not inlined) because the front-end dashboard already loads external CSS, one more request is fine, and separation means the admin side doesn't pay for it.
