<!-- audience: admin -->

# Configuration — Lookups

**Audience:** academy administrator.

The Lookups tile on the Configuration page is the one place to manage every dropdown vocabulary the dashboard renders — activity types, positions, age groups, goal statuses, evaluation types, behaviour ratings, potential bands, and so on. Edits here propagate immediately to every form, list, and report that reads the affected vocabulary.

## The four surfaces

1. **Configuration → Lookups** — landing page. One tile per lookup category, **grouped into domain sections** (Activities & attendance, Players & teams, Evaluations & development, Goals, Scouting & trials, Tournaments & match, Staff & people, Reports & workflow, Advanced / internal). Each tile carries an icon, a short description, and clicks through to the per-category editor. A section with no visible tiles renders no heading. *(v4.26.11 — previously a single flat grid of ~32 cards.)*
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

### Age-group labels (v4.26.6)

Age groups use the international **U** notation as the canonical internal key (`U7…U23`, `Senior`). On a Dutch site they display with the **O** (Onder) convention — `O7…O23`, `Senioren` — resolved through `tt_translations` like every other lookup label. Migration `0163_seed_age_group_dutch_labels` backfills these Dutch labels on existing installs (INSERT IGNORE, so a club's own edits via the Edit view are never overwritten); fresh installs seed them from `LookupTranslationSeeds`. French, German and Spanish keep the U notation natively, so they carry no override row. Every dashboard surface renders the age-group label through `LookupTranslator`, never the raw `name`.

## Locked rows

Some rows are marked `is_locked = true` because workflow rules read them by name. Locked rows:

- Stay click-through to the Edit view.
- Render the lock icon next to the label.
- Hide the delete button (the row cannot be deleted without breaking the workflow rule).

The Internal key field on a locked row is also disabled — the same Q4 protection that applies to all existing rows.

## REST surface

Every action in this view goes through `/wp-json/talenttrack/v1/lookups/{type}` (POST / PUT / DELETE) with the existing `tt_edit_settings` capability gate. The view is rendered server-side; the JS module composes the network payload and reloads on success. No new REST endpoints; the `/translations/preview` endpoint returns every other installed locale in one bulk response.

## Canonical-language contract (v4.12.0)

The going-forward rule: **`tt_lookups.name` is the stable English internal key**. It is never a translated user-visible string. Operator-visible labels live in `tt_translations` and are rendered through `LookupTranslator::name()` (which falls back to `name` only when no translation is registered).

Practical consequences:

- New rows added via the admin grid: the Internal key field is required; type a lowercase ASCII string with no spaces (e.g. `match`, `training`, `in_progress`).
- Existing rows: the Internal key field is read-only. To change it, a code migration is required so every `WHERE name = ...` reference across the codebase is updated atomically.
- Dashboards never read `tt_lookups.name` directly. They go through `LookupTranslator::name($row)`, which resolves via `tt_translations` for the current locale, then the gettext domain, and only as the last-resort backstop returns the raw `name`.

## Drift review tool (v4.12.0)

Pilot installs that pre-date v4.11.0 may carry mixed-language values in `tt_lookups.name` (some Dutch, some English, some lowercase) because earlier admin workflows let operators type anything into that column. v4.12.0 ships a one-shot review tool to normalise the column without breaking the dashboard.

**Migration 0132** walks every `tt_lookups` row, cross-checks `name` against the canonical seed map in `LookupCanonicalSeeds`, and writes a `lookup.needs_review` entry to `tt_audit_log` for every drifted row (carrying the current value, the suggested canonical, and the detected source locale). The migration never auto-renames anything — every accepted rewrite is operator-driven.

**Reaching the tool:** Configuration -> "Lookup canonical-language review" tile (only appears while there are pending rows). The tile description carries the count.

**Per-row actions:**

- **Accept** rewrites `tt_lookups.name` to the chosen canonical (default: the migration's suggestion; you can override with another value from the canonical list) AND preserves the previous drifted value as a `tt_translations` entry in the locale you select. Result: the column is now canonical English; dashboards still show the Dutch / English / etc. label your team is used to seeing.
- **Skip** leaves the row untouched and records the deliberate decision so the queue stops surfacing it. Use Skip when the drifted value is acceptable as the canonical (e.g. a custom domain term your academy invented that has no English equivalent).

Both actions are append-only writes to `tt_audit_log`; the original `lookup.needs_review` entry is preserved for traceability.

**Where to look for follow-up:** the History line at the bottom of the tool shows total counts of applied + skipped resolutions. The `Audit log` tile in Configuration filters on `entity_type = lookup` for full forensic detail.
