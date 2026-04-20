# TalentTrack v2.15.0 — Epic 2 Sprint 2B: FIFA-style Player Cards + Team Podium

## What's new

Every player now has a **collectible-card-style visual summary** — tiered gold/silver/bronze by current rolling-average rating, rendered in pure CSS with custom typography, metallic gradient surfaces, animated shine, and subtle crystalline faceting. The card surfaces in four places across the frontend + admin, and drives the new "Mijn team" tab on the player dashboard plus top-3 podiums on coach dashboards.

This is Sprint 2B of Epic 2. Sprint 2A (v2.14.0) gave coaches an analytical rate card with tables, filters, and charts — the diagnostic surface. Sprint 2B adds the **motivational surface**: what a 12-year-old wants to see on their phone and maybe screenshot.

## The card design

A tiered sports trading card. Tier is determined by the player's rolling average over their last 5 evaluations:

- **Gold** (≥ 4.0): warm amber / champagne gradient, deep brown ink
- **Silver** (≥ 3.0): cool platinum / steel gradient, near-black ink
- **Bronze** (< 3.0): copper / rust gradient, warm dark ink
- **Unrated**: muted desaturated gray frame (for players with no rated evaluations yet)

Each card shows:
- Overall rolling-average rating, top-left, in oversized display type
- Primary preferred position (e.g. "LB", "CM", "GK") below the rating
- Player photo, upper-right, soft tulip-petal mask — or initials on a metallic-gradient background if no photo is set
- Player name centered across the middle of the card
- Four main category stats at the bottom (TEC / TAC / FYS / MEN) — all-time average values
- Team name as a subtle footer badge line
- Optional tier ribbon (used on podium cards)

The visual vocabulary is **original** — not a FIFA / EA FC clone. The faceted geometric overlay is built from stacked CSS `clip-path` polygons with overlay blend mode; the metallic feel comes from three layered gradients (diagonal base, conic light-catch, radial upper-left highlight); the shine sweep is a 7-second CSS animation of a semi-transparent band. No bitmap assets, no copyrighted geometry, no EA-licensed visual elements.

Typography: **Oswald** (display, for the rating number and player name) paired with **Manrope** (body, for stats and labels). Google Fonts import, one `@font-face` request, cached after first load.

## Where the card appears

### 1. Player rate card page — Standard / Card toggle
`TalentTrack → Player Rate Cards` and the Players edit "Rate card" tab now have a **Standard / Card** view switch at the top. Standard view is the analytical surface from Sprint 2A (filter bar, three headline numbers, main breakdown table with accordion subs, trend line + radar). Card view shows the large version of the FIFA-style card, centered on a light gray background. The switch is URL-persisted (`?view=card`).

### 2. Player dashboard — Overview tab
Existing player info sits on the left; the card sits on the right in a two-column grid. On narrow screens (< 820px) the card drops below the info. Medium-size card.

### 3. Player dashboard — new "Mijn team" tab
New tab between Overview and Evaluations. Contents:
- The player's own card, centered (medium size)
- Team top-3 podium below — the three highest-rolling-average active players on the player's team, arranged as 2-1-3 with 1st in the middle and elevated
- Teammate roster at the bottom — circular photo or initials avatar + name, but **no ratings** (per the Sprint 2B privacy decision: top 3 get recognition, everyone else is listed as members without numbers)

### 4. Coach dashboard — Roster tab
For each team the coach manages: the top-3 podium renders above the existing roster grid. Same 2-1-3 arrangement. If a team has fewer than 3 rated players, empty slots render as dashed-outline placeholders with a "Not enough ranked players yet" message — the podium doesn't collapse.

### 5. Coach dashboard — Player Detail tab
When a coach clicks into a specific player, the FIFA-style card renders on the left and the classic player info block (team, position, foot, jersey number, custom fields, radar) renders on the right. Side by side, flex-wrap on narrow screens.

## What's stored, what's computed

Nothing stored. The card's tier and values come from the same compute-on-read pipeline as the rate card — `PlayerStatsService::getHeadlineNumbers()` for the rolling average, `::getMainCategoryBreakdown()` for the four stat values. `TeamStatsService::getTopPlayersForTeam()` is new and batches overall computation for all active players on a team in three SQL roundtrips regardless of team size, then sorts by rolling average desc with eval-count and last-name tiebreakers.

The card adds zero DB writes. Tier crosses happen purely through comparison — a player whose rolling average crosses 4.0 on their next evaluation will show up as gold on the next page render.

## Privacy decision captured in code

One of the explicit design decisions this sprint: **full team rankings are NOT shown on the player-facing dashboard**. Only the top 3 are elevated on the podium. Everyone else sees their teammates listed by name + photo, but not ranked, and without their individual ratings exposed.

This is a club-culture choice — we went with the "recognize the leaders, protect the rest" model rather than the "full transparency" model. If a club wants the other behavior later, it's a one-line change in `PlayerDashboardView::renderMyTeamTab()`.

