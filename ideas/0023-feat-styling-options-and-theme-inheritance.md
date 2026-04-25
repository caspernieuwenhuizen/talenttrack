<!-- type: feat -->

# Styling options + WP-theme inheritance for TalentTrack surfaces

Raw idea (carved out during the JG4IT theme build, 25 April 2026):

The plugin's frontend dashboard does not inherit the surrounding WordPress theme's visual identity. The existing **Branding** tab in `ConfigurationPage.php:397-421` only exposes academy name, logo, and two color pickers (primary + secondary), and `BrandStyles.php` injects those as `:root` CSS variables. That's not enough for a club whose host site has been carefully designed — buttons, links, headings, and body type all keep TalentTrack's defaults regardless of theme.

The escape hatch today is a per-install custom theme that overrides plugin tokens at higher specificity (which is what the JG4IT theme does). Useful, but every club shouldn't need a bespoke theme to make the dashboard match their brand. It should be a setting.

Two-part feature:

1. **Toggle: "Inherit WordPress theme styles for shared elements."** When ON, any visual property where TalentTrack and the host theme overlap (typography, link color, button style, heading family, body color) defers to the theme cascade. When OFF, the plugin keeps its current behavior.
2. **Styling fields for TT-only elements.** For surfaces with no theme equivalent (player cards, dashboard tile borders, evaluation accents, status pills, etc.), expose explicit color / font fields so they can be tuned per-install without writing CSS.

## What needs a shaping conversation

Before this becomes a spec, answer:

1. **Where does this live in the admin UI?** Extend the existing "Branding" tab, or split into a new "Styling" tab alongside it? Branding-tab keeps it in one place; new tab keeps it from getting cluttered as fields multiply.
2. **Granularity of the inherit toggle.** Single global toggle, or per-property toggles (typography on, link color off, button style on, etc.)? Per-property is more flexible but more UX surface.
3. **What counts as a "shared element" vs "TT-only"?** Need an explicit, written list. Shared candidates: `body` font, `h1–h4` family, link color, button background, focus ring color. TT-only candidates: player card tier tokens (gold/silver/bronze — almost certainly locked), dashboard tile borders, evaluation accent stripes, status pill colors, success/warning/danger.
4. **Font handling.** Google Fonts dropdown (curated list), free-text family name, or self-hosted upload? Dropdown is the cleanest UX but coupled to a fixed list; free-text is flexible but easy to typo.
5. **Existing `primary_color` / `secondary_color` keys.** Keep them as-is (alongside new fields), or migrate them into a more structured token bag? Backward-compat says keep + extend.
6. **Scope of inheritance.** Frontend dashboard only, or also TalentTrack's wp-admin pages? Most clubs probably don't theme wp-admin, so frontend-only is the likely answer.
7. **Per-component color overrides.** Single accent + danger/warning/success, or per-component (player card, tile, evaluation, goal)? The 80% answer is shared semantic tokens (success/warning/danger/accent) plus locked player card tiers.
8. **How does the toggle actually suppress styles?** Body-class scoping (`<body class="tt-theme-inherit">` + CSS rules that `unset` / `inherit`) is the cheapest implementation. Confirm this is acceptable vs the alternative of conditionally dequeuing parts of `public.css`.
9. **Migration of the JG4IT theme.** When this lands, the JG4IT theme should drop its `body .tt-dashboard { ... }` override block and rely on the toggle instead. Worth verifying nothing regresses.

## Rough scope (before shaping)

- Extend `tab_branding()` in `ConfigurationPage.php` with:
  - Inherit-theme toggle (boolean).
  - Display + body font fields.
  - Accent + status (danger/warning/success/info) color pickers.
- Extend `BrandStyles.php` to:
  - Emit `--tt-font-display` and `--tt-font-body` tokens.
  - Add `tt-theme-inherit` body class when toggle is ON.
  - Inject the new color tokens.
- Add a `/* ─── Theme inheritance ─── */` section to `frontend-admin.css` with rules keyed off `body.tt-theme-inherit .tt-dashboard` that `unset` / `inherit` the shared properties.
- Document the canonical override path so future themes (like the JG4IT one) keep working without modification.

## Out of scope (for v1)

- Customizing the wp-admin TalentTrack pages (they should keep WP-admin defaults).
- Per-team or per-page styling (it's a single sitewide config).
- Editing player card tier colors (they're an intentional brand artifact).
- Self-hosted font uploads (defer; rely on Google Fonts or system stack for v1).
- A live-preview customizer experience (Settings page form is fine for v1).
- Touching the legacy `public.css` rewrite. Hardcoded values that block inheritance get overridden via the new section in `frontend-admin.css`, not by editing `public.css` itself — that's a #0012-style cleanup, not this feature.

## Independence

- **Independent of #0019 Sprint 2** (REST list endpoints + `FrontendListTable`). Zero file overlap with that sprint's PRs (`SessionsRestController`, `GoalsRestController`, `FrontendListTable.php`, `frontend-list-table.js`, the new view classes).
- The only shared file is `assets/css/frontend-admin.css`. Sprint 2 adds a `/* ─── FrontendListTable ─── */` block; this work would add a `/* ─── Theme inheritance ─── */` block. Section locality keeps the merge mechanical.
- **Conceptually adjacent to #0011 Sprint 4 ("Branding + marketing site"),** but #0011 is about TalentTrack's own product brand (logo, marketing site, Freemius surfaces), not per-install club styling. Different layer; no conflict.
- **Sequence requirement: ship after Sprint 2 merges.** Easier to rebase the small CSS section than to rebase Sprint 2 around it.

## Touches (when specced)

Existing:
- `src/Modules/Configuration/Admin/ConfigurationPage.php` — extend `tab_branding()` + `handle_save_config()`.
- `src/Shared/Frontend/BrandStyles.php` — extend with new tokens + body class wiring.
- `assets/css/frontend-admin.css` — new `/* ─── Theme inheritance ─── */` section.
- `docs/configuration-branding.md` + `docs/nl_NL/configuration-branding.md` — document the new fields.
- `languages/talenttrack-nl_NL.po` — Dutch strings for new labels.

Likely new:
- None. Extension, not a new module.

## Sequence position

Phase 1 follow-on. Lands after #0019 Sprint 2 ships; can be slotted between sprints where it fits velocity. Not blocking anything in the existing backlog.

## Estimated effort

~6–8 hours once shaped:
- New form fields + handlers in Branding tab (~1.5h)
- `BrandStyles.php` extensions + body-class wiring (~1h)
- CSS inheritance rules in `frontend-admin.css` (~1.5h)
- Docs + `.po` updates (~1h)
- Testing across a few theme setups + the JG4IT theme (~1.5h)
- Buffer for surprises in legacy `public.css` overrides (~1h)

Higher end (~10h) if per-property toggles are chosen over a single global toggle.
