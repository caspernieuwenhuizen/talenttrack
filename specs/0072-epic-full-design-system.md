<!-- type: epic -->

# #0072 — full design system: tokens + per-component styling editor

## Problem

The v3.64 custom-CSS visual editor (#0064 Path C) ships 21 fields — 13 colours, 2 fonts, 2 weights, 2 corner radii, 1 spacing scale, 1 shadow strength. That covers tokens, not components. Clubs that want TalentTrack to look like *their* brand keep hitting the same gap:

- "Make the buttons match our pitchside scoreboard" — no per-button-variant control. Primary, secondary, ghost, danger all share `--tt-primary` / `--tt-accent` and there's no way to tune them independently.
- "Our forms look generic" — no input-state styling (focus, filled, error), no validation-message styling, no checkbox / radio / toggle controls, no helper-text token.
- "The tables look like a default WP plugin" — no header / row / striped / sticky styling.
- "Our chips are wrong" — status pills, badges, tags all share one set of colour tokens with no per-component override.
- "Cards need more shadow on hover" — shadow-strength is global, not per-state, not per-component.

On top of that, three concrete bugs in the v3.64 editor (POST-after-wp_head race on the toggle, `tt-tab-current` vs `.tt-tab-active` class mismatch, font fields rendered as text inputs instead of dropdowns matching `BrandFonts::displayOptions()`) make the existing surface feel half-finished. The full design-system pass is the right moment to fix them, but the bug fixes ship as a small companion hotfix so they're not gated on the multi-sprint epic landing.

The 21-field flat blob also stops scaling. ~80 foundation tokens + per-component variants want a structured `{tokens: {…}, components: {…}}` shape. The current `custom_css.frontend.visual_settings` JSON is migrated once into the new shape and old saves are upgraded.

## Proposal

A six-sprint epic on the existing `?tt_view=custom-css` surface, frontend-only (`SURFACE_FRONTEND` from `CustomCssRepository`). Each sprint adds a category to the editor's left-rail navigator + its tokens + per-component variants where applicable + live visual examples. Sprints ship as separate PRs against feature branches; the editor's left rail says "(coming soon)" for unfinished categories so real clubs can use what's there.

A small **Sprint 0 hotfix** ships first as a companion to land the v3.64 bugs before the editor surface starts changing under the user's feet.

### Sprint 0 — pre-epic hotfix companion (~3-4h)

Tiny, ships independently as `v3.71.x`. Companion to this epic, not part of it — listed here for traceability.

- POST → redirect (PRG) on `template_redirect` for the seven `tt_css_action` values in `FrontendCustomCssView::handlePost`. Fixes the toggle-doesn't-show-CSS-change bug — `wp_head` runs before the shortcode body today, so the same response renders pre-toggle CSS even though the DB has updated.
- `tt-tab-current` → `tt-tab-active` (or add the `.tt-tab-current` rule to `public.css`). The view emits the wrong class; `public.css:261` only styles `.tt-tab-active`. Pick one and stay consistent across all `.tt-tabbar` consumers (`FrontendCustomCssView`, plus any other tabbar in the codebase that landed on the wrong name).
- `font_display` + `font_body` → `<select>` populated from `BrandFonts::displayOptions()` / `bodyOptions()`, sanitized server-side against the catalogue. Removes the free-text input that today accepts any string and silently fails on non-Google fonts.

### Sprint 1 — Foundation tokens + restructured editor (~30h)

The chokepoint sprint. Everything else assumes the new token surface + structured storage exists.

- **Token expansion** from 21 → ~80 design-system primitives covering item 20 from the source list:
  - Colours: primary / secondary / accent / surface / background / border + success / warning / error / info — each with default + hover + active + disabled + subtle (low-opacity tint) variants.
  - Spacing scale: `--tt-sp-1` through `--tt-sp-8` as a geometric scale (multiplier from a base — current `spacing_scale` becomes the multiplier).
  - Borders: radii (`-sm` / `-md` / `-lg` / `-pill`), widths (`-thin` / `-medium` / `-thick`), styles (`-solid` / `-dashed`).
  - Shadows: small / medium / large + a focus-ring shadow.
  - Motion: duration (fast / base / slow), easing (in / out / in-out / standard).
  - Layout: container widths, breakpoints (echoes of `360 / 480 / 768 / 1024`), z-index ladder (`-dropdown` / `-modal` / `-tooltip` / `-notification`).
- **Structured storage migration**: one-time migration of v3.64 21-field flat `custom_css.frontend.visual_settings` JSON into the new `{tokens: {…}, components: {…}}` shape. Old saves are upgraded on first read; history rows stay in their original shape but get a `schema_version` column for round-trip safety.
- **Editor restructure**: left-rail navigator with category groups, right pane shows the current category. On viewports < 768px the rail collapses to a top dropdown so the layout stays mobile-first per CLAUDE.md § 2.
- **Live visual examples per token**: small live-rendered miniatures using the actual `.tt-*` DOM contracts (a real `.tt-btn`, `.tt-pill`, `.tt-input`, `.tt-card`) inside a `<div class="tt-preview-stage">…</div>` per category. Re-renders on every input change via `<style>` tag swap on the preview root only — no full-page reload. ~50 lines of vanilla JS per CLAUDE.md § 2.
- **REST endpoint**: `GET /design-system/tokens` + `PUT /design-system/tokens` under `talenttrack/v1`. Permission callback: `tt_admin_styling`. Per-sprint subsequent endpoints (e.g. `/design-system/components/buttons`) follow as each category lands. Return shape documented in `docs/rest-api.md`.
- **Mutex** with `theme_inherit` (#0023) preserved — saving custom design-system tokens for the frontend surface forces `theme_inherit=0`, same as today.

### Sprint 2 — Typography (~20h)

Item 1 of the source list, full coverage.

- Body / paragraphs / H1 / H2 / H3 / H4 / H5 / H6 / subheadings / captions / labels / helper / error / success / warning / info / inline code / code blocks / quotes / lists (ol + ul) / list items / inline links / strong / em / underline / strikethrough / small / overline / metadata / placeholder.
- Each gets the seven typography dimensions: font-family / size / weight / line-height / letter-spacing / colour / decoration. Family + colour reference Sprint 1 tokens by name (`--tt-font-display` etc.) so a token change cascades.
- Live visual example per type: rendered with the current settings inline.
- Editor section in left rail: "Typography" → opens a sub-list of the 22 types.

### Sprint 3 — Buttons + Links + Forms (~25h)

Items 2, 3, 4 of the source list.

- **Buttons**: primary / secondary / tertiary / ghost / text / icon / floating action / split / toggle / dropdown. States: default / hover / focus / active / disabled / loading / selected. Variants: success / warning / danger. Per CLAUDE.md § 2 — sizing stays consistent across states (48px touch target locked); only colour / background / border vary per state.
- **Links**: inline / navigation / footer / sidebar / breadcrumb / card / CTA. States: default / hover / visited / focus / active / disabled.
- **Form inputs**: single-line / password / number / search / email / phone / URL — each with default / focus / filled / error / disabled / read-only states. Selection inputs: checkbox / radio / toggle / select / multi-select / autocomplete. Textareas: standard / auto-growing. Date / time / range pickers. Sliders / steppers / colour pickers. Labels / validation messages / required indicators / tooltips / input groups.
- Visual examples for every variant × state combination using real `.tt-input` / `.tt-btn` / `.tt-link` DOM.

### Sprint 4 — Navigation + Tables + Cards + Lists (~25h)

Items 5, 6, 7, 11 of the source list.

- **Navigation**: top / side / bottom / tabs / pills / breadcrumbs / pagination / stepper / hamburger / mega / dropdown / context menus. States: active / hover / selected / disabled.
- **Tables**: container / headers / rows / cells / striped rows / expandable rows / sort indicators / filter controls / sticky headers / loading rows / selected rows. Plus badges / status indicators / action buttons inside cells.
- **Cards**: basic / product / profile / dashboard / stat / interactive — header / body / footer / media / action area. States: default / hover / selected.
- **Lists**: simple / navigation / selectable / expandable / definition. List item / divider / item actions / item icons.

### Sprint 5 — Feedback + Overlays + Search + Empty/Loading/Error (~20h)

Items 8, 9, 12, 18 of the source list.

- **Feedback**: alerts / toasts / notifications / banners / inline validation / progress bars / spinners / skeletons / status badges / chips / tags. Types: success / error / warning / info.
- **Overlays**: modal dialog / drawer / bottom sheet / popover / tooltip / lightbox / flyout panel. Backdrop / header / body / footer / close button.
- **Search**: search bar / results / suggestions / filter chips / filter panel / sort dropdown / advanced filters. States: active filters / empty results.
- **Empty / loading / error states**: empty state illustrations + copy slot, skeleton loaders, error fallback panel, offline state, retry button.

### Sprint 6 — Media + Dashboard widgets + Accessibility + polish (~20h)

Items 10, 14, 17, 19 of the source list, plus ship-along.

- **Media**: images / avatars / image galleries / video players / audio players / thumbnails / icons / illustrations. States: loading / error fallback.
- **Dashboard widgets**: KPI cards / charts / graphs / legends / metrics / trend indicators / data widgets. Charts integrate via existing `--tt-*` token names so chart libraries pick up the palette automatically.
- **Utility components**: dividers / containers / sections / accordions / collapse panels / carousels / timelines / trees / rating stars.
- **Accessibility**: focus-visible token (`--tt-focus-ring`) hardened, high-contrast fallback (`@media (prefers-contrast: more)` block emitted with bumped contrast), reduced-motion token (`@media (prefers-reduced-motion: reduce)` switches motion duration to `0.01ms`).
- **Reset / starter templates**: rebuild the three v3.64 templates (Fresh light / Classic football / Minimal) against the full token set so non-developer clubs land on a finished look in one click. Drop the old templates from history once the new set is live.
- **Docs**: `docs/design-system.md` + `docs/nl_NL/design-system.md` documenting the token taxonomy, the editor walk-through, the REST endpoint shape, and the safe-mode escape hatch (`?tt_safe_css=1` from #0064). Same `docs/<slug>.md` + `docs/nl_NL/<slug>.md` ship-along rule per DEVOPS.md.

## Wizard plan

**Exemption** — this is a settings surface, not a record-creation flow. Per CLAUDE.md § 3 settings panels are exempt from the wizard-first rule. The left-rail navigator is functionally a stepper but each section saves independently and there is no enforced order — same precedent as #0064 Path C.

## Out of scope

- **Item 15 (Commerce)** — TalentTrack is academy software; product listings, price labels, checkout forms have no surface here.
- **Item 16 (Mobile-specific gestures)** — swipe actions, pull-to-refresh, FAB are non-trivial JS work and don't fit the design-system token model. Separate idea if a club asks.
- **Item 13 (Authentication-specific styling)** — login / signup / password-reset / OTP / social-login are rarely brand-customized in academy software and would balloon the editor surface for a feature few clubs use. Separate idea if asked.
- **wp-admin coverage** — frontend surface only this round. The wp-admin `SURFACE_ADMIN` half of #0064 keeps its current 21-field editor. Revisit when the frontend surface lands.
- **Per-team / sub-club granularity** — club-level only, same as #0064 v1. Per-team styling is a separate spec if it's ever asked for.
- **Dark mode (`prefers-color-scheme: dark`)** — light-only in v1. Storage shape leaves room for `tokens.dark.<name>` so adding it later is one migration + a duplicate left-rail tab; not committed to v1.
- **Marketing site styling** — TalentTrack is the plugin; the club's WP marketing site is the WP theme's job.
- **CSS-in-JS / runtime-generated styles** — static CSS only, same as #0064.
- **Per-page overrides** — one design-system payload per surface, same as #0064.

## Acceptance criteria

### Sprint 0 hotfix (companion)

- Toggling Custom CSS off and reloading the dashboard shows zero `<style id="tt-custom-css-frontend">` in the response. Toggling on shows the saved CSS. Same response (no second reload required).
- Clicking each tab on `?tt_view=custom-css` changes the URL, the underlined tab indicator moves, and the right-pane content changes. Three out of four tabs do not look identical to the visual tab.
- Display + body font controls are `<select>` elements; submitting a value not in the catalogue is rejected server-side with a clear error.

### Sprint 1 (Foundation)

- `?tt_view=custom-css` opens with a left rail showing all six categories (some marked "(coming soon)") + a "Foundation" rail item that opens an accordion of the ~80 tokens grouped by colours / spacing / borders / shadows / motion / layout / z-index.
- Saving any token bumps the per-surface version counter. Inline `<style>` in the next page response carries the new value.
- Existing v3.64 saves render correctly after the storage migration. History rows from before the migration are still revertable.
- `GET /wp-json/talenttrack/v1/design-system/tokens` returns the structured token tree. `PUT` with a partial tree updates only the provided keys. Permission callback: `tt_admin_styling`.
- Live preview miniature in each category re-renders on input change without a page reload.
- Mobile (360px width): left rail collapses to a top dropdown; the editor stays usable per CLAUDE.md § 2 (no horizontal scroll, 48px touch targets, font-size ≥16px on inputs).
- Mutex with `theme_inherit` (#0023) preserved — saving custom tokens for the frontend surface forces `theme_inherit=0`.

### Per Sprint 2-6

Each sprint's PR ships:
- Its category in the left rail with no "(coming soon)" tag.
- All listed components / variants / states wired to the token system.
- Live visual examples for every variant × state combination.
- nl_NL.po updated in the same PR per the ship-along rule.
- `docs/design-system.md` updated with the section's coverage.
- No regression on already-shipped sprints (smoke-check by opening each prior category and saving without changes).

### No regression

- Mobile-first guarantees from CLAUDE.md § 2 hold across every category — 48px touch targets, 16px input font-size, no hover-only functionality, no horizontal scroll at 360px, semantic HTML.
- `?tt_safe_css=1` from #0064 still bypasses the entire surface.
- Existing v3.64 saved CSS continues to render correctly until the operator opens the new editor and re-saves (round-trip).
- The Branding tab's (academy name + logo + show-logo + tile-scale) keep working independently. Primary / secondary colours + fonts on the Branding tab become read-only with a "Edit colours and fonts in the Design system →" link to the new editor (locked decision, see Notes).

## Notes

### Sizing

| Sprint | Focus | Effort |
|--------|-------|--------|
| 0 | PRG hotfix + tab-class fix + font dropdowns (companion, ships independently) | ~3-4h |
| 1 | Foundation tokens + structured storage + restructured editor + REST + live preview | ~30h |
| 2 | Typography (22 element types × 7 dimensions) | ~20h |
| 3 | Buttons + Links + Forms (variants × states) | ~25h |
| 4 | Navigation + Tables + Cards + Lists | ~25h |
| 5 | Feedback + Overlays + Search + Empty/Loading/Error | ~20h |
| 6 | Media + Dashboard widgets + Accessibility + Templates rebuild + Docs | ~20h |

**Total: ~140h** for the epic (Sprint 0 not counted — separate hotfix). ~6 sprints across 2-3 months at typical cadence. Sprint 1 is the chokepoint; Sprints 2-6 can run in parallel after it lands but the recommendation is sequential so each sprint's PR review stays small.

### Hard decisions locked during shaping

These are decisions made in chat that the spec assumes; recording them so we don't re-litigate during build.

- **Frontend surface only.** wp-admin `SURFACE_ADMIN` keeps its v3.64 21-field editor for now.
- **State variants apply to colour / background / border tokens only.** Sizing + spacing stay consistent across states for accessibility (48px touch targets per CLAUDE.md § 2).
- **Editor UX shape**: left rail + right pane, collapses to top dropdown on mobile.
- **Visual examples**: live miniatures using real `.tt-*` DOM in a `<div class="tt-preview-stage">` per category, re-render on input change.
- **Storage shape**: structured `{tokens: {…}, components: {…}}` with one-time migration of v3.64 flat blob.
- **Branding-tab convergence**: primary / secondary colours + fonts on the Configuration → Branding tab become read-only with a link to the new design-system editor. Academy name + logo + show-logo + tile-scale stay on the Branding tab. Mutex with `theme_inherit` preserved.
- **Dark mode**: light-only in v1; storage shape leaves room for `tokens.dark.<name>`. Separate idea when asked.
- **Starter templates**: rebuilt against new token set in Sprint 6.
- **REST coverage**: ships in Sprint 1 alongside the PHP form.
- **PR cadence**: one PR per sprint. Each sprint ships visible to clubs (left rail tag updates from "(coming soon)" → live).
- **Sprint 0 hotfix is separate.** Ships before or independently of the epic so the bugs don't gate the spec landing.

### Cross-references

- **#0023** Styling options + WP-theme inheritance — primary / secondary colour + font controls move into the design-system editor; the Branding tab links across. Mutex with `theme_inherit` preserved.
- **#0064** Custom CSS independence — this epic builds on #0064's Path C visual editor. `?tt_view=custom-css`, `tt_admin_styling` cap, `?tt_safe_css=1` escape hatch, `CustomCssRepository` storage, `CssSanitizer` block-list, `tt_custom_css_history` table, mutex with theme-inherit — all kept.
- **#0021** Audit log — every design-system save writes one audit row, same pattern as #0064.
- **#0033** Authorization and module management — `tt_admin_styling` cap was already added in #0064; reused here.
- **#0052** SaaS-readiness REST + tenancy — every endpoint scopes by `CurrentClub::id()`; storage stays per-club, same as #0064.
- **`assets/css/frontend-admin.css`** + **`src/Shared/Frontend/BrandStyles.php`** — token consumers downstream of the editor; both already key off `--tt-*` so no consumer changes are needed for Sprint 1.
- **CLAUDE.md § 2** Mobile-first front end — every editor surface and every preview miniature must hold the 360px / 48px / 16px guarantees.

### Things to verify in the first 30 minutes of build

- Spike: how many of the new ~80 tokens already have a consumer in the existing CSS? If most don't, the visual-example previews won't reflect token changes until consumers are added — Sprint 1 needs a one-time pass through `frontend-admin.css` + `public.css` to wire every new token.
- Confirm the `wp_enqueue_code_editor` CodeMirror initialisation from #0064 still works on the new editor surface (the textarea path stays under "CSS editor" tab; the design-system editor is a new tab on the same view).
- Confirm `tt_custom_css_history` schema can carry the `schema_version` column without breaking the v3.64 history-list query.
- Audit `?tt_view=custom-css&surface=admin` to confirm wp-admin surface keeps its v3.64 editor unchanged through the Sprint 1 storage migration. The migration runs per-surface; admin's blob stays in flat shape until that surface is re-shaped (separate spec).
- Spike: live-preview miniature performance budget — 80 tokens × per-input recompile must stay under the 200ms first-interaction budget from CLAUDE.md § 2 on a Moto-G-class device. If it doesn't, debounce the recompile + render only the visible category's miniatures.
