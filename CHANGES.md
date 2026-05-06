# TalentTrack v3.103.3 — Mobile pattern library: bottom-sheet, CTA bar, segmented control, list-item (#0084 Child 2)

Second child of #0084 (Mobile experience). Ships the small native-pattern vocabulary that mobile-first surfaces sit on top of, so the deferred wizard mobile work in Child 3 has components to reach for.

Pure CSS + tiny JS helper, conditionally loaded — tablets and desktops are unaffected.

## What landed

### `assets/css/mobile-patterns.css` — four components

- **`tt-mobile-bottom-sheet`** — slide-up modal. Slides up on `is-open`, max 80vh, drag-to-dismiss via the handle, backdrop tap-to-close, Escape-key close, `prefers-reduced-motion: reduce` honoured.
- **`tt-mobile-cta-bar`** — fixed bottom action bar with a safe-area-inset-aware spacer (`tt-mobile-cta-bar-spacer`) to prevent overlap on the last form field. Auto-fills the 48px touch-target floor (#0056). Replaces inline submit buttons that scroll off long forms — the immediate consumer is the new-evaluation wizard's `RateActorsStep` Submit (closes the v3.78.0 deferred polish item in Child 3).
- **`tt-mobile-segmented-control`** — 2-4 option picker. Native iOS / Android-feeling segment selector, `role="tablist"` + `aria-selected` driven, focus-visible ring, prefers-reduced-motion-aware.
- **`tt-mobile-list-item`** — table-row replacement. Card-style two-line layout (primary + secondary) with a chevron-right tap-to-detail affordance. Wrapped in `<ul class="tt-mobile-list">`. Auto-hidden above 720px so a sibling `<table>` carries the desktop view — single template ships both, the user only sees one.

All components read the existing TalentTrack design tokens (`--tt-bg`, `--tt-bg-soft`, `--tt-ink`, `--tt-line`, `--tt-accent`) with hardcoded fallbacks so a surface that doesn't enqueue the design-token stylesheet still renders correctly.

### `assets/js/mobile-helpers.js` — gesture handler

Public API on `window.TT.Mobile`:
- `open(sheet)` — slide in + show backdrop + lock body scroll.
- `close(sheet)` — reverse.
- `bind(sheet)` — attach drag-to-dismiss + backdrop-click + Escape handlers. Idempotent. Returns a teardown function.

Auto-binds every `.tt-mobile-bottom-sheet` in the DOM at `DOMContentLoaded` and watches via `MutationObserver` for sheets injected later (REST-driven drawers, etc.). Drag-to-dismiss threshold = `min(35% sheet height, 120px)`. ~2KB minified, no dependencies.

The other three components in the library are pure CSS — no JS needed.

### Conditional enqueue

`DashboardShortcode::render()` enqueues both files only when the resolved view classifies as `native` per `MobileSurfaceRegistry` or when the empty-view dashboard renders (the persona-dashboard tile grid is mobile-first by construction). Tablets and desktops still see the existing responsive treatment unchanged.

### `docs/mobile-patterns.md` + Dutch twin

One section per component with markup samples, behaviour notes, and a "don't" list. Documents the two conventions enforced on `native` surfaces:
- No `<table>` markup below 480px (use `tt-mobile-list-item` instead).
- No ad-hoc `position: fixed` outside the CTA-bar component.

Cross-references the underlying `docs/architecture-mobile-first.md` (#0056 foundation) and `docs/access-control.md` (#0084 Child 1's per-club setting).

### CI lint job — `Mobile pattern conventions (#0084)`

New job in `.github/workflows/release.yml` flags:
- `<table>` markers in templates known to render on `native` routes — currently the new-evaluation wizard's three native steps (`RateActorsStep`, `PlayerPickerStep`, `ActivityPickerStep`). The list will grow with Child 3's full classification rollout.
- `position: fixed` rules in any CSS file outside the allow-list (`mobile-patterns.css` plus a small set of legacy stylesheets that legitimately use it for sticky table headers, modal overlays, etc.).

## What's NOT in this PR

- **Actual application of the components on existing surfaces** (Child 3 — `RateActorsStep` Submit migrates to `tt-mobile-cta-bar`, `PlayerPickerStep` and `ActivityPickerStep` migrate to `tt-mobile-bottom-sheet`).
- **Full route classification** (Child 3 — expands the registered classes to all ~25 routes including `native` declarations on the persona dashboard, the new-evaluation wizard, the player profile, and the prospect-logging wizard).
- **Configuration → Mobile sub-tile** (Child 3 — the operator-facing nav surface for the toggle).

## Affected files

- `assets/css/mobile-patterns.css` — new (~3KB minified).
- `assets/js/mobile-helpers.js` — new (~2KB minified).
- `src/Shared/Frontend/DashboardShortcode.php` — conditional asset enqueue.
- `docs/mobile-patterns.md` — new.
- `docs/nl_NL/mobile-patterns.md` — new.
- `.github/workflows/release.yml` — new `Mobile pattern conventions (#0084)` lint job.
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version bump + ship metadata.

## Translations

Zero new translatable strings — every component is pure visual + ARIA-only.

## Player-centricity

The pattern library exists so the surfaces that earn out on phones — the new-evaluation wizard a coach uses to finish a training, the prospect-logging wizard a scout uses at a tournament, the player profile a parent checks on the bus — feel native rather than just well-styled. Child 3 applies the components to these exact surfaces; Child 2 makes sure the vocabulary is there to apply.
