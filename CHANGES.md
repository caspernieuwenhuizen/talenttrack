# TalentTrack v4.9.0 — Exports column picker on CSV + XLSX cards (closes #986)

Surfaces a generic, exporter-driven column picker on every tabular card on the central Exports page (`?tt_view=exports`). Coaches, HoDs and Admins who want a focused export — "just date, player and rating", not 14 columns — can now deselect columns before clicking Export instead of post-processing the file in Excel.

The shape is intentionally generic: no per-exporter UI code. Every CSV / XLSX exporter contributes via one new interface method, and the picker / filter logic is shared across both renderers.

## Architecture — three moves

### 1. `ExporterInterface::availableColumns(): array`

Each exporter declares an ordered map of `column_key => translatable_label`. The map order is the column order in the payload's `headers` / `rows`, which is how the filter projects positions back to keys without changing every `collect()` to return a key-mapped row shape.

Tabular CSV / XLSX exporters declare their columns; non-tabular exporters (PDF / JSON / iCal / ZIP / multi-sheet `demo_data_xlsx`) return an empty array — they're opted out of the picker by contract.

### 2. `Format\ColumnFilter::apply()` + `sanitiseSelection()`

Two static helpers in a single class:

- `sanitiseSelection( $raw, $availableColumns )` — validates a POSTed `columns[]` list against the declared map and returns the kept keys **in declared order** (so a renderer never reasons about user-toggle order).
- `apply( $payload, $availableColumns, $selectedKeys )` — projects the payload's headers + rows down to the kept positions. Tail columns beyond the declared static set (e.g. dynamic main-category columns in `PlayerEvaluationsCsvExporter`) pass through unchanged. Multi-sheet payloads (`sheets` key) are returned as-is — the picker is single-sheet only.

`ExportService::run()` calls the helper after `collect()` and before `render()`. The CSV and XLSX renderers themselves stay format-pure — no column logic leaks into either.

### 3. UI on the export card

`FrontendExportsView::renderCard()` now emits a `<details class="tt-export-card__columns">` block whenever the card's exporter has a non-empty `availableColumns()`:

- Summary: "Columns · all selected" (default) → "Columns · 3 of 6 selected" once the user toggles.
- Inside: All / None quick-toggles, then one checkbox per column, all checked by default.
- A short inline `<script>` watches `change` events on the columns and on the format chip; the summary count updates live, and the whole `<details>` hides when the user switches the format chip to a non-tabular slug (PDF / JSON / iCal / ZIP).

No URL state for column selection — picker resets to "all" on each page load. The cost of compact URLs is greater than the value of a remembered picker (the rare power-user case can save a CSV template in Excel).

## Files touched

- `src/Modules/Export/ExporterInterface.php` — adds `availableColumns(): array`.
- `src/Modules/Export/Format/ColumnFilter.php` — new helper.
- `src/Modules/Export/ExportService.php` — wires the helper between `collect()` and `render()`; strips `selected_columns` out of filters before `validateFilters()` runs (so per-exporter validators don't need to know about column selection).
- `src/Modules/Export/Exporters/*CsvExporter.php`, `*XlsxExporter.php` — every existing exporter declares its `availableColumns()`. Non-tabular exporters return `[]` (one line each).
- `src/Shared/Frontend/FrontendExportsView.php` — renders the picker; extracts `columns[]` from POST and passes it as `selected_columns` on the `ExportRequest`'s filters.
- `assets/css/frontend-exports.css` — styling for the collapsed `<details>` block, checkbox grid, quick-toggle row.

## Default = all selected

Anyone who doesn't open the picker gets today's CSV / XLSX byte-for-byte. Both helpers short-circuit when the selection is missing or matches the full declared set; the renderer pipeline is untouched in that path.

## Out of scope

- **Save column-selection presets** — would need a new `tt_user_export_presets` table.
- **Reordering columns** in the picker — column order is exporter-declared. Drag handles would be a much bigger UI ask.
- **PDF "include section X" toggles** — different concern; PDFs are HTML-payload, not tabular.
- **Per-surface tabular exporters** (e.g. a future team-planner CSV) — they'll get the picker for free as long as they implement the new method.

Patch / minor split: minor bump — new user-visible feature on the central Exports surface, every tabular card now carries a new control.
