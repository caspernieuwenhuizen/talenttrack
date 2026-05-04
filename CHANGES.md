# TalentTrack v3.90.2 — Demo data: per-category selective generation + selective wipe

Operator on a pilot install set up real teams + players + people manually, then wanted to wipe only the demo activities/evaluations/goals while keeping the real master data they'd built. Today's demo wipe is all-or-nothing: it either nukes every demo-tagged row or nothing. And generation already had master-data toggles (teams / people / players) but always wrote activities/evaluations/goals on top regardless. This release reworks both surfaces around the same six-category grid.

## Generation — six-checkbox grid

Step 0.5 ("What to generate") on the Demo Data page now exposes:

**Master data** (procedural source only — Excel + hybrid read these from the workbook):

- ☑ Generate teams
- ☑ Generate people + WP users
- ☑ Generate players

**Dependent entities** (every source):

- ☑ Generate activities
- ☑ Generate evaluations
- ☑ Generate goals

All default ON, preserving the v3.0 "everything generated" behaviour. The dependent-entity flags compose with the existing hybrid skip rules — if your Excel sheet covered evaluations, the procedural EvaluationGenerator still skips for that batch. Unchecking the operator-side flag forces the skip regardless.

`DemoGenerator::run()` reads three new keys (`gen_activities`, `gen_evaluations`, `gen_goals`); the `$skip_*` calculation now `OR`s in `! $gen_*` so source rules + operator opt-out compose. Default value is true when key is absent, so existing callers keep their v3.0 behaviour.

## Deletion — six-checkbox grid + cascade preview

The single "Wipe demo data" button is replaced by:

| Category | Cascade |
| - | - |
| Teams | + team_person, activities, attendance, evaluations, eval_ratings on those teams |
| People | + team_person assignments |
| Players | + attendance, evaluations, eval_ratings, goals tied to those players |
| Activities | + attendance for those activities |
| Evaluations | + per-category eval_ratings |
| Goals | (standalone) |

Each box renders with its current cascade row count (e.g. "Teams — 156 demo rows incl. team_person, activities, attendance, evaluations, eval_ratings on those teams"). Counts come from `DemoDataCleaner::categoryCounts()` which runs once at page render. Default state: no boxes checked — operator opts in. Form still requires "WIPE" typed in the confirm field.

Result notice now reports what was actually deleted: *"Demo data wiped — 1450 rows across activities, evaluations, goals."* Persistent demo WP users still go through the separate "Wipe demo users too" form with its three safety rails (domain match, not-current-user, not-last-admin) — that flow is unchanged.

## API change — `DemoDataCleaner::wipeData( ?array $categories = null )`

Old signature `wipeData()` still works: `null` falls back to the v3.85.0 "walk every entity type in DATA_ORDER except `person`" behaviour. New signature accepts a list of category keys (`['teams', 'activities']`); each key expands to its dependency cascade per `DemoDataCleaner::CATEGORIES`, the union is deduplicated server-side, and rows are deleted in `DATA_ORDER` so FK constraints stay happy. New `categoryCounts()` static helper returns `[category_key => total_demo_rows_in_cascade]` for the form preview.

Empty selection bounces with "Pick at least one category to wipe." Anything not in `CATEGORIES` is silently dropped at sanitisation time.

## What's not in this PR

- **Live JS preview** of the cascade impact — server-side counts at page render are static; the operator doesn't see the total update as boxes are checked. Counts are shown per-row though, so the math is visible. Worth adding if operators ask.
- **Per-batch wipe** — `tt_demo_tags` already keys by `batch_id`, so an operator could one day pick "wipe just the run from 2026-04-25". Not in scope.
- **Excel-source dependent-entity flags** — when source is `excel`, the three dependent-entity flags are wired but the existing `$source === 'excel'` short-circuit in DemoGenerator still skips them all (the spec defers entirely to the workbook). Hybrid does honour the flags. If excel-source operators want to mix-and-match in the future, the wiring is there.

## Renumbering

v3.90.1 → v3.90.2 in PR after the Excel-upload hardening shipped on origin/main mid-CI.

# TalentTrack v3.90.1 — Demo Excel upload no longer surfaces as a hosting 500

Hardening pass on the **Tools → TalentTrack Demo Data → Excel upload** path so a too-big or malformed workbook produces a friendly red notice instead of the generic blank-page / 500 the host's reverse proxy would otherwise return.

## Why

A pilot operator reported an Excel upload error that "almost looks like a hosting server side error" — no TalentTrack notice, no friendly bounce, just a generic page. Three failure modes in the upload path could each produce that symptom:

1. **`catch (\Exception $e)` in `ExcelImporter::importFile()` does not catch `\Throwable`.** PhpSpreadsheet can surface `\TypeError` on malformed XLSX, and an OOM during `load()` throws `\Error` / `\OutOfMemoryError`. Any of those bubbled out of WordPress's request lifecycle as a fatal — the host's reverse proxy turned that into a generic 500.
2. **No memory / time-limit raise before reading the workbook.** PhpSpreadsheet wants 64–128MB even with `setReadDataOnly(true)`. Shared hosts default to 64MB. OOM → fatal → 500.
3. **`post_max_size` overflow leaves `$_POST` and `$_FILES` empty.** `admin-post.php` then refuses the action entirely (no `action`, no nonce). The user sees WP's generic "Are you sure you want to do this?" page or a 413 from the host.

## What changed

- **`ExcelImporter::importFile()` now catches `\Throwable`**, not just `\Exception`. Any TypeError / Error from PhpSpreadsheet becomes the same friendly "Could not read the workbook: …" error the existing path returns.
- **`DemoDataPage::handleExcelImport()` raises `wp_raise_memory_limit('admin')` + `set_time_limit(0)`** before invoking the importer.
- **The importer call is wrapped in another `\Throwable` catch** as a defence-in-depth net for any fatal slipping past the inner catch (Logger writes the error class + message before bouncing).
- **Both the `tt_demo_excel_import` and `tt_demo_generate` handlers detect the `post_max_size` overflow** (POST request with non-zero Content-Length but empty `$_POST`) at the top of the handler and bounce with "Upload exceeded the server's POST size limit (post_max_size = …MB)".
- **`UPLOAD_ERR_*` codes get specific operator-readable messages** instead of "Upload failed (error code 1)". `UPLOAD_ERR_INI_SIZE` names `upload_max_filesize`; `UPLOAD_ERR_NO_TMP_DIR` names `upload_tmp_dir`; and so on.
- **The upload form surfaces the server's actual limits** below the file input — `upload_max_filesize`, `post_max_size`, `memory_limit` — so an operator can size their workbook before the upload fails.

## Files touched

- `talenttrack.php` — version bump to 3.90.1
- `src/Modules/DemoData/Excel/ExcelImporter.php` — catch `\Throwable`
- `src/Modules/DemoData/Admin/DemoDataPage.php` — raise memory + time limits, wrap importer in Throwable catch, detect post_max_size overflow, friendlier UPLOAD_ERR messages, surface server limits in the form
- `languages/talenttrack-nl_NL.po` — 14 new NL msgids
- `SEQUENCE.md` — Done row added
