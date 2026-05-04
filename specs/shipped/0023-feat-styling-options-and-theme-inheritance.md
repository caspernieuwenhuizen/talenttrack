<!-- type: feat -->

# #0023 — Styling options + WP-theme inheritance

## Problem

The plugin's frontend dashboard does not inherit the surrounding WordPress theme's visual identity. The existing **Branding** tab in [`ConfigurationPage.php:397-421`](../src/Modules/Configuration/Admin/ConfigurationPage.php#L397) exposes only academy name, logo, and two color pickers (primary + secondary), and [`BrandStyles.php`](../src/Shared/Frontend/BrandStyles.php) injects those as `:root` CSS variables. Buttons, links, headings, and body type all keep TalentTrack's defaults regardless of the surrounding theme.

The current escape hatch is a per-install custom theme that overrides plugin tokens at higher specificity (a pilot client theme built April 25 demonstrates the pattern). It works, but every club shouldn't need a bespoke theme to make the dashboard match its brand. It should be a setting.

## Proposal

Two-part feature, both landing in the existing **Branding** tab:

1. **Single global toggle** — "Inherit WordPress theme styles for shared elements." When ON, typography (`font-family`), link color, button background, and heading family on the dashboard defer to the surrounding theme via the cascade. When OFF, the plugin keeps its current rendering.
2. **Curated styling fields for TT surfaces** — display + body font dropdowns (Google Fonts) and six semantic color pickers (accent + danger / warning / success / info + focus ring) that map directly onto the existing `--tt-*` token names.

Decisions locked during shaping (25 April 2026):

- **Single global toggle**, not per-property. Most clubs want all-in or all-out; per-property is configurability nobody asked for.
- **Curated Google Fonts dropdown** with ~12 display + ~12 body candidates. Plugin handles the enqueue. Free-text option deferred — typo risk too high for a one-shot config field.
- **Semantic tokens only** (accent + status colors). No per-component fields. Player card tier tokens stay locked.
- **Extend the existing Branding tab**, no new tab. All visual config in one place; one docs page.

## Scope

### Branding tab additions

Append to `tab_branding()` in `ConfigurationPage.php`, below the existing Primary/Secondary color rows:

| Field | Type | Storage key | Default |
| --- | --- | --- | --- |
| Inherit WordPress theme styles | checkbox | `theme_inherit` | `0` |
| Display font | `<select>` (curated list) | `font_display` | `''` (system) |
| Body font | `<select>` (curated list) | `font_body` | `''` (system) |
| Accent color | color picker | `color_accent` | `#1e88e5` |
| Danger color | color picker | `color_danger` | `#b32d2e` |
| Warning color | color picker | `color_warning` | `#dba617` |
| Success color | color picker | `color_success` | `#00a32a` |
| Info color | color picker | `color_info` | `#2271b1` |
| Focus ring color | color picker | `color_focus` | `#1e88e5` |

Existing `academy_name`, `logo_url`, `primary_color`, `secondary_color` keys are preserved unchanged for backward compatibility. New keys are additive.

A short help paragraph at the top of the new section explains the toggle's behavior in one sentence and what falls back when it's ON.

### Curated Google Fonts list

Defined as a PHP constant in `BrandStyles.php` (or a new `BrandFonts.php` if cleaner). Approximate set — final list tuned during implementation:

**Display candidates** (uppercase-friendly, sporty/condensed): Barlow Condensed, Oswald, Bebas Neue, Anton, Saira Condensed, Fjalla One, Archivo Black, Teko, Big Shoulders Display, Russo One.

**Body candidates** (clean sans + a couple of serifs): Inter, Manrope, Plus Jakarta Sans, DM Sans, Work Sans, IBM Plex Sans, Source Sans 3, Nunito Sans, Outfit, Sora, Merriweather, Source Serif 4.

Plus two non-Google entries at the top of each dropdown:
- `(System default)` — empty value, no font enqueue, falls through to TalentTrack's existing default stack.
- `(Inherit from theme)` — only meaningful when the inherit toggle is ON; otherwise behaves like System default.

### `BrandStyles.php` extensions

Three additions to `injectVars()`:

1. **Read the new keys** alongside `primary_color` / `secondary_color`, with sensible defaults from the table above.
2. **Emit additional tokens** in the `<style id="tt-brand-vars">` block:
   - `--tt-accent`, `--tt-danger`, `--tt-warning`, `--tt-success`, `--tt-info`, `--tt-focus-ring`
   - `--tt-font-display`, `--tt-font-body` (only emitted if the chosen value is not `(System default)` / `(Inherit from theme)`)
3. **Add a body class** via `body_class` filter: `tt-theme-inherit` when the toggle is ON. Cheap, reversible, no page reload semantics.

Font enqueue: a separate `wp_enqueue_scripts` hook in `BrandStyles` that registers a single Google Fonts request combining the chosen display + body families with the weights TalentTrack actually uses (400, 600, 700 for body; 600, 700, 800 italic for display — match the existing player card weights). Skip the enqueue when both fonts are System default or Inherit.

### CSS theme-inheritance section

New block in `assets/css/frontend-admin.css` under a fresh `/* ─── Theme inheritance ─── */` heading. Lands after Sprint 2's `/* ─── FrontendListTable ─── */` block to avoid merge conflict.

Rules apply only when the toggle is ON (selector requires `body.tt-theme-inherit`):

```css
/* Defer typography to host theme */
body.tt-theme-inherit .tt-dashboard,
body.tt-theme-inherit .tt-dashboard p,
body.tt-theme-inherit .tt-dashboard li {
    font-family: inherit;
    color: inherit;
}
body.tt-theme-inherit .tt-dashboard h1,
body.tt-theme-inherit .tt-dashboard h2,
body.tt-theme-inherit .tt-dashboard h3,
body.tt-theme-inherit .tt-dashboard h4 {
    font-family: inherit;
    color: inherit;
}
/* Defer link color to host theme */
body.tt-theme-inherit .tt-dashboard a {
    color: inherit;
}
/* Defer plain button styles to host theme; keep .tt-pc and tile-grid styles */
body.tt-theme-inherit .tt-dashboard button.button-primary,
body.tt-theme-inherit .tt-dashboard button[type="submit"]:not(.tt-pc *):not(.tt-tile *) {
    background: revert;
    color: revert;
    border-color: revert;
}
```

Importantly: TT-only surfaces (`.tt-pc` player card and tile grid) are explicitly excluded from button reset so their tier/brand styling survives.

### Token override layering

Order of CSS specificity (lowest → highest):

1. Plugin defaults in `frontend-admin.css` (`.tt-dashboard { --tt-primary: ...; }`)
2. `BrandStyles` injected `:root` overrides (always wins for tokens consumed at `:root` scope; loses to `.tt-dashboard`-scoped tokens).
3. Custom theme `body .tt-dashboard { ... }` overrides (the child-theme escape hatch) still win — same approach as today.

Net: clubs without a custom theme get the new fields working out of the box. Clubs with a custom theme keep their existing override path.

## Out of scope (v1)

- Customizing TalentTrack's wp-admin pages (they keep WP-admin defaults — most clubs don't theme wp-admin).
- Per-team or per-page styling.
- Editing player card tier colors (gold/silver/bronze are an intentional brand artifact).
- Self-hosted font uploads (defer; Google Fonts dropdown + system stack covers the cases).
- Live-preview customizer experience (Settings page form is fine; preview happens by viewing the dashboard).
- Rewriting the legacy `public.css` to remove its hardcoded values. Where it blocks inheritance, the new theme-inheritance section overrides at higher specificity. Full `public.css` cleanup is a separate concern (see #0012-style refactor).
- Per-property toggles (rejected during shaping).

## Acceptance criteria

- [ ] **Toggle**: Branding tab has a "Inherit WordPress theme styles" checkbox; saving persists `theme_inherit` config key.
- [ ] **Body class**: when toggle is ON, every frontend page has `<body class="... tt-theme-inherit ...">`. When OFF, that class is absent.
- [ ] **Font dropdowns**: display + body selects render the curated list. Saving persists `font_display` / `font_body` keys.
- [ ] **Font enqueue**: the chosen Google Fonts are loaded on every frontend page (one combined request). System default / Inherit values trigger no font request.
- [ ] **Color tokens**: the six new color pickers all save and emit a corresponding `--tt-*` token in the `<style id="tt-brand-vars">` block in `<head>`.
- [ ] **Inheritance behavior** (toggle ON, against a default-WP theme like Twenty Twenty-Five):
  - Dashboard body text uses theme's font-family.
  - `h1`–`h4` inside `.tt-dashboard` use theme's heading font.
  - Plain links use theme's link color.
  - `.button-primary` falls back to theme's button styling.
  - Player card visual identity is unchanged (tiers still gold/silver/bronze).
  - Dashboard tile grid borders + accents keep their TT-token-driven look.
- [ ] **Inheritance behavior** (toggle OFF): visuals match current behavior. No regression vs main.
- [ ] **Pilot client theme compatibility**: when this feature ships, the pilot client theme can drop its `body .tt-dashboard { ... }` override block and rely on the toggle. Verified by switching the pilot install from override-only to toggle-only and confirming the dashboard still looks right.
- [ ] **Docs**: `docs/configuration-branding.md` + `docs/nl_NL/configuration-branding.md` cover the new fields with one screenshot of the extended Branding tab.
- [ ] **Translations**: `languages/talenttrack-nl_NL.po` has Dutch strings for every new label.
- [ ] **No regression** on the wp-admin Configuration page or the existing primary/secondary color rendering.

## Notes

### What "inherit" actually does — the honest framing

Some properties cascade reliably (font-family, color). Others don't (background, padding, border-radius). The toggle's effect:

- **Typography**: full inheritance. Theme's `body { font-family }` cascades through `inherit`.
- **Link color**: full inheritance.
- **Heading color + family**: full inheritance.
- **Buttons**: best-effort — plugin's button-background overrides are reverted, but the host theme's button styles only apply if the theme uses selectors that match the plugin's button DOM (most don't — themes style `.wp-block-button__link`, plugin uses `.button-primary` / `button[type="submit"]`). In practice the inherited button looks like a UA-default button on most themes, which is acceptable. Themes that style by element (`button { ... }`) get full inheritance.
- **Spacing, borders, shadows**: not inherited — plugin's structural CSS stays.

