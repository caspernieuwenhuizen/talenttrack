<!-- type: feat -->

# My card tile — visual polish and consistency

## Problem

The "My card" tile on `FrontendOverviewView` — the player's quick at-a-glance card on their own dashboard — was flagged as having "technical errors and does not look at all appealing." The specific errors were never documented (the idea sat in `needs-triage` without ever being reproduced in detail).

Rather than wait for those specific bugs to be documented, we're treating this as a visual/consistency concern adjacent to #0003 (player evaluations view polish). The My card tile and the My evaluations view are the two player-facing surfaces that need coherent visual treatment. #0003 handles the evaluations view; this spec handles the card tile.

Any genuine technical errors (PHP warnings, JS console errors) are caught during implementation when we re-implement the rendering path — rather than needing them documented upfront.

Who feels it: every player who lands on their dashboard. It's the first thing they see. Poor first impression hurts retention.

## Proposal

A visual polish pass on the My card tile that:

1. Reuses the `RatingPillComponent` introduced by #0003 (or this spec if it ships first).
2. Embeds the FIFA-style player card (same `PlayerCardView` used in #0014 Part A) for engagement and consistency.
3. Cleans up any PHP warnings, JS errors, or layout issues encountered during rebuild.
4. Follows the same visual language as the rest of the #0019 frontend CSS scaffold.

The My card is a *tile* on the dashboard — it's a condensed summary, not a full page. The polish keeps it condensed but makes the content actually scannable and engaging.

## Scope

### My card tile rebuild

Surface: `src/Shared/Frontend/FrontendOverviewView.php` — specifically the "My card" section. The FIFA card rendering itself lives in `src/Modules/Stats/Admin/PlayerCardView.php` and is already shared-capable.

Tile contents (in visual order):

- **Player photo + name + team + tier** — compact header, same data as the Profile hero strip from #0014 Part A (reduced to fit a tile).
- **FIFA card embed** — the existing `PlayerCardView` rendered at tile-appropriate size. The card is the engagement hook.
- **Rolling rating with tier pill** — uses `RatingPillComponent` from #0003.
- **"View full profile" link** — deep-links to `FrontendMyProfileView` (the full view rebuilt in #0014 Part A).

The tile is deliberately terse — it's a summary. The full profile is one click away.

### Visual consistency

- Uses CSS variables and tokens from #0019 Sprint 1's `frontend-admin.css` scaffold.
- Rating pill treatment matches #0003's (shared component).
- Tier badge styling matches #0014 Part A's hero strip.
- No inline styles — all styles in `frontend-admin.css` or component CSS partials.

### Bug cleanup (catch-as-you-go)

During rebuild, any PHP warnings, JS console errors, or visible layout issues get fixed. Rather than waiting for a separate bug list, the rebuild itself cleans the surface.

Explicit checks during implementation:
- Load the page with `WP_DEBUG_DISPLAY = true` — no warnings or notices render into the page.
- Browser console on page load — no JS errors.
- Mobile viewport (375px, 414px) — no layout breakage.
- Player with missing photo — graceful placeholder.
- Player not yet on a team — tile renders with appropriate empty-state content.
- Player with no evaluations yet — tier/rating section shows "First evaluation coming soon" or similar.

### Responsive behavior

The My card tile is part of the overview tile grid, which already handles responsive layout. This spec ensures the tile's *internal* content stacks cleanly when the tile is narrow (phone portrait, single-column tile grid).

## Out of scope

- **The full profile view** — that's #0014 Part A (which this tile links to).
- **New rating sparklines or charts** within the tile — too much for a tile; the full profile is where those live.
- **Tile layout, positioning, or drag-rearrange** — the tile framework is separate; this spec only concerns the My card tile's contents.
- **Coach-side or admin-side variants of this tile** — those are different templates.
- **Player photo upload** — stays a coach-side action.

## Acceptance criteria

### Visual

- [ ] My card tile renders with compact header (photo, name, team, tier).
- [ ] FIFA card is embedded and scales appropriately to tile size.
- [ ] Rolling rating with tier pill is present.
- [ ] "View full profile" link is visible and works.

### Consistency

- [ ] Rating pills use the shared `RatingPillComponent` (same visual as #0003 My evaluations).
- [ ] Tier badge styling matches #0014 Part A's hero strip.
- [ ] All styles in `frontend-admin.css` / component partials — no inline styles.

### Cleanup

- [ ] With `WP_DEBUG_DISPLAY = true`, no PHP warnings or notices render on the overview page.
- [ ] No JavaScript errors in browser console on overview page load.
- [ ] Mobile viewport (375px, 414px) — tile content is readable, no horizontal scroll, no overlap.
- [ ] Empty states render cleanly: no photo, no team, no evaluations.

### No regression

- [ ] The rest of the overview dashboard is unchanged.
- [ ] Other tiles still work.
- [ ] Coach and HoD views of the overview (if they use the same component) are unaffected.

## Notes

### Sizing

~3–5 hours. Breakdown:

- My card tile rebuild: ~2 hours
- Visual consistency work: ~1 hour
- Bug discovery/cleanup during rebuild: ~1 hour (variable)
- Mobile testing + empty states: ~1 hour

This is one of the smallest specs in the backlog. It's tightly scoped to a single tile on a single view.

### Depends on

- **#0019 Sprint 1** — CSS scaffold and shared component conventions. Do not start before Sprint 1.
- **#0003** (player evaluations view polish) — introduces `RatingPillComponent`. If #0003 ships before this, reuse directly. If this ships first, introduce the component here.
- **#0014 Part A** (profile rebuild) — introduces the hero strip pattern. If Part A ships before this, align with its pattern. If this ships first, define the tier-badge pattern here for reuse.

In SEQUENCE.md, #0003, #0004, and #0014 Part A form a cluster of player-facing polish work that should ideally ship in quick succession. Order within the cluster: #0003 first (introduces the shared pill), then #0004 (small, reuses pill), then #0014 Part A (bigger, ties them together).

### Blocks

Nothing.

### Touches

- `src/Shared/Frontend/FrontendOverviewView.php` — rebuild the My card tile section only
- `src/Shared/Frontend/Components/RatingPillComponent.php` — consume (or introduce if this ships before #0003)
- `assets/css/frontend-admin.css` — add any missing tile-scoped styles

### Why this spec replaces the needs-triage version

The original #0004 was too vague to spec. Rather than continue waiting for specific bug reports, shaping redirected this to a polish spec that catches any real technical issues during rebuild. This is a pragmatic trade: we lose nothing by doing the rebuild anyway (the card needed visual consistency regardless), and we gain a concrete deliverable instead of a perennially-open `needs-triage` item.

If a specific technical error shows up later that the rebuild didn't catch, it becomes a new bug report — not a revival of this idea.
