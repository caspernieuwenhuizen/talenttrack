# layout-patterns — notes

Wireframes for five canonical SaaS screen layouts, drawn to answer one question:
**how much effort is it to restructure TalentTrack's visual organisation?**

Deliberately monochrome. This is about where the regions sit, not what colour
they are. Colour, typography and component styling are a separate pass — the
2026 visual language already exists in `docs/frontend-2026-patterns.md` and
`assets/css/tokens.css` and is not in question here.

## The seven zones

Every pattern in `index.html` is drawn with the same labelled regions so they
can be compared region by region:

| Zone | Region |
| --- | --- |
| A | Brand / tenant identity |
| B | Primary navigation — every module the user can reach |
| C | Secondary navigation — section or record tabs |
| D | Context header — breadcrumb, record identity, page actions |
| E | Content — the work surface |
| F | Utility — search, alerts, help, persona / user menu |
| G | Detail / inspector — the selected record, in place |

**TalentTrack today has A, D, E, F. It has no B and no C.** Navigation happens
by returning to the persona tile hub or by walking the breadcrumb chain. That
absence — not the styling — is what the recurring "I can't find X" pilot
feedback is about.

## What the code says about cost

Three findings from the current frontend that set the price for every pattern:

1. **The nav data already exists.** `src/Shared/Tiles/TileRegistry.php` carries
   `slug`, `group`, `order`, `icon`, a per-persona `labels` map (with a
   `__hidden__` marker) and the matrix `entity` / `cap` for every destination.
   `TileRegistry::groupOrder()` already fixes the group sequence (Performance,
   People, Planning & tactics, Development, Reference). A sidebar or a top-nav
   band is a **second renderer over existing data**. The information
   architecture is not the work.
2. **The chrome has one chokepoint.** `DashboardShortcode` emits the header in
   one place (~line 1510), and `CanvasShell` + `templates/canvas.php` already
   take over the whole document and dequeue non-TalentTrack CSS. A shell
   wrapper lands at one seam — not across 67 shared views + 92 module views.
3. **Content width is the only real risk.** Patterns that take horizontal room
   away from views (1, 3, 4) need a width audit. Of 132 stylesheets, ~20 use
   `position: fixed`, `position: sticky` or `100vw` — the wide data grids
   (`frontend-ratings-grid`, `frontend-minutes-audit`, `frontend-attendance-grid`),
   `frontend-match-prep`, `frontend-match-execution`, `frontend-team-chemistry`
   and `frontend-filter-bar` are the ones to check first. Patterns 2 and 5 avoid
   this class of risk entirely.

## Effort summary

| Pattern | Effort | Dominant cost |
| --- | --- | --- |
| 2 · Top nav | **S** | Nav band + overflow rule. Content width unchanged → no stylesheet audit. |
| 5 · App shell + hub | **S** (+M with a command palette) | Bottom bar + safe-area padding + per-persona slot config. |
| 1 · Sidebar shell | **M** | Shell wrapper + off-canvas drawer + width audit of ~20 sheets. |
| 4 · Console, per surface | **M** | Only the 3–4 genuinely queue-shaped views. |
| 3 · Rail + panel | **L** | List/detail region split → routing model + REST panel loading + pane-stack mobile. |
| 4 · Console, app-wide | **XL** | All of the above plus a fourth region and a second mobile design. |

## Recommendation — sidebar + player spine + peek

See the **★ Recommended** tab in `index.html`. Desktop takes pattern 1's grouped
sidebar, pattern 2's record-level tab band and pattern 4's inspector-as-peek;
mobile takes pattern 5 outright. Both viewports read the same `TileRegistry`.

**Richest and best-suited are not the same answer, and this is why they
converge here.** The three- and four-pane workspaces *feel* the richest, but the
property doing the work is not pane count — it is that context persists and you
never lose your place. That property is separable from the layout that usually
carries it:

- **The player context spine** pins the player's identity (photo, name, team,
  position, age, status) and their tabs across every surface inside that player.
  This is the part specific to a talent system rather than borrowed from a
  generic SaaS shell — CLAUDE.md §1 expressed as layout instead of as a rule
  people have to remember.
- **The peek panel** opens a related record beside the current one instead of
  navigating away, so cross-entity moves stay reversible. It composes with the
  existing `RecordLink::detailUrlForWithBack()` vocabulary rather than replacing
  it.
