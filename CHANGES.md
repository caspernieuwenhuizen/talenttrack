# TalentTrack v3.108.0 — Demo-data Excel: Sessions → Activities sheet rename (#0080 Wave D, closes epic)

Closes #0080. The last leftover from the v3.81.0 vocabulary sweep — the demo-data Excel template's `Sessions` tab is renamed to `Activities`. **Hard rename, no soft fallback** per spec note 161 plus operator confirmation that no in-flight workbook uses the legacy name.

## What landed

### Schema rename

`SheetSchemas::all()`'s `'sessions'` entry's `'sheet'` field flips from `'Sessions'` to `'Activities'`.

The schema array key stays `sessions` for back-compat with the internal code paths that key on the schema map (the importer's per-sheet validators, the `present_sheets` accumulator, the seed lookups inside the procedural top-up). Renaming the key would have rippled through ~20 call sites for zero visible benefit; the user-facing rename is the sheet display name.

### Template export

`TemplateBuilder` reads sheet names from `$schema['sheet']`, so the rename propagates automatically. Freshly-downloaded `.xlsx` files now carry an `Activities` tab where `Sessions` used to be. The README sheet's prose updated in lock-step ("Sessions, Attendance, Evaluations" → "Activities, Attendance, Evaluations" in the Transactional list).

### Importer — early blocker on legacy workbooks

`ExcelImporter::importFile()` detects a workbook that carries a `Sessions` sheet but no `Activities` sheet and emits an early blocker before any other validation runs:

> Sheet "Sessions" was renamed to "Activities" in v3.108.0 — re-download the demo-data template, or rename the sheet to "Activities" in your workbook.

No silent fallback. The block is intentional — soft-renaming the sheet for the user would have hidden the schema drift and made the workbook diverge from the live template on the next download.

### Documentation

`docs/demo-data-excel.md` (EN) and `docs/nl_NL/demo-data-excel.md` (NL) gain a top-of-file migration note flagging the rename, the manual rename escape hatch, and the explicit "no soft fallback" stance.

### Translations

One new NL msgid for the blocker copy.

## What's NOT in this PR

- **Renaming `Session_Attendance`** — kept as-is. The attendance tab is keyed on `session_attendance` internally, the rename was scoped to the activities top-line tab only.
- **Renaming the internal `'sessions'` schema key** — kept as-is for code-path stability per the rationale above.
- **A migration that auto-renames operator-installed workbooks** — out of scope; operators re-download the template or rename one sheet by hand.

Renumbered v3.104.0 → v3.105.0 → v3.106.0 → v3.107.0 → v3.108.0 across multiple rebases as parallel-agent ships of #0084 (mobile pattern library), #0083 Children 1-6, #0063 Export module foundation, #0066 Communication module foundation, #0078 Phase 1 custom widget builder data-source layer, and #0076 Playwright coverage v1 starter took the v3.104.0 / v3.104.1 / v3.104.2 / v3.104.3 / v3.104.4 / v3.104.5 / v3.105.0 / v3.106.0 / v3.106.1 / v3.106.2 / v3.107.0 slots.
