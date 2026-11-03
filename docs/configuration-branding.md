---
title: Configuration & branding
group: configuration
summary: Academy name, logo, rating scale, color palette, lookup tables.
audience: [admin]
views: [configuration]
capability: tt_view_branding
order: 20
---

# Configuration & branding

The **Configuration** page is where your academy's identity and the plugin's operational knobs live.

## Tile-grid landing

Visiting **Configuration** with no `?tab=` parameter shows a tile grid grouped by topic — Lookups & reference data, Branding & display, Authorization, System, Custom data, and Players & bulk actions. Each tile drills into either an in-page tab (the historical 14 lookup + branding + system tabs) or an existing top-level admin page (Custom Fields, Evaluation Categories, Authorization Matrix, Modules, etc.). Old `?page=tt-config&tab=<slug>` bookmarks still resolve.

The tab strip at the top is gone; from any in-page tab use the **← Configuration** link in the page title to return to the tile grid.

## Appearance surface

On the frontend Configuration view, the former **Branding** and **Theme & fonts** tiles are consolidated into a single **Appearance** entry — so every brand colour lives in one place instead of being split across two tiles. Opening Appearance shows one page with stacked sections:

- **Identity** — academy name, club short code, logo.
- **Colours** — primary, secondary, and the full accent/status palette (accent, danger, warning, success, info, focus-ring), all together.
- **Typography** — display font and body font.
- **Theme isolation** — an informational note that TalentTrack always renders in full isolation from the active WordPress theme (read-only; there is no toggle).
- **Advanced** — a link into the Custom CSS editor.

No configuration keys changed and there is no data migration — existing values render unchanged. Save + Cancel sit at the bottom of the page. Old `?config_sub=branding` / `?config_sub=theme` deep links still resolve to the Appearance surface.

## Tile appearance

The Appearance surface gains a **Tile appearance** dropdown that sets the size and column density of the tiles shown across the plugin — the dashboard tile grid, Configuration, the Reports launcher and the Teams "Team development" tiles — in one place, academy-wide.

| Preset | Effect |
| --- | --- |
| **Compact** | Denser tiles, more columns per row — fits more on screen. |
| **Comfortable** (default) | The standard tile size used before this release. |
| **Spacious** | Larger, roomier tiles with fewer columns per row. |

The setting is stored academy-wide under the `tile_appearance` configuration key and applies to every tile surface at once — there are no per-screen overrides. All presets reflow responsively: on a phone the grid collapses to a single column, and Spacious never causes horizontal scrolling.

A single shared standard now drives every tile's size and layout (`TileGridStandard`), so the surfaces stay visually identical to one another regardless of the chosen preset.

**Tile scale:** the older numeric **Tile scale** percentage (set on the wp-admin Configuration page) still works — it is applied as an additional multiplier on top of the chosen preset, so existing overrides keep their effect. When Tile scale is left at 100%, the preset alone governs tile sizing.

### Tile layout

Alongside the size dropdown (now labelled **Tile size**), the Appearance surface gains a separate **Tile layout** dropdown. Layout and size are independent axes — any combination is valid (for example Spacious + Stacked).

| Layout | Effect |
| --- | --- |
| **Row (icon left of title)** (default) | The icon sits to the left, with the title and description stacked beside it. This is the arrangement used before this release — no visual change on upgrade. |
| **Stacked (icon + title, description below)** | The icon and title share the first line; the description spans the full tile width beneath. The icon is sized to span roughly two title rows, so a long title wraps to a second line next to the icon instead of widening the tile — the tile keeps its standard width. |

The layout applies everywhere a tile shows an icon: the dashboard tile grid always, and the Configuration / Reports / Modules tiles whenever a tile carries an icon. Tiles without an icon have no top line to share and render the same in either layout. Stored academy-wide under the `tile_layout` configuration key; default `row`.

### Tile colour scheme

Alongside **Tile size** and **Tile layout**, the Appearance surface gains a **Tile colour scheme** dropdown. Colour is a third independent axis — it recolours the dashboard tiles (border, fill and accent) without changing their size or layout, so any combination is valid. The scheme applies to the dashboard tile grid (`.tt-ftile`).

| Scheme | Effect |
| --- | --- |
| **Default** | White fill, hairline grey border, a 3px brand-green left accent and a faint green tint on hover. |
| **Brand border** | White fill with a full 1.5px brand-green border. |
| **Gold-topped** (default) | A full brand-green border plus a 3px gold top edge, echoing the dashboard bar's gold underline. |
| **Soft green fill** | A light brand-green tint fill with a green border; the icon chip flips to white. |
| **Solid green** | Tiles match the top bar — dark green fill, gold bottom accent, white text and a translucent icon chip. |
| **Left accent** | White fill with a thick 4px green left edge that turns gold on hover. |

Stored academy-wide under the `tile_style` configuration key; default `gold-topped`. The colours come from the brand tokens (`--tt-primary`, `--tt-secondary`), so they follow the academy's Primary/Secondary colour choices automatically.

## Full-canvas app & theme isolation (mandatory,)

TalentTrack always renders as a full-canvas app, fully isolated from the active WordPress theme. There is **no opt-out** — full isolation is the contract. On the page that hosts the `[talenttrack_dashboard]` shortcode:

- the theme's header, footer, sidebar, menus and widgets are not rendered (canvas takeover, since v4.34.0); and
- **every non-TalentTrack stylesheet is dequeued before the page paints**, so the theme's `style.css` (and any other plugin's CSS) cannot override TalentTrack's palette, typography or layout.

