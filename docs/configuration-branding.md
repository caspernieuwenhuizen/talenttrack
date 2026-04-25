# Configuration & branding

The **Configuration** page is where your academy's identity and the plugin's operational knobs live.

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

## Theme inheritance & curated styling

*Added in v3.8.0.* The Branding tab has a second section that lets the dashboard match a club's existing WordPress theme without writing CSS or building a custom theme.

### Inherit WordPress theme styles (toggle)

When ON, the dashboard defers four things to the surrounding WP theme:

- Body and heading **fonts**
- **Link** color
- **Heading** color
- Plain submit / primary **button** styling

When OFF, the dashboard uses TalentTrack's own defaults — same as before this version.

What the toggle does **not** affect (intentionally):

- Player card tier styling (gold / silver / bronze stays locked — it's part of the product identity)
- Dashboard tile grid borders and accents
- The `FrontendListTable` component
- Spacing, layout, structural CSS

If a property you want inherited isn't covered above, it likely doesn't cascade naturally (e.g. background colors, paddings, borders) — the plugin's structural CSS keeps it consistent on purpose.

### Display font / Body font

Two dropdowns with curated [Google Fonts](https://fonts.google.com/) families.

- **Display** candidates are condensed / sporty (Oswald, Bebas Neue, Anton, Barlow Condensed…) — used for headings, tile titles, and player card numbers.
- **Body** candidates are clean sans-serifs plus a couple of serifs (Inter, Manrope, DM Sans, Source Serif 4…) — used for paragraphs, tables, and form labels.

Each dropdown has two non-Google entries at the top:

- **(System default)** — no Google Fonts request; falls through to TalentTrack's default font stack.
- **(Inherit from theme)** — only meaningful when the inherit-toggle above is ON; otherwise behaves like System default.

When at least one dropdown picks a curated family, the plugin enqueues a single combined Google Fonts request (display + body together, with the weights TalentTrack actually uses).

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

Leaving a field empty restores the default token from the plugin's stylesheet.

### Honest framing — what "inherit" actually does

Some CSS properties cascade naturally (font-family, color, link color). Others don't (background, padding, border-radius). The toggle's effect:

- **Typography**: full inheritance.
- **Link color**: full inheritance.
- **Heading color and family**: full inheritance.
- **Buttons**: best-effort. The plugin's button-background and color rules are reverted, but the host theme's button styling only takes over if its CSS targets selectors that match the plugin's button DOM. Most themes style block-editor buttons (`.wp-block-button__link`) — they won't restyle the plugin's `.button-primary` automatically. Themes that style the `<button>` element directly get full inheritance.
- **Spacing, borders, shadows**: not inherited — the plugin's structural CSS stays.

If you have a custom theme that adds `body .tt-dashboard { ... }` overrides (the JG4IT-style approach), those still win — the toggle is the easier path, but the override path keeps working.

### Backward compatibility

The existing **Primary color** and **Secondary color** fields keep working unchanged. The new fields are additive — installs that don't touch them see no visual difference.