## Schema / migrations

**None.** Pure additive UI on existing data. No activation-time changes.

## Fallback behavior

If the card's CSS fails to load (theme conflict, CDN blocked), the HTML still renders the player's name, rating, and stats in readable order inside the unstyled element tree. Degraded but not broken.

If Google Fonts fails to load, CSS falls back to Arial Narrow / system-ui — the card still works, just loses some character.

If a player has no photo and no name at all (shouldn't happen but defensive), initials render as "?" on the metallic-gradient mask.

## Files in this release

### New
- `assets/css/player-card.css` — ~500 lines of considered CSS (tier colorways, facet overlays, shine animation, size variants, podium, print styles)
- `src/Modules/Stats/Admin/PlayerCardView.php` — reusable card renderer (single + podium)
- `src/Infrastructure/Stats/TeamStatsService.php` — team-level analytics (top N, teammate list)

### Modified
- `talenttrack.php` — version 2.15.0
- `src/Modules/Stats/Admin/PlayerRateCardView.php` — Standard/Card toggle + renderCardView()
- `src/Shared/Frontend/PlayerDashboardView.php` — card on Overview, new Mijn team tab with own card + podium + teammate roster
- `src/Shared/Frontend/CoachDashboardView.php` — podium per team on Roster tab, FIFA card on Player Detail tab
- `languages/talenttrack-nl_NL.po` + `.mo` — 13 new strings

### Deleted
(none)

## Install

Extract the ZIP. The folder inside is named `talenttrack-v2.15.0/` (separate from your live `talenttrack/`), so you can review contents before moving files in. Copy contents into your existing `talenttrack/` plugin directory preserving the tree structure. Deactivate + reactivate the plugin — activation is fast, no migrations to run.

## Verify

1. **Rate card page** — `TalentTrack → Player Rate Cards` now has a Standard / Card toggle at top. Pick a player, click Card view. A large card renders centered, tier matching the player's rolling average.
2. **Players edit → Rate card tab** — same toggle, same card.
3. **Player front-end dashboard → Overview tab** — card appears to the right of existing content. On mobile, below.
4. **Player front-end dashboard → Mijn team tab** — new tab. Own card, team podium, teammate list without ratings.
5. **Coach front-end dashboard → Roster tab** — for each team you coach, a 3-card podium renders above the roster grid.
6. **Coach front-end dashboard → Player Detail tab** — clicking a player shows the FIFA card on the left alongside the classic info block on the right.
7. **Tier transitions** — a player with rolling avg 4.1 is gold. A player with rolling avg 3.2 is silver. A player with rolling avg 2.5 is bronze. A player with zero rated evaluations is "Unrated" (muted gray). Verify by pulling up several players with different histories.

## Out of scope (slated for later)

- Team aggregate rate card (team-level analog of the player rate card): not this sprint
- Comparative views (player A vs player B): not this sprint
- Public-facing / logged-out access to cards: intentionally not supported
- Exportable / printable / PDF cards: later
- Configurable tier thresholds: hardcoded at 4.0 / 3.0 for now
- Stats on the card other than the four mains: subs or other breakdowns aren't shown — keeps the card readable at size `sm`
- Shared card background images: CSS-first approach worked, no raster assets needed. If a specific tier doesn't land visually, swapping `.tt-pc--<tier> .tt-pc__surface` to `background: url(...)` is a one-line escape hatch. The markup is structured to support that cleanly.

## Design notes worth recording

- **Originality matters here legally and aesthetically.** EA's FIFA Ultimate Team visual vocabulary is copyrighted. This card design is inspired by the broader "sports trading card" archetype (rectangular, tier-colored, big-number-top-left, photo-dominant, stat-grid-bottom) which predates FIFA and isn't protectable. Specific elements that would infringe (faceted diamond geometry identical to FIFA's, their exact color ramps, their league-and-nation-badge composition) are avoided. What's distinctly mine: the tulip-petal photo mask, the soft facet polygons with overlay blend mode, the Oswald + Manrope typography pair, the specific colorway stops.
- **Compute-on-read stays consistent.** Every number on the card is freshly computed from evaluations + ratings at render time. The card is a view, not a cached aggregate. Tier crosses happen naturally.
- **Size variants do the responsive heavy lifting.** Podium 2nd/3rd slots use `sm`, podium center uses `md`, rate-card Card view uses `lg`. Same CSS variables, same layout, three scale factors.
- **Prefers-reduced-motion is honored.** Users who set their OS to reduce motion see static cards without entrance animations, shine sweep, or hover transforms. Keeps the card accessible to users with vestibular sensitivities.
- **One entry-point deduplication**: `PlayerCardView::enqueueStyles()` is called from every surface that might render a card (rate card page, rate card tab, both frontend dashboards). WordPress deduplicates by handle, so multiple calls are harmless — each surface independently ensures the stylesheet is loaded regardless of which surface the user hits first.