The Branding tab's help text mentions this honestly: "Inheritance applies to fonts, colors, and basic links/buttons. TalentTrack's structural design (spacing, layout, player cards) is unchanged."

### Storage layer

Reuse the existing `QueryHelpers::set_config` / `get_config` pattern (option-table backed). No schema change. No new table. Backward-compatible: missing keys read defaults from the table in the Scope section.

### Touches

Existing:
- `src/Modules/Configuration/Admin/ConfigurationPage.php` — extend `tab_branding()` rendering + the `handle_save_config()` pass-through (already accepts arbitrary `cfg[*]` keys via `sanitize_key` + `sanitize_text_field`, so new fields work without handler changes; verify color picker values pass sanitization).
- `src/Shared/Frontend/BrandStyles.php` — extend `injectVars()`, add `body_class` filter, add `wp_enqueue_scripts` for fonts.
- `assets/css/frontend-admin.css` — append `/* ─── Theme inheritance ─── */` section.
- `docs/configuration-branding.md` + `docs/nl_NL/configuration-branding.md` — document the new fields.
- `languages/talenttrack-nl_NL.po` — Dutch labels for every new string.

Possibly new:
- `src/Shared/Frontend/BrandFonts.php` — if the curated font list grows beyond a const array. Otherwise inline in `BrandStyles`.

