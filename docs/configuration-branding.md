<!-- audience: admin -->

# Configuration & branding

The **Configuration** page is where your academy's identity and the plugin's operational knobs live.

## Tile-grid landing (v3.28.0+)

Visiting **Configuration** with no `?tab=` parameter shows a tile grid grouped by topic — Lookups & reference data, Branding & display, Authorization, System, Custom data, and Players & bulk actions. Each tile drills into either an in-page tab (the historical 14 lookup + branding + system tabs) or an existing top-level admin page (Custom Fields, Evaluation Categories, Authorization Matrix, Modules, etc.). Old `?page=tt-config&tab=<slug>` bookmarks still resolve.

The tab strip at the top is gone; from any in-page tab use the **← Configuration** link in the page title to return to the tile grid.

## Appearance surface (v4.26.13+)

On the frontend Configuration view, the former **Branding** and **Theme & fonts** tiles are consolidated into a single **Appearance** entry — so every brand colour lives in one place instead of being split across two tiles. Opening Appearance shows one page with stacked sections:

- **Identity** — academy name, club short code, logo.
- **Colours** — primary, secondary, and the full accent/status palette (accent, danger, warning, success, info, focus-ring), all together.
- **Typography** — display font and body font.
- **Theme isolation** — an informational note that TalentTrack always renders in full isolation from the active WordPress theme (read-only; there is no toggle).
- **Advanced** — a link into the Custom CSS editor.

No configuration keys changed and there is no data migration — existing values render unchanged. Save + Cancel sit at the bottom of the page. Old `?config_sub=branding` / `?config_sub=theme` deep links still resolve to the Appearance surface.

## Tile appearance (v4.33.0+)

The Appearance surface gains a **Tile appearance** dropdown that sets the size and column density of the tiles shown across the plugin — the dashboard tile grid, Configuration, the Reports launcher and the Teams "Team development" tiles — in one place, academy-wide.

| Preset | Effect |
| --- | --- |
| **Compact** | Denser tiles, more columns per row — fits more on screen. |
| **Comfortable** (default) | The standard tile size used before this release. |
| **Spacious** | Larger, roomier tiles with fewer columns per row. |

The setting is stored academy-wide under the `tile_appearance` configuration key and applies to every tile surface at once — there are no per-screen overrides. All presets reflow responsively: on a phone the grid collapses to a single column, and Spacious never causes horizontal scrolling.

A single shared standard now drives every tile's size and layout (`TileGridStandard`), so the surfaces stay visually identical to one another regardless of the chosen preset.

**Tile scale:** the older numeric **Tile scale** percentage (set on the wp-admin Configuration page) still works — it is applied as an additional multiplier on top of the chosen preset, so existing overrides keep their effect. When Tile scale is left at 100%, the preset alone governs tile sizing.

### Tile layout (v4.35.0+)

Alongside the size dropdown (now labelled **Tile size**), the Appearance surface gains a separate **Tile layout** dropdown. Layout and size are independent axes — any combination is valid (for example Spacious + Stacked).

| Layout | Effect |
| --- | --- |
| **Row (icon left of title)** (default) | The icon sits to the left, with the title and description stacked beside it. This is the arrangement used before this release — no visual change on upgrade. |
| **Stacked (icon + title, description below)** | The icon and title share the first line; the description spans the full tile width beneath. The icon is sized to span roughly two title rows, so a long title wraps to a second line next to the icon instead of widening the tile — the tile keeps its standard width. |

The layout applies everywhere a tile shows an icon: the dashboard tile grid always, and the Configuration / Reports / Modules tiles whenever a tile carries an icon. Tiles without an icon have no top line to share and render the same in either layout. Stored academy-wide under the `tile_layout` configuration key; default `row`.

## Full-canvas app & theme isolation (mandatory, v4.45.26+)

TalentTrack always renders as a full-canvas app, fully isolated from the active WordPress theme. There is **no opt-out** — full isolation is the contract (#1728). On the page that hosts the `[talenttrack_dashboard]` shortcode:

- the theme's header, footer, sidebar, menus and widgets are not rendered (canvas takeover, since v4.34.0); and
- **every non-TalentTrack stylesheet is dequeued before the page paints**, so the theme's `style.css` (and any other plugin's CSS) cannot override TalentTrack's palette, typography or layout.

The WordPress admin bar still appears for logged-in staff (it is a WordPress control, not theme chrome, and gives staff a one-click route back to wp-admin), and operator-chosen Google Fonts still load. Everything else from the theme is stripped.

Earlier releases (v4.34.0–v4.45.25) exposed a **Full-canvas app** checkbox and a **Theme inheritance** toggle that let an academy defer styling to the WP theme. Both were removed in v4.45.26 because they contradicted total visual independence: a theme that won specificity battles could poison the palette. To re-brand TalentTrack, use the **Colours**, **Typography** and **Logo** sections of Appearance, or **Custom CSS** — not the theme.

