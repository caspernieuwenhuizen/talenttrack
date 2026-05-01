<!-- type: feat -->

# #0074 — Persona dashboard visual refresh: subtle title header, no decorative icons, first-class polish

> Originally drafted as #0072 in the user's intake (uploaded as a PDF in commit `81646b0`). Renumbered on intake — #0072 was already taken by the New Evaluation wizard spec intaked moments earlier.

## Problem

The persona dashboard currently has a thoughtful information architecture but a slightly shopworn presentation. Specifically, three issues that compound:

1. **No page-level title header.** The dashboard opens directly into the role-switcher pill bar followed by widgets. There is nothing identifying the page itself — no "Dashboard", no persona name, no orientation. For a user who has just arrived from a deep-link or another browser tab this is jarring; for a screenshot in a customer demo it looks unfinished.
2. **Decorative icons everywhere.** `NavigationTileWidget` renders a coloured rounded square containing the first one-or-two letters of the tile label (P for Players, T for Trials, etc.). `ActionCardWidget` renders a circular yellow "+" before each action's label. Both are scaffolding for visual hierarchy, but in practice they read as filler — they don't carry meaning the typography couldn't, they multiply the colour palette beyond what's earned, and they age the design against current product UI norms (Linear, Vercel, modern Notion-style admin surfaces all use typographic hierarchy without per-row badges).
3. **The widget chrome is functional but flat.** Cards have a single-stop shadow, square-ish padding, and panel headers in upper-case 12px caption type — a 2018 vintage. The grid scans correctly but doesn't feel premium. For a Head of Development glancing at the page first thing in the morning, the visual impression matters as much as the data.

