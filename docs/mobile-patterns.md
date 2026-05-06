<!-- audience: developer -->

# Mobile pattern library

> Components shared by surfaces classified as `native` per `MobileSurfaceRegistry`. Sits on top of #0056's mobile-first foundation (48px tap-targets, `inputmode`, `:focus-visible`, safe-area-insets, `touch-action`). If you're not building a mobile-first surface, you don't need anything from this doc — the existing responsive CSS handles `viewable` surfaces unchanged.

The library is four small components, by design. We resisted speculative additions (`tt-mobile-stepper`, `tt-mobile-empty-state`, `tt-mobile-skeleton`) that lacked an immediate consumer. Add those when a surface needs them.

CSS lives at [assets/css/mobile-patterns.css](../assets/css/mobile-patterns.css). The drag-to-dismiss helper for the bottom sheet lives at [assets/js/mobile-helpers.js](../assets/js/mobile-helpers.js). Both load conditionally — `DashboardShortcode::render()` only enqueues them when the resolved view classifies as `native` or when the empty-view dashboard renders.

## Loading

Native surfaces inherit the library automatically — there's nothing to enqueue from a view's `render()` method. The CSS uses the existing TalentTrack design tokens (`--tt-bg`, `--tt-ink`, `--tt-line`, `--tt-accent`, `--tt-bg-soft`) with hardcoded fallbacks so a surface that doesn't enqueue the design-token stylesheet still renders correctly.

If you're authoring a new `native` surface and need the components on a route that doesn't go through `DashboardShortcode` (rare — most surfaces dispatch from the shortcode), enqueue manually:

```php
wp_enqueue_style(
    'tt-mobile-patterns',
    TT_PLUGIN_URL . 'assets/css/mobile-patterns.css',
    [ 'tt-public' ],
    TT_VERSION
);
wp_enqueue_script(
    'tt-mobile-helpers',
    TT_PLUGIN_URL . 'assets/js/mobile-helpers.js',
    [],
    TT_VERSION,
    true
);
```

## Conventions enforced on `native` surfaces

- **No `<table>` below 480px.** Use `tt-mobile-list-item` instead. Lint catches `<table>` markers in templates known to render on `native` routes.
- **No ad-hoc `position: fixed` elements.** Bottom CTAs are allowed via `tt-mobile-cta-bar`; everything else must scroll. Lint catches new `position: fixed` rules in any CSS file outside `mobile-patterns.css`.

These are guardrails, not absolute prohibitions — exemptions exist for known-correct uses (modal positioning, sticky table headers on `viewable` surfaces). Add a `// lint:mobile-allow-fixed` comment with a one-line reason if you're sure.

## 1. `tt-mobile-bottom-sheet` — slide-up modal

**When to use.** Filters, confirmations, secondary actions, the deferred wizard player picker. Replaces classic centered modals on mobile.

**Markup:**

```html
<div class="tt-mobile-bottom-sheet" id="filter-sheet" role="dialog" aria-modal="true" aria-labelledby="filter-sheet-title">
    <div class="tt-mobile-bottom-sheet-handle" aria-hidden="true"></div>
    <h2 id="filter-sheet-title" class="tt-mobile-bottom-sheet-title">Filter</h2>
    <div class="tt-mobile-bottom-sheet-content">
        <!-- form fields, list, whatever -->
    </div>
</div>
```

**Open / close from JS:**

```js
const sheet = document.getElementById('filter-sheet');
window.TT.Mobile.open(sheet);
// later …
window.TT.Mobile.close(sheet);
```

**Behaviour.** Slides up from the bottom on `is-open`, max 80% screen height, drag-to-dismiss via the handle, backdrop tap-to-close, Escape key closes. Auto-binds on every `.tt-mobile-bottom-sheet` in the DOM at load time and on any sheet injected later (a `MutationObserver` watches). Honours `prefers-reduced-motion: reduce` (CSS removes the slide transition; the open/close still happens, just instantly).

**Don't.** Stack two sheets. The library doesn't manage a stack and the second sheet will fight the first one's `body { overflow: hidden }` lock.

## 2. `tt-mobile-cta-bar` — fixed bottom action

