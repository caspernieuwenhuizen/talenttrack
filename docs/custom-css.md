<!-- audience: admin -->

# Custom CSS

> User-spec for the #0064 custom-CSS module shipped in v3.64.0. Lets a club admin make TalentTrack look exactly the way they specify, regardless of what WordPress theme is active. Companion to the [Branding](configuration-branding.md) page (#0023), which goes the *opposite* direction — defer to the active theme. The two are mutually exclusive on the same surface.

## What it does

A club admin can change colours, fonts, corners, spacing and shadows on the TalentTrack frontend dashboard, on the wp-admin TalentTrack pages, or both. There are three authoring paths plus a four-tab landing surface, all reached at `?tt_view=custom-css` (or via **Configuration → Custom CSS**):

- **Visual settings** (Path C) — pick colours, fonts, weights, corner radius, spacing scale, shadow strength via dropdowns and pickers. Save round-trips into a generated CSS body that lives in the same storage as the other paths.
- **CSS editor** (Path B) — write CSS by hand. The textarea uses the WordPress code editor (CodeMirror) for syntax highlighting + line numbers. A "Preview in new tab" link opens the dashboard so changes can be seen live.
- **Upload + templates** (Path A) — drop in a `.css` file, or apply one of three starter templates (Fresh light / Classic football / Minimal — all light-leaning).
- **History** — the last 10 auto-saves plus any named presets. Click **Revert** to restore an earlier save (which itself becomes a fresh auto-save row, so the revert is undoable).

A surface switcher at the top toggles between **Frontend dashboard** (the `[tt_dashboard]` shortcode + everything under it) and **wp-admin pages**. Each surface has its own enabled toggle and its own CSS payload.

## How it stays out of trouble

- **Scoped class isolation** — every TalentTrack surface wraps in a `tt-root` body class. Custom CSS rules should be prefixed with `.tt-root` so the WordPress theme's CSS can't reach in. The starter templates and the visual editor's generator output already do this; the textarea and file-upload paths trust the operator to follow the convention.
- **Block-list sanitization on save** — the saver rejects JavaScript URLs (`url(javascript:…)`), `expression()`, `behavior:`, `-moz-binding`, remote `@import`, and external `@font-face` URLs. Every save runs through this filter before the CSS reaches the database; rejected payloads return an inline error pointing at the offending fragment.
- **200 KB hard cap** — bigger than 200 KB and the save is rejected. That's about 10× the size of the entire bundled `frontend-admin.css`, so it's only a backstop against accidental paste of an entire site stylesheet.
- **Mobile-first guarantee** — the plugin's base mobile-first stylesheet always loads first; custom CSS layers after. Path C deliberately doesn't expose layout-affecting controls (no breakpoints, no flex direction overrides). Paths A and B come with a documented warning that overriding layout properties is at the club's own risk.
- **Mutex with #0023 theme inheritance** — turning custom CSS on for the Frontend surface automatically turns the **Theme inheritance** toggle off. The two surfaces are never both active on the same page; the UI nudges you to that boundary so the configuration space stays simple.

## Safe mode

Add `?tt_safe_css=1` to any URL and TalentTrack skips the custom CSS for that pageview. That gives a non-technical admin a recovery path if a save broke the layout — open the dashboard with the safe-mode URL, navigate to **Configuration → Custom CSS → History**, and revert to the last good snapshot.

## Visual editor reference

The Path C form maps form fields to `--tt-*` CSS custom properties on `.tt-root`. The full list:

| Field | Token | Notes |
|-------|-------|-------|
| Primary | `--tt-primary` | Headline accent — buttons, top tile colour. |
| Secondary | `--tt-secondary` | Pull-out colour — pills, accent borders. |
| Accent | `--tt-accent` | Reuses the primary in most templates. |
| Success | `--tt-success` | Attendance "present", positive trend arrows. |
| Info | `--tt-info` | Neutral chips, info banners. |
| Warning | `--tt-warning` | Amber pill, "review needed" states. |
| Danger | `--tt-danger` | Destructive buttons, "absent" attendance. |
| Focus ring | `--tt-focus-ring` | Keyboard-focus outline colour. |
| Background | `--tt-bg` | Page background. |
| Card surface | `--tt-surface` | Tile / panel / card fill. |
| Text | `--tt-text` | Default text colour. |
| Muted text | `--tt-muted` | Field hints, meta lines. |
| Lines + borders | `--tt-line` | Field borders, table dividers. |
| Display font | `--tt-font-display` | Headings + the FIFA-card name. |
| Body font | `--tt-font-body` | Everything else. |
| Body weight | `--tt-fw-body` | 300 / 400 / 500 / 600 / `normal` / `bold`. |
| Heading weight | `--tt-fw-heading` | 500–800. |
| Corner radius — medium | `--tt-r-md` | Cards, inputs, buttons. 0–32 px. |
| Corner radius — large | `--tt-r-lg` | Hero cards, modals. 0–40 px. |
| Spacing scale | `--tt-spacing-scale` | Multiplier on the `--tt-sp-*` scale. 0.6–1.6. |
| Shadow strength | n/a | `none` / `light` / `strong` — toggles drop-shadows on cards/panels/tiles. |

Anything not set falls through to TT's bundled defaults.

## Starter templates

Three light-leaning starting points. Each replaces the live CSS for the active surface; use **History → Revert** if you change your mind.

- **Fresh light** — soft mint + teal palette with rounded corners and a soft-shadow card style. Reads bright in daylight; works well for academies that lean modern.
- **Classic football** — forest green + gold + cream — the traditional academy crest palette. Sharper corners and a slightly heavier card border for a club-shop feel.
- **Minimal** — neutral greys with one charcoal accent. Skips drop shadows and rounds corners only slightly. Sits behind any club brand without competing with it.

## Capabilities

| Capability | Granted to | What it allows |
|------------|------------|----------------|
| `tt_admin_styling` | Administrator, Club Admin | View the Custom CSS surface; save, upload, apply templates, save presets, and revert from history. |

Coaches, scouts and staff don't get this by default. A club that wants to delegate styling to a "marketing manager" role can grant the cap on a custom role via the Roles & rights admin page.

## Storage

The "live" payload lives in `tt_config`, keyed `custom_css.<surface>.css` / `.enabled` / `.version` / `.visual_settings` (where `<surface>` is `frontend` or `admin`). The `tt_custom_css_history` table holds the rolling last-10 auto-saves + any named presets. Both are scoped to `club_id` per the SaaS-readiness baseline (#0052).

## Out of scope

- Marketing site styling — TalentTrack is the plugin only. The club's WP marketing site is the active theme's job.
- Per-page CSS overrides — one CSS payload per surface, not one per page.
- JavaScript injection — strictly CSS. No `<script>` tags, no JS uploads.
- Custom HTML / template overrides — out of scope, never. The plugin owns its templates.
- Per-team or per-coach styling — club-level only.