The task: refresh `assets/css/persona-dashboard.css` (the 563-line stylesheet that drives all eight personas' landing pages) plus a small surgical change in two widget renderers to remove the decorative icons. Outcome is a "first-class look and feel" — the user's words. That phrasing is deliberate: this is **not a redesign**, it's a polish pass that keeps every widget and every layout decision in place but raises the visual register.

## Proposal

Three coordinated changes, each small, that compound into a meaningful refresh:

1. **Add a subtle page header.** A single horizontal block at the top of the dashboard, above the role switcher. Persona name as a properly-sized `<h1>`; one optional sub-line ("Good morning, Sarah" with date) only when no hero widget is in the layout. Muted, generous in vertical breathing room, light typographic emphasis.
2. **Remove decorative icons.** The `tt-pd-tile-icon` coloured square goes away. The `tt-pd-action-icon` "+" badge goes away. Tiles and action cards lean entirely on typographic hierarchy + a hover affordance. Affordance comes back via a subtle right-arrow chevron on tile-link hover (CSS-only, no asset), which signals "go here" without per-row colour.
3. **Refresh the widget shell.** Softer corner radius, a two-stop shadow that's more present on hover, refined typographic scale across panel headers and KPI numerals, and a colour palette consolidation — replace the handful of raw hex values sprinkled through the file with a small set of CSS custom properties so future polish passes have a single point of edit.

The full file (`assets/css/persona-dashboard.css`) gets rewritten. Other persona-related CSS files (`persona-dashboard-editor.css`) are untouched — the editor is an admin-facing surface with different visual conventions.

## Scope

### 1. Page header — new component

A new top-of-page element rendered by `PersonaLandingRenderer::render()` immediately inside the `tt-pd-landing` wrapper, before the role switcher.

**Markup:**

```html
<header class="tt-pd-page-header">
    <h1 class="tt-pd-page-title">Head of Development</h1>
    <p class="tt-pd-page-subtitle">Friday, 1 May · Anna's Academy</p>
</header>
```

**Subtitle composition:**

- For personas where a hero widget is present in the resolved template (`team_manager`, `assistant_coach`, `head_coach`, `parent`, `player`), the subtitle shows just the date + club name. The hero widget is doing the greeting work; duplicating it would be noise.
- For personas where no hero widget is present (`head_of_development`, `academy_admin`, `scout`), the subtitle adds a short greeting prefix: "Good morning, {first name} ·" before the date and club. Time-of-day phrasing (morning / afternoon / evening) follows the user's timezone via the existing `wp_timezone()` helper.

**Translatable strings:**

- "Good morning, %s" / "Good afternoon, %s" / "Good evening, %s"
- Persona names (already translated via the existing persona-name labels on `PersonaResolver`).

**CSS:**

```css
.tt-pd-page-header {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
    padding: 0.25rem 0 0.5rem;
    border-bottom: 1px solid var(--tt-pd-divider);
    margin-bottom: 1rem;
}
.tt-pd-page-title {
    font-size: 1.5rem;
    font-weight: 600;
    line-height: 1.2;
    color: var(--tt-pd-text-primary);
    letter-spacing: -0.01em;
    margin: 0;
}
.tt-pd-page-subtitle {
    font-size: 0.875rem;
    color: var(--tt-pd-text-muted);
    margin: 0;
}
```

The header is subtle by design — same width as the grid, single thin divider beneath, small enough not to compete with the hero widget when one is present. It's an orientation cue, not a marketing surface.

Mobile (≤720px) gets the same component with slightly tighter padding (`padding: 0 0 0.5rem`) and a smaller title (`font-size: 1.25rem`). No special breakpoint code beyond what's already in the file.

The header is suppressed entirely if `?tt_view=` is set to anything other than the default landing — no use case exposes the dashboard with a header on a sub-view.

### 2. Decorative icon removal

Two surgical changes in widget renderers + the matching CSS deletions.

**`NavigationTileWidget::render()`** — drop the `tt-pd-tile-icon` span. Today's render:

```html
<a class="tt-pd-tile-link" href="...">
    <span class="tt-pd-tile-icon" style="background-color:#2563eb;">P</span>
    <span class="tt-pd-tile-label">Players</span>
    <span class="tt-pd-tile-desc">Manage roster & profiles</span>
</a>
```

After:

```html
<a class="tt-pd-tile-link" href="...">
    <span class="tt-pd-tile-label">Players</span>
    <span class="tt-pd-tile-desc">Manage roster & profiles</span>
    <span class="tt-pd-tile-arrow" aria-hidden="true">→</span>
</a>
```

The `tt-pd-tile-arrow` is positioned absolutely in the bottom-right via CSS, opacity 0 by default, opacity 0.5 on hover/focus. CSS → glyph (`content: "→"` in a pseudo-element if we'd rather not put text in markup; the spec uses an inline span for screenreader simplicity but either is fine).

The `color` field on each tile registration in `CoreTemplates.php` stays in the data structure but is unused for rendering. Reason: keeping it lets a future "back to icons" decision land without a data-model change, and clubs that override their templates and pass colours don't break. The field gets a docblock note explaining it's not currently rendered.

**`ActionCardWidget::render()`** — drop the `tt-pd-action-icon` span. Today's render:

```html
<a class="tt-pd-action-link" href="...">
    <span class="tt-pd-action-icon">+</span>
    <span class="tt-pd-action-label">New evaluation</span>
</a>
```

After:

```html
<a class="tt-pd-action-link" href="...">
    <span class="tt-pd-action-label">+ New evaluation</span>
</a>
```

The "+" affordance moves into the link's typography — labels become "+ New evaluation" rendered as a single text node, where the "+" is part of the translatable string. This keeps the visual cue ("this creates something new") without the extra DOM node and without the yellow circle.

**Translatable strings change accordingly:**

- `'New evaluation'` → `'+ New evaluation'`
- `'New goal'` → `'+ New goal'`
- (etc. for the six action keys today plus `new_trial` from #0073)

The Dutch translations get the same treatment: `'Nieuwe evaluatie'` → `'+ Nieuwe evaluatie'`.

**CSS deletions:**

- `.tt-pd-tile-icon` (lines 314–323) — removed
- `.tt-pd-action-icon` (lines 353–362) — removed
- The `display: flex; flex-direction: column` on `tt-pd-tile-link` stays but the gap shrinks from `0.5rem` to `0.375rem` since one element is gone

### 3. Widget shell refresh

The shell is the visual container around every widget. Today it's a flat white card with one shadow stop. The refresh adds:

**Softer corner radius.** `border-radius: 0.75rem → 0.875rem` (14px). Subtle but reads as more polished against the existing `1rem` hero radius.

**Two-stop shadow with hover state.**

```css
.tt-pd-widget {
    background: var(--tt-pd-surface);
    border-radius: 0.875rem;
    box-shadow:
        0 1px 2px rgba(11, 31, 58, 0.04),
        0 4px 16px rgba(11, 31, 58, 0.04),
        inset 0 0 0 1px rgba(11, 31, 58, 0.04);
    transition: box-shadow 180ms ease, transform 180ms ease;
    /* ...other props unchanged */
}
.tt-pd-widget:hover {
    box-shadow:
        0 1px 2px rgba(11, 31, 58, 0.06),
        0 8px 24px rgba(11, 31, 58, 0.08),
        inset 0 0 0 1px rgba(11, 31, 58, 0.06);
}
```

The inset shadow gives a hairline border that's barely perceptible on white but holds the shape against any background. The transition uses `ease`, not `ease-out`, deliberately — most product UI uses simple `ease` for hover affordances; the difference is small but consistent with current defaults.

Tiles and action links get a similar treatment (lifted on hover by a 1px translate-y) but the hover should be on the link, not the widget shell, so that the pointer-cursor behaviour matches what's clickable.

```css
.tt-pd-tile-link {
    /* ...existing */
    transition: transform 120ms ease, box-shadow 120ms ease;
}
.tt-pd-tile-link:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(11, 31, 58, 0.06);
}
.tt-pd-tile-link:hover .tt-pd-tile-arrow {
    opacity: 0.5;
}
```

**Refined typographic scale.** The current `tt-pd-panel-title` is `0.75rem` uppercase letter-spaced — a 2018-era caption style. The refresh moves panel headers to a more confident `0.9375rem` semibold sentence-case:

```css
.tt-pd-panel-title,
.tt-pd-info-title {
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--tt-pd-text-primary);
    letter-spacing: -0.005em;
    text-transform: none;
}
```

KPI numerals get a tabular-nums treatment so multi-row KPI strips stay aligned vertically:

```css
.tt-pd-kpi-current {
    font-size: 2rem;
    font-weight: 700;
    line-height: 1.05;
    color: var(--tt-pd-text-primary);
    font-variant-numeric: tabular-nums;
    letter-spacing: -0.02em;
}
```

Weight drops 800 → 700 because at 32px the 800 reads as heavy on most displays; 700 with tighter letter-spacing reads as confident without shouting.

**Colour token consolidation.** A new `:root` block at the top of the file replaces the scattered hex values. This is the largest mechanical change but the smallest visual change — most colours stay exactly the same, just relocated.

```css
:root {
    /* Surface */
    --tt-pd-surface: #ffffff;
    --tt-pd-surface-subtle: #f8fafc;
    --tt-pd-surface-hover: #f1f5f9;

    /* Text */
    --tt-pd-text-primary: #0b1f3a;
    --tt-pd-text-secondary: #334155;
    --tt-pd-text-muted: #64748b;

    /* Accent (used very sparingly) */
    --tt-pd-accent: #2563eb;
    --tt-pd-accent-soft: rgba(37, 99, 235, 0.08);

    /* Status colours — unchanged from existing usage but tokenised */
    --tt-pd-success: #15803d;
    --tt-pd-warning: #d97706;
    --tt-pd-danger: #b91c1c;

    /* Lines */
    --tt-pd-divider: rgba(11, 31, 58, 0.08);
    --tt-pd-divider-strong: rgba(11, 31, 58, 0.14);

    /* Hero gradient stops — unchanged */
    --tt-pd-hero-start: #0b1f3a;
    --tt-pd-hero-end: #1a3a5f;
    --tt-pd-hero-cta: #facc15;
}
```

Three observations on the colour work:

- `#5b6e75` (used today for muted text) becomes `#64748b` — slate-500 from Tailwind's palette. Reads slightly cooler and pairs better with the `#0b1f3a` primary. Difference is small but consistent across the file.
- The accent yellow `#facc15` is preserved for the hero CTA only. Removing the action-card icon means yellow no longer sprinkles across the page.
- The status colours are unchanged in hue but moved slightly toward the warmer end (success from `#16a34a → #15803d`, warning from `#f59e0b → #d97706`) — both still well-tested AAA contrast ratios on white, both read as more grown-up.

### 4. Spacing rhythm

A small but consequential change: the dashboard's vertical spacing is currently `1rem` between bands. Bumping to `1.5rem` between major bands (header → role switcher → hero → grid → grid sections) and keeping `1rem` within the grid gives the page room to breathe.

```css
.tt-pd-landing {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;       /* was: 1rem */
    padding: 0.5rem 0; /* was: 0.75rem 0 */
}
.tt-pd-grid {
    gap: 1rem;         /* was: 0.75rem */
}
```

On mobile the gap shrinks back to `1rem` between bands and `0.75rem` within the grid via the existing media-query block — no CSS-grid breakpoint changes needed beyond updating the values.

### 5. Focus rings

The existing focus-ring rules use `outline: 2px solid #2563eb; outline-offset: 2px;` consistently. Keep them — they meet WCAG AA, they match the accent colour, and changing them would be polish for polish's sake. One small fix: a few `:focus-visible` rules use `outline: 2px solid #fff` on the hero CTA for contrast against the dark hero background, but the offset is missing. Add `outline-offset: 2px` to the hero CTA's `:focus-visible` for visual consistency with the rest.

### 6. What doesn't change

- **The grid layout system.** 12-column at desktop, 6-column at tablet, single-column at mobile, with explicit `grid-column` / `grid-row` placement from widget renderers. All preserved verbatim.
- **Widget contract.** `WidgetInterface` and individual widget classes are not refactored. The two widgets that change (`NavigationTile`, `ActionCard`) only change their HTML output, not their slot config or registration.
- **Persona templates.** `CoreTemplates::headOfDevelopment()` etc. don't change. Templates are layout decisions; this spec is presentation only.
- **The role switcher pill bar.** Its visual treatment stays.
- **The hero variant** (`tt-pd-variant-hero`) keeps its dark-blue gradient background and its yellow CTA — the hero is a deliberate "loud" surface and shouldn't be flattened to match the rest of the page.
- **Editor CSS** (`persona-dashboard-editor.css`) is admin-only and out of scope.

## Wizard plan

Not applicable — this is a presentation refresh, no record-creation flow.

## Out of scope

- **A wholesale visual identity refresh.** The hero gradient, the yellow CTA, the navy primary — all preserved. This is polish, not rebrand. A rebrand is a separate, larger conversation that involves the academy logo guidelines.
- **Dark mode.** TalentTrack does not ship dark mode; this spec doesn't introduce one. The colour tokens are forward-compatible (a dark-mode override would be a separate `[data-tt-theme="dark"] :root { ... }` block) but adding the dark theme itself is its own feature.
- **Animation beyond hover transitions.** No page-load fade-ins, no skeleton-shimmer animations. Snappy and quiet beats noisy. If the data source for a widget is slow, a skeleton state is a separate concern handled inside the widget renderer.
- **Replacing the role switcher pill bar.** It works and reads as a legitimate persona-switcher; redesigning it isn't part of this pass. A replacement (segmented control, dropdown) is a future option but not now.
- **Per-club theming via the editor.** Clubs can override colours via the existing branding tab today; this refresh's tokens are forward-compatible with that mechanism but no new editor knobs are added in this PR.
- **An icon system for widgets that genuinely need them** (e.g. the player status colour dot, the trend arrows on KPI cards, the role-switcher's active pill checkmark). Those are informational icons — they carry meaning the typography couldn't. They stay. The "exclude icons" instruction targets the **decorative** icons specifically: tile-icons (one-letter coloured squares) and action-icons (yellow plus-circle). The spec uses the term "decorative icons" deliberately to draw this line.
- **A new logo or wordmark above the page header.** The page header is title text only; no club logo is rendered there (the WordPress theme's site header handles club branding). Adding a logo to the dashboard chrome would be redundant and would conflict with theme variation across customers.

## Acceptance criteria

- [ ] The persona dashboard renders a new `<header class="tt-pd-page-header">` immediately inside `.tt-pd-landing`, before the role switcher.
- [ ] The page title (`<h1 class="tt-pd-page-title">`) shows the localised persona name (e.g. "Head of Development", "Hoofd ontwikkeling" in Dutch).
- [ ] The page subtitle shows date + club name. For personas without a hero widget (`head_of_development`, `academy_admin`, `scout`) the subtitle is prefixed with "Good morning, {first name} ·" / "Good afternoon, ..." / "Good evening, ..." appropriate to the user's timezone.
- [ ] The page header is hidden when `?tt_view=` is set to any view other than the dashboard landing.
- [ ] `NavigationTileWidget::render()` no longer emits a `<span class="tt-pd-tile-icon">` element. The CSS rule `.tt-pd-tile-icon { ... }` is removed from `persona-dashboard.css`.
- [ ] `ActionCardWidget::render()` no longer emits a `<span class="tt-pd-action-icon">` element. The "+" cue is part of the action's `label_key` text (e.g. `"+ New evaluation"`). The CSS rule `.tt-pd-action-icon { ... }` is removed.
- [ ] A `.tt-pd-tile-arrow` element renders inside each `tt-pd-tile-link`. It is invisible (`opacity: 0`) by default and reaches `opacity: 0.5` on hover and focus.
- [ ] The widget shell uses the new two-stop shadow + inset hairline at rest and a deeper shadow on hover, with an `ease` 180ms transition.
- [ ] Tile and action links lift by 1px on hover via `transform: translateY(-1px)` with a 120ms ease transition.
- [ ] Panel and info titles use sentence-case `0.9375rem` semibold, NOT uppercase `0.75rem` caption.
- [ ] KPI numerals use `font-variant-numeric: tabular-nums` and `700` weight (not 800).
- [ ] A `:root { ... }` token block is the new top of `persona-dashboard.css` and replaces the scattered hex values throughout the file.
- [ ] The vertical spacing between the role switcher, hero band, and grid increases from `1rem` to `1.5rem` at desktop; mobile keeps `1rem`.
- [ ] Visual regression tests (existing screenshot suite for the dashboard) are updated with new baselines. Both the previous and new screenshots are committed for review.
- [ ] Lighthouse and axe scores for the dashboard at desktop + mobile breakpoints are at least equal to today's. Specifically: contrast on `.tt-pd-page-subtitle` against `--tt-pd-surface` meets AA (4.5:1 — the muted-text token `#64748b` clears this on `#fff`).
- [ ] All Dutch translations for changed action labels are present in `languages/talenttrack-nl_NL.po`.

## Notes

### Documentation updates

- `docs/persona-dashboard.md` and `docs/nl_NL/persona-dashboard.md` — add a "Visual conventions" section documenting the page header, the absence of decorative tile icons, and the rationale ("Tiles rely on typographic hierarchy and a hover affordance — clubs that need stronger visual differentiation between tiles can add per-tile descriptions in the editor"). One screenshot before/after.
- `docs/dashboard-editor.md` — note that the per-tile `color` config field is no longer rendered as an icon background. The field is preserved for back-compat and may be re-rendered if a future iteration brings tile icons back, but currently has no visual effect.
- `languages/talenttrack-nl_NL.po` — translations for the new greeting strings, the action label changes, and the page subtitle. These are user-facing, so a native-speaker review pass is the right level of care: suggested phrasings "Goedemorgen, %s" / "Goedemiddag, %s" / "Goedenavond, %s" but defer final phrasing.
- `SEQUENCE.md` — append the spec row.
- `CHANGES.md` — entry: "Persona dashboard visual refresh: subtle page title header, decorative tile / action icons removed, refreshed widget shells, tokenised colour palette. No layout changes."

### CLAUDE.md updates

None — this spec doesn't introduce new architectural conventions. Documenting the colour-token block in the existing "front-end conventions" section is reasonable upkeep but not blocking.

### Test hooks

- **Visual regression**: re-record screenshots for all eight persona landing pages at desktop + mobile breakpoints. Diff manually before merging baselines (the human review is the tie-breaker on whether the new look is acceptable).
- **Unit**: `PersonaLandingRenderer` emits the page header markup; the header is suppressed when `?tt_view=` is set to a non-default value.
- **Accessibility**: automated axe scan of the dashboard for each of the eight personas. No new violations introduced.
- **Browser**: smoke test on Safari (the trickiest for `font-variant-numeric` and CSS custom properties on older versions; both supported in current evergreen Safari but worth a manual check). Also Firefox + Chrome current.

### A small judgement call worth flagging

The "+" prefix on action labels ("+ New evaluation") uses the actual plus character, not the entity `&#43;` or a separate icon font glyph. This makes the string copy-paste-friendly, screen-reader-friendly (it's read as "plus new evaluation" which is acceptable), and translation-system-friendly (no markup leaks into `.po` strings). The trade-off is slightly less typographic control over the plus's exact visual weight; the right-hand-side alignment will be perfect via the label's existing flex layout.

### One inconsistency uncovered while drafting

The current `tt-pd-cta` button in the hero variant uses `border-radius: 0.625rem` while the new widget shell uses `0.875rem`. Hero CTAs are a deliberately different visual register from the grid widgets — the smaller radius helps them read as buttons rather than cards. Keep the disparity. Documented here only because a polish-aware reviewer might call it out.

### Implementation effort

~150–200 lines net change to `persona-dashboard.css` (more deletions than additions), ~10 lines across two widget renderers, ~30 lines in `PersonaLandingRenderer` for the new header component, ~10 PO entries. Half a day for the code, another half for the visual review and translation pass.
