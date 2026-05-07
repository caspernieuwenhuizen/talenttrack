# TalentTrack v3.109.2 — Seed review: Excel export + offline edit + re-import for lookups, eval categories, roles

New admin surface for bulk-reviewing every seeded translatable row in one Excel.

## What landed

### `?page=tt-seed-review` (Configuration group)

Two actions, both cap-gated on `tt_edit_settings`:

- **Download review template** → streams a single `.xlsx` with one sheet per seed table.
- **Apply edits from upload** → accepts the edited file back, diffs against live rows by primary key, and applies in-place updates.

### Sheets

| Sheet | Source table | Editable columns |
| - | - | - |
| Lookups | `tt_lookups` (club-scoped) | name · description · sort_order · meta_color · locked |
| Eval categories | `tt_eval_categories` (main + sub) | label · display_order · is_active · rating_max · meta |
| Roles | `tt_roles` | label |
| Functional roles | `tt_functional_roles` | label · sort_order · is_active |

Every row carries:

- `id` — primary key, used as the match key on re-import.
- The canonical `name` / `label` (typically English).
- `label_nl` — populated by switching the active locale to `nl_NL` and calling `__()` on the stored English string. Empty when no Dutch translation exists in the .po yet.
- `language_of_name` / `language_of_label` heuristic flag (Dutch markers like *Aanwezig* / *Rechts* / *Hoofdtrainer* → `nl`; otherwise `en`).
- `notes` — free-text column for the operator's review comments. Ignored on re-import.

### Re-import behaviour

- Match key: `id`. Rows with no `id` or with an `id` not present in the live DB are skipped.
- Diff: per-column comparison; only changed columns get persisted.
- `meta_color` and `locked` merge into `tt_lookups.meta`; other meta keys are preserved.
- Rows missing from the upload are left alone (partial patch, not full replacement).
- New rows can NOT be added through the importer.
- Every applied row update writes a `seed_review.row_updated` audit event.

### What this is NOT

- **Not a seed-file rewriter.** Shipped `config/authorization_seed.php` and migrations are untouched. Operators who want a change to ship to other installs as code work it back manually.
- **Not a multilingual editor.** The `label_nl` column is read-only (sourced from `__()`); editing it does nothing on re-import. Translations land in `nl_NL.po` through the existing translations.yml workflow.

## Affected files

- `src/Modules/SeedReview/SeedReviewModule.php` — new module shell
- `src/Modules/SeedReview/SeedExporter.php` — Excel writer (4 sheets)
- `src/Modules/SeedReview/SeedImporter.php` — Excel parser + per-table appliers
- `src/Modules/SeedReview/Admin/SeedReviewPage.php` — admin page + export/import handlers
- `config/modules.php` — register the new module
- `talenttrack.php`, `readme.txt`, `CHANGES.md`, `SEQUENCE.md` — version + ship metadata

Uses the existing PhpSpreadsheet dependency. Renumbered v3.109.1 → v3.109.2 mid-rebase after parallel-agent ship of #0083 deferred follow-ups (team + activity Analytics tabs + explorer Export CSV) took v3.109.1.
