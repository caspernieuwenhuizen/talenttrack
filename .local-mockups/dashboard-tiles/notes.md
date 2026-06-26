# Dashboard tile display — 5 directions

The current dashboard (`FrontendTileGrid` / `TileGridStandard`) renders one
uniform grid of icon-chip + label + description tiles, split into a
"Today's work" section and a collapsible "Setup & administration" section,
with a sticky "My work" rail at ≥1024px. The brief: *"I don't like the
dashboard tiles"* — so these five are distinct **display paradigms**, not
inner-tile tweaks (that was the earlier `tile-layout/` mockup).

Open `index.html` and use the state picker. Resize to 360 / 768 / 1024 to
check mobile-first behaviour. Palette + tokens are faithful to
`assets/css/tokens.css` (green/gold 2026). Counts are realistic, player-centric
("what needs me"): 12 evaluations due, 4 in trial, 9 goals open, etc.

## The five

| # | Direction | Strength | Cost / risk |
|---|-----------|----------|-------------|
| 1 | **Bento** — hero tiles + compact nav, varied spans | Editorial, modern; surfaces the count on the entities that matter | Heaviest build (grid spans, hero gradient); awkward if every entity wants to be "primary" |
| 2 | **Action rows** — full-width row list | Densest, most scannable, best on a phone; closest to today's markup → cheapest port | Less visual hierarchy; can feel like a settings menu |
| 3 | **KPI-forward** — big lead number per tile | Reads as a development overview; ideal for head-of-academy | Lead number must be live, or it feels stale; needs real counts wired |
| 4 | **Focus + nav** — "needs you today" hero + My-work rail, then icon nav grid | Clearest act-first / browse-second hierarchy; most player-centric; reuses today's section split | Two zones = more vertical space before the nav grid |
| 5 | **Launcher** — app-home-screen icon grid, count as corner pip | Fastest pure navigation, most tiles above the fold | Weakest at conveying status (pips are tiny); descriptions dropped |

## Recommendation

**Direction 4 (Focus + nav)** is the closest fit to the product principle
(§1 player-centric — answer "what does this player need next?" first) and the
*least* disruptive to ship: it keeps the existing "Today's work / Setup"
split and the My-work rail, but promotes the rail's intent into a hero so the
dashboard opens on action, then demotes the entity tiles to a clean nav grid.

**Direction 2 (Action rows)** is the strongest mobile-only fallback and the
cheapest to build — worth keeping as the ≤480px rendering of whichever
direction wins, since a row list is the natural phone shape.

Directions 1/3/5 are viable but each asks more: bento needs an editorial
spans decision, KPI needs every count wired live, launcher sacrifices the
descriptions that orient new coaches.

## Open questions for the user

- Pick one direction (or a hybrid — e.g. **4 on desktop, 2 on phone**).
- Are the live counts available cheaply per tile, or would KPI/bento lead
  numbers force N extra queries on dashboard load? (Affects 1 and 3.)
- Keep the collapsible "Setup & administration" section, or fold setup into
  an overflow / kebab? All five mockups currently keep it.

## Port path once chosen

The winning direction becomes the spec for an issue against `FrontendTileGrid`
+ `TileGridStandard`. The tile data (label/desc/icon/count) already exists in
the tile registry; the change is the **arrangement + the count surfacing**, not
new data. Inline `<style>` in `FrontendTileGrid` must move to an enqueued
mobile-first sheet (the #1389 inline-style gate now enforces this).