The canvas only takes over the page that hosts the `[talenttrack_dashboard]` shortcode; every other page on the site renders through the theme as usual. Print and export pages (match prep, PDP, methodology) are unaffected — they already render as standalone documents with no chrome.

## Frontend Configuration sections (v4.26.16+)

The frontend Configuration landing groups its tiles into purpose-based sections instead of one flat grid; a section with no visible (permitted) tiles renders no heading:

- **Appearance** — the consolidated Appearance surface + Custom CSS.
- **Dashboard** — Default dashboard, plus the filter-contributed Dashboard layouts / Custom widgets tiles.
- **Data & vocabularies** — Lookups, Rating scale, Players CSV import, Lookup canonical-language review.
- **Methodology & cycles** — PDP cycle blocks, Seasons, Player status methodology, and the VCT config tiles.
- **Integrations** — Spond.
- **System** — General, Feature toggles, Backups, Translations, Audit log, Setup wizard, wp-admin menus, Modules.

Tiles that open in wp-admin (Spond, Feature toggles, Backups, Translations, Audit log, Setup wizard) carry an external-link marker so the context switch is expected; the frontend tiles do not. Rendering goes through the shared `FrontendSectionedTileGrid`.

## Tabs

### General

- **Academy name** — used throughout the plugin and in printable reports
- **Logo URL** — shown in the frontend dashboard header and print output
- **Primary color** — tile accents, chart lines, headline figures
- **Rating scale max** — default is 5; you can change to 10 if your coaches prefer a 1–10 scale

### Lookups

Each lookup tab (Position, Age Group, Foot Option, Goal Status, Goal Priority, Attendance Status) is a simple list you can edit, reorder via drag, and extend with new entries.

**Translations** — every lookup edit form now has a Translations block with one row per installed site locale. Fill in the translated Name (and optionally Description) to control what your Dutch users see without shipping a plugin update. Leave a locale row empty to fall back to the canonical Name and any translation the plugin already ships in its `.po` file. Values you add yourself (e.g. a custom "Goalkeeper-Sweeper" position) can now be translated without touching code.

### Evaluation Types

Different flavors of evaluation: Training, Match, Tournament. Match types can be flagged as "Requires match details" which prompts coaches for opponent, competition, result, home/away, and minutes played when they create a match eval.

### Toggles

Feature toggles for things like the print module, certain frontend sections, audit trail. Flip on/off as needed.

### Audit

A read-only log of configuration changes for accountability.

## Drag to reorder

Lookup lists support drag-to-reorder (v2.19.0). Grab the ⋮⋮ handle on any row and drag. Order is saved automatically and immediately reflected in all dropdowns across the plugin.

## Curated styling

*Added in v3.8.0; theme inheritance removed in v4.45.26.* The Appearance surface lets a club brand the dashboard — fonts and a full semantic colour palette — without writing CSS. TalentTrack always renders in full isolation from the active WordPress theme (see *Full-canvas app & theme isolation* above), so branding is done entirely through these fields (or Custom CSS), never by deferring to the theme.

### Display font / Body font

Two dropdowns with curated [Google Fonts](https://fonts.google.com/) families.

- **Display** candidates are condensed / sporty (Oswald, Bebas Neue, Anton, Barlow Condensed…) — used for headings, tile titles, and player card numbers.
- **Body** candidates are clean sans-serifs plus a couple of serifs (Inter, Manrope, DM Sans, Source Serif 4…) — used for paragraphs, tables, and form labels.

Each dropdown has a non-Google entry at the top:

- **(System default)** — no Google Fonts request; falls through to TalentTrack's default font stack.

When at least one dropdown picks a curated family, the plugin enqueues a single combined Google Fonts request (display + body together, with the weights TalentTrack actually uses). Google Fonts is the one external stylesheet that survives canvas isolation.

### Color pickers

Six semantic colors, each backed by a `--tt-*` CSS custom property used throughout the dashboard:

| Field | Token | Used for |
| --- | --- | --- |
| Accent color | `--tt-accent` | Highlights, charts |
| Danger color | `--tt-danger` | Delete buttons, error banners, validation states |
| Warning color | `--tt-warning` | Warning banners, "Partial" attendance pills |
| Success color | `--tt-success` | Success banners, "Saved" feedback, complete states |
| Info color | `--tt-info` | Info banners |
| Focus ring color | `--tt-focus-ring` | Keyboard focus outlines |

Leaving a field empty restores the default token from the plugin's stylesheet. The operator's chosen colours are injected as `:root` custom properties and, because canvas mode strips the active theme's CSS, nothing can override them.

### Backward compatibility

The existing **Primary color** and **Secondary color** fields keep working unchanged. The new fields are additive — installs that don't touch them see no visual difference.