The WordPress admin bar still appears for logged-in staff (it is a WordPress control, not theme chrome, and gives staff a one-click route back to wp-admin), and operator-chosen Google Fonts still load. Everything else from the theme is stripped.

Earlier releases (v4.34.0–v4.45.25) exposed a **Full-canvas app** checkbox and a **Theme inheritance** toggle that let an academy defer styling to the WP theme. Both were removed in v4.45.26 because they contradicted total visual independence: a theme that won specificity battles could poison the palette. To re-brand TalentTrack, use the **Colours**, **Typography** and **Logo** sections of Appearance, or **Custom CSS** — not the theme.

The canvas only takes over the page that hosts the `[talenttrack_dashboard]` shortcode; every other page on the site renders through the theme as usual. Print and export pages (match prep, PDP, methodology) are unaffected — they already render as standalone documents with no chrome.

## Frontend Configuration sections

Configuration groups its settings **by the task you have in mind**, not by the module that happens to own them. There are ten sections, always in this order, and each opens with the question it answers:

| Section | The question | What is in it |
| --- | --- | --- |
| Academy | What is the academy called, how does it look, and how does the season run? | General, Appearance, Custom CSS, Seasons, Holidays, Season rollover, Default dashboard, Dashboard layouts, Custom widgets, Setup |
| Players & teams | What do we record for each player and team? | Custom fields, Profile cards, Player status methodology, Functional roles, Trial tracks, Letter templates, Players CSV import |
| Development & evaluations | How do we assess and develop players? | Evaluation categories, Category weights, Rating scale, PDP cycle blocks, PDP preparation questions, Manage tests, Chemistry settings, Methodology vocabulary |
| Activities & matches | How do we plan and record training and matches? | Match minutes, VCT configuration, Spond, Strava |
| Lookups | Which values appear in the lists? | All lookups, Attendance statuses, Football form, Lookup canonical-language review |
| People & access | Who may see and do what? | Accounts & access, Invitations, Access control matrix, Require two-step verification |
| Messages & tasks | What do we send to whom, and which tasks run automatically? | Messages, Workflow templates, Translations |
| Data | How do we bring data in and keep it safe? | Backups, Export configuration, Recycle bin, Import history, Media retention |
| Features & plan | What is switched on, and what is in our plan? | Modules, Features, Wizards, Plan and restrictions |
| System | For the administrator: logs and technical tools. | Audit log, Message log, Data browser, Migrations, wp-admin menus, Open wp-admin |

The rules that keep it readable:

- **Every setting appears once.** There is one access matrix, and Rating scale and Football form each have a single home. A setup screen is not listed a second time because it is also a tile.
- **You only see what you may open.** The System section is for academy administrators only, and so is the access matrix; a head of development sees neither.
- **A small section folds into the next one.** When fewer than three entries in a section are yours to open, they join the section after it, so nobody sees a section with one card in it.
- **Switched-off modules stay findable for an administrator.** Their settings are listed under Features & plan, marked *off*, and lead to the Modules page where you turn the module back on. Everyone else does not see them.

The **Find a setting** box at the top filters as you type. It matches the name, the description and a list of extra words for each setting, without regard to capitals or accents. *logo* finds Appearance, *weighting* (*weging*) finds Category weights, *spond* finds Spond and *2fa* finds the two-step requirement. When nothing matches, the page says so. Escape clears the box.

**Require two-step verification** (People & access) opens in the app. Tick the roles that must set up two-step verification, then Save, or Cancel to go back to Configuration. Each role shows how many of its accounts have not set it up yet, as a count and never by name, so you can see how many people a change reaches before you save. It needs the academy-wide right to change settings, and every change is audit-logged. Resetting somebody else's second factor after a lockout stays in wp-admin.

Entries that open in wp-admin carry an external-link marker so the context switch is expected; frontend entries do not.

*Developers:* the entries live in `config/settings_entries.php` and are read by `\TT\Shared\Configuration\SettingsRegistry`, which the landing, `GET /talenttrack/v1/settings-entries` (see the REST API reference) and search all use. Every entry for a screen in this repository goes in that file, because `tools/check-settings-entries.php` reads only the file; `SettingsRegistry::register()` is for code outside this repository, such as an add-on plugin. Entries contributed through the `tt_config_tile_groups` filter still appear, under Academy, unless an entry already leads to the same screen; the filter is deprecated. `tools/check-settings-entries.php` fails the build when a `?config_sub=` form, a setup tile in a settings section or a settings screen the Configuration module owns has no entry, unless `not_entries` says why.

## Tabs

### General

- **Academy name** — used throughout the plugin, in printable reports, and on the letterhead of every trial letter. Leave it empty and those surfaces fall back to the WordPress site title, which names the installation rather than the academy
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
| Focus ring color | `--tt-focus-ring`, `--tt-focus-color` | The outline around the active field or button when someone uses the keyboard |

Leaving a field empty restores the default token from the plugin's stylesheet. The operator's chosen colours are added to the plugin's design tokens on the page and, because canvas mode strips the active theme's CSS, nothing can override them.

**Focus ring color.** Without a focus colour, the keyboard focus ring follows the primary colour. The blue the field shows before you pick anything (`#1e88e5`) counts as no focus colour, because the form saves it with every other Branding setting. A focus colour you set takes its place, as long as it stands out against the page (a contrast of at least 3 to 1). A fainter colour is still saved, but the form warns that it is hard to see as a focus outline, and the ring keeps following the primary colour so keyboard users can always see where they are. Under a visual theme the theme decides the ring.

### Backward compatibility

The existing **Primary color** and **Secondary color** fields keep working unchanged. The new fields are additive — installs that don't touch them see no visual difference.
