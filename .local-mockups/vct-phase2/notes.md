# VCT Phase 2 — shared design notes

Cross-surface tokens, conventions, and decisions shared across the
six VCT Phase 2 mockup directories:

- `.local-mockups/vct-session-wizard/` (maps to VCT-9)
- `.local-mockups/vct-session-coach-view/` (VCT-10)
- `.local-mockups/vct-library/` (VCT-11)
- `.local-mockups/vct-config-tiles/` (VCT-12)
- `.local-mockups/vct-team-panel/` (VCT-13)
- `.local-mockups/vct-phv-flag/` (VCT-14)

Read this before iterating on any single mockup so the family stays
visually coherent — the executor's eventual port becomes mechanical.

## Tokens

Inline `:root` CSS variables on each mockup mirror the production
palette:

```
--tt-ink:        #1a1d21    body copy
--tt-ink-soft:   #5b6e75    secondary
--tt-paper:      #ffffff    surfaces
--tt-bg:         #f5f7f6    page
--tt-line:       #d6dadd    rules
--tt-mute:       #f0f3f2    inert chips
--tt-accent:     #1d7874    primary teal
--tt-warn:       #c75c1f    intensity / attention
--tt-danger:     #b32d2e    destructive
--tt-success:    #2e7d4f    confirm
```

Touch target floor `--tap-min: 48px`. Inputs ≥ 16px font to prevent
iOS auto-zoom. Gap unit `--gap: 12px`, `--gap-lg: 16px`.

## Intensity bands

VCT exercises carry an intensity band 1–5 (recovery → max). Across
every surface the bands render with the same dot palette so the coach
recognises them without a legend:

| Band | Label                 | Colour       | Use                              |
|------|-----------------------|--------------|----------------------------------|
| 1    | Recovery              | `#c8dcdb`    | Cooldown, mobility                |
| 2    | Low                   | `#9bc2bd`    | Possession, control               |
| 3    | Moderate              | `#6ba39c`    | Pattern play, repetition          |
| 4    | High                  | `#3b8580`    | Match-tempo small-sided           |
| 5    | Maximum               | `#c75c1f`    | Sprints, max-effort blocks        |

`.tt-vct-intensity[data-band="N"]` is the canonical class — used as a
solid-fill chip in lists, a thin coloured rule on session-coach-view
block dividers, and a colour-coded edge on library exercise cards.

## Exercise categories

Five categories from the seeded VCT lookups. Same icon set across
every surface (mockups render the emoji as a stand-in; production
swaps in SVG icons from `assets/icons/vct/`):

| Key                 | Label                | Icon |
|---------------------|----------------------|------|
| `technique`         | Techniek             | ◇    |
| `possession`        | Positiespel          | ◯    |
| `transition`        | Omschakeling         | ⇄    |
| `pressing`          | Druk geven           | ⇈    |
| `set_piece`         | Standaardsituatie    | △    |
| `phv`               | PHV / fysiek         | ◈    |

PHV (physical / health / vitality) is the new category #1062 surfaces
on the player profile (VCT-14). Coaches tag exercises that need a
PHV-flagged player to participate or sit out per the age-profile
guidance.

## MD context (Match-day window)

VCT sessions sit on a weekday line relative to the match (MD-1, MD-2,
…). The coach-view + wizard render this as a horizontal MD bar at the
top of the surface. Pre-match days (MD-5 … MD-1) get a cool blue, MD
itself is teal, recovery days (MD+1, MD+2) get warm orange. Same
palette across the wizard and the coach-on-pitch view.

## Print sub-renders

VCT-10's coach-view ships a printable A4 portrait sub-render (a
laminated card a coach takes to the pitch). Pattern matches the
existing `MatchPrepPrintRouter` / `PlayerGoalIntakePrintRouter` —
standalone HTML, `@page { size: A4 portrait; margin: 0 }`, no theme
chrome.

An A6 variant (1/4 of A4, 4-up on a single sheet) is documented as
a future option — coaches sometimes print a stack of A6 cards to
hand to assistants.

## Wizard chrome

The session wizard (VCT-9) uses the V3 sidebar-timeline chrome
shipped via #1036. Same step-indicator + sticky save-state pattern
as every other wizard. The mockup at `.local-mockups/vct-session-wizard/`
demonstrates the 5 steps but doesn't re-mock the chrome itself.

## Mobile-first contract

Every VCT surface targets 360px first per CLAUDE.md §2. Min-width
breakpoints upgrade to 768px tablet and 1024px desktop. No max-width
patches.

The exception is the printable A4 sub-render of the coach-view, which
is portrait-A4 by definition. The on-screen variant of the same view
is mobile-first.

## What the implementation children inherit

Each of VCT-9 through VCT-14 files as a focused implementation child
that references its mockup directory as the design-of-record. The
mockup HTML is meant to be ported mechanically — the executor copies
the CSS verbatim, swaps the placeholder data for live `WP_REST_Request`
+ `…Repository::…` calls, and wires the wizard / view registration.

If a mockup feels under-specified during implementation, raise the
gap on this shared `notes.md` first (so the other children stay in
sync) before editing the per-surface notes.