**When to use.** A primary action button that needs to stay visible as the user scrolls a long form or list. The reference consumer is the new-evaluation wizard's `RateActorsStep` Submit (the deferred polish item from v3.78.0 — closes in #0084 Child 3).

**Markup:**

```html
<form>
    <!-- form fields -->
    <div class="tt-mobile-cta-bar-spacer" aria-hidden="true"></div>
</form>
<div class="tt-mobile-cta-bar">
    <button type="submit" form="…" class="tt-button-primary">Save</button>
</div>
```

**Notes.**
- The `tt-mobile-cta-bar-spacer` is required at the end of the scrollable area. It reserves empty space equal to the CTA bar's height + safe-area-inset so the last form field isn't covered by the fixed bar.
- The button hits the v3.50.0 (#0056) 48px touch-target floor automatically when used inside a `.tt-mobile-cta-bar`.
- Don't combine with `position: fixed` headers — the safe-area math gets ugly.

## 3. `tt-mobile-segmented-control` — 2-4 option picker

**When to use.** Replaces a `<select>` when the option count is small (2 to 4) and the labels are short (≤12 characters). Native iOS / Android-feeling segment picker.

**Markup:**

```html
<div class="tt-mobile-segmented-control" role="tablist" aria-label="Time range">
    <button type="button" role="tab" aria-selected="true">Today</button>
    <button type="button" role="tab" aria-selected="false">Week</button>
    <button type="button" role="tab" aria-selected="false">Season</button>
</div>
```

**Behaviour.** Selection is communicated via `aria-selected="true"` — the CSS styles it. JS for selection isn't shipped here (different surfaces apply the change differently — some refresh the list, some toggle a chart). A simple toggling helper:

```js
const control = document.querySelector('.tt-mobile-segmented-control');
control.addEventListener('click', (e) => {
    const tab = e.target.closest('[role="tab"]');
    if (!tab) return;
    control.querySelectorAll('[role="tab"]').forEach(t =>
        t.setAttribute('aria-selected', t === tab ? 'true' : 'false')
    );
});
```

## 4. `tt-mobile-list-item` — table-row replacement

**When to use.** Replaces table rows on mobile. Card-style two-line layout (primary + secondary) with a chevron-right tap-to-detail affordance.

**Markup:**

```html
<ul class="tt-mobile-list" role="list">
    <li class="tt-mobile-list-item">
        <a href="?tt_view=players&id=42">
            <span class="tt-mobile-list-item-primary">Casper Nieuwenhuizen</span>
            <span class="tt-mobile-list-item-secondary">U13 · Last seen 2026-05-04</span>
            <span class="tt-mobile-list-item-chevron" aria-hidden="true">›</span>
        </a>
    </li>
</ul>
```

**Behaviour.** Each item is a tappable area meeting the v3.50.0 (#0056) 48px floor. The list is automatically hidden above 720px — desktop callers fall back to their existing `<table>` markup, which is sibling-rendered and shown above 720px:

```html
<ul class="tt-mobile-list" role="list">
    <!-- … -->
</ul>
<table class="tt-table-desktop">
    <!-- desktop table … -->
</table>
```

The `<table>` declares its own visibility rule (`@media (max-width: 719px) { display: none; }`) — both blocks ship in the same template, the user only ever sees one.

## Reduced-motion

All animations honour `prefers-reduced-motion: reduce`. The bottom sheet's transform-transition is suppressed; the segmented control's background-transition is suppressed. The functional behaviour stays the same; only the smoothness is removed.

## Performance

- The full CSS file is ~3 KB minified, conditionally loaded.
- The JS helper is ~2 KB minified, conditionally loaded, no dependencies.
- The bottom-sheet auto-binder uses one document-level `MutationObserver`. The work it does on each mutation is one `querySelectorAll` over the added subtree — bounded.

## Related docs

- [`docs/architecture-mobile-first.md`](architecture-mobile-first.md) — the underlying mobile-first authoring rules from #0056.
- [`docs/access-control.md`](access-control.md) — the per-club `force_mobile_for_user_agents` setting from #0084 Child 1.
