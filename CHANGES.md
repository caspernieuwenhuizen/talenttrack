# TalentTrack v4.0.9 — Exports page look & feel pass (closes #863)

## Pilot report

> look and feel is terrible

Cards on the central Exports page (#797) were cramped, mis-aligned in the grid, and inconsistently sized; the format pill collided with the title; descriptions wrapped mid-phrase at narrow widths.

## Root cause

The v1 from #797 was inline-styled, single-column on every viewport, with `auto-fill, minmax(320px, 1fr)` that produced too-narrow cards at common viewport widths. The format pill sat inside a flex header competing with the title; cards stretched to whatever their description + field count demanded, so the grid never visually aligned.

## Fix

### New stylesheet — `assets/css/frontend-exports.css`

A dedicated CSS file, BEM-style class names, mobile-first.

- Single column below 768px; `repeat(auto-fill, minmax(360px, 1fr))` above.
- `.tt-export-card` is `position: relative; min-height: 260px; display: flex; flex-direction: column;` — so the grid visually aligns regardless of description length, and the footer can pin to the bottom via `margin-top: auto`.
- Format pill absolutely positioned in the card's top-right (no longer competing with the title in the header flex row).
- All inputs (`select`, `input[type="date"]`, `input[type="number"]`, text) are `width: 100%; min-height: 44px; font-size: 14px;` via the stylesheet — no inline gymnastics. The 44px floor keeps mobile touch targets above the CLAUDE.md §2 threshold.
- Status dropdowns now take the full card width, so "Active only" no longer truncates to "Active o…".
- Date inputs get `min-width: 0` so the native dd-mm-jjjj glyph row fits inside narrow cards without clipping.

### `FrontendExportsView`

- `enqueueAssets()` override enqueues `tt-frontend-exports` with `tt-frontend-mobile` as dependency, versioned with `TT_VERSION`.
- `renderCard()` swaps every inline-styled `<div style=…>` for a `.tt-export-card__*` class.
- `renderField()` swaps the inline-styled `<label>` for `.tt-export-card__field` and removes inline width / min-height on every input.
- The inline JS error path swaps `msg.style.color = '#b32d2e'` for a `tt-export-card__msg--error` class toggle — error styling now lives in CSS, where a future dark-mode override can pick it up centrally.

## Scope

- Pure presentation. No data shape change, no REST contract change, no capability change.
- Inline-styled v1 → class-based v2; future polish (per-exporter format options #864, missing bulk exports #865) builds on the same class set.

## Verification

- Cards in any grid row align (same `min-height`).
- Format pill sits top-right; title row has clean padding-right that clears the pill.
- Dropdowns + date + number inputs all fill the card width with consistent 44px touch height.
- At 360px the page is a single column with no horizontal scroll.
- An error response styles the message red via the class modifier; the timer clears the class on dismissal.

## Closes

- #863 — Exports page look & feel
