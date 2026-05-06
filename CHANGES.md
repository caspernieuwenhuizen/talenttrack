# TalentTrack v3.104.0 — Mobile pattern library + classification rollout + sticky wizard CTA (#0084 Children 2 + 3)

Closes #0084 — the routing scaffolding from Child 1 (v3.103.2) is now joined by the four-component pattern library that `native` surfaces consume, the sticky wizard action bar that closes the v3.78.0 deferred polish from #0072, and the rollout of `native` declarations across the surfaces that earn it.

## Why bundle Children 2 + 3

Spec note: Child 2 is "small, parallelisable" and Child 3 "consumes both". The codebase's parallel-agent collision pattern means a serial-sequence Child-2-PR-then-Child-3-PR loses two CI rounds + two version slots. The bundled ship is the cheaper path. The work is small enough — pure CSS components, vanilla JS helpers, doc files, and surface declarations.

## Child 2 — mobile pattern library

Four small CSS components in `assets/css/mobile-patterns.css`. Conditional enqueue: `DashboardShortcode::render()` enqueues `tt-mobile-patterns` style + `tt-mobile-helpers` script only when `MobileSurfaceRegistry::isNative($view)` is true. Surfaces classified `viewable` or `desktop_only` never load them — the desktop bundle stays slim.

### `tt-mobile-bottom-sheet`

Modal that slides up from the bottom of the viewport, max 80% screen height, drag-to-dismiss. Sibling `.tt-mobile-bottom-sheet-backdrop` provides the dimmed overlay. Drag-handle `.tt-mobile-bottom-sheet-handle` is the touch target for the dismiss gesture. Honours `prefers-reduced-motion: reduce`.

`assets/js/mobile-helpers.js` exposes `TT.Mobile.openBottomSheet(el)` / `closeBottomSheet(el)` / `bindBottomSheet(el)`. Drag listens on `touchstart/move/end`; pulls follow the finger; release with > 80 px translation or flick velocity > 0.5 px/ms closes; otherwise snaps back open. Backdrop click also closes. Auto-bind runs at DOM-ready.

### `tt-mobile-cta-bar`

Sticky bottom bar containing the primary action button. Stays visible while the user scrolls long forms. Honours `env(safe-area-inset-bottom)` to clear the iOS home indicator. The immediate consumer is the wizard's `.tt-wizard-actions` row (Child 3 below applies the same pattern via inline CSS so it works without enqueuing the library).

### `tt-mobile-segmented-control`

Replaces dropdowns when there are 2–4 options. Native iOS / Android-feeling segment picker backed by hidden radio inputs so it submits in standard form payloads. For 5+ options use a `<select>` instead.

### `tt-mobile-list-item`

Card-style two-line row with `*-leading` (avatar / icon slot) + `*-content` (primary + secondary line) + `*-trailing` (chevron / status). Replaces `<table>` rows on `native` surfaces. Three-column grid layout, 64 px min-height, 14 px font-size for primary, 13 px muted for secondary, ellipsis truncation on overflow.

### Documentation

`docs/mobile-patterns.md` (EN) and `docs/nl_NL/mobile-patterns.md` (NL) document all four components: when to use, how to use, code examples. Cross-referenced from `docs/architecture-mobile-first.md`, which gains a new `#0084 — surface classification` section.

## Child 3 — classification rollout + sticky wizard CTA

### Additional `desktop_only` surfaces

Child 1 (v3.103.2) seeded 17 desktop-only routes. Child 3 adds three more:

- `players-import` — CSV mapping flow needs a laptop.
- `onboarding-pipeline` — xl-size pipeline widget; doesn't fit on phones.
- `reports` — wizard + multi-column tables.

### `native` surface declarations

Three `native` declarations register the pattern-library auto-enqueue:

- `players` — coach reaches the player profile from the sideline all the time.
- `wizard` — wizard aggregator slug; every wizard goes through it.
- `teammate` — player viewing a teammate's card.

The persona dashboard (empty `?tt_view=`) and the player-detail tabs are intentionally not classified `native` here — their classification is the dispatcher's responsibility but the per-row pattern migration follows opportunistic touches per spec.

### Sticky wizard CTA

`.tt-wizard-actions` becomes `position: sticky; bottom: 0;` on phones (≤ 720 px) via the wizard's inline stylesheet in `FrontendWizardView::enqueueWizardStyles()`. The Submit / Next button stays visible while the coach scrolls long forms — closes the v3.78.0 deferred polish item from #0072 about `RateActorsStep` mobile UX.

Combined with the v3.103.0 (#0080 B4) card stack, the new-evaluation wizard now:

1. Renders each player as a stack-card (B4 from v3.103.0) with categories laid out vertically.
2. Has the Save / Next button anchored at the bottom of the viewport (this PR), so a coach scrolling a long ladder of players never loses sight of "how do I finish this".

The wizard moves from "responsive but flat" to "feels native" without a separate spec.

## What's NOT in this PR

- **Bottom-sheet retrofit on `PlayerPickerStep` / `ActivityPickerStep`.** The existing dropdowns work; bottom-sheet is a polish opportunity for a follow-up. Per spec note 176, "the wizard becomes the reference implementation for the pattern library", but the immediate consumer (the sticky CTA on `.tt-wizard-actions`) lands here.
- **CI lint rules** forbidding `<table>` in native templates and ad-hoc `position: fixed`. Conventions documented in `docs/mobile-patterns.md` but enforcement defers — runtime stays the source of truth for now.
- **Native-class pattern migration on the player-detail surface.** The `players` slug is `native` so the pattern library loads; the per-row migration (e.g. team list switching from `<table>` to `tt-mobile-list-item`) follows opportunistic touches per spec note about "Other native-class surfaces inherit the patterns as they're touched".

## Migrations

None. Code-only.

## Affected files

- `assets/css/mobile-patterns.css` — new (4 components, ~190 lines).
- `assets/js/mobile-helpers.js` — new (~110 lines).
- `docs/mobile-patterns.md` + `docs/nl_NL/mobile-patterns.md` — new.
- `docs/architecture-mobile-first.md` — appended #0084 surface-classification section.
- `src/Shared/CoreSurfaceRegistration.php` — extended `registerMobileClasses()` with three more `desktop_only` surfaces and the `native` set.
- `src/Shared/Frontend/DashboardShortcode.php` — conditional pattern-library enqueue on `native` surfaces.
- `src/Shared/Frontend/FrontendWizardView.php` — sticky-CTA CSS rule for `.tt-wizard-actions` on phones.
- `languages/talenttrack-nl_NL.po` — no new operator-facing strings (all-CSS / silent-visual + classification declarations).
- `readme.txt`, `talenttrack.php`, `SEQUENCE.md` — version bump + ship metadata.