### Depends on

- **#0019 Sprint 2 must merge first.** Sprint 2 adds a `/* ─── FrontendListTable ─── */` block to `frontend-admin.css`; this work appends a `/* ─── Theme inheritance ─── */` block after it. Section locality keeps the merge mechanical.

### Blocks

Nothing in the existing backlog. #0011 Sprint 4 (TalentTrack product branding for marketing) is a different layer — it operates on plugin headers, marketing site, and Freemius surfaces, not per-install club styling. No conflict.

### Sequence position

Phase 1 follow-on. Lands after Sprint 2 ships. Slot whenever it fits the user's velocity.

### Sizing

~6–8 hours:

| Work | Hours |
| --- | --- |
| Branding tab fields + curated font list | 1.5 |
| `BrandStyles.php` extensions (tokens, body class, font enqueue) | 1.5 |
| `frontend-admin.css` theme-inheritance section | 1.5 |
| Docs (`configuration-branding.md` + nl_NL translation) | 1.0 |
| `nl_NL.po` updates | 0.5 |
| Testing across 2–3 themes (Twenty Twenty-Five, pilot client theme, one block theme) | 1.0 |
| Buffer for `public.css` legacy override surprises | 1.0 |
| **Total** | **~8h** |

Single PR, single release (next minor — likely v3.8.0).

### Cross-references

- Idea origin: [`ideas/0023-feat-styling-options-and-theme-inheritance.md`](../ideas/0023-feat-styling-options-and-theme-inheritance.md).
- Plugin CSS architecture survey conducted during a pilot client theme build (April 25, 2026): documents the `.tt-dashboard`-scoped token system in `frontend-admin.css` and the ~45% hardcoded legacy in `public.css`.
- Adjacent: #0011 Sprint 4 (product brand, not in scope here), #0012 (anti-AI-fingerprint cleanup, separate concern).
