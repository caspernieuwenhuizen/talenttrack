<!-- type: epic -->

# #0056 — Mobile-first cleanup pass

## Problem

`CLAUDE.md` § 2 has been the law of the land for "mobile-first, touch-optimized" since v3.x. In practice the codebase is **the opposite**: every legacy stylesheet is desktop-first, the most-tapped buttons are below the 48 px floor, the entire codebase has zero `inputmode` attributes, and several UX surfaces (Configuration, Authorization Matrix, Workflow templates) are genuinely better on a tablet/laptop with no signal to the user that this is the case.

The standing rules in `CLAUDE.md` say "fix opportunistically when touching" — six months on, the same three pieces of debt are still there. Opportunistic doesn't get done. This epic tracks the cleanup explicitly.

A recent design review surfaced five concrete categories of debt:

1. **Desktop-first CSS architecture.** `assets/css/public.css`, `frontend-admin.css`, and the explicitly-named `frontend-mobile.css` are all built desktop-first (every breakpoint is `@media (max-width: …)`). The mobile sheet is a **patch layer** loaded last, overriding desktop rules with `!important` in places (e.g. `grid-template-columns: 1fr 1fr !important;` in `frontend-mobile.css:35`). This works, but mobile rendering depends on a successful cascade override — any CSS load failure or specificity collision leaves mobile broken. New components added in `frontend-admin.css` get desktop styling for free and then need a separate mobile patch — easy to forget. ~3,007 lines of CSS across 6 files have **no shared mobile-first base**.

2. **Two parallel form systems with different mobile behaviour.** New `.tt-field` / `.tt-input` (in `frontend-admin.css`) correctly sets `font-size: 16px` to prevent iOS zoom-on-focus. Legacy `.tt-form-row input` (in `public.css` ~line 150) sets `font-size: 0.9rem` (~14.4 px), which **triggers iOS zoom-on-focus on every form using the older class**. This is a genuine UX bug today, not theoretical.

3. **Tap targets below the 48 px floor** the standards specify:
   - `.tt-btn` desktop: 10px padding + ~16px line-height ≈ **42 px tall**.
   - `.tt-btn` mobile (`frontend-mobile.css:173`): `min-height: 44px` — Apple's 44 floor, not the 48 the standards specify.
   - `.tt-btn-sm` mobile: `min-height: 32px` — well below either floor. Used in pagers, list-table actions, archive/unarchive toggles — the **most-tapped** buttons.
   - `.tt-tab` mobile: `min-height: 40px`.
   - Pager buttons: `min-width: 36px`.
   - The pager and `.tt-btn-sm` are the worst offenders because they're on every list view a coach uses on a phone.

4. **No safe-area insets, no `touch-action`, no `:focus-visible`.** Three zero-results greps:
   - `env(safe-area-inset-*)` — anywhere we have a fixed bar (back button, sticky header, modal footers), iOS notches and home indicators **overlap content**.
   - `touch-action` — without `touch-action: manipulation` on tappable elements, we get the legacy 300 ms tap delay on some Android browsers + accidental double-tap zoom.
   - `:focus-visible` — focus rings rely on `:focus`, so desktop mouse users see them too. Modern accessibility pattern is `:focus-visible` for keyboard-only.
   - These are small additions, but they're the difference between "responsive" and "actually mobile-native".