- **Full-width canvas** is kept, because the primary surface is one player's
  longitudinal story — timelines, rating trends, minutes across a season — which
  wants width and vertical room. A 40% console pane fights that.
- **The console treatment from pattern 4 still gets adopted**, but per-surface,
  on the 3–4 genuinely queue-shaped views (matches needing review, trial funnel,
  evaluation coverage) — not globally.

### Phasing — the richness is the last phase, not a prerequisite

| Phase | Effort | Scope |
| --- | --- | --- |
| 1 · Persistent nav | **S–M** | Sidebar ≥1024px, off-canvas drawer below, bottom bar on mobile — all from `TileRegistry`. Plus the ~20-stylesheet width audit. Most of the perceived change lands here. |
| 2 · Player spine | **S per surface** | One component, adopted by player detail first, then team / activity / staff. Replaces hand-rolled per-view headers, so it removes code as it lands. |
| 3 · Peek + ⌘K | **M** | The only genuinely new plumbing: REST reads for peeked records, and a searchable index across players, teams, activities and views. Both are things §4 says should exist anyway. |

Each phase is shippable and useful alone. Nothing in phases 1–2 is wasted if 3
is deferred.

## The §5 amendment

§5 currently counts *all* nav affordances, which makes any persistent global nav
a forbidden third. The amendment separates global chrome from per-view
affordances and keeps the ban where it was actually aimed — at hand-rolled
per-view back links:

- **Global chrome — exactly one primary nav**: sidebar ≥1024px / drawer + bottom
  bar below, rendered once by the shell from `TileRegistry`. Never emitted by a
  view.
- **Per view — still exactly two**: the breadcrumb chain ending at Dashboard,
  and the contextual `tt_back` pill. Unchanged.
- **Record-scoped tabs are zone C, not a third affordance** — they move *within*
  one record rather than leaving it. Rendered only by the spine component, never
  hand-rolled.
- **The original ban stands verbatim**: no `FrontendBackButton`, no hardcoded
  "Back to dashboard" / "Back to \<list\>", no custom back affordance that
  sidesteps the chain + pill.

## Mobile bottom-bar slots — deliberately deferred

Which five destinations fill the bar, per persona, is a product decision, and it
is structured so it never gates the shell:

- Own issue (#2459), parallel to phase 2, depending only on phase 1.
- The drawer in phase 1 carries the **full** grouped set on mobile, so nothing is
  unreachable without the bar — a suboptimal slot choice costs a tap, not access.
- Ships with a **derived default**: first four visible `kind: 'work'` tiles in
  `groupOrder()` sequence for that persona, plus "More" → the tile hub. Pure
  function of existing registry data, so it lands with no decision made.
- Slots are club-scoped config (`tt_shell_mobile_slots`), so changing them later
  is a config edit, not a release. Invalid or stale slugs fall back to the
  derived default.
- Then decide **from evidence**: `tt_usage_events` (migration 0011) already
  records `event_type = 'frontend_view'` with the view slug in `event_target`,
  per `user_id`, `club_id`-scoped, 90-day retention. Nothing new to instrument —
  read top slugs per persona after ~4 weeks of the shell being live. Viewport is
  not recorded; if the persona split proves insufficient, adding a viewport
  bucket is a separate small change.

Settled: desktop and mobile are deliberately different patterns, sharing one
registry feed and one set of destinations.

## Issue trail

| Issue | Phase |
| --- | --- |
| #2453 | Epic — selectable app shell |
| #2455 | 0 · Amend CLAUDE.md §5 (ready-for-dev) |
| #2456 | 1 · Shell abstraction + layout setting + sidebar / rail / drawer |
| #2459 | 1b · Mobile bottom bar (derived default + config override) |
| #2457 | 2 · Player context spine |
| #2458 | 3 · Peek panel + ⌘K palette |

## Still to test

- Pattern 1 at 1280px against the widest real grid (ratings grid, ~14 columns)
  — does 210px of sidebar force a horizontal scroll that is not there today?
- Pattern 5's bottom bar against the live match-execution view, which already
  owns the bottom of the screen for its half/substitution controls.
- Whether the docs drawer (`.tt-docs-drawer`, right-side) and a left sidebar
  read as balanced or as two competing rails.
