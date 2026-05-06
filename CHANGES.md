# TalentTrack v3.105.0 — Demo-data Excel: Sessions → Activities sheet rename (#0080 Wave D, closes epic)

Closes #0080. Wave D is the last leftover from the v3.81.0 user-facing-vocabulary sweep — the demo-data Excel template's `Sessions` sheet renamed to `Activities`. Hard rename per the locked decision in spec note 161, with operator confirmation that no in-flight workbook uses the legacy name.

## What changed

- **Schema** (`SheetSchemas::all()`) — the `'sessions'` entry's `'sheet'` field flips from `'Sessions'` to `'Activities'`. The schema array key stays `sessions` for back-compat with internal code paths (importer / cleaner / generator / tag registry continue to read `$rows['sessions']` exactly as before).
- **Template export** (`TemplateBuilder`) — reads sheet names from `$schema['sheet']`, so the rename propagates automatically; a freshly-downloaded `.xlsx` carries an `Activities` tab where `Sessions` used to be. The README sheet's prose updated to say `Activities`.
- **Importer** (`ExcelImporter::importFile()`) — detects a workbook that carries a `Sessions` sheet but no `Activities` sheet and emits an early blocker before any other validation: *"Sheet 'Sessions' was renamed to 'Activities' in v3.105.0 — re-download the demo-data template, or rename the sheet to 'Activities' in your workbook."* No silent fallback, no auto-rename. Workbooks that already use `Activities` import normally.
- **Docs** — `docs/demo-data-excel.md` + `docs/nl_NL/demo-data-excel.md` get a top-of-file migration note explaining the rename + the importer's blocker behaviour.

## What didn't change

- The `Session_Attendance` sheet keeps its name. The spec only called out the activities sheet for rename; the attendance sheet's name carries the FK column convention (`session_key` → `sessions.auto_key`) and the rename would touch every FK label too. Out of scope.
- The internal `'sessions'` schema key in `SheetSchemas::all()` and the importer's `$rows['sessions']` lookup. Both are code-path identifiers, never user-facing.
- All other sheets (Teams / People / Players / Trial_Cases / Evaluations / Goals / Player_Journey / etc.) — unchanged.

## Files touched

- `src/Modules/DemoData/Excel/SheetSchemas.php` — `'sheet' => 'Activities'`
- `src/Modules/DemoData/Excel/ExcelImporter.php` — early blocker on legacy `Sessions` sheet
- `src/Modules/DemoData/Excel/TemplateBuilder.php` — README prose update
- `languages/talenttrack-nl_NL.po` — 1 new msgid (the blocker message)
- `docs/demo-data-excel.md` + `docs/nl_NL/demo-data-excel.md` — migration note
- `talenttrack.php` + `readme.txt` + `SEQUENCE.md` (3.103.2 → 3.105.0)
- `specs/0080-epic-deferred-polish-wave-2.md` → `specs/shipped/` (closes the epic)

## Closes #0080

| Wave | Status |
| --- | --- |
| A — license-feature gates | Shipped in v3.95.1 (radar / undo-bulk / partial-restore) |
| A residual — radar visual refresh | Shipped in v3.103.0 |
| B — UX polish (5 items) | Shipped in v3.103.0 |
| C — architectural cleanup (2 items) | Shipped in v3.103.0 |
| **D — Excel sheet rename** | **Shipped in v3.105.0** ← this PR |