5. **Zero `inputmode` attributes across the entire codebase.** `grep -rn 'inputmode='` returns 0 results. That means:
   - Phone fields show alphabetic keyboard by default on Android (unless `type="tel"` is also set; `type` alone isn't enough on every Android keyboard).
   - Numeric / decimal inputs (`height_cm`, `weight_kg`, `jersey_number`, ratings) show the full keyboard with the number row, not the numeric pad.
   - Email inputs are correctly using `type="email"` (good), but `inputmode="email"` would belt-and-braces.
   - One-pass fix in `CustomFieldRenderer.php` and the few hand-rolled number inputs. Real mobile UX win for ~30 minutes of work.

Plus one new ask surfaced during this discussion:

6. **"Best on desktop" surface marker.** Some screens are genuinely awkward on a phone — Configuration tile-grid, Authorization Matrix, Lookups admin, Workflow templates editor, Letter templates editor, Migrations diff, Audit log search. A coach who opens one of these on a phone gets a usable-but-cramped experience. We should let those screens **declare** they prefer a bigger viewport and show a friendly notice when opened on a phone, without blocking access.

## Why this is interesting

- **Closes a credibility gap.** Every PR review writes "is this mobile-first?" as a checklist item, and every PR review honestly should answer "no, the surrounding code isn't" because the legacy CSS pulls everything desktop-first. Closing this debt makes the standing checklist truthful.
- **Real UX wins, not theoretical.** Item 2 (the 14.4 px form bug) is **causing iOS auto-zoom** on every form using the legacy class today. Item 5 (no `inputmode`) means coaches typing a jersey number get the wrong keyboard. These aren't future-proofing — they're current bugs.
- **Cheap wins compounding.** Items 4 + 5 + the easy half of item 1-2-3 are 4-6 hours of mechanical edits with high-leverage UX returns. The harder half of item 1 is the only meaningful effort.
- **Unblocks #0042.** Youth-aware contact strategy lands PWA push + onboarding banner in mobile; the surrounding chrome should be properly mobile-native by then.

## Working scope (4 sprints + 1 deferred)

### Sprint 1 — Quick wins (~3-4h)

The mechanical pass that doesn't need any restructuring:

- **Bump legacy form font-size**: `.tt-form-row input / select / textarea` from `0.9rem` to `1rem` (16 px). Optional: also add the `.tt-field` styling primitives so future migrations have a clean target. This stops iOS auto-zoom on every legacy form.
- **Add `inputmode` attributes** everywhere a `type="number"` / `type="tel"` / `type="email"` exists. Centralised through `CustomFieldRenderer.php` for custom fields; one-pass touch on the ~10 hand-rolled inputs (jersey_number, height_cm, weight_kg, rating thresholds, etc.).
- **Add `touch-action: manipulation`** to `.tt-btn`, `.tt-tab`, `.tt-tile`, `.tt-pager-link`, all checkboxes / radios. Site-wide rule; one block in `public.css`.
- **Add safe-area-inset padding** to the four fixed bars: dashboard back button, modal footers, sticky header on scout link viewer, parent-meeting fullscreen exit button.
- **`:focus-visible` migration**. Replace the `:focus { outline: ... }` rules with `:focus-visible { outline: ... }`. Done in one pass across the 6 CSS files.

CI gate: a small grep gate in `.github/workflows/release.yml` that fails if any new `<input type="number">` lands without `inputmode="..."`. Same pattern as the `#0035` legacy-sessions grep gate already in place. Guards against regressing.

### Sprint 2 — Tap target floor (~2-3h)

Bump the four undersized targets to the 48 px standards floor, **gated on `(pointer: coarse)`** so desktop density isn't destroyed:

```css
@media (pointer: coarse) {
    .tt-btn       { min-height: 48px; }
    .tt-btn-sm    { min-height: 48px; padding-inline: 14px; }
    .tt-tab       { min-height: 48px; }
    .tt-pager-link { min-width: 48px; min-height: 48px; }
}
```

Pager buttons and `.tt-btn-sm` are the most-tapped surfaces — biggest UX win per line-of-CSS. Adjacent-target spacing (the 8px-between-targets standard) stays as-is.

### Sprint 3 — "Best on desktop" surface marker (~3-4h)

New tile-registry flag. Add `desktop_preferred => true` to the `TileRegistry::register([...])` call sites for surfaces that genuinely don't fit small viewports:

- Configuration tile-landing.
- Authorization Matrix admin.
- Lookups admin.
- Workflow templates editor (`workflow-config`).
- Letter templates editor (`trial-letter-templates-editor`).
- Migrations preview.
- Audit log viewer.
- Wizards admin.

When the user lands on a `desktop_preferred=true` view AND the viewport is `(max-width: 767px) AND (pointer: coarse)`, render a friendly dismissable banner above the content:

> **This page works best on a tablet or laptop.** You can keep going on your phone, but a bigger screen makes editing easier.
>
> [ Continue anyway ] [ Open later on desktop ]

Dismissable per-session via `localStorage` flag (`tt_desktop_preferred_dismissed_<view>`); reappears on every new device / session. Doesn't block — the user can complete the task on a phone if they need to.

Reuses the existing `FrontendBackButton` + `tt-notice` styles from #0019 — no new JS, no new component beyond a small HTML block. No REST. The flag lives entirely in PHP + a 5-line render helper.

### Sprint 4 — Mobile-first authoring rule + first migrated surface (~5-7h)

Pick **one** existing manage view (recommend `FrontendActivitiesManageView` since it's the most-touched coach surface) and rewrite its CSS partial mobile-first as a template + reference for future migrations:

- Base styles target 360 px.
- `@media (min-width: 480px)` and `@media (min-width: 768px)` scale up.
- No `!important`, no `max-width:` queries.
- Document the pattern in a new `docs/architecture-mobile-first.md` topic with the before/after diff.

**`CLAUDE.md` § 2 update**: tighten the standing rule from "migrate legacy rules opportunistically when touching them" to "**all new components are mobile-first; legacy migrations happen one view per release**, tracked in this epic until SEQUENCE.md shows zero legacy desktop-first sheets". A weaker rule got us six months of debt drift; an explicit one stops that.

CI gate (optional, light): a grep that flags `@media (max-width:` in newly-added CSS files (not existing ones — too noisy to fix all at once). Author can override with a comment marker like `/* legacy desktop-first */` if they're patching the legacy sheets.

### Deferred (own epic later) — Form system consolidation

Migrate every view from `.tt-form-row` to `.tt-field` / `.tt-input`. Substantial — touches ~25 view files. Sprint 1's font-size bump on `.tt-form-row` makes the legacy class **safe in the meantime**, so the migration becomes a code-quality cleanup rather than a UX bug fix. Park as a separate idea/epic when capacity allows; don't block this epic on it.

Same pattern for the **full mobile-first CSS rewrite**. Sprint 4 establishes the template; bulk migration of the remaining views is its own future effort.

## Out of scope (this epic)

- **Full mobile-first rewrite of the legacy stylesheets.** Sprint 4 ships the pattern + one migrated view; bulk migration of all 25+ views happens separately and probably opportunistically over multiple releases. The CSS reduces from ~3000 lines to ~2000 lines once the patch-layer pattern is gone, but that's not v1.
- **Native iOS / Android apps.** PWA push is enough.
- **Form system consolidation** (`.tt-form-row` → `.tt-field`). Sprint 1 makes the legacy class non-buggy; full migration is a future effort.
- **Visual redesign.** This is plumbing + standards compliance, not a fresh design pass.
- **Per-view layout overhauls.** Sprint 4's pilot is a CSS rewrite, not a UX rethink.
- **Replacing `frontend-mobile.css` with media queries inline in each component sheet.** Tempting but mechanical at scale; defer.

## Acceptance criteria (epic-level)

### Sprint 1

- [ ] `.tt-form-row input / select / textarea` has `font-size: 16px` (1rem); iOS no longer zooms on focus.
- [ ] Every `<input type="number" / "tel">` in PHP / templates has a corresponding `inputmode="..."`.
- [ ] `touch-action: manipulation` is applied to all interactive elements site-wide.
- [ ] All four fixed bars (back button, modal footers, sticky scout-link header, parent-meeting exit) respect `env(safe-area-inset-*)`.
- [ ] `:focus-visible` replaces `:focus` for visual outline rules.
- [ ] CI gate fails if a new `<input type="number">` ships without `inputmode`.

### Sprint 2

- [ ] All four target classes meet the 48 px floor under `(pointer: coarse)`.
- [ ] Desktop density is unchanged (verify on the rate-card and configuration views).

### Sprint 3

- [ ] `TileRegistry` accepts `desktop_preferred => true`.
- [ ] All eight named surfaces (Configuration, Auth Matrix, Lookups, Workflow templates, Letter templates, Migrations, Audit log, Wizards admin) are tagged.
- [ ] Banner appears on `(max-width: 767px) AND (pointer: coarse)` for tagged views; doesn't appear elsewhere.
- [ ] Banner dismissable per-session per-view; reappears on new devices.

### Sprint 4

- [ ] `FrontendActivitiesManageView` (or chosen pilot) ships with mobile-first CSS in its own partial.
- [ ] `docs/architecture-mobile-first.md` exists with before/after example.
- [ ] `CLAUDE.md` § 2 updated with the stronger rule.
- [ ] Optional CI lint flags new `@media (max-width:` queries (override marker available).

### Cross-cutting

- [ ] All standards-checklist items in `CLAUDE.md` § 2 → § 5 pass for the touched surfaces.
- [ ] No regressions on desktop (visual diff on three representative views).
- [ ] Translations + docs ship-along rules respected.

## Notes

### Sizing

| Sprint | Estimate |
| - | - |
| 1 — Quick wins | ~3-4h |
| 2 — Tap target floor | ~2-3h |
| 3 — "Best on desktop" surface marker | ~3-4h |
| 4 — Mobile-first authoring rule + first migrated view | ~5-7h |
| **Total v1** | **~13-18h** as one bundled PR per the compression pattern |

Deferred form-system + full-mobile-first rewrite → its own future epic, ~30-50h depending on scope.

### Cross-references

- **CLAUDE.md § 2** — standards source of truth; this epic closes the gap between the rule and the codebase.
- **#0014 / #0017 / #0019 / #0044** — every previous frontend epic shipped under the "fix opportunistically" rule and didn't close the debt.
- **#0035** — established the CI grep-gate pattern that Sprint 1 reuses for `inputmode`.
- **#0042** — youth-aware contact strategy depends on a credible mobile chrome before PWA push lands.

### Open questions for shaping

1. **Sprint 4 pilot** — `FrontendActivitiesManageView` or a different view? (Activities = most-touched coach surface; Players = highest visibility; Goals = simplest.)
2. **CI gate scope** — `inputmode` enforcement only, or also `@media (max-width:` lint, or also a font-size lint? Strict gates catch regressions; over-strict gates slow contributors down.
3. **"Best on desktop" notice** — banner above content (intrusive but visible) vs. corner toast (subtle but missable). Recommendation: banner for v1, toast as a v1.1 if banner feels heavy.
4. **Tap-target gating** — `(pointer: coarse)` only, or also `(max-width: 767px)`? The former targets touch devices regardless of size (good for tablets); the latter targets phones only. Recommendation: `(pointer: coarse)`.
5. **Form system migration** — confirmed deferred? Sprint 1's font-size bump makes it non-urgent.

### Sequence position (proposed)

After the current Ready queue clears for non-foundational items. Pairs well with #0042 (youth-aware contact strategy) since #0042 lands the PWA push surface and #0056 makes the surrounding chrome credible. Independent of #0028, #0031, #0039, #0052, #0053, #0054 — all of those benefit from the cleanup but none block on it.
