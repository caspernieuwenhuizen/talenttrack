<!-- audience: admin -->

# Configuration — Lookups

**Audience:** academy administrator.

The Lookups tile on the Configuration page is the one place to manage every dropdown vocabulary the dashboard renders — activity types, positions, age groups, goal statuses, evaluation types, behaviour ratings, potential bands, and so on. Edits here propagate immediately to every form, list, and report that reads the affected vocabulary.

## The four surfaces

1. **Configuration → Lookups** — landing page. One tile per lookup category. Each tile carries an icon, a short description, and clicks through to the per-category editor.
2. **Per-category editor** — the list-first view of one lookup category's values (e.g. Activity types). Default view: a clean roster of values with a `+ Add value` button at the top.
3. **Add new value** — opens from the `+ Add value` button. Empty form with the Internal key field at the top + a 5-locale translation grid below.
4. **Edit value** — opens by tapping a row in the list. Same shape as Add but populated with the row's data and translations. The Internal key field is read-only on existing rows.

## List view

Each row shows, left to right:

- **Drag grip** (⋮⋮) — drag to reorder. The new sort order persists immediately via the existing reorder endpoint.
- **Colour swatch** — when the category carries a `show_color` flag (Activity types, Goal statuses, etc.).
- **Label + internal key** — the operator-visible label comes from `tt_translations` (so a Dutch site shows the Dutch label); the internal key is shown in monospace next to it as a stable database identifier.
- **Translation-coverage dots** — one dot per supported locale. Filled green = a translation exists for that locale; warning orange = missing. Site locale is shown first; `en_US` second; remaining locales follow in stable order. The coverage check looks at the Label only — Description is optional and doesn't gate the dot.
- **Sort order** — the integer the row sorts by within the category.
- **Delete button** — hidden on locked rows (rows that workflow rules depend on). Locked rows are still click-through to the edit view; the delete button is simply not rendered.

A `+ Add value` button at the top of the list opens the Add view.

## Edit view

Tap any row to open the Edit view. The form has two cards:

### Card 1 — the row's columns

- **Internal key** — disabled on existing rows. This is the stable database identifier (e.g. `match`, `training`). To change it, a code migration is required so every reference is updated atomically.
- **Sort order** — integer.
- **Pill colour** — colour picker, when the category carries `show_color`.
- **Description (canonical, optional)** — the English description shown when no per-locale translation is set, when the category carries `show_desc`.

### Card 2 — translations

A grid with one row per supported locale (`en_US`, `nl_NL`, `de_DE`, `es_ES`, `fr_FR` on a typical install). Site locale is highlighted in the brand accent colour. Each row carries a Label input and — when the category carries `show_desc` — a Description input.

- **English (`en_US`) is a first-class translation slot**. The canonical English display value lives in `tt_translations`, not in the database's `name` column. The `name` column is now framed as the immutable internal key.
- **Translate from English** button (above the grid) calls the configured translation engine and pre-fills empty Label fields with auto-translations. Review and edit before saving.

## Add view

Same shape as Edit, but:

- Internal key is editable (and required). Lowercase ASCII, no spaces. This value cannot be changed later.
- Sort order defaults based on the existing list.
- All translation fields start empty.

## Save + Cancel

Both the Add and Edit views carry a Cancel + Save pair at the bottom (CLAUDE.md §6 contract). Cancel returns to the list view of the same category. A `+ Back to list` ghost button on the left rail does the same thing.

## Data backfill (v4.11.0)

In v4.11.0 a one-time migration (`0131_lookup_translation_seeds`) backfills `tt_translations` rows for every existing `tt_lookups` row across the five supported locales:

- **en_US** — seeded from the row's `name` (and `description` where present).
- **nl_NL / de_DE / es_ES / fr_FR** — seeded from the shipped `.po` translations if the locale has a `msgstr` for that msgid; otherwise left empty so the admin form surfaces the missing slot.

The migration is idempotent; re-running it has no effect.

## Locked rows

Some rows are marked `is_locked = true` because workflow rules read them by name. Locked rows:

- Stay click-through to the Edit view.
- Render the lock icon next to the label.
- Hide the delete button (the row cannot be deleted without breaking the workflow rule).

The Internal key field on a locked row is also disabled — the same Q4 protection that applies to all existing rows.

## REST surface

Every action in this view goes through `/wp-json/talenttrack/v1/lookups/{type}` (POST / PUT / DELETE) with the existing `tt_edit_settings` capability gate. The view is rendered server-side; the JS module composes the network payload and reloads on success. No new REST endpoints; the `/translations/preview` endpoint returns every other installed locale in one bulk response.
